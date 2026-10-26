<?php
/**
 * Persistence and strict whitelisting for the webhook's own configuration
 *: whether it is
 * enabled at all, the retention window, the new-account email switch and
 * the notification-email recipients. Stored as one `wp_options` row,
 * `autoload => false` (read only by the handful of webhook requests a site
 * actually receives, never on every page load).
 *
 * Two things this class used to make configurable are now fixed,
 * hard-coded values instead (site owner request, 2026-09): the per-send
 * limits (`fixed_limits()`) and the role whitelist for `user.upsert`
 * (always `['customer']`). Neither can be changed through the settings
 * screen or a saved option any more -- `get()` always returns the fixed
 * value for each, regardless of what an older saved option contains.
 * `store_debug_events` (whether granular `debug`-level events are stored)
 * remains a configurable checkbox, unchanged. The IP allowlist and
 * trusted-proxy-header settings that used to live here have been removed
 * entirely (see `Webhook\RequestGuard`, which now always resolves the
 * caller's IP from `REMOTE_ADDR`).
 *
 * Follows the same shape as `Support\TableSettings`: a pure `defaults()`/
 * `sanitize()` pair (never touches the database), plus `get()`/`save()` that
 * do. `sanitize()` never trusts raw input for an enumerated field --
 * `retention_months` is rejected outright when outside its small set of
 * valid values, exactly like `TableSettings::sanitize()`'s `position`.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Support\RoleInstaller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'b2bpl_webhook_settings';

	/**
	 * Option remembering the tax-id meta key detected on this site, so the
	 * detection query runs once and not on every customer written.
	 *
	 * @var string
	 */
	const NIT_KEY_OPTION = 'b2bpl_nit_meta_key';

	/**
	 * Transient set when the detection found no tax id anywhere on the
	 * site, so the look-up is retried later instead of either running on
	 * every customer written or being settled for good.
	 *
	 * @var string
	 */
	const NIT_KEY_RETRY_TRANSIENT = 'b2bpl_nit_meta_key_retry';

	/**
	 * How long that retry is held off, in seconds (12 hours).
	 *
	 * @var int
	 */
	const NIT_KEY_RETRY_SECONDS = 43200;

	/**
	 * Meta key used while the site has no tax id stored anywhere yet: the
	 * WooCommerce-shaped name a shop adding the field by hand would most
	 * likely pick.
	 *
	 * @var string
	 */
	const DEFAULT_NIT_META_KEY = 'billing_nit';

	/**
	 * Maximum number of notification-email recipients kept
	 * (`sanitize_notification_emails()`).
	 *
	 * @var int
	 */
	const MAX_NOTIFICATION_EMAILS = 10;

	/**
	 * The only retention windows offered: the same 3 choices as
	 * the activity log since 1.3.0.
	 *
	 * @return int[]
	 */
	public static function valid_retention_months() {
		return array( 6, 12, 24 );
	}

	/**
	 * The fixed per-send limit values, and -- for `max_body_bytes`
	 * only -- the separate, higher hard ceiling `storage_max_body_bytes()`
	 * still uses to decide how much of an oversized request body is
	 * nonetheless stored for diagnosis. No longer configurable: kept as
	 * `[value, storage ceiling]` pairs purely as this class' own record of
	 * both numbers, never exposed as a settings form field or accepted from
	 * a saved option any more.
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	private static function limit_bounds() {
		return array(
			'list_operations'     => array( 20, 50 ),
			'product_rules'       => array( 500, 2000 ),
			'users'               => array( 50, 200 ),
			// Catalogue batches: products are heavier than price
			// rules -- each one can write terms, meta and images -- so the
			// per-send count is deliberately small, and the variations of
			// the whole batch are capped on their own.
			'catalog_operations'  => array( 20, 50 ),
			'catalog_variations'  => array( 200, 500 ),
			// Written as literal byte counts rather than `MB_IN_BYTES`
			// (WordPress core), since this stays a pure function meant to be
			// unit-testable without WordPress loaded.
			'max_body_bytes'      => array( 2 * 1024 * 1024, 5 * 1024 * 1024 ),
			'requests_per_minute' => array( 30, 120 ),
		);
	}

	/**
	 * The fixed, non-configurable per-send limits actually enforced
	 *: no site can change any of these through the settings screen
	 * or a saved option -- `get()['limits']` always returns exactly this,
	 * regardless of what a previously saved option contains. Exposed so an
	 * administrator-facing "usage guide" screen can print the current
	 * values without duplicating them.
	 *
	 * @return array<string,int>
	 */
	public static function fixed_limits() {
		$limits = array();

		foreach ( self::limit_bounds() as $key => $bounds ) {
			$limits[ $key ] = $bounds[0];
		}

		return $limits;
	}

	/**
	 * Default settings, used when no option has been saved yet and as the
	 * base merged with any saved value.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// the webhook does not exist until an
			// administrator explicitly turns it on.
			'enabled'              => false,
			// fixed, no longer configurable -- without a role, a new
			// user is `customer`; this is also the only role a webhook
			// `user.upsert` may ever assign.
			// + `customer` for a retail account and the B2B
			// account-type role for a wholesale one. Still the only two a
			// webhook `user.upsert` may ever assign, and neither carries a
			// capability beyond reading.
			'allowed_roles'        => self::roles_for( RoleInstaller::b2b_role() ),
			'limits'               => self::fixed_limits(),
			'retention_months'     => 12,
			// off by default, can be enabled globally here or per
			// user with `send_welcome_email: true`.
			'send_new_user_email'  => false,
			// granular `debug`-level events, on by default; a site
			// can turn them off to reduce row volume once it trusts the
			// integration, keeping only `info`/`warning`/`error`.
			'store_debug_events'   => true,
			// Notification emails (site owner request, 2026-09): empty by
			// default -- no email is ever sent while this is empty, see
			// `Webhook\Notifier`.
			'notification_emails'  => array(),
			// Attachment ID of the logo shown in notification emails; 0 falls
			// back to the WooCommerce email header image.
			'notification_logo_id' => 0,
			// Catalogue. Off until a site decides an outside
			// system may create its products: everything else the webhook
			// does only touches this plugin's own data, this writes the
			// shop's catalogue.
			'catalog_enabled'      => false,
			// Status a product is created with. `publish` by default
			// because a draft is invisible to the B2B rules, which only
			// resolve published products; a shop that wants to review
			// before publishing sets `draft`.
			'catalog_status'       => 'publish',
			// Whether a category or tag that does not exist yet is created.
			'catalog_create_terms' => true,
		);
	}

	/**
	 * Pure sanitization of a candidate `notification_emails` submission:
	 * keeps only well-formed, trimmed, de-duplicated addresses, capped at
	 * `MAX_NOTIFICATION_EMAILS`. An invalid or missing candidate is simply
	 * dropped, never replaced by a fallback -- an empty list is a
	 * meaningful, valid value (no notification email is ever sent).
	 *
	 * @param mixed $input Candidate list of email address strings.
	 * @return string[]
	 */
	public static function sanitize_notification_emails( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		foreach ( $input as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$item = trim( $item );

			if ( '' === $item || ! is_email( $item ) ) {
				continue;
			}

			$clean[] = $item;
		}

		$clean = array_values( array_unique( $clean ) );

		return array_slice( $clean, 0, self::MAX_NOTIFICATION_EMAILS );
	}

	/**
	 * Sanitizes and strictly whitelists a settings submission, falling back
	 * to the default for any missing/invalid field.
	 *
	 * @param array<string,mixed> $input Raw or partially-sanitized input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();

		$retention_months = isset( $input['retention_months'] ) ? (int) $input['retention_months'] : $defaults['retention_months'];

		if ( ! in_array( $retention_months, self::valid_retention_months(), true ) ) {
			$retention_months = $defaults['retention_months'];
		}

		return array(
			'enabled'              => ! empty( $input['enabled'] ),
			// Fixed (no longer accepted from `$input`): see the class
			// docblock.
			'allowed_roles'        => self::roles_for( RoleInstaller::b2b_role() ),
			'limits'               => self::fixed_limits(),
			'retention_months'     => $retention_months,
			'send_new_user_email'  => ! empty( $input['send_new_user_email'] ),
			'store_debug_events'   => ! empty( $input['store_debug_events'] ),
			'notification_emails'  => self::sanitize_notification_emails(
				isset( $input['notification_emails'] ) ? $input['notification_emails'] : null
			),
			'notification_logo_id' => isset( $input['notification_logo_id'] ) ? max( 0, (int) $input['notification_logo_id'] ) : 0,
			'catalog_enabled'      => ! empty( $input['catalog_enabled'] ),
			'catalog_status'       => ( isset( $input['catalog_status'] ) && 'draft' === $input['catalog_status'] ) ? 'draft' : 'publish',
			'catalog_create_terms' => ! isset( $input['catalog_create_terms'] ) || ! empty( $input['catalog_create_terms'] ),
		);
	}

	/**
	 * Returns the current, fully-sanitized settings (saved values merged
	 * over the defaults, so a future new field always has a safe default
	 * even for a site that saved settings before it existed).
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = array_replace_recursive( $defaults, $saved );

		// `limits`/`allowed_roles` are fixed (see the class docblock): an
		// older saved option may still carry a different value for either,
		// which must never surface here, even before `sanitize()` runs
		// below.
		$merged['limits']        = $defaults['limits'];
		$merged['allowed_roles'] = $defaults['allowed_roles'];

		return self::sanitize( $merged );
	}

	/**
	 * Persists a sanitized settings submission.
	 *
	 * @param array<string,mixed> $input Raw input (sanitized internally).
	 * @return array<string,mixed> The settings actually persisted.
	 */
	public static function save( array $input ) {
		$sanitized = self::sanitize( $input );

		update_option( self::OPTION_NAME, $sanitized, false );

		return $sanitized;
	}

	/**
	 * Whether the webhook is currently enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return ! empty( self::get()['enabled'] );
	}

	/**
	 * The value of a single limit.
	 *
	 * @param string $key One of `fixed_limits()`'s keys.
	 * @return int
	 */
	public static function limit( $key ) {
		$limits = self::fixed_limits();

		return isset( $limits[ $key ] ) ? (int) $limits[ $key ] : 0;
	}

	/**
	 * The absolute ceiling for how much of a request body is ever persisted
	 *: always the hard maximum of `max_body_bytes` (5 MB),
	 * independent of the fixed, *accepted* limit (2 MB). A request larger
	 * than the accepted limit is still rejected (`413`) by the controller,
	 * but as much of it as fits under this ceiling is still stored,
	 * truncated and hashed, so the rejection itself can be diagnosed from
	 * the log.
	 *
	 * @return int Bytes.
	 */
	public static function storage_max_body_bytes() {
		$bounds = self::limit_bounds();

		return $bounds['max_body_bytes'][1];
	}

	/**
	 * The two roles a webhook `user.upsert` may assign: the retail one and
	 * whichever role this site uses for wholesale accounts. Pure, so the
	 * pairing is testable without WordPress.
	 *
	 * @param string $b2b_role Configured B2B role, or an empty string.
	 * @return string[]
	 */
	public static function roles_for( $b2b_role ) {
		return array_values( array_unique( array( 'customer', RoleInstaller::sanitize_b2b_role( $b2b_role ) ) ) );
	}

	/**
	 * The role a B2B customer is given: the one this plugin registers
	 * itself, so a site works with no configuration at all.
	 *
	 * @return string
	 */
	public static function b2b_role() {
		return RoleInstaller::b2b_role();
	}

	/**
	 * The role a retail customer is given.
	 *
	 * @return string
	 */
	public static function b2c_role() {
		return 'customer';
	}

	/**
	 * Meta keys a WooCommerce shop commonly stores a customer's tax id
	 * (NIT, RUC, CIF, VAT) in, in the order they are tried. The first is
	 * the one the Checkout Field Editor plugin writes, which is how a shop
	 * that added the field through the checkout editor stores it.
	 *
	 * @return string[]
	 */
	public static function known_nit_meta_keys() {
		return array( '_wc_billing/thwcfe-block/nit', 'billing_nit', '_billing_nit', 'billing_vat', '_billing_vat' );
	}

	/**
	 * The meta key a customer's tax id is written to.
	 *
	 * Never a key of this plugin's own invention: the point is that the
	 * number lands in the very field the shop's checkout already shows, so
	 * it appears on the order and on the customer's address like any other.
	 * Worked out from the site itself -- no shop should have to
	 * type a meta key into a settings screen -- and only a developer can
	 * override it, with the `b2bpl_nit_meta_key` filter.
	 *
	 * @return string
	 */
	public static function nit_meta_key() {
		/**
		 * Filters the meta key a customer's tax id (NIT) is written to.
		 *
		 * An empty string, the default, means "work it out from this site".
		 *
		 * @param string $key Meta key, or '' to detect it.
		 */
		$configured = self::sanitize_nit_meta_key( apply_filters( 'b2bpl_nit_meta_key', '' ) );

		if ( '' !== $configured ) {
			return $configured;
		}

		$detected = get_option( self::NIT_KEY_OPTION, '' );

		if ( is_string( $detected ) && '' !== $detected ) {
			return $detected;
		}

		// Nothing anywhere on the site stores a tax id yet, which on a shop
		// that simply has not taken a B2B order says nothing about which
		// field it will end up using. So the answer is deliberately *not*
		// written down: it is looked for again later, and the first
		// customer who checks out with a NIT reveals the real field. The
		// transient only keeps that look-up off every single request in
		// the meantime.
		if ( get_transient( self::NIT_KEY_RETRY_TRANSIENT ) ) {
			return self::DEFAULT_NIT_META_KEY;
		}

		$detected = self::detect_nit_meta_key();

		if ( '' === $detected ) {
			set_transient( self::NIT_KEY_RETRY_TRANSIENT, 1, self::NIT_KEY_RETRY_SECONDS );

			return self::DEFAULT_NIT_META_KEY;
		}

		update_option( self::NIT_KEY_OPTION, $detected, false );
		delete_transient( self::NIT_KEY_RETRY_TRANSIENT );

		return $detected;
	}

	/**
	 * Keeps only the characters a WordPress meta key may contain, slashes
	 * included, since that is the shape a checkout-field plugin writes
	 * (`_wc_billing/thwcfe-block/nit`). Pure.
	 *
	 * @param mixed $key Candidate meta key.
	 * @return string
	 */
	public static function sanitize_nit_meta_key( $key ) {
		return (string) preg_replace( '#[^A-Za-z0-9_/\\-]#', '', trim( (string) $key ) );
	}

	/**
	 * Looks for a tax-id field this site already uses, among the keys known
	 * above. Returns an empty string when the site has none at all, which
	 * the caller treats as "ask again later", never as a final answer.
	 *
	 * @return string
	 */
	private static function detect_nit_meta_key() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return '';
		}

		$known = self::known_nit_meta_keys();

		// The customer's own profile first: that is where this plugin
		// writes and where WooCommerce reads a billing address back from.
		$found = self::first_known_meta_key( $wpdb->usermeta, $known );

		if ( '' !== $found ) {
			return $found;
		}

		// Then the orders, because a checkout field reaches an order before
		// it ever reaches a profile: a shop can perfectly well have taken
		// twenty orders carrying a NIT without a single customer having the
		// field filled in on their account.
		foreach ( self::order_meta_tables() as $table ) {
			$found = self::first_known_meta_key( $table, $known );

			if ( '' !== $found ) {
				return $found;
			}
		}

		return '';
	}

	/**
	 * The tables an order's custom fields may live in: the HPOS meta table
	 * when this site has one, and the post meta table, which is both the
	 * legacy storage and where an HPOS site in compatibility mode still
	 * keeps a copy.
	 *
	 * @return string[]
	 */
	private static function order_meta_tables() {
		global $wpdb;

		$tables = array();
		$hpos   = $wpdb->prefix . 'wc_orders_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, run only while no tax-id field has been found yet.
		if ( $hpos === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) ) {
			$tables[] = $hpos;
		}

		$tables[] = $wpdb->postmeta;

		return $tables;
	}

	/**
	 * The first of `$known` that appears as a `meta_key` in `$table`.
	 *
	 * @param string   $table Meta table name, always a `$wpdb` property.
	 * @param string[] $known Meta keys to look for, a fixed in-code list.
	 * @return string
	 */
	private static function first_known_meta_key( $table, array $known ) {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $known ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name comes from `$wpdb`, placeholders from a fixed in-code list; the result is cached in an option by the caller.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT meta_key FROM {$table} WHERE meta_key IN ( {$placeholders} ) LIMIT 1", $known ) );

		return is_string( $found ) && '' !== $found ? $found : '';
	}

	/**
	 * Brand taxonomies this plugin knows how to write to, in the order it
	 * tries them. WooCommerce ships `product_brand` in recent versions; the
	 * rest are the ones the common brand plugins register. Pure, so the
	 * order is testable without WordPress.
	 *
	 * @return string[]
	 */
	public static function known_brand_taxonomies() {
		return array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'product_brands', 'berocket_brand' );
	}

	/**
	 * The taxonomy a catalogue product's `brand` is written to: the first
	 * known brand taxonomy actually registered on this site (
	 * worked out, not configured), or whatever the `b2bpl_brand_taxonomy`
	 * filter names, as long as that one really exists.
	 *
	 * Returns an empty string when the shop has no brand taxonomy at all --
	 * the brand is then skipped and reported in the batch log, never
	 * invented, since registering a taxonomy behind the shop's back would
	 * leave it with data no plugin of its own can show.
	 *
	 * @return string
	 */
	public static function catalog_brand_taxonomy() {
		/**
		 * Filters the taxonomy a product's brand is written to.
		 *
		 * An empty string, the default, means "work it out from this site".
		 *
		 * @param string $taxonomy Taxonomy name, or '' to detect it.
		 */
		$configured = (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( trim( (string) apply_filters( 'b2bpl_brand_taxonomy', '' ) ) ) );

		if ( '' !== $configured ) {
			return taxonomy_exists( $configured ) ? $configured : '';
		}

		foreach ( self::known_brand_taxonomies() as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		return '';
	}
}
