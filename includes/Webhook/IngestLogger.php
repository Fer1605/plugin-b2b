<?php
/**
 * Writes the "everything is recorded before anything else happens" half of
 * the webhook (constitution principle IV), for
 * `b2bpl/v1` requests only (`RouteMatcher`).
 *
 * WordPress' REST dispatch order is what shapes this class in two halves:
 *
 * 1. `rest_pre_dispatch` fires once routing is about to happen, but *only*
 *    for a request `rest_authentication_errors` did not already reject
 *    (`RequestGuard`). This is the earliest point a full `WP_REST_Request`
 *    is available, and it runs before the route's own `permission_callback`
 *    and callback -- exactly "before validating or processing" for every
 *    request that gets this far. The inserted row id and batch id are
 *    stashed on the request itself (`set_param()`) so `RestController`'s
 *    callback can look them up without a second query.
 * 2. `rest_post_dispatch` fires for *every* `b2bpl/v1` request, including
 *    the ones `rest_authentication_errors` rejected before `rest_pre_dispatch`
 *    ever ran (401 bad credentials, 403 forbidden IP/HTTPS, 404 disabled,
 *    429 rate limited): those have no row yet, so this is where they get
 * one -- a full row for everything except a `401`, which ( one
 *    exception) gets only the minimal security line, no body at all. For a
 *    request that *did* get a row in `rest_pre_dispatch`, this same hook is
 *    also where that row is finalized with the response actually sent,
 *    since the final `WP_REST_Response` (after `rest_ensure_response()`
 *    has normalized even a `WP_Error` result into one) is only available
 *    here.
 *
 * If the initial insert in `rest_pre_dispatch` itself fails, the request is
 * never dispatched at all: a `503 b2bpl_log_unavailable` is returned
 * directly from the filter (constitution principle IV: nothing is ever
 * processed without first being recorded).
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Database\Schema;
use B2BPriceLists\Repository\Webhook\EventsRepository;
use B2BPriceLists\Repository\Webhook\RequestsRepository;
use B2BPriceLists\Support\Masker;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IngestLogger
 */
class IngestLogger {

	/**
	 * Request param key holding the inserted row's id, stashed on the
	 * `WP_REST_Request` by `log_before_dispatch()` for
	 * `RestController`/`finalize_dispatched_request()` to read back.
	 *
	 * @var string
	 */
	const PARAM_REQUEST_ROW_ID = 'b2bpl_request_row_id';

	/**
	 * Request param key holding the generated `batch_id`.
	 *
	 * @var string
	 */
	const PARAM_BATCH_ID = 'b2bpl_batch_id';

	/**
	 * Request param key holding the *original* row (as an array) when the
	 * current request's `idempotency_key` collided with one already used
	 * by this same authenticated user -- `RestController`
	 * reads this to answer with the contract's `200 duplicate` or `409
	 * b2bpl_idempotency_conflict` without running schema validation again.
	 *
	 * @var string
	 */
	const PARAM_DUPLICATE_ORIGINAL = 'b2bpl_duplicate_original_row';

	/**
	 * Allowed values of `endpoint`.
	 *
	 * @var string
	 */
	const ENDPOINT_UNKNOWN = 'unknown';

	/**
	 * Registers the two dispatch-lifecycle hooks. Called once from
	 * `Plugin::init()`, unconditionally -- logging (including the "webhook
	 * is disabled" 404) must happen whether or not the webhook itself is
	 * enabled.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'log_before_dispatch' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'log_after_dispatch' ), 999, 3 );
	}

	/**
	 * `rest_pre_dispatch` callback.
	 *
	 * @param mixed           $result  A prior filter's short-circuit value, or `null`.
	 * @param WP_REST_Server  $server  REST server instance (unused; kept for the hook's signature).
	 * @param WP_REST_Request $request Current request.
	 * @return mixed `$result` unchanged for anything other than `b2bpl/v1`
	 *               (or once logged successfully); a `WP_Error` (503) if
	 *               logging itself fails.
	 */
	public static function log_before_dispatch( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		if ( ! RouteMatcher::matches( null, ltrim( (string) $request->get_route(), '/' ) ) ) {
			return $result;
		}

		try {
			$outcome = self::attempt_log_and_detect_duplicate( $request );
		} catch ( Throwable $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort sink; the whole point of this branch is that the database write itself failed.
			error_log( '[B2B Price Lists] Webhook log write failed; request rejected unprocessed: ' . $exception->getMessage() );

			return self::log_unavailable_error();
		}

		if ( 'failed' === $outcome['outcome'] ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[B2B Price Lists] Webhook log write returned no row id; request rejected unprocessed.' );

			return self::log_unavailable_error();
		}

		if ( 'duplicate' === $outcome['outcome'] ) {
			// No new row for this attempt (the unique index
			// itself is what detected it): the repeat is instead recorded
			// as an event on the *original* request, and the controller
			// (`RestController::handle_batch_submission()`) answers `200`/
			// `409` directly from `$outcome['original_row']`, without
			// re-running schema validation.
			$request->set_param( self::PARAM_DUPLICATE_ORIGINAL, $outcome['original_row'] );
			self::record_duplicate_event( $outcome['original_row'] );

			return $result;
		}

		$request->set_param( self::PARAM_REQUEST_ROW_ID, $outcome['row_id'] );

		return $result;
	}

