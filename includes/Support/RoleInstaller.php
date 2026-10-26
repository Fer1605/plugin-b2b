<?php
/**
 * Grants/retires the plugin's own `manage_b2b_price_lists` capability
 * on standard WordPress roles, versioned independently of the
 * `b2bpl_*` table schema (`B2BPL_CAPS_VERSION`) so a
 * future change to the set of roles or capabilities granted by default can
 * be rolled out to existing installs the same way `Database\Installer`
 * rolls out schema changes.
 *
 * Briefly, earlier in 1.5.0's own development, this class also
 * created a dedicated `b2bpl_integration` role used exclusively to
 * authenticate webhook requests. replaced that mechanism with the
 * webhook's own username/password (`Webhook\Credentials`), so this class no
 * longer ever creates that role -- `install()` now only ever *removes* it
 * (idempotently, via `uninstall_integration_role()`), covering a site that
 * still has it from an earlier 1.5.0 build. `B2BPL_CAPS_VERSION` was bumped
 * to `3` for this migration.
 *
 * Idempotent by construction: `WP_Role::add_cap()` with the same value is a
 * harmless no-op, so running `install()` again (activation, an update, or a
 * stale `b2bpl_caps_version`) never duplicates anything.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RoleInstaller
 */
class RoleInstaller {

	/**
	 * Option storing the currently installed capability set version.
	 *
	 * @var string
	 */
	const OPTION_CAPS_VERSION = 'b2bpl_caps_version';

	/**
	 * Slug of the dedicated, minimum-privilege role earlier in 1.5.0's own
	 * development used exclusively to authenticate webhook requests
	 *. replaced that mechanism; this constant is
	 * kept only so `uninstall_integration_role()` can still remove the role
	 * from a site that has it from an earlier 1.5.0 build.
	 *
	 * @var string
	 */
	const INTEGRATION_ROLE = 'b2bpl_integration';

	/**
	 * Slug of the account-type role for wholesale customers.
	 *
	 * It exists to *identify* a B2B account -- for filtering customers,
	 * for a theme that wants to greet them differently, for a shop that
	 * reports on them -- and it is created with exactly the capabilities of
	 * `customer`, nothing more. The project's closed principle still holds:
	 * roles never decide B2B prices, the assigned list does.
	 *
	 * @var string
	 */
	const B2B_CUSTOMER_ROLE = 'b2bpl_b2b_customer';

	/**
	 * Sanitizes a candidate B2B role slug, falling back to this plugin's
	 * own role for anything empty or unusable. Pure, so the fallback is
	 * testable without WordPress.
	 *
	 * @param mixed $role Candidate role slug.
	 * @return string
	 */
	public static function sanitize_b2b_role( $role ) {
		$role = preg_replace( '/[^a-z0-9_-]/', '', strtolower( trim( (string) $role ) ) );

		return '' === $role ? self::B2B_CUSTOMER_ROLE : $role;
	}

	/**
	 * The role a wholesale account is given on this site.
	 *
	 * Deliberately not a setting: a shop never has to name it,
	 * because the plugin registers the role itself
	 * (`install_b2b_customer_role()`), and a field whose only correct value
	 * is the one already filled in is just noise in the admin screen. The
	 * rare site that already has a wholesale role of its own points at it
	 * with the `b2bpl_b2b_role` filter.
	 *
	 * @return string
	 */
	public static function b2b_role() {
		/**
		 * Filters the role given to a wholesale (B2B) account.
		 *
		 * Only ever an account-type label: the B2B price always comes from
		 * the price list assigned to the customer, never from their role.
		 *
		 * @param string $role Role slug, `b2bpl_b2b_customer` by default.
		 */
		return self::sanitize_b2b_role( apply_filters( 'b2bpl_b2b_role', self::B2B_CUSTOMER_ROLE ) );
	}

	/**
	 * Marks a plain retail account as a wholesale one, used when a price
	 * list is assigned to it from the admin panel: a list is what
	 * makes an account wholesale, so the label should follow it without
	 * anybody having to remember to change the role by hand.
	 *
	 * Only ever touches an account whose single role is `customer`. An
	 * administrator, a shop manager, an account with any other role, and
	 * one that already carries the B2B role are left exactly as they are --
	 * this must never be a way to change somebody's permissions. Nothing is
	 * lost when the promotion is declined: the price comes from the list,
	 * never from the role.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether the role was actually changed.
	 */
	public static function promote_to_b2b( $user_id ) {
		if ( ! function_exists( 'get_userdata' ) || ! function_exists( 'get_role' ) ) {
			return false;
		}

		$user = get_userdata( (int) $user_id );

		if ( ! $user || array( 'customer' ) !== array_values( (array) $user->roles ) ) {
			return false;
		}

		$role = self::b2b_role();

		if ( 'customer' === $role || ! get_role( $role ) ) {
			return false;
		}

		$user->set_role( $role );

		return true;
	}

	/**
	 * The roles granted the capability when no site has customized the
	 * `b2bpl_default_capability_roles` filter.
	 *
	 * @return string[]
	 */
	public static function default_roles() {
		return array( 'administrator', 'shop_manager' );
	}

