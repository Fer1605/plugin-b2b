<?php
/**
 * Two-level cache invalidation helper.
 *
 * `Domain\PriceResolver` is the read path: it stores/reads resolved rule
 * groups and user-list assignments through this class's cache group, always
 * keyed by `list_id`+`product_id` together or by `user_id`, never
 * by `product_id` alone. This class itself only provides the write-path
 * invalidation calls, wired into every place lists/assignments/rule groups
 * change, so the resolver never serves a stale result after an admin edit.
 * Safe to call even when no persistent object cache backend is configured.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cache
 */
class Cache {

	/**
	 * Object cache group used for every key this plugin writes.
	 *
	 * @var string
	 */
	const GROUP = 'b2bpl';

	/**
	 * Cache key holding the short-lived count behind the orders screen's
	 * "B2B (n)" view (`Order\OrdersListIntegration`).
	 *
	 * @var string
	 */
	const KEY_B2B_ORDERS_COUNT = 'b2bpl_b2b_orders_count';

	/**
	 * How long the "B2B (n)" count is cached for, in seconds. Short on
	 * purpose: it is a request-count query (`wc_get_orders()` with
	 * `paginate` and `limit=1`, never a full order load), so a short TTL
	 * costs little while still absorbing the screen being reloaded
	 * repeatedly, and `invalidate_b2b_orders_count()` clears it immediately
	 * whenever an order is (re)marked.
	 *
	 * @var int
	 */
	const B2B_ORDERS_COUNT_TTL = 60;

	/**
	 * Reads the cached "B2B (n)" orders count, if any.
	 *
	 * @return int|false The cached count, or `false` when not cached.
	 */
	public static function b2b_orders_count() {
		$cached = wp_cache_get( self::KEY_B2B_ORDERS_COUNT, self::GROUP );

		return is_numeric( $cached ) ? (int) $cached : false;
	}

	/**
	 * Stores the "B2B (n)" orders count for `B2B_ORDERS_COUNT_TTL` seconds.
	 *
	 * @param int $count Freshly computed count.
	 * @return void
	 */
	public static function set_b2b_orders_count( $count ) {
		wp_cache_set( self::KEY_B2B_ORDERS_COUNT, (int) $count, self::GROUP, self::B2B_ORDERS_COUNT_TTL );
	}

	/**
	 * Invalidates the cached "B2B (n)" orders count. Called whenever an
	 * order's B2B mark is (re)computed, so the view never lags a whole TTL
	 * behind a checkout that just happened.
	 *
	 * @return void
	 */
	public static function invalidate_b2b_orders_count() {
		wp_cache_delete( self::KEY_B2B_ORDERS_COUNT, self::GROUP );
	}

	/**
	 * Invalidates the cached "assigned list" lookup for a single user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function invalidate_user( $user_id ) {
		wp_cache_delete( 'b2bpl_user_list_' . (int) $user_id, self::GROUP );
	}

	/**
	 * The current cache generation of a list. Every cached rule-group set is
	 * keyed by it, so invalidating a list is a single `wp_cache_set()` that
	 * works on ANY object cache backend.
	 *
	 * This replaces relying on `wp_cache_flush_group()`, which several
	 * persistent drop-ins (some Redis/Memcached builds) do not implement:
	 * there it returns false and silently deletes nothing, while
	 * `PriceResolver` writes its entries with no TTL. A price edit would
	 * then never reach the storefront. That was survivable while prices
	 * changed rarely; an offer is by nature switched on and off often, so
	 * the generation counter below is what actually guarantees the change
	 * is seen.
	 *
	 * @param int $list_id List id.
	 * @return int
	 */
	public static function list_version( $list_id ) {
		$version = wp_cache_get( self::version_key( $list_id ), self::GROUP );

		if ( ! is_numeric( $version ) ) {
			$version = 1;
			wp_cache_set( self::version_key( $list_id ), $version, self::GROUP );
		}

		return (int) $version;
	}

	/**
	 * Invalidates every cached resolution tied to a given list, by bumping
	 * its cache generation (backend-independent) and additionally flushing
	 * the whole group where the backend supports it.
	 *
	 * @param int $list_id List id.
	 * @return void
	 */
	public static function invalidate_list( $list_id ) {
		wp_cache_set( self::version_key( $list_id ), self::list_version( $list_id ) + 1, self::GROUP );
		self::flush_group();
	}

	/**
	 * Cache key holding a list's generation counter.
	 *
	 * @param int $list_id List id.
	 * @return string
	 */
	private static function version_key( $list_id ) {
		return 'b2bpl_list_ver_' . (int) $list_id;
	}

	/**
	 * Invalidates every user assigned to a given list at once (used when a
	 * list is deleted or deactivated and its members must fall back to B2C
	 * immediately, TC-071).
	 *
	 * @param int[] $user_ids Affected user ids.
	 * @return void
	 */
	public static function invalidate_users( array $user_ids ) {
		foreach ( $user_ids as $user_id ) {
			self::invalidate_user( $user_id );
		}
	}

	/**
	 * Flushes the whole plugin cache group when the running object cache
	 * implementation supports group flushing; a silent no-op otherwise
	 * (correctness for Level 2 is optional per -- Level 1 request
	 * caching, introduced by the future resolver, does not depend on this).
	 *
	 * @return void
	 */
	public static function flush_group() {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}
}