	/**
	 * The standard `503 b2bpl_log_unavailable` error (contract): the
	 * request is never processed when it cannot first be recorded
	 * (constitution principle IV).
	 *
	 * @return WP_Error
	 */
	private static function log_unavailable_error() {
		return new WP_Error(
			'b2bpl_log_unavailable',
			__( 'The request could not be recorded and was not processed.', 'b2b-price-lists' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Attempts to insert this request's full log row (
	 * insert-first, never check-then-insert). When the insert fails
	 * specifically because of the `(auth_user_id, idempotency_key)` unique
	 * index -- and only then -- this is treated as a duplicate submission,
	 * not a logging failure: the original row is re-read and returned
	 * instead.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array{outcome:string,row_id:int|null,original_row:array<string,mixed>|null}
	 *               `outcome` is one of `inserted`, `duplicate`, `failed`.
	 */
	private static function attempt_log_and_detect_duplicate( WP_REST_Request $request ) {
		global $wpdb;

		$data       = self::build_row_data( $request );
		$repository = new RequestsRepository();
		$row_id     = $repository->insert( $data );

		if ( false !== $row_id ) {
			return array(
				'outcome'      => 'inserted',
				'row_id'       => $row_id,
				'original_row' => null,
			);
		}

		$last_error  = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		$mysql_errno = ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) ? mysqli_errno( $wpdb->dbh ) : 0; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- `$wpdb` exposes no equivalent of the driver's own numeric error code (only `last_error`, a string); reading `mysqli_errno()` straight off `$wpdb->dbh` is the only way to check for MySQL's error 1062 specifically, read-only, no query of its own.

		if ( ! self::is_idempotency_conflict( $data['idempotency_key'], $mysql_errno, $last_error ) ) {
			return array(
				'outcome'      => 'failed',
				'row_id'       => null,
				'original_row' => null,
			);
		}

		$original = $repository->find_by_auth_user_and_idempotency_key( $data['auth_user_id'], $data['idempotency_key'] );

		if ( null === $original ) {
			// The conflict was real (the unique index rejected the insert),
			// but the row it collided with is now unreadable (e.g. removed
			// by the retention purge between the two operations) -- treated
			// as a logging failure rather than fabricating an "original".
			return array(
				'outcome'      => 'failed',
				'row_id'       => null,
				'original_row' => null,
			);
		}

		return array(
			'outcome'      => 'duplicate',
			'row_id'       => null,
			'original_row' => $original,
		);
	}

	/**
	 * Whether a failed `INSERT` into `b2bpl_webhook_requests` was rejected by
	 * its `(auth_user_id, idempotency_key)` unique index specifically
	 *, rather than by any other constraint or transient database
	 * error. MySQL's own error code `1062` ("Duplicate entry") is checked
	 * first; the index name (`Database\Schema::WEBHOOK_IDEMPOTENCY_INDEX`,
	 * shared with `Database\Installer`) is only a fallback for a driver that
	 * does not expose `mysqli_errno()` (a non-`mysqli` `$wpdb->dbh`, e.g. a
	 * PDO-based drop-in).
	 *
	 * Pure and unit-testable.
	 *
	 * @param string|null $idempotency_key Current request's idempotency key
	 *                                      (`null` means the insert cannot
	 *                                      possibly be an idempotency
	 *                                      conflict: `NULL` never collides
	 *                                      with anything in a `UNIQUE` index).
	 * @param int         $mysql_errno     `mysqli_errno( $wpdb->dbh )`, or
	 *                                      `0` when unavailable.
	 * @param string      $last_error      `$wpdb->last_error`.
	 * @return bool
	 */
	public static function is_idempotency_conflict( $idempotency_key, $mysql_errno, $last_error ) {
		if ( null === $idempotency_key ) {
			return false;
		}

		if ( 1062 === (int) $mysql_errno ) {
			return true;
		}

		return false !== stripos( (string) $last_error, Schema::WEBHOOK_IDEMPOTENCY_INDEX );
	}

	/**
	 * Records the repeat submission as an event on the original request
	 * (contract acceptance scenario 6: "registra el duplicado"), instead of
	 * a whole new `webhook_requests` row (which the unique index makes
	 * impossible for a genuine repeat, by design).
	 *
	 * @param array<string,mixed> $original_row Original request row.
	 * @return void
	 */
	private static function record_duplicate_event( array $original_row ) {
		( new EventsRepository() )->insert(
			array(
				'request_id' => (int) $original_row['id'],
				'level'      => 'info',
				'code'       => 'duplicate_resubmission',
				'message'    => __( 'The same idempotency key was submitted again; the original batch was not reprocessed.', 'b2b-price-lists' ),
			)
		);
	}

	/**
	 * `rest_post_dispatch` callback.
	 *
	 * @param WP_REST_Response|mixed $response Final response, already
	 *                                          normalized by
	 *                                          `rest_ensure_response()`
	 *                                          (so a `WP_Error` result
	 *                                          arrives here as a
	 *                                          `WP_REST_Response`, never a
	 *                                          bare `WP_Error`).
	 * @param WP_REST_Server         $server   REST server instance (unused).
	 * @param WP_REST_Request        $request  Current request.
	 * @return mixed `$response` unchanged; this filter only ever observes.
	 */
	public static function log_after_dispatch( $response, $server, $request ) {
		unset( $server );

		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $response;
		}

		if ( ! RouteMatcher::matches( null, ltrim( (string) $request->get_route(), '/' ) ) ) {
			return $response;
		}

		try {
			self::log_final_outcome( $request, $response );
		} catch ( Throwable $exception ) {
			// A logging failure at this stage must never break the response
			// already decided -- the request was already accepted/rejected;
			// only the bookkeeping about it is at risk.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[B2B Price Lists] Could not finalize a webhook log entry: ' . $exception->getMessage() );
		}

		return $response;
	}

