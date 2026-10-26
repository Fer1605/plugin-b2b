<?php
/**
 * REST routes for the webhook (contracts/webhook-api.md,
 *): one single route, `/webhook`. `GET` is
 * the connectivity check, `GET ?batch_id=` returns a batch's status, and
 * `POST` receives a batch whose required `type` (`lists` or `users`,
 *) decides which operations it may carry.
 *
 * Registered only while the webhook is enabled (`Webhook\Settings::is_enabled()`)
 * -- with it off, these routes simply do not exist and WordPress answers
 * its own standard `rest_no_route` 404 (R9), which `Webhook\IngestLogger`
 * still records via `rest_post_dispatch`. Every route uses
 * `show_in_index => false` and the same `permission_callback`
 * shape: `Webhook\AuthContext::is_authenticated` -- the
 * webhook's own username/password, validated earlier by
 * `Webhook\RequestGuard` -- never a cookie session, never a nonce, never a
 * WordPress user's Application Password.
 *
 * `IngestLogger` has, by the time any callback here runs, already written
 * the full pre-processing log row (or detected an idempotency duplicate)
 * and stashed either `IngestLogger::PARAM_REQUEST_ROW_ID` or
 * `IngestLogger::PARAM_DUPLICATE_ORIGINAL` on the request. Every response
 * this controller returns is finalized onto that same row automatically by
 * `IngestLogger::log_after_dispatch()` -- nothing here writes to
 * `RequestsRepository` directly, which is what keeps "the log always
 * matches the response actually sent" a structural guarantee rather than
 * something every branch below has to remember to do.
 *
 * Since every `permission_callback` here only ever checks
 * `Webhook\AuthContext::is_authenticated()`: `Webhook\RequestGuard`'s own
 * `rest_authentication_errors` filter has, by the time any route callback
 * runs, already rejected a request whose webhook username/password
 * (`Webhook\Credentials`) is missing or wrong, so a route ever dispatching
 * at all implies that flag is already `true`. There is exactly one
 * credential per site, so the batch status query no longer distinguishes an
 * "owner" from an administrator -- any authenticated request may read any
 * batch.
 *
 * Batch processing itself (Action Scheduler, `list.*`/`products.*`/`user.*`)
 * is W3: a valid batch here only reaches `queued` and fires
 * `do_action( 'b2bpl_webhook_batch_queued', $batch_id )` as its extension
 * point, exactly as the design describes.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Repository\Webhook\ItemsRepository;
use B2BPriceLists\Repository\Webhook\RequestsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestController
 */
class RestController {

	/**
	 * This plugin's REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_SLUG = 'b2bpl/v1';

	/**
	 * The webhook's single route, inside `NAMESPACE_SLUG`.
	 *
	 * @var string
	 */
	const ROUTE = '/webhook';

	/**
	 * The full webhook URL for this site.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::NAMESPACE_SLUG . self::ROUTE );
	}

	/**
	 * The URL that returns a batch's status (`GET /webhook?batch_id=`).
	 *
	 * @param string $batch_id Batch UUID.
	 * @return string
	 */
	public static function status_url( $batch_id ) {
		return add_query_arg( 'batch_id', rawurlencode( (string) $batch_id ), self::url() );
	}

	/**
	 * Registers `rest_api_init`. Called once from `Plugin::init()`,
	 * unconditionally -- the routes themselves are only added when the
	 * webhook is enabled (checked inside `register_routes()`), so this
	 * registration being unconditional never registers anything while
	 * disabled.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers every route, only while the webhook is enabled.
	 *
	 * @return void
	 */
	public static function register_routes() {
		if ( ! Settings::is_enabled() ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE_SLUG,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_get' ),
					'permission_callback' => array( __CLASS__, 'check_ingest_permission' ),
					'args'                => array(
						'batch_id' => array(
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_batch' ),
					'permission_callback' => array( __CLASS__, 'check_ingest_permission' ),
				),
				'show_in_index' => false,
			)
		);
	}

