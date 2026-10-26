<?php
/**
 * Per-request marker set by `Webhook\RequestGuard` once it has validated the
 * webhook's own username/password, read back by
 * `Webhook\RestController`'s `permission_callback`s. Deliberately not a
 * `WP_User`/capability: the webhook does not authenticate as any WordPress
 * user (a cookie session or an Application Password never authorizes it,
 * per), so "is this request allowed to use the webhook" is this
 * plain boolean flag instead of `current_user_can()`.
 *
 * A single static flag, not a stack like `Webhook\Context`: at most one
 * webhook request is ever authenticated per PHP request, and nothing here
 * needs to nest.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AuthContext
 */
class AuthContext {

	/**
	 * Whether the current request has been authenticated by `RequestGuard`
	 * with the webhook's own credentials.
	 *
	 * @var bool
	 */
	private static $authenticated = false;

	/**
	 * The configured username the current request authenticated as, once
	 * `mark_authenticated()` has run.
	 *
	 * @var string
	 */
	private static $username = '';

	/**
	 * Marks the current request as authenticated. Called only by
	 * `RequestGuard::filter_authentication_errors()`, once the submitted
	 * credentials have been validated against `Webhook\Credentials`.
	 *
	 * @param string $username The configured username the request matched.
	 * @return void
	 */
	public static function mark_authenticated( $username ) {
		self::$authenticated = true;
		self::$username      = (string) $username;
	}

	/**
	 * Whether the current request is authenticated for the webhook.
	 *
	 * @return bool
	 */
	public static function is_authenticated() {
		return self::$authenticated;
	}

	/**
	 * The configured username the current request authenticated as, or an
	 * empty string when `is_authenticated()` is `false`.
	 *
	 * @return string
	 */
	public static function username() {
		return self::$username;
	}

	/**
	 * Clears the marker. Exposed for tests only, so one test's
	 * `mark_authenticated()` can never leak into the next test in the same
	 * process.
	 *
	 * @return void
	 */
	public static function reset_for_tests() {
		self::$authenticated = false;
		self::$username      = '';
	}
}