	/**
	 * Pure sanitization of a candidate role list: keeps only non-empty
	 * strings, trims them, drops duplicates, and falls back to `$default`
	 * whenever the input is not an array or reduces to nothing usable.
	 *
	 * Deliberately free of any WordPress function call (no `apply_filters()`,
	 * no `get_role()`) so it can be unit-tested directly against every shape
	 * a misbehaving `b2bpl_default_capability_roles` filter might return.
	 *
	 * @param mixed    $roles    Candidate role slugs, typically a filtered value.
	 * @param string[] $fallback Fallback list, already assumed valid.
	 * @return string[]
	 */
	public static function sanitize_roles( $roles, array $fallback = array( 'administrator', 'shop_manager' ) ) {
		if ( ! is_array( $roles ) ) {
			return $fallback;
		}

		$clean = array();

		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) ) {
				continue;
			}

			$role = trim( $role );

			if ( '' === $role ) {
				continue;
			}

			$clean[] = $role;
		}

		$clean = array_values( array_unique( $clean ) );

		return empty( $clean ) ? $fallback : $clean;
	}

	/**
	 * The role slugs that should be granted `Capabilities::capability()`,
	 * after applying the `b2bpl_default_capability_roles` filter (
	 * point 6) and sanitizing whatever it returns.
	 *
	 * @return string[]
	 */
	public static function roles_to_grant() {
		$default = self::default_roles();
		$roles   = $default;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the WordPress roles granted `manage_b2b_price_lists`
			 * on activation/update. Defaults to `administrator`
			 * and `shop_manager`.
			 *
			 * @param string[] $roles Role slugs.
			 */
			$roles = apply_filters( 'b2bpl_default_capability_roles', $default );
		}

		return self::sanitize_roles( $roles, $default );
	}

	/**
	 * Grants the capability to every role in `roles_to_grant()` that
	 * actually exists on this site. A role missing from the site (a custom
	 * role plugin that removed `shop_manager`, for instance) is silently
	 * skipped rather than fatally erroring or recreating the role.
	 *
	 * Also removes the `b2bpl_integration` role if still present:
	 * idempotent, and harmless to run on every activation/update even for a
	 * site that never had it.
	 *
	 * @return void
	 */
	public static function install() {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		$capability = Capabilities::capability();

		foreach ( self::roles_to_grant() as $role_slug ) {
			$role = get_role( $role_slug );

			if ( ! $role ) {
				continue;
			}

			$role->add_cap( $capability, true );
		}

		self::install_b2b_customer_role();
		self::uninstall_integration_role();
	}

	/**
	 * Registers the B2B customer role, cloned from `customer` so a wholesale
	 * account can do exactly what a retail one can and not a thing more.
	 * Idempotent: a site that already has the role is left alone, including
	 * any capability an administrator added to it by hand.
	 *
	 * @return void
	 */
	public static function install_b2b_customer_role() {
		if ( ! function_exists( 'get_role' ) || ! function_exists( 'add_role' ) ) {
			return;
		}

		if ( get_role( self::B2B_CUSTOMER_ROLE ) ) {
			return;
		}

		$customer     = get_role( 'customer' );
		$capabilities = $customer ? $customer->capabilities : array( 'read' => true );

		add_role( self::B2B_CUSTOMER_ROLE, __( 'B2B customer', 'b2b-price-lists' ), $capabilities );
	}

	/**
	 * Compares the stored capability-set version against
	 * `B2BPL_CAPS_VERSION` and (re)installs the capability when they
	 * differ. Safe to call on every compatible `plugins_loaded` and on
	 * plugin activation, exactly like `Database\Installer::maybe_upgrade()`.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed_version = (int) get_option( self::OPTION_CAPS_VERSION, 0 );
		$target_version    = defined( 'B2BPL_CAPS_VERSION' ) ? (int) B2BPL_CAPS_VERSION : 0;

		if ( $installed_version === $target_version ) {
			return;
		}

		self::install();

		update_option( self::OPTION_CAPS_VERSION, $target_version, false );
	}

	/**
	 * Retires `Capabilities::capability()` from every role currently
	 * registered on the site -- not only the roles in `roles_to_grant()` --
	 * since a site may have granted it to an additional role via the
	 * `b2bpl_default_capability_roles` filter or `WP_Role::add_cap()`
	 * directly at some point in the past (uninstalling
	 * always retires the permission, independent of the data-purge
	 * opt-in). Removing a capability never deletes any of this plugin's
	 * business data.
	 *
	 * @return void
	 */
	public static function uninstall() {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}

		$wp_roles = wp_roles();

		if ( ! $wp_roles || empty( $wp_roles->roles ) ) {
			return;
		}

		$capability = Capabilities::capability();

		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );

			if ( $role ) {
				$role->remove_cap( $capability );
			}
		}

		delete_option( self::OPTION_CAPS_VERSION );
	}

	/**
	 * Removes the dedicated `b2bpl_integration` role entirely (
	 *). Users who held only that role are left with no role at all
	 * -- the same outcome as removing any other role a user belongs to --
	 * and never gain any other permission as a result. Called both from
	 * `install()` above (a migration for a site upgrading from an earlier
	 * 1.5.0 build that still has the role) and unconditionally from
	 * `uninstall.php`, before the data-purge opt-in switch: like retiring
	 * `manage_b2b_price_lists` in `uninstall()` above, deleting a role this
	 * plugin created is not a business-data-destructive action.
	 *
	 * @return void
	 */
	public static function uninstall_integration_role() {
		if ( function_exists( 'remove_role' ) ) {
			remove_role( self::INTEGRATION_ROLE );
		}
	}
}