	/**
	 * `permission_callback` for both methods of `/webhook`
	 *: the webhook's own username/password
	 * (`Webhook\AuthContext::is_authenticated()`, set by
	 * `Webhook\RequestGuard`). Never satisfied by a cookie session or an
	 * Application Password belonging to a WordPress user, even an
	 * administrator's.
	 *
	 * @return true|WP_Error
	 */
	public static function check_ingest_permission() {
		return AuthContext::is_authenticated() ? true : self::forbidden_error();
	}

	/**
	 * The standard `403 b2bpl_forbidden` (contract). Defensive only: by the
	 * time a route callback here ever runs, `Webhook\RequestGuard` has
	 * already rejected any request that is not authenticated
	 * (`Webhook\AuthContext::is_authenticated()` would always be `true`),
	 * so this branch should not normally be reachable in practice.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'b2bpl_forbidden',
			__( 'You are not allowed to use this endpoint.', 'b2b-price-lists' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * `GET /webhook`: a batch's status when the `batch_id` query
	 * parameter is present, otherwise the connectivity check.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get( WP_REST_Request $request ) {
		if ( '' !== (string) $request->get_param( 'batch_id' ) ) {
			return self::handle_batch_status( $request );
		}

		return self::handle_ping();
	}

	/**
	 * `GET /webhook` without `batch_id`: the connectivity check.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_ping() {
		$settings = Settings::get();

		return rest_ensure_response(
			array(
				'ok'              => true,
				'user'            => AuthContext::username(),
				'webhook_enabled' => true,
				'server_time'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
				// the catalogue keys are reported too, and whether
				// that module is on, so a sending system can size its
				// batches from the ping instead of guessing.
				'catalog_enabled' => ! empty( $settings['catalog_enabled'] ),
				'limits'          => array(
					'list_operations'    => (int) $settings['limits']['list_operations'],
					'product_rules'      => (int) $settings['limits']['product_rules'],
					'users'              => (int) $settings['limits']['users'],
					'catalog_operations' => (int) $settings['limits']['catalog_operations'],
					'catalog_variations' => (int) $settings['limits']['catalog_variations'],
					'max_body_bytes'     => (int) $settings['limits']['max_body_bytes'],
				),
			)
		);
	}

	/**
	 * `POST /webhook`: one batch of `type` `lists`
	 * (`list.*`/`products.*`) or `users` (`user.*`).
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_batch( WP_REST_Request $request ) {
		return self::handle_batch_submission( $request, PayloadSchema::ENDPOINT_WEBHOOK );
	}

	/**
	 * Body of `POST /webhook`: idempotency-duplicate short
	 * circuit, `Content-Type`, size, JSON well-formedness, structural
	 * validation (`PayloadSchema`), and, if everything holds, the `202`
	 * that queues the batch for W3.
	 *
	 * @param WP_REST_Request $request  Current request.
	 * @param string          $endpoint `PayloadSchema::ENDPOINT_WEBHOOK`.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function handle_batch_submission( WP_REST_Request $request, $endpoint ) {
		$duplicate_original = $request->get_param( IngestLogger::PARAM_DUPLICATE_ORIGINAL );

		if ( is_array( $duplicate_original ) ) {
			return self::duplicate_response( $request, $duplicate_original );
		}

		$row_id = (int) $request->get_param( IngestLogger::PARAM_REQUEST_ROW_ID );

		if ( $row_id <= 0 ) {
			// Defensive: `IngestLogger::log_before_dispatch()` always sets
			// one of the two params before dispatch reaches this callback.
			// Reaching this means the log write cannot be trusted, so the
			// request is refused the same way a failed pre-dispatch write
			// would have been.
			return new WP_Error(
				'b2bpl_log_unavailable',
				__( 'The request could not be recorded and was not processed.', 'b2b-price-lists' ),
				array( 'status' => 503 )
			);
		}

		$content_type = $request->get_content_type();
		$is_json      = is_array( $content_type ) && isset( $content_type['value'] ) && false !== stripos( (string) $content_type['value'], 'application/json' );

		if ( ! $is_json ) {
			return new WP_Error(
				'b2bpl_unsupported_media_type',
				__( 'The request body must be application/json.', 'b2b-price-lists' ),
				array( 'status' => 415 )
			);
		}

		$settings  = Settings::get();
		$max_bytes = (int) $settings['limits']['max_body_bytes'];
		$raw_body  = (string) $request->get_body();

		if ( strlen( $raw_body ) > $max_bytes ) {
			return new WP_Error(
				'b2bpl_payload_too_large',
				__( 'The request body exceeds the maximum allowed size.', 'b2b-price-lists' ),
				array( 'status' => 413 )
			);
		}

		$decoded = json_decode( $raw_body, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'b2bpl_invalid_json',
				__( 'The request body is not valid JSON.', 'b2b-price-lists' ),
				array( 'status' => 400 )
			);
		}

		$row = ( new RequestsRepository() )->find( $row_id );

		if ( null === $row ) {
			return new WP_Error(
				'b2bpl_log_unavailable',
				__( 'The request could not be recorded and was not processed.', 'b2b-price-lists' ),
				array( 'status' => 503 )
			);
		}

		$batch_id = (string) $row['batch_id'];

		$idempotency_header = $request->get_header( 'Idempotency-Key' );
		$errors             = PayloadSchema::validate( $decoded, $endpoint, $settings['limits'], $idempotency_header );

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'b2bpl_invalid_payload',
				__( 'The batch is not valid.', 'b2b-price-lists' ),
				self::build_invalid_payload_error_data( $errors, $batch_id )
			);
		}

		$items_total = count( $decoded['operations'] );

		/**
		 * Fires once a webhook batch has been validated and is ready for
		 * background processing (W3: `Webhook\BatchQueue`/`BatchProcessor`
		 * hook this to actually enqueue and run it). Nothing is enqueued by
		 * this release -- the batch is left in the `queued` state its log
		 * row already carries.
		 *
		 * @param string $batch_id UUID of the batch, matching `batch_id` in the response.
		 */
		do_action( 'b2bpl_webhook_batch_queued', $batch_id );