	/**
	 * Either finalizes the row `log_before_dispatch()` already created, or
	 * -- for a request rejected before that point ever ran (401/403/404/
	 * 429) -- creates it now. Every outcome that is any kind of error (FR:
	 * notification emails, site owner request 2026-09: "absolutely every
	 * error", `$http_status >= 400`, with no exception for `401`/`404`/
	 * `429`) fires `Notifier::HOOK_REQUEST_REJECTED` -- `Webhook\Notifier`
	 * itself decides whether an email is actually sent (configured
	 * addresses, throttling); this class only ever describes *what
	 * happened*.
	 *
	 * @param WP_REST_Request        $request  Current request.
	 * @param WP_REST_Response|mixed $response Final response.
	 * @return void
	 */
	private static function log_final_outcome( WP_REST_Request $request, $response ) {
		$http_status = self::response_status( $response );
		$data        = self::response_data( $response );

		$row_id = $request->get_param( self::PARAM_REQUEST_ROW_ID );

		if ( $row_id ) {
			self::finalize_row( (int) $row_id, $data, $http_status );
			self::maybe_notify( $request, $http_status, $data, array( 'row_id' => (int) $row_id ) );

			return;
		}

		if ( $request->get_param( self::PARAM_DUPLICATE_ORIGINAL ) ) {
			// Already fully handled in `log_before_dispatch()`
			// (`record_duplicate_event()`): the original row's own fields
			// must never be overwritten by this repeat attempt's outcome. A
			// `409` conflict (a different body reusing the same key) is
			// still a genuine rejection worth notifying about; a plain
			// `200` duplicate never is (`maybe_notify()` filters on
			// `$http_status` either way).
			$original = $request->get_param( self::PARAM_DUPLICATE_ORIGINAL );
			self::maybe_notify( $request, $http_status, $data, array( 'row' => is_array( $original ) ? $original : array() ) );

			return;
		}

		// No row yet, and not a duplicate: rejected before
		// `log_before_dispatch()` ever ran (401/403/404/429).
		$rejection_code = self::rejection_code_from_response_data( $data );

		if ( 401 === $http_status ) {
			$new_row_id = self::insert_security_line( $request, $http_status, $rejection_code );
			self::maybe_notify(
				$request,
				$http_status,
				$data,
				array(
					'row_id'             => $new_row_id ? (int) $new_row_id : null,
					// Never the password: only the username half of whatever
					// Basic credentials (if any) were attempted, so a `401`
					// notification can still say who/what was tried.
					'username_attempted' => self::attempted_username(),
				)
			);
		} else {
			$new_row_id = self::insert_full_row( $request, $http_status, self::internal_status_for_http_status( $http_status, false ), $rejection_code, wp_json_encode( $data ) );
			self::maybe_notify( $request, $http_status, $data, array( 'row_id' => $new_row_id ? (int) $new_row_id : null ) );
		}

		if ( 401 === $http_status || 403 === $http_status ) {
			RequestGuard::register_failed_attempt( self::client_ip( $request ) );
		}
	}

