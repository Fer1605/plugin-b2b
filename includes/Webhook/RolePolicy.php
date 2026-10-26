<?php
/**
 * Role/capability policy for `user.*` webhook operations (
 *): whether a requested role may be assigned at all, and whether an
 * existing WordPress account is a "protected" one no webhook action may ever
 * touch.
 *
 * Every decision is expressed as a pure, static method taking already-fetched
 * facts (a role's own capabilities, a user's roles/effective capabilities) as
 * plain arrays -- never `get_role()`/`WP_User` itself -- so the exact policy
 * is directly unit-testable without WordPress, exactly like
 * `Webhook\Resolver\SkuResolver`'s `classify_matches()`/`validate_parent_type()`
 * split. The handful of methods that do touch WordPress
 * (`dangerous_capabilities()`, `role_error()`, `user_is_protected()`) are thin
 * orchestration on top of the pure ones, covered instead by manual staging
 * verification (see the delivery report), the same documented split already
 * used throughout W1-W3.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RolePolicy
 */
class RolePolicy {

	/**
	 * Capabilities no webhook `user.*` action may ever grant, directly or by
	 * way of a role that carries one of them ("nunca puede incluir
	 * roles con permisos administrativos"). Filterable
	 * (`b2bpl_webhook_dangerous_capabilities`) so a site with its own custom
	 * capabilities can extend the list, but never evaluated from a fixed
	 * snapshot taken when the allowlist was saved -- always at the moment a
	 * `user.*` operation actually runs (R7), against the role's *current*
	 * definition.
	 *
	 * @return string[]
	 */
	public static function default_dangerous_capabilities() {
		return array(
			'manage_options',
			'edit_users',
			'create_users',
			'promote_users',
			'delete_users',
			'install_plugins',
			'activate_plugins',
			'edit_plugins',
			'edit_theme_options',
			'switch_themes',
			'update_core',
			'unfiltered_html',
			'manage_woocommerce',
			'edit_shop_orders',
			Capabilities::MANAGE_PRICE_LISTS,
		);
	}