		return new WP_REST_Response(
			array(
				'batch_id'    => $batch_id,
				'status'      => 'queued',
				'items_total' => $items_total,
				'status_url'  => self::status_url( $batch_id ),
			),
			202
		);
	}

	/**
	 * Answers a repeated `idempotency_key` (acceptance scenario
	 * 6): `200` with the original batch when the body is byte-for-byte the
	 * same, `409 b2bpl_idempotency_conflict` when the caller reused the key
	 * for a genuinely different request.
	 *
	 * @param WP_REST_Request     $request      Current request.
	 * @param array<string,mixed> $original_row Original request row.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function duplicate_response( WP_REST_Request $request, array $original_row ) {
		$current_hash = hash( 'sha256', (string) $request->get_body() );

		if ( hash_equals( (string) $original_row['body_sha256'], $current_hash ) ) {
			return new WP_REST_Response( self::build_duplicate_payload( $original_row ), 200 );
		}

		return new WP_Error(
			'b2bpl_idempotency_conflict',
			__( 'This idempotency key was already used for a different request body.', 'b2b-price-lists' ),
			array(
				'status'   => 409,
				'batch_id' => $original_row['batch_id'],
			)
		);
	}

	/**
	 * The `200` duplicate-idempotency-key response body: the
	 * original batch's *real*, current `status` -- `queued`, `processing`,
	 * `completed`, `completed_with_errors`, `paused` or `failed` -- never
	 * hard-coded to `completed` regardless of how far the original batch has
	 * actually gotten. Pure and unit-testable.
	 *
	 * @param array<string,mixed> $original_row Original request row.
	 * @return array{batch_id:string,status:string,duplicate:true}
	 */
	public static function build_duplicate_payload( array $original_row ) {
		return array(
			'batch_id'  => $original_row['batch_id'],
			'status'    => $original_row['status'],
			'duplicate' => true,
		);
	}

	/**
	 * The `data` payload of a `422 b2bpl_invalid_payload` error:
	 * the contract's example includes `data.batch_id` (the row this
	 * rejection was still logged against, even though nothing was queued)
	 * alongside the existing `status`/`errors`. Pure and unit-testable.
	 *
	 * @param array<int,array{path:string,code:string}> $errors   `PayloadSchema::validate()`'s result.
	 * @param string                                    $batch_id This request's own logged `batch_id`.
	 * @return array{status:int,batch_id:string,errors:array}
	 */
	public static function build_invalid_payload_error_data( array $errors, $batch_id ) {
		return array(
			'status'   => 422,
			'batch_id' => (string) $batch_id,
			'errors'   => $errors,
		);
	}

	/**
	 * `GET /webhook?batch_id=`: status and per-item results, for
	 * any authenticated caller -- there is exactly one credential per site,
	 * so no per-caller ownership check applies (see
	 * `check_batch_status_permission()`).
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_batch_status( WP_REST_Request $request ) {
		$batch_id = (string) $request->get_param( 'batch_id' );
		$row      = ( new RequestsRepository() )->find_by_batch_id( $batch_id );

		if ( null === $row ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.', 'b2b-price-lists' ),
				array( 'status' => 404 )
			);
		}

		$items = ( new ItemsRepository() )->get_by_request( (int) $row['id'] );
		$total = (int) $row['items_total'];

		return rest_ensure_response(
			array(
				'batch_id'    => $row['batch_id'],
				'endpoint'    => $row['endpoint'],
				'status'      => $row['status'],
				'received_at' => self::to_iso8601( $row['received_at'] ),
				'started_at'  => empty( $row['started_at'] ) ? null : self::to_iso8601( $row['started_at'] ),
				'finished_at' => empty( $row['finished_at'] ) ? null : self::to_iso8601( $row['finished_at'] ),
				'counts'      => array(
					'total'   => $total,
					'success' => (int) $row['items_success'],
					'partial' => (int) $row['items_partial'],
					'error'   => (int) $row['items_error'],
					'skipped' => (int) $row['items_skipped'],
					'pending' => max( 0, $total - (int) $row['items_success'] - (int) $row['items_partial'] - (int) $row['items_error'] - (int) $row['items_skipped'] ),
				),
				'items'       => array_map( array( __CLASS__, 'format_item' ), $items ),
			)
		);
	}

	/**
	 * Formats one `b2bpl_webhook_items` row for the `GET /webhook?batch_id=`
	 * response (contract example shape).
	 *
	 * @param array<string,mixed> $item Raw item row.
	 * @return array<string,mixed>
	 */
	private static function format_item( array $item ) {
		$formatted = array(
			'index'  => (int) $item['item_index'],
			'ref'    => $item['ref'],
			'action' => $item['action'],
			'status' => $item['status'],
		);

		if ( ! empty( $item['result'] ) ) {
			$formatted['result'] = json_decode( (string) $item['result'], true );
		}

		if ( ! empty( $item['errors'] ) ) {
			$formatted['errors'] = json_decode( (string) $item['errors'], true );
		}

		return $formatted;
	}

	/**
	 * Formats a `Y-m-d H:i:s(.v)` UTC value (as stored) into an ISO-8601
	 * string with a `Z` suffix, for the JSON response.
	 *
	 * @param string $mysql_datetime Stored datetime.
	 * @return string
	 */
	private static function to_iso8601( $mysql_datetime ) {
		$mysql_datetime = (string) $mysql_datetime;

		if ( '' === $mysql_datetime ) {
			return '';
		}

		return str_replace( ' ', 'T', $mysql_datetime ) . 'Z';
	}
}