	/**
	 * Fires `Notifier::HOOK_REQUEST_REJECTED` for any outcome that is an
	 * error (FR: notification emails -- "absolutely every error", site
	 * owner request 2026-09), assembling whatever context is available: the
	 * full row when one exists (`row_id`/`row`), or -- for the minimal
	 * `401` security line, which never gets a full row (
	 * exception) -- the bare request facts plus the attempted username.
	 * Never includes a password, an `Authorization` header, or a raw body.
	 *
	 * @param WP_REST_Request     $request     Current request.
	 * @param int                 $http_status Final HTTP status.
	 * @param array<string,mixed> $data        Decoded response body.
	 * @param array<string,mixed> $extra       Extra context: `row_id` (int|null,
	 *                                          row id when one was written),
	 *                                          `row` (array|null, already-loaded
	 *                                          row for the duplicate-conflict
	 *                                          branch), `username_attempted`
	 *                                          (string|null, Basic auth username
	 *                                          attempted for a `401`, never the
	 *                                          password).
	 * @return void
	 */
	private static function maybe_notify( WP_REST_Request $request, $http_status, array $data, array $extra = array() ) {
		if ( ! Notifier::should_notify_rejection( $http_status ) ) {
			return;
		}

		$row = null;

		if ( isset( $extra['row'] ) && is_array( $extra['row'] ) && ! empty( $extra['row'] ) ) {
			$row = $extra['row'];
		} elseif ( ! empty( $extra['row_id'] ) ) {
			$row = ( new RequestsRepository() )->find( (int) $extra['row_id'] );
		}

		/**
		 * Fires for every webhook request outcome that is an error (any
		 * `$http_status >= 400`, including `401`/`404`/`429`, FR:
		 * notification emails). Consumed by `Webhook\Notifier`, registered
		 * from `Plugin::init()`, which decides whether an email is actually
		 * sent (configured recipients, hourly throttle/digest queue).
		 *
		 * @param array<string,mixed> $context {
		 *     @type int         $http_status        Final HTTP status.
		 *     @type string|null $batch_id           This request's own logged `batch_id`, when known.
		 *     @type string|null $endpoint           `lists`/`users`/`ping`/`batches`/`unknown`.
		 *     @type string      $method             HTTP method.
		 *     @type string      $route              REST route.
		 *     @type string      $ip                 Caller's `REMOTE_ADDR`.
		 *     @type string|null $rejection_code     Top-level error code.
		 *     @type string      $message            Top-level error message.
		 *     @type array       $errors             Item/request-level error entries, when any.
		 *     @type string      $received_at        When the request was received (MySQL UTC datetime).
		 *     @type string|null $username_attempted Basic auth username attempted (`401` only), never the password.
		 *     @type int|null    $request_id         Row id, when one exists.
		 * }
		 */
		do_action(
			Notifier::HOOK_REQUEST_REJECTED,
			array(
				'http_status'        => (int) $http_status,
				'batch_id'           => null !== $row && isset( $row['batch_id'] ) ? (string) $row['batch_id'] : null,
				'endpoint'           => null !== $row && isset( $row['endpoint'] )
					? (string) $row['endpoint']
					: self::resolve_endpoint_from_route( $request->get_route(), $request->get_method(), (string) $request->get_param( 'batch_id' ) ),
				'method'             => (string) $request->get_method(),
				'route'              => (string) $request->get_route(),
				'ip'                 => self::client_ip( $request ),
				'rejection_code'     => self::rejection_code_from_response_data( $data ),
				'message'            => isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '',
				'errors'             => self::extract_error_entries( $data ),
				'received_at'        => null !== $row && isset( $row['received_at'] ) ? (string) $row['received_at'] : current_time( 'mysql', true ),
				'username_attempted' => isset( $extra['username_attempted'] ) ? $extra['username_attempted'] : null,
				'request_id'         => null !== $row && isset( $row['id'] ) ? (int) $row['id'] : ( isset( $extra['row_id'] ) ? $extra['row_id'] : null ),
			)
		);
	}

	/**
	 * The top-level `data.errors` array of a WordPress REST error response
	 * body, when present (contract: `422 b2bpl_invalid_payload`'s
	 * `data.errors`). Pure and unit-testable.
	 *
	 * @param array<string,mixed> $data Response data.
	 * @return array<int,array<string,mixed>>
	 */
	public static function extract_error_entries( array $data ) {
		return isset( $data['data']['errors'] ) && is_array( $data['data']['errors'] ) ? $data['data']['errors'] : array();
	}

