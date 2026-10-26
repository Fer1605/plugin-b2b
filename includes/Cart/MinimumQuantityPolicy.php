<?php
/**
 * Pure minimum-quantity decision logic, deliberately free of any
 * WordPress/WooCommerce function call so it can be covered by fast unit
 * tests -- the same split already established by `Domain\RuleGroupValidator`
 * (pure) vs. `Domain\RuleGroupService` (WordPress-aware orchestration), and
 * by `Domain\PriceResolutionEngine` vs. `Domain\PriceResolver`.
 * `Cart\CartPriceHandler` is the only caller: it resolves the price/rule via
 * `Domain\PriceResolver`, hands the result here to decide whether to
 * reject, and only then calls `wc_add_notice()`/returns `false` itself.
 *
 * @package B2BPriceLists\Cart
 */

namespace B2BPriceLists\Cart;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MinimumQuantityPolicy
 */
class MinimumQuantityPolicy {

	/**
	 * The minimum purchasable quantity implied by a
	 * `Domain\PriceResolver::resolve()` result, or `null` when no B2B rule
	 * applies (no minimum is ever enforced by this plugin in that case --
	 * standard WooCommerce behaviour).
	 *
	 * @param array $result `PriceResolver::resolve()` result.
	 * @return int|null
	 */
	public static function resolve_minimum( array $result ) {
		if ( empty( $result['applies'] ) || empty( $result['minimum_quantity'] ) ) {
			return null;
		}

		return (int) $result['minimum_quantity'];
	}

	/**
	 * Whether a requested/target quantity satisfies the applicable rule's
	 * minimum. Always `true` when no rule applies -- this policy
	 * only ever tightens behaviour for B2B lines, never restricts a
	 * standard B2C purchase.
	 *
	 * @param array     $result   `PriceResolver::resolve()` result.
	 * @param int|float $quantity Requested/target quantity.
	 * @return bool
	 */
	public static function is_quantity_valid( array $result, $quantity ) {
		$minimum = self::resolve_minimum( $result );

		if ( null === $minimum ) {
			return true;
		}

		return ( (float) $quantity ) >= $minimum;
	}
}
