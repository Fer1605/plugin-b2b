<?php
/**
 * Network-level gate for the webhook, enforced
 * through `rest_authentication_errors` -- the same filter WordPress core's
 * own cookie-nonce check uses, which runs *before* a route's
 * `permission_callback`, letting a disabled webhook, a non-HTTPS request, a
 * rate-limited caller, or one without the webhook's own valid username/
 * password (never a WordPress user, never an Application Password,
 * never a cookie session) be rejected before this plugin's
 * `permission_callback` even runs.
 *
 * The IP allowlist and trusted-proxy-header settings this class used to
 * enforce (`Webhook\Settings`' former `ip_allowlist`/`trusted_proxy_header`)
 * have been removed (site owner request, 2026-09): the caller's IP is
 * always `REMOTE_ADDR`, used only for the failed-attempt rate limiter
 * below, never to allow or forbid a request outright.
 *
 * Since this class also owns the webhook's own HTTP Basic
 * credential check: it reads the `Authorization` header (with a fallback to
 * `PHP_AUTH_USER`/`PHP_AUTH_PW` and to `REDIRECT_HTTP_AUTHORIZATION`, for
 * hosts that do not expose the header directly to PHP), validates it against
 * `Webhook\Credentials`, and -- when valid -- marks the request authenticated
 * on `Webhook\AuthContext` and returns `true`. Returning `true` (rather than
 * leaving `$errors` as `null`) is what keeps WordPress core's own
 * Application Password check (`rest_application_password_check_errors`,
 * priority 90) and cookie check (`rest_cookie_check_errors`, priority 100)
 * from ever inspecting the same `Authorization` header themselves: both
 * start with "return the existing result unchanged if it is not empty",
 * and a non-`null` `WP_Error` or `true` is never empty. In particular, a
 * caller's webhook username never matching a real `WP_User` (so core's own
 * Application Password authenticator would otherwise reject it as
 * `invalid_username`) never surfaces, because this filter's own decision --
 * valid or invalid -- always wins, decided first, at priority 20.
 *
 * Registered unconditionally from `Plugin::init()` (not only when the
 * webhook is enabled), because turning the webhook *off* is itself one of
 * the things this filter has to enforce (acceptance scenario 1: a disabled
 * webhook still needs to answer a request to its URL with the standard
 * `404`, and that request still has to be logged). Every check below runs
 * only for a `b2bpl/v1` request (`RouteMatcher::matches()`) and returns
 * `$errors` completely untouched otherwise -- Novamira's MCP endpoint,
 * WooCommerce's REST API and WordPress core's own routes are never touched
 * by this class.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RequestGuard
 */
class RequestGuard {

	/**
	 * Error code used for every rate-limit rejection (contract: 429
	 * `b2bpl_rate_limited`).
	 *
	 * @var string
	 */
	const RATE_LIMIT_ERROR_CODE = 'b2bpl_rate_limited';

	/**
	 * Sliding window, in seconds, both counters below use. Matches
	 * `Webhook\Settings`' `requests_per_minute` limit's own unit.
	 *
	 * @var int
	 */
	const RATE_WINDOW_SECONDS = 60;

	/**
	 * Transient key prefix for the per-authenticated-user request counter.
	 *
	 * @var string
	 */
	const USER_RATE_TRANSIENT_PREFIX = 'b2bpl_wh_rate_u_';

	/**
	 * Transient key prefix for the per-IP *failed-attempt* counter
	 * ("por IP solo para intentos fallidos"). Incremented by
	 * `Webhook\IngestLogger` once a response's final status is known (401/
	 * 403), never here -- this filter only ever *reads* it, since at the
	 * point it runs the outcome of the current request is not decided yet.
	 *
	 * @var string
	 */
	const IP_FAILURE_TRANSIENT_PREFIX = 'b2bpl_wh_fail_ip_';