	/**
	 * The Basic-auth username a `401` request attempted, if any -- never
	 * the password ( own credential parsing is reused read-only,
	 * for this one field, straight from the live superglobals; this class
	 * runs from a dispatch hook, always with the real request in scope,
	 * never a test double).
	 *
	 * @return string|null
	 */
	private static function attempted_username() {
		$credentials = RequestGuard::parse_basic_credentials( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the username half is ever read below; the password is never touched.

		return ( is_array( $credentials ) && isset( $credentials['username'] ) && '' !== $credentials['username'] )
			? (string) $credentials['username']
			: null;
	}

	/**
	 * The HTTP status code of a dispatch response, regardless of whether it
	 * is a `WP_REST_Response` (the normal case, post `rest_ensure_response()`)
	 * or, defensively, a bare `WP_Error` (should not happen this late, but
	 * handled rather than fataling).
	 *
	 * @param mixed $response Response value.
	 * @return int
	 */
	private static function response_status( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			return (int) $response->get_status();
		}

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();

			return isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
		}

		return 200;
	}

	/**
	 * The decoded response body, as an array, for either response shape.
	 *
	 * @param mixed $response Response value.
	 * @return array<string,mixed>
	 */
	private static function response_data( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();

			return is_array( $data ) ? $data : array();
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'data'    => $response->get_error_data(),
			);
		}

		return array();
	}

	/**
	 * The top-level `code` of a WordPress REST error response body, when
	 * present.
	 *
	 * @param array<string,mixed> $data Response data.
	 * @return string|null
	 */
	private static function rejection_code_from_response_data( array $data ) {
		return isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : null;
	}

	/**
	 * Maps an HTTP status (and whether the outcome was a duplicate
	 * idempotency key) to this plugin's own `status` column value
	 *. W3 introduces the later transitions
	 * (`processing`/`completed`/`completed_with_errors`/`paused`); this
	 * release only ever produces the values reachable before any actual
	 * batch processing exists.
	 *
	 * Pure and unit-testable.
	 *
	 * @param int  $http_status HTTP status code.
	 * @param bool $is_duplicate Whether the `200` response carries `duplicate: true`.
	 * @return string
	 */
	public static function internal_status_for_http_status( $http_status, $is_duplicate ) {
		if ( $http_status >= 500 ) {
			return 'failed';
		}

		if ( 202 === $http_status ) {
			return 'queued';
		}

		if ( 200 === $http_status ) {
			return $is_duplicate ? 'duplicate' : 'completed';
		}

		if ( $http_status >= 400 ) {
			return 'rejected';
		}

		return 'received';
	}

	/**
	 * A `POST /webhook` batch is logged as `lists` or `users` when its body
	 * declares a valid `type`, so the log shows what each send
	 * carried. Anything else keeps the route-based value. Pure and
	 * unit-testable.
	 *
	 * @param string $endpoint Route-based value.
	 * @param mixed  $decoded  Decoded JSON body, if any.
	 * @return string
	 */
	public static function endpoint_with_type( $endpoint, $decoded ) {
		if ( 'webhook' !== $endpoint || ! is_array( $decoded ) || ! isset( $decoded['type'] ) ) {
			return (string) $endpoint;
		}

		return in_array( $decoded['type'], PayloadSchema::types(), true ) ? (string) $decoded['type'] : (string) $endpoint;
	}

	/**
	 * Resolves `endpoint` from a REST route: on the
	 * single `/webhook` route, `POST` is a batch (`webhook`), `GET` with a
	 * `batch_id` is a status query (`batches`) and a plain `GET` is the
	 * connectivity check (`ping`). Anything else is `unknown`.
	 *
	 * Pure and unit-testable.
	 *
	 * @param string $route    e.g. `/b2bpl/v1/webhook`.
	 * @param string $method   HTTP method.
	 * @param string $batch_id `batch_id` query parameter, if any.
	 * @return string
	 */
	public static function resolve_endpoint_from_route( $route, $method = 'POST', $batch_id = '' ) {
		$route = trim( (string) $route, '/' );
		$path  = preg_replace( '#^b2bpl/v1/?#', '', $route );

		if ( 'webhook' !== $path ) {
			return self::ENDPOINT_UNKNOWN;
		}

		if ( 'POST' === strtoupper( (string) $method ) ) {
			return 'webhook';
		}

		return '' !== (string) $batch_id ? 'batches' : 'ping';
	}

	/**
	 * Prepares a raw request body for storage: hashes the *complete*
	 * original body, then truncates the stored copy to `$max_bytes` when it
	 * is longer -- the hash always covers everything the caller
	 * actually sent, never just the truncated copy, so it can be compared
	 * against a re-sent identical payload regardless of the storage cap.
	 *
	 * Pure and unit-testable (no WordPress function call).
	 *
	 * @param string $raw_body  Raw request body, exactly as received.
	 * @param int    $max_bytes Storage ceiling (`Settings::storage_max_body_bytes()`).
	 * @return array{stored:string,truncated:bool,sha256:string,bytes:int}
	 */
	public static function prepare_body_for_storage( $raw_body, $max_bytes ) {
		$raw_body  = (string) $raw_body;
		$bytes     = strlen( $raw_body );
		$max_bytes = max( 0, (int) $max_bytes );
		$truncated = $bytes > $max_bytes;

		return array(
			'stored'    => $truncated ? substr( $raw_body, 0, $max_bytes ) : $raw_body,
			'truncated' => $truncated,
			'sha256'    => hash( 'sha256', $raw_body ),
			'bytes'     => $bytes,
		);
	}

	/**
	 * Resolves the caller's IP the same way `RequestGuard` does (always
	 * `REMOTE_ADDR`), straight from the live superglobals (this class runs
	 * from a dispatch hook, always with the real request in scope, never a
	 * test double).
	 *
	 * @param WP_REST_Request $request Current request (unused directly;
	 *                                  kept for a consistent call shape and
	 *                                  potential future header-based
	 *                                  resolution from the request itself).
	 * @return string
	 */
	private static function client_ip( WP_REST_Request $request ) {
		unset( $request );

		return RequestGuard::resolve_client_ip( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- resolve_client_ip() only ever reads REMOTE_ADDR, never a client-supplied header.
	}

	/**
	 * Builds the (lower-cased header name => single string value) map from
	 * a `WP_REST_Request`, ready for `Masker::filter_headers()`.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array<string,string>
	 */
	private static function flatten_headers( WP_REST_Request $request ) {
		$flattened = array();

		foreach ( $request->get_headers() as $name => $values ) {
			$flattened[ $name ] = is_array( $values ) ? implode( ', ', $values ) : (string) $values;
		}

		return $flattened;
	}

	/**
	 * Inserts a full row (everything except the 401 security-line
	 * exception): raw body (masked), decoded+masked payload, masked
	 * headers, size/hash/truncation.
	 *
	 * @param WP_REST_Request $request        Current request.
	 * @param int|null        $http_status    Known final status, when
	 *                                         called from
	 *                                         `log_final_outcome()` for a
	 *                                         request rejected before
	 *                                         `log_before_dispatch()` ran;
	 *                                         `null` when called from
	 *                                         `log_before_dispatch()`
	 *                                         itself (status not decided
	 *                                         yet).
	 * @param string|null     $status         Internal `status` column value.
	 * @param string|null     $rejection_code Top-level error code, if any.
	 * @param string|null     $response_body  Pre-encoded response JSON, if
	 *                                         already known.
	 * @return int|false
	 */
	private static function insert_full_row( WP_REST_Request $request, $http_status = null, $status = null, $rejection_code = null, $response_body = null ) {
		$data           = self::build_row_data( $request, $http_status, $status, $rejection_code, $response_body );
		$original_key   = $data['idempotency_key'];
		$should_release = self::should_release_idempotency_key( $status ) && null !== $original_key;

		if ( $should_release ) {
			// Released at INSERT time, never reserved in the first place
			//: a request rejected before ever reaching `queued`
			// (403 IP/HTTPS, 404 disabled, 429 rate-limited) must not occupy
			// the `(auth_user_id, idempotency_key)` slot the caller needs to
			// retry the *same* key after fixing whatever was rejected.
			$data['idempotency_key'] = null;
		}

		$row_id = ( new RequestsRepository() )->insert( $data );

		if ( $row_id && $should_release ) {
			self::record_idempotency_release_event( (int) $row_id, $original_key );
		}

		return $row_id;
	}

	/**
	 * Builds the full row data for a request (everything except the 401
	 * security-line exception): raw body (masked), decoded+masked payload,
	 * masked headers, size/hash/truncation, and the idempotency key --
	 * shared by both `insert_full_row()` (used when the outcome is already
	 * known, or not needed for idempotency purposes) and
	 * `attempt_log_and_detect_duplicate()` (the insert-first idempotency
	 * check).
	 *
	 * @param WP_REST_Request $request        Current request.
	 * @param int|null        $http_status    See `insert_full_row()`.
	 * @param string|null     $status         See `insert_full_row()`.
	 * @param string|null     $rejection_code See `insert_full_row()`.
	 * @param string|null     $response_body  See `insert_full_row()`.
	 * @return array<string,mixed>
	 */
	private static function build_row_data( WP_REST_Request $request, $http_status = null, $status = null, $rejection_code = null, $response_body = null ) {
		$raw_body = (string) $request->get_body();
		$prepared = self::prepare_body_for_storage( $raw_body, Settings::storage_max_body_bytes() );

		$masked_headers = Masker::filter_headers( self::flatten_headers( $request ) );
		$decoded_stored = self::decode_json_if_possible( $prepared['stored'] );
		// a body that is not valid JSON (or is not an
		// object/array at all) still has to be masked before it is stored --
		// `Masker::mask_raw_text()` applies the same secret-key fragments as
		// a best-effort regex pass over `key=value` and `"key": "value"`
		// pairs, so a malformed payload never leaks a credential just
		// because it failed to parse.
		$masked_raw = null === $decoded_stored
			? Masker::mask_raw_text( $prepared['stored'] )
			: wp_json_encode( Masker::mask( $decoded_stored ) );

		$decoded = self::decode_json_if_possible( $raw_body );
		$payload = null === $decoded ? null : wp_json_encode( Masker::mask( $decoded ) );

		$idempotency_header = $request->get_header( 'Idempotency-Key' );
		$idempotency_key    = PayloadSchema::resolve_idempotency_key( is_array( $decoded ) ? $decoded : array(), $idempotency_header );

		return array(
			'batch_id'          => wp_generate_uuid4(),
			'endpoint'          => self::endpoint_with_type( self::resolve_endpoint_from_route( $request->get_route(), $request->get_method(), (string) $request->get_param( 'batch_id' ) ), $decoded ),
			'method'            => $request->get_method(),
			'received_at'       => current_time( 'mysql', true ),
			// the webhook is never a `WP_User` -- `auth_user_id` is
			// always `0`, and `auth_user_login` is the *configured* webhook
			// username (`Webhook\AuthContext::username()`, set by
			// `Webhook\RequestGuard` once credentials validate), never a
			// WordPress login name.
			'auth_user_id'      => 0,
			'auth_user_login'   => AuthContext::username(),
			'app_password_uuid' => null,
			'ip'                => self::client_ip( $request ),
			'user_agent'        => (string) $request->get_header( 'User-Agent' ),
			'idempotency_key'   => $idempotency_key,
			'http_status'       => null === $http_status ? 0 : (int) $http_status,
			'status'            => null === $status ? 'received' : $status,
			// only ever set here when the outcome is already
			// known (`$http_status`/`$status` provided -- `insert_full_row()`,
			// for a request rejected before `log_before_dispatch()` ever ran).
			// The very first, pre-dispatch insert (`attempt_log_and_detect_duplicate()`,
			// `$http_status` still `null`) never sets it: that row's outcome
			// is not decided yet.
			'finished_at'       => ( null !== $http_status && self::should_set_finished_at( null === $status ? 'received' : $status ) )
				? current_time( 'mysql', true )
				: null,
			'rejection_code'    => $rejection_code,
			'body_bytes'        => $prepared['bytes'],
			'body_sha256'       => $prepared['sha256'],
			'body_truncated'    => $prepared['truncated'],
			'headers'           => wp_json_encode( $masked_headers ),
			'raw_body'          => is_string( $masked_raw ) ? $masked_raw : wp_json_encode( $masked_raw ),
			'payload'           => $payload,
			'response_body'     => $response_body,
		);
	}

	/**
	 * Inserts the minimal security line for a `401` ( exception):
	 * date, IP, route, method -- deliberately no body, no payload, no
	 * headers beyond what identifies the attempt.
	 *
	 * @param WP_REST_Request $request        Current request.
	 * @param int             $http_status    Always `401` (kept as a
	 *                                         parameter so the stored value
	 *                                         always reflects reality even
	 *                                         if a future caller reuses
	 *                                         this for a different status).
	 * @param string|null     $rejection_code Top-level error code, if any.
	 * @return int|false
	 */
	private static function insert_security_line( WP_REST_Request $request, $http_status, $rejection_code ) {
		$repository = new RequestsRepository();

		return $repository->insert(
			array(
				'batch_id'        => wp_generate_uuid4(),
				'endpoint'        => self::resolve_endpoint_from_route( $request->get_route(), $request->get_method(), (string) $request->get_param( 'batch_id' ) ),
				'method'          => $request->get_method(),
				'received_at'     => current_time( 'mysql', true ),
				// A `401` always means the webhook's own credentials were
				// missing or wrong: never authenticated, so never
				// a configured username either.
				'auth_user_id'    => 0,
				'auth_user_login' => '',
				'ip'              => self::client_ip( $request ),
				'user_agent'      => (string) $request->get_header( 'User-Agent' ),
				'http_status'     => (int) $http_status,
				'status'          => 'rejected',
				// a `401` is always a synchronous, definitive
				// outcome -- decided and finished in the very same request.
				'finished_at'     => current_time( 'mysql', true ),
				'rejection_code'  => $rejection_code,
				'body_bytes'      => 0,
				'body_sha256'     => '',
				'body_truncated'  => false,
			)
		);
	}

	/**
	 * Finalizes an already-inserted row with the response actually sent.
	 *
	 * @param int                 $row_id      Row id from `log_before_dispatch()`.
	 * @param array<string,mixed> $data        Decoded response body.
	 * @param int                 $http_status Final HTTP status.
	 * @return void
	 */
	private static function finalize_row( $row_id, array $data, $http_status ) {
		$repository = new RequestsRepository();

		$is_duplicate = ! empty( $data['duplicate'] );
		$status       = self::internal_status_for_http_status( $http_status, $is_duplicate );

		$update = array(
			'http_status'   => $http_status,
			'status'        => $status,
			'response_body' => wp_json_encode( $data ),
		);

		// never set while the batch is merely `queued` (a `202`
		// response) -- only once processing has actually finished, which for
		// *this* method (only ever reached for a request that already has a
		// row -- i.e. one that got far enough to be queued or synchronously
		// rejected here, not the pre-dispatch-rejected 401/403/404/429 case
		// `insert_full_row()`/`insert_security_line()` handle their own way)
		// means every status other than `queued` itself (a synchronous
		// `rejected`/`failed` decided right here is exactly the "definitive
		// synchronous rejection" also calls out).
		if ( self::should_set_finished_at( $status ) ) {
			$update['finished_at'] = current_time( 'mysql', true );
		}

		if ( isset( $data['code'] ) && is_string( $data['code'] ) ) {
			$update['rejection_code'] = $data['code'];
		}

		if ( isset( $data['items_total'] ) ) {
			$update['items_total'] = (int) $data['items_total'];
		}

		if ( self::should_release_idempotency_key( $status ) ) {
			$existing     = $repository->find( $row_id );
			$original_key = ( null !== $existing && isset( $existing['idempotency_key'] ) ) ? $existing['idempotency_key'] : null;

			if ( null !== $original_key && '' !== (string) $original_key ) {
				$update['idempotency_key'] = null;
				self::record_idempotency_release_event( $row_id, $original_key );
			}
		}

		$repository->update( $row_id, $update );
	}

	/**
	 * Whether a request that reached this internal `status`
	 * must have its idempotency key released: any request
	 * rejected synchronously, before it ever became `queued` -- `rejected`
	 * covers every 4xx that reaches this point (400/403/404/413/415/422/429;
	 * a `409` idempotency conflict never reaches here at all, since it never
	 * gets a new row -- see `attempt_log_and_detect_duplicate()`), and
	 * `failed` covers a `5xx`. `queued`/`completed`/`duplicate` never release
	 * anything: only an accepted batch reserves the key for its retention
	 * window.
	 *
	 * Pure and unit-testable.
	 *
	 * @param string $status Internal `status` column value.
	 * @return bool
	 */
	public static function should_release_idempotency_key( $status ) {
		return in_array( $status, array( 'rejected', 'failed' ), true );
	}

	/**
	 * Pure decision behind `finished_at`: only ever set
	 * once processing has actually finished, or for a synchronous,
	 * definitive rejection decided at request time -- never while a batch
	 * is merely `queued` (a `202` response, still to be processed
	 * asynchronously) or `processing` (a still-in-progress run). Every other
	 * internal status this class or `Webhook\BatchProcessor` can reach --
	 * `received` (defensive default, should not normally be persisted),
	 * `rejected`/`failed` (a definitive synchronous outcome), `completed`/
	 * `completed_with_errors`/`duplicate`/`paused` -- is a real endpoint of
	 * that request's own lifecycle.
	 *
	 * @param string $status Internal `status` column value.
	 * @return bool
	 */
	public static function should_set_finished_at( $status ) {
		return ! in_array( $status, array( 'queued', 'processing' ), true );
	}

	/**
	 * Records the idempotency-key release as a traceable event (
	 * "guarda la clave original ... para trazabilidad") -- the row itself
	 * and its JSON are otherwise left completely intact.
	 *
	 * @param int    $request_id      Request id.
	 * @param string $idempotency_key The key that was released.
	 * @return void
	 */
	private static function record_idempotency_release_event( $request_id, $idempotency_key ) {
		( new EventsRepository() )->insert(
			array(
				'request_id' => (int) $request_id,
				'level'      => 'info',
				'code'       => 'idempotency_key_released',
				'message'    => __( 'The idempotency key was released because the request was rejected before being queued; it can be reused on a corrected retry.', 'b2b-price-lists' ),
				'context'    => wp_json_encode( array( 'idempotency_key' => $idempotency_key ) ),
			)
		);
	}

	/**
	 * `json_decode()`, returning `null` for anything that is not valid JSON
	 * (including a body that decodes to the scalar `null` itself, which is
	 * indistinguishable here and treated the same way: "nothing structured
	 * to mask").
	 *
	 * @param string $raw Raw string.
	 * @return array<mixed>|null
	 */
	private static function decode_json_if_possible( $raw ) {
		if ( '' === trim( (string) $raw ) ) {
			return null;
		}

		$decoded = json_decode( (string) $raw, true );

		return ( null === $decoded || JSON_ERROR_NONE !== json_last_error() ) ? null : $decoded;
	}
}