	/**
	 * Pure cleanup of a candidate capability list (the default list, or
	 * whatever `b2bpl_webhook_dangerous_capabilities` returned): keeps only
	 * non-empty, trimmed strings and drops duplicates, exactly like
	 * `Support\RoleInstaller::sanitize_roles()`.
	 *
	 * @param mixed $capabilities Candidate list.
	 * @return string[]
	 */
	public static function sanitize_capability_list( $capabilities ) {
		if ( ! is_array( $capabilities ) ) {
			return array();
		}

		$clean = array();

		foreach ( $capabilities as $capability ) {
			if ( ! is_string( $capability ) ) {
				continue;
			}

			$capability = trim( $capability );

			if ( '' === $capability ) {
				continue;
			}

			$clean[] = $capability;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether a role's own capabilities (`WP_Role::$capabilities`, or a
	 * user's effective capabilities, `WP_User::$allcaps`) include any
	 * dangerous one. Pure: both arguments are plain
	 * `capability => bool` maps, exactly the shape either class already
	 * exposes.
	 *
	 * @param array<string,bool> $capabilities         Role/user capability map.
	 * @param string[]           $dangerous_capabilities `dangerous_capabilities()`'s result.
	 * @return bool
	 */
	public static function has_dangerous_capability( array $capabilities, array $dangerous_capabilities ) {
		foreach ( $dangerous_capabilities as $capability ) {
			if ( ! empty( $capabilities[ $capability ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a role slug may be assigned by a webhook `user.*` operation:
	 * it must be in the site's own `allowed_roles` whitelist (`Webhook\Settings`,
	 * default `['customer']`) *and* its own capabilities must not include any
	 * dangerous one -- both conditions evaluated together, since a site could
	 * otherwise widen `allowed_roles` to a role that itself carries
	 * `manage_options` ("se evalúa en tiempo de ejecución").
	 *
	 * @param string             $role                   Candidate role slug.
	 * @param string[]           $allowed_roles          `Webhook\Settings::get()['allowed_roles']`.
	 * @param array<string,bool> $role_capabilities      The role's own
	 *                                                    `WP_Role::$capabilities`
	 *                                                    (empty array when the
	 *                                                    role does not exist).
	 * @param string[]           $dangerous_capabilities `dangerous_capabilities()`'s result.
	 * @return bool
	 */
	public static function is_role_assignable( $role, array $allowed_roles, array $role_capabilities, array $dangerous_capabilities ) {
		if ( ! in_array( $role, $allowed_roles, true ) ) {
			return false;
		}

		return ! self::has_dangerous_capability( $role_capabilities, $dangerous_capabilities );
	}

	/**
	 * Whether an existing WordPress account is "protected" from every
	 * webhook `user.*` action: it holds any role outside the
	 * whitelist (e.g. `administrator`, `shop_manager` when only `customer` is
	 * allowed) *or* any of its effective capabilities is a dangerous one --
	 * whichever comes first, since a custom role squarely inside the
	 * whitelist could still have been individually granted a dangerous
	 * capability directly.
	 *
	 * @param string[]           $user_roles             The account's own role slugs (`WP_User::$roles`).
	 * @param array<string,bool> $effective_capabilities The account's effective capabilities (`WP_User::$allcaps`).
	 * @param string[]           $allowed_roles          `Webhook\Settings::get()['allowed_roles']`.
	 * @param string[]           $dangerous_capabilities `dangerous_capabilities()`'s result.
	 * @return bool
	 */
	public static function is_protected_account( array $user_roles, array $effective_capabilities, array $allowed_roles, array $dangerous_capabilities ) {
		if ( self::has_dangerous_capability( $effective_capabilities, $dangerous_capabilities ) ) {
			return true;
		}

		foreach ( $user_roles as $role ) {
			if ( ! in_array( $role, $allowed_roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `dangerous_capabilities()` after applying the
	 * `b2bpl_webhook_dangerous_capabilities` filter -- the one WordPress-touching
	 * step in this class besides `role_error()`/`user_is_protected()` below.
	 *
	 * @return string[]
	 */
	public static function dangerous_capabilities() {
		$defaults = self::default_dangerous_capabilities();

		if ( ! function_exists( 'apply_filters' ) ) {
			return self::sanitize_capability_list( $defaults );
		}

		/**
		 * Filters the capabilities no webhook `user.*` action may ever
		 * grant, directly or by way of a role that carries one of them.
		 *
		 * Can only ever *widen* this list (B2 review of W4/W5): a site
		 * cannot use this filter to remove one of the defaults, which would
		 * silently reopen a privilege-escalation path exists to
		 * close. To relax a specific default, change the whitelist
		 * (`Webhook\Settings`'s `allowed_roles`) or the role's own
		 * capabilities instead.
		 *
		 * @param string[] $capabilities Defaults to `default_dangerous_capabilities()`.
		 */
		$filtered = apply_filters( 'b2bpl_webhook_dangerous_capabilities', $defaults );

		return self::merge_dangerous_capabilities( $defaults, $filtered );
	}

	/**
	 * Pure merge behind the filter above (B2, independent review of W4/W5):
	 * the base/default list is always present in the result, regardless of
	 * what a filter callback returned -- widening only, de-duplicated, any
	 * non-string entry from the filtered value silently dropped (never a
	 * fatal error from a misbehaving filter callback).
	 *
	 * @param string[] $defaults `default_dangerous_capabilities()`'s result.
	 * @param mixed    $filtered Whatever `b2bpl_webhook_dangerous_capabilities` returned.
	 * @return string[]
	 */
	public static function merge_dangerous_capabilities( array $defaults, $filtered ) {
		$filtered = is_array( $filtered ) ? self::sanitize_capability_list( $filtered ) : array();

		return array_values( array_unique( array_merge( self::sanitize_capability_list( $defaults ), $filtered ) ) );
	}

	/**
	 * The item error code for a requested role, or `null` when it may be
	 * assigned: `role_not_allowed` when the role is outside
	 * `allowed_roles`, does not exist on this site, or carries a dangerous
	 * capability.
	 *
	 * @param string   $role          Candidate role slug.
	 * @param string[] $allowed_roles `Webhook\Settings::get()['allowed_roles']`.
	 * @return string|null
	 */
	public static function role_error( $role, array $allowed_roles ) {
		$role_capabilities = array();

		if ( function_exists( 'get_role' ) ) {
			$wp_role = get_role( $role );

			if ( $wp_role ) {
				$role_capabilities = (array) $wp_role->capabilities;
			} elseif ( in_array( $role, $allowed_roles, true ) ) {
				// In the whitelist, but does not actually exist on this
				// site: never silently create it, and never treat "unknown"
				// as "safe".
				return 'role_not_allowed';
			}
		}

		return self::is_role_assignable( $role, $allowed_roles, $role_capabilities, self::dangerous_capabilities() )
			? null
			: 'role_not_allowed';
	}

	/**
	 * Whether an existing WordPress user is a protected account.
	 *
	 * @param \WP_User $user          Existing user.
	 * @param string[] $allowed_roles `Webhook\Settings::get()['allowed_roles']`.
	 * @return bool
	 */
	public static function user_is_protected( \WP_User $user, array $allowed_roles ) {
		return self::is_protected_account( (array) $user->roles, (array) $user->allcaps, $allowed_roles, self::dangerous_capabilities() );
	}
}