	/**
	 * Registers the `rest_authentication_errors` callback. Called once from
	 * `Plugin::init()`.
	 *
	 * @return void
	 */
	public static function register() {
		// Priority 20, before WordPress core's own Application Password
		// check (90) and cookie check (100): this callback always returns a
		// definitive, non-`null` decision for a `b2bpl/v1` request once the
		// webhook is enabled (see the class docblock) -- `true` once the
		// webhook's own username/password (`Webhook\Credentials`) is valid,
		// or a `WP_Error` otherwise -- so those two later callbacks, which
		// both start with "return the existing result unchanged if it is not
		// empty", never run their own body at all for this route. This is
		// what makes core's Application Password mechanism (and a cookie
		// session) unable to authorize the webhook, per, regardless
		// of what the same `Authorization` header would otherwise have
		// resolved to on its own.
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'filter_authentication_errors' ), 20 );
		// Independent of the above: attaches the `Retry-After` header
		// onto the final response of *any* request this
		// plugin rate-limited, once WordPress has turned the `WP_Error` this
		// class returned above into a real `WP_REST_Response` (`rest_post_dispatch`
		// always receives that later shape, never the bare `WP_Error`).
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_retry_after_header' ), 10 );
	}

	/**
	 * Whether the current request targets `b2bpl/v1`, from the live
	 * superglobals and `rest_get_url_prefix()`.
	 *
	 * @return bool
	 */
	public static function current_request_targets_webhook() {
		$rest_route_param = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route detection (which filter callback should run at all), never used to authorize or mutate anything.
		$request_uri      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : null;
		$rest_prefix      = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : null;

		return RouteMatcher::matches( $request_uri, $rest_route_param, $rest_prefix );
	}

	/**
	 * The `rest_authentication_errors` callback.
	 *
	 * @param WP_Error|null|true $errors Whatever a prior filter callback
	 *                                    already decided.
	 * @return WP_Error|null|true
	 */
	public static function filter_authentication_errors( $errors ) {
		if ( is_wp_error( $errors ) ) {
			return $errors;
		}

		if ( ! self::current_request_targets_webhook() ) {
			return $errors;
		}

		if ( ! Settings::is_enabled() ) {
			// Indistinguishable from a genuinely unregistered route (R9):
			// the same code, message and status WordPress core's own
			// `rest_no_route` uses.
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.', 'b2b-price-lists' ),
				array( 'status' => 404 )
			);
		}

		if ( self::requires_https() && ! is_ssl() ) {
			return new WP_Error(
				'b2bpl_https_required',
				__( 'This endpoint requires an HTTPS connection.', 'b2b-price-lists' ),
				array( 'status' => 403 )
			);
		}

		$settings = Settings::get();
		$ip       = self::resolve_client_ip( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only ever used as an opaque rate-limit counter key, never echoed or matched against anything.

		$limit       = isset( $settings['limits']['requests_per_minute'] ) ? (int) $settings['limits']['requests_per_minute'] : 0;
		$credentials = self::parse_basic_credentials( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_basic_credentials() only ever splits/decodes; the result is never trusted until credentials_valid() checks it against Webhook\Credentials below.

		if ( null === $credentials ) {
			// Nothing resembling Basic credentials was sent at all (
			// no `Authorization` header, no `PHP_AUTH_USER`, no
			// `REDIRECT_HTTP_AUTHORIZATION`).
			if ( self::is_ip_rate_limited( $ip, $limit ) ) {
				return self::rate_limited_error();
			}

			return new WP_Error(
				'rest_not_logged_in',
				__( 'You are not currently logged in.', 'b2b-price-lists' ),
				array( 'status' => 401 )
			);
		}

		if ( false === $credentials || ! self::credentials_valid( $credentials['username'], $credentials['password'] ) ) {
			// Either malformed (not "Basic ...", invalid base64, no `:`) or
			// well-formed but wrong: both count as a failed
			// attempt for the per-IP brute-force counter, same as before.
			if ( self::is_ip_rate_limited( $ip, $limit ) ) {
				return self::rate_limited_error();
			}

			return new WP_Error(
				'b2bpl_invalid_credentials',
				__( 'The username or password is incorrect.', 'b2b-price-lists' ),
				array( 'status' => 401 )
			);
		}

		if ( self::is_user_rate_limited( $credentials['username'], $limit ) ) {
			return self::rate_limited_error();
		}

		self::increment_user_rate( $credentials['username'] );
		AuthContext::mark_authenticated( $credentials['username'] );

		// A definitive `true`, never `$errors`/`null`: this is what keeps
		// WordPress core's own Application Password/cookie checks from ever
		// running their own body for this route (see the class docblock).
		return true;
	}

	/**
	 * Parses HTTP Basic credentials from the request's own superglobals
	 *: the `Authorization` header first, falling back to
	 * `PHP_AUTH_USER`/`PHP_AUTH_PW` (already split by PHP/the web server on
	 * some SAPIs), then to `REDIRECT_HTTP_AUTHORIZATION` (CGI/FastCGI setups
	 * that only expose the header under that alternate key). Pure and
	 * unit-testable.
	 *
	 * @param array<string,mixed> $server `$_SERVER`-shaped array.
	 * @return array{username:string,password:string}|null|false `null` when
	 *               nothing resembling Basic credentials is present at all;
	 *               `false` when something is present but malformed; an
	 *               array once successfully parsed.
	 */
	public static function parse_basic_credentials( array $server ) {
		if ( isset( $server['HTTP_AUTHORIZATION'] ) && '' !== trim( (string) $server['HTTP_AUTHORIZATION'] ) ) {
			return self::decode_basic_header( (string) $server['HTTP_AUTHORIZATION'] );
		}

		if ( isset( $server['PHP_AUTH_USER'] ) ) {
			return array(
				'username' => (string) $server['PHP_AUTH_USER'],
				'password' => isset( $server['PHP_AUTH_PW'] ) ? (string) $server['PHP_AUTH_PW'] : '',
			);
		}

		if ( isset( $server['REDIRECT_HTTP_AUTHORIZATION'] ) && '' !== trim( (string) $server['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return self::decode_basic_header( (string) $server['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		return null;
	}

	/**
	 * Decodes a single `Authorization: Basic ...` (or
	 * `REDIRECT_HTTP_AUTHORIZATION`) header value into a username/password
	 * pair. A password itself containing `:` is preserved whole (only the
	 * *first* `:` in the decoded value ever separates username from
	 * password, per the Basic auth scheme). Pure and unit-testable.
	 *
	 * @param string $header Raw header value.
	 * @return array{username:string,password:string}|false `false` when the
	 *               value is not `Basic ...`, is not valid base64, or does
	 *               not contain a `:` once decoded.
	 */
	public static function decode_basic_header( $header ) {
		$header = trim( (string) $header );

		if ( 0 !== stripos( $header, 'basic ' ) ) {
			return false;
		}

		$encoded = trim( substr( $header, 6 ) );

		if ( '' === $encoded ) {
			return false;
		}

		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- standard HTTP Basic auth decoding (RFC 7617), not obfuscation; strict mode rejects anything that is not valid base64.

		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return false;
		}

		list( $username, $password ) = explode( ':', $decoded, 2 );

		return array(
			'username' => $username,
			'password' => $password,
		);
	}

	/**
	 * Validates a candidate username/password pair against the webhook's own
	 * configured credentials (`Webhook\Credentials`): the username
	 * with `hash_equals()`, the password with `wp_check_password()` against
	 * the stored hash. `false` whenever no credentials are configured at all
	 * (a site that never generated/set a webhook password can never
	 * authenticate, by construction).
	 *
	 * @param string $username Candidate username.
	 * @param string $password Candidate password.
	 * @return bool
	 */
	private static function credentials_valid( $username, $password ) {
		$stored = Credentials::get();

		if ( ! Credentials::is_configured( $stored ) ) {
			return false;
		}

		if ( ! Credentials::username_matches( $username, $stored['username'] ) ) {
			return false;
		}

		return Credentials::verify_password( $password, $stored['password_hash'] );
	}

	/**
	 * `rest_post_dispatch` callback: attaches the
	 * `Retry-After` HTTP header (in addition to the existing `data.retry_after`
	 * body field) onto a `429 b2bpl_rate_limited` response. Every other
	 * response, from this plugin's routes or anyone else's, passes through
	 * completely untouched.
	 *
	 * @param mixed $response Final dispatch response.
	 * @return mixed
	 */
	public static function add_retry_after_header( $response ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}

		$data        = $response->get_data();
		$retry_after = self::extract_retry_after_seconds( is_array( $data ) ? $data : array() );

		if ( null !== $retry_after ) {
			$response->header( 'Retry-After', (string) $retry_after );
		}

		return $response;
	}

	/**
	 * Pure extraction of the `Retry-After` value from a dispatch response's
	 * decoded body: only ever non-`null` for this plugin's own
	 * `b2bpl_rate_limited` error shape, reading `data.retry_after` when
	 * present and falling back to the fixed window length otherwise.
	 *
	 * @param array<string,mixed> $response_data Decoded response body.
	 * @return int|null
	 */
	public static function extract_retry_after_seconds( array $response_data ) {
		if ( ! isset( $response_data['code'] ) || self::RATE_LIMIT_ERROR_CODE !== $response_data['code'] ) {
			return null;
		}

		$retry_after = $response_data['data']['retry_after'] ?? null;

		return is_numeric( $retry_after ) ? (int) $retry_after : self::RATE_WINDOW_SECONDS;
	}

	/**
	 * The standard 429 error for both counters.
	 *
	 * @return WP_Error
	 */
	private static function rate_limited_error() {
		return new WP_Error(
			self::RATE_LIMIT_ERROR_CODE,
			__( 'Too many requests. Please wait before trying again.', 'b2b-price-lists' ),
			array(
				'status'      => 429,
				'retry_after' => self::RATE_WINDOW_SECONDS,
			)
		);
	}

	/**
	 * Whether HTTPS should be required for the current environment
	 * (required everywhere except `WP_ENVIRONMENT_TYPE=local`).
	 *
	 * @return bool
	 */
	public static function requires_https() {
		$environment_type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		return self::should_require_https( $environment_type );
	}

	/**
	 * Pure decision behind `requires_https()`, unit-testable directly.
	 *
	 * @param string $environment_type `wp_get_environment_type()`'s value.
	 * @return bool
	 */
	public static function should_require_https( $environment_type ) {
		return 'local' !== $environment_type;
	}

	/**
	 * Resolves the caller's IP address: always `REMOTE_ADDR` (site owner
	 * request, 2026-09: the former trusted-proxy-header setting has been
	 * removed -- a client-supplied header is never trusted for this).
	 *
	 * Pure and unit-testable: takes the `$_SERVER`-shaped array explicitly
	 * rather than reading the superglobal itself.
	 *
	 * @param array<string,mixed> $server `$_SERVER`-shaped array.
	 * @return string Empty string when nothing usable is found.
	 */
	public static function resolve_client_ip( array $server ) {
		return isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
	}

	/**
	 * Whether a raw counter value has reached a limit. `$limit <= 0` never
	 * limits anything (treated as "unbounded"), which only happens if
	 * `Webhook\Settings` is ever bypassed -- its own `sanitize_limits()`
	 * never persists a value below 1.
	 *
	 * Pure and unit-testable.
	 *
	 * @param int $count Current counter value (before this request).
	 * @param int $limit Configured limit.
	 * @return bool
	 */
	public static function is_count_over_limit( $count, $limit ) {
		return $limit > 0 && $count >= $limit;
	}

	/**
	 * Transient key for the webhook username's request counter (
	 * keyed by the configured username itself -- there is no numeric user
	 * id anymore -- hashed the same way `ip_failure_transient_key()` hashes
	 * an IP address, so an unusual username can never produce an invalid
	 * transient key).
	 *
	 * @param string $username Configured username the request authenticated as.
	 * @return string
	 */
	private static function user_rate_transient_key( $username ) {
		return self::USER_RATE_TRANSIENT_PREFIX . md5( (string) $username );
	}

	/**
	 * Transient key for an IP's failed-attempt counter.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	private static function ip_failure_transient_key( $ip ) {
		return self::IP_FAILURE_TRANSIENT_PREFIX . md5( (string) $ip );
	}

	/**
	 * Pure fixed-window state decision: given whatever a
	 * counter's transient currently holds (`false`/anything not shaped like
	 * `{count,window_start}` means "no window yet") and the current time,
	 * decides the state a new request/attempt should be recorded against --
	 * the *same* window (its `count` carried over) when less than
	 * `$window_seconds` has elapsed since `window_start`, or a brand new one
	 * (`count` reset to `0`, `window_start` reset to `$now`) once it has.
	 * This is what makes the window fixed rather than sliding: a window's
	 * expiry is decided purely from its own original `window_start`, never
	 * extended just because another request arrived inside it.
	 *
	 * @param mixed $existing       Current transient value (`false`, or an
	 *                               array previously returned by this method).
	 * @param int   $now            Current Unix timestamp.
	 * @param int   $window_seconds `RATE_WINDOW_SECONDS`.
	 * @return array{count:int,window_start:int}
	 */
	public static function rate_window_state( $existing, $now, $window_seconds ) {
		if ( ! is_array( $existing ) || ! isset( $existing['window_start'], $existing['count'] ) ) {
			return array(
				'count'        => 0,
				'window_start' => (int) $now,
			);
		}

		$elapsed = (int) $now - (int) $existing['window_start'];

		if ( $elapsed >= $window_seconds ) {
			return array(
				'count'        => 0,
				'window_start' => (int) $now,
			);
		}

		return array(
			'count'        => (int) $existing['count'],
			'window_start' => (int) $existing['window_start'],
		);
	}

	/**
	 * The transient TTL, in seconds, that keeps a fixed window's *total*
	 * lifetime capped at `$window_seconds` from its own `window_start` --
	 * never a full `$window_seconds` again on every request, which is
	 * exactly the sliding-window bug corrects. Always at least
	 * `1`, so a already-expired-but-not-yet-garbage-collected window still
	 * gets a valid (immediate) expiry rather than an invalid `0`/negative
	 * one.
	 *
	 * @param int $window_start   The window's own start (Unix timestamp).
	 * @param int $now            Current Unix timestamp.
	 * @param int $window_seconds `RATE_WINDOW_SECONDS`.
	 * @return int
	 */
	public static function remaining_window_seconds( $window_start, $now, $window_seconds ) {
		return max( 1, $window_seconds - ( (int) $now - (int) $window_start ) );
	}

	/**
	 * Whether the webhook's own configured username has already reached the
	 * request limit within its current fixed window (a single
	 * credential per site, so this is effectively the whole webhook's own
	 * rate limit).
	 *
	 * @param string $username Configured username the request authenticated as.
	 * @param int    $limit    `limits.requests_per_minute`.
	 * @return bool
	 */
	public static function is_user_rate_limited( $username, $limit ) {
		$state = self::rate_window_state( get_transient( self::user_rate_transient_key( $username ) ), time(), self::RATE_WINDOW_SECONDS );

		return self::is_count_over_limit( $state['count'], $limit );
	}

	/**
	 * Records one more authenticated request within the current fixed
	 * window: the counter's expiry is only ever the *remaining*
	 * time left in its own window, never reset to a full
	 * `RATE_WINDOW_SECONDS` on every call.
	 *
	 * @param string $username Configured username the request authenticated as.
	 * @return void
	 */
	public static function increment_user_rate( $username ) {
		$now   = time();
		$key   = self::user_rate_transient_key( $username );
		$state = self::rate_window_state( get_transient( $key ), $now, self::RATE_WINDOW_SECONDS );

		++$state['count'];

		set_transient( $key, $state, self::remaining_window_seconds( $state['window_start'], $now, self::RATE_WINDOW_SECONDS ) );
	}

	/**
	 * Whether an IP address has already reached the failed-attempt limit
	 * within its current fixed window (brute-force protection,
	 * only ever incremented by `Webhook\IngestLogger` on a 401/403 outcome).
	 *
	 * @param string $ip    IP address.
	 * @param int    $limit `limits.requests_per_minute`, reused as the
	 *                       failed-attempt ceiling (no separate setting is
	 *                       part of the approved data model for this).
	 * @return bool
	 */
	public static function is_ip_rate_limited( $ip, $limit ) {
		$state = self::rate_window_state( get_transient( self::ip_failure_transient_key( $ip ) ), time(), self::RATE_WINDOW_SECONDS );

		return self::is_count_over_limit( $state['count'], $limit );
	}

	/**
	 * Records one more failed attempt (401/403) from an IP address within
	 * its current fixed window. Called by `Webhook\IngestLogger`
	 * once a response's final status is known -- never by this class itself.
	 *
	 * @param string $ip IP address.
	 * @return void
	 */
	public static function register_failed_attempt( $ip ) {
		if ( ! is_string( $ip ) || '' === $ip ) {
			return;
		}

		$now   = time();
		$key   = self::ip_failure_transient_key( $ip );
		$state = self::rate_window_state( get_transient( $key ), $now, self::RATE_WINDOW_SECONDS );

		++$state['count'];

		set_transient( $key, $state, self::remaining_window_seconds( $state['window_start'], $now, self::RATE_WINDOW_SECONDS ) );
	}
}
