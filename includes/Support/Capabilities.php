<?php
/**
 * Centralized capability gate for every admin/AJAX endpoint of the plugin.
 *
 * A single method is reused everywhere a write (or sensitive read) action is
 * performed, so there is exactly one place that decides who is allowed to
 * manage B2B price lists. This "nothing should call `current_user_can()`
 * directly outside of this class" rule is about this plugin's *own*
 * capability specifically (`capability()`/`MANAGE_PRICE_LISTS`, filterable
 * via `b2bpl_manage_capability`) -- it does not apply to checks against a
 * native WordPress/WooCommerce capability that has nothing to do with
 * managing this plugin, such as `edit_shop_orders` (used read-only by the
 * 1.4.0 order-integration shortcuts) or `edit_user`: those
 * are not this plugin's to centralize or make filterable, and calling
 * `current_user_can()` with them directly, where needed, is correct.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Capabilities
 */
class Capabilities {

	/**
	 * The default WordPress capability required to manage B2B price lists,
	 * assignments and rules.
	 *
	 * Until 1.3.0 this reused WooCommerce's own `manage_woocommerce`. Since
	 * 1.4.0 the plugin has its own dedicated capability instead, granted by
	 * `Support\RoleInstaller` to `administrator` and `shop_manager` on
	 * activation/update, so a site can adjust who manages this plugin
	 * without touching `manage_woocommerce` (which controls the rest of
	 * WooCommerce too) and without depending on a roles plugin. Always read
	 * this through `capability()`, never this constant directly, since a
	 * site can replace it entirely with the `b2bpl_manage_capability`
	 * filter.
	 *
	 * @var string
	 */
	const MANAGE_PRICE_LISTS = 'manage_b2b_price_lists';

	/**
	 * The capability required to take the activity log out of the site:
	 * exporting it and downloading its monthly archives.
	 *
	 * Deliberately stricter than managing lists. A shop manager may see the
	 * Activity screen, but a file with who changed what across the whole
	 * store can be forwarded anywhere once downloaded, so only site
	 * administrators may produce one.
	 *
	 * @var string
	 */
	const EXPORT_ACTIVITY = 'manage_options';

	/**
	 * The capability required to change the webhook's own configuration:
	 * enabling it, the role whitelist, the IP allowlist, the per-request
	 * limits and the retention window. Deliberately always
	 * `manage_options`, the same native WordPress capability used
	 * everywhere else in this plugin for a site-wide, security-sensitive
	 * setting (`EXPORT_ACTIVITY`) -- a Shop Manager with
	 * `manage_b2b_price_lists` must not be able to turn the webhook on by
	 * themselves (US5 acceptance scenario 4).
	 *
	 * @var string
	 */
	const CONFIGURE_INTEGRATION = 'manage_options';

	/**
	 * The capability required to export or download anything from the
	 * webhook's own log viewer (W5/T044): the received JSON (formatted or
	 * original), the response, item results, events, or a whole batch
	 * `.zip`. Deliberately the same, stricter-than-`MANAGE_PRICE_LISTS`
	 * pattern as `EXPORT_ACTIVITY` -- a Shop Manager may see and reprocess a
	 * batch from the viewer, but taking its raw content (which can include
	 * customer data received from the external system) out of the site is
	 * reserved for site administrators.
	 *
	 * @var string
	 */
	const EXPORT_INTEGRATION_LOG = 'manage_options';

	/**
	 * The capability required to manage B2B price lists, assignments and
	 * rules, after applying the `b2bpl_manage_capability` filter.
	 *
	 * The single source of truth for this capability: every other class in
	 * the plugin (admin menu, controllers, AJAX endpoints, order screen
	 * integrations) reads it through this method, never the constant
	 * directly, so a site can swap it for any capability -- an existing
	 * one, or a custom one it grants through a roles plugin -- with one
	 * filter callback.
	 *
	 * @return string
	 */
	public static function capability() {
		$capability = self::MANAGE_PRICE_LISTS;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the capability required to manage B2B price lists.
			 *
			 * @param string $capability Defaults to `manage_b2b_price_lists`.
			 */
			$capability = apply_filters( 'b2bpl_manage_capability', $capability );
		}

		return ( is_string( $capability ) && '' !== $capability ) ? $capability : self::MANAGE_PRICE_LISTS;
	}

	/**
	 * Whether the current user may manage B2B price lists.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::capability() );
	}

	/**
	 * Whether the current user may export the activity log and download
	 * its archives.
	 *
	 * @return bool
	 */
	public static function current_user_can_export_activity() {
		return self::current_user_can_manage() && current_user_can( self::EXPORT_ACTIVITY );
	}

	/**
	 * Whether the current user may export or download content from the
	 * webhook's own log viewer (W5).
	 *
	 * @return bool
	 */
	public static function current_user_can_export_integration_log() {
		return self::current_user_can_manage() && current_user_can( self::EXPORT_INTEGRATION_LOG );
	}

	/**
	 * Whether the current user may change the webhook's own configuration.
	 *
	 * @return bool
	 */
	public static function can_configure_integration() {
		return current_user_can( self::CONFIGURE_INTEGRATION );
	}
}
