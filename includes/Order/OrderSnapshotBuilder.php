<?php
/**
 * Pure builder for the order line item audit snapshot: takes a
 * `Domain\PriceResolver::resolve()` result (already `applies === true`) and
 * the running plugin version, and produces the exact 12-field associative
 * array (`_b2bpl_*` meta key => value) to persist on a `WC_Order_Item`.
 *
 * Deliberately free of any WordPress/WooCommerce function call, so it can
 * be covered by fast, WordPress-independent unit tests -- the same split
 * already established by `Domain\PriceResolutionEngine` (pure) vs.
 * `Domain\PriceResolver` (WordPress-aware orchestration). `Order\OrderAuditMeta`
 * is the only caller: it resolves the price, hands the result here, and
 * persists whatever this class returns via the `WC_Order_Item` CRUD API
 * (`add_meta_data`/`save`), never a raw SQL write.
 *
 * Returns `null` whenever any of the fields requires cannot be
 * established from the resolution result -- the caller must then write no
 * metadata at all, rather than a partial snapshot ( step 2/3).
 *
 * @package B2BPriceLists\Order
 */

namespace B2BPriceLists\Order;

use B2BPriceLists\Domain\RuleGroupValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderSnapshotBuilder
 */
class OrderSnapshotBuilder {

	/**
	 * Line item meta keys ( 12 fields, plus the 3 offer fields
	 * added in 1.1.0), each under the plugin's `_b2bpl_` meta prefix.
	 *
	 * @var string
	 */
	const META_LIST_ID                 = '_b2bpl_list_id';
	const META_LIST_NAME_SNAPSHOT      = '_b2bpl_list_name_snapshot';
	const META_RULE_GROUP_ID           = '_b2bpl_rule_group_id';
	const META_PRICING_MODE            = '_b2bpl_pricing_mode';
	const META_RANGE_ID                = '_b2bpl_range_id';
	const META_RANGE_MIN_QTY           = '_b2bpl_range_min_qty';
	const META_RANGE_MAX_QTY           = '_b2bpl_range_max_qty';
	const META_B2B_UNIT_PRICE          = '_b2bpl_b2b_unit_price';
	const META_BASE_UNIT_PRICE         = '_b2bpl_base_unit_price';
	const META_DISCOUNT_PERCENT        = '_b2bpl_discount_percent';
	const META_DISCOUNT_SOURCE         = '_b2bpl_discount_source';
	const META_REFERENCE_REGULAR_PRICE = '_b2bpl_reference_regular_price';
	const META_PRODUCT_ID              = '_b2bpl_product_id';
	const META_VARIATION_ID            = '_b2bpl_variation_id';
	const META_PLUGIN_VERSION          = '_b2bpl_plugin_version';

	/**
	 * Every meta key this class ever writes, in declared order
	 * (the three offer fields sit next to the price they explain) --
	 * used both to build the snapshot and, by `Order\OrderSnapshotDisplay`,
	 * to keep the raw generic order-item-meta admin table free of a
	 * redundant, unformatted duplicate of the same data.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array(
			self::META_LIST_ID,
			self::META_LIST_NAME_SNAPSHOT,
			self::META_RULE_GROUP_ID,
			self::META_PRICING_MODE,
			self::META_RANGE_ID,
			self::META_RANGE_MIN_QTY,
			self::META_RANGE_MAX_QTY,
			self::META_B2B_UNIT_PRICE,
			self::META_BASE_UNIT_PRICE,
			self::META_DISCOUNT_PERCENT,
			self::META_DISCOUNT_SOURCE,
			self::META_REFERENCE_REGULAR_PRICE,
			self::META_PRODUCT_ID,
			self::META_VARIATION_ID,
			self::META_PLUGIN_VERSION,
		);
	}

	/**
	 * Builds the snapshot array, or `null` when the resolution result does
	 * not carry every field requires (defensive: this should never
	 * happen for a result whose `applies` is `true` and that has already
	 * passed through `PriceResolutionEngine`, but the caller must never
	 * write a partial snapshot on the strength of an assumption alone).
	 *
	 * @param array  $resolution     `PriceResolver::resolve()` result.
	 * @param string $plugin_version Current `B2BPL_VERSION`.
	 * @return array<string,mixed>|null Meta key => value pairs. A value of
	 *                                  `null` for a given key means "field
	 *                                  intentionally not applicable" (e.g.
	 *                                  the range fields for a `fixed` line,
	 *                                  `variation_id` for a simple product,
	 *                                  or a product with no regular price)
	 *                                  -- the caller skips writing those,
	 *                                  never writes a literal null value.
	 */
	public static function build( array $resolution, $plugin_version ) {
		if ( empty( $resolution['applies'] ) ) {
			return null;
		}

		$pricing_mode = isset( $resolution['pricing_mode'] ) ? (string) $resolution['pricing_mode'] : '';

		if ( ! in_array( $pricing_mode, array( RuleGroupValidator::PRICING_MODE_FIXED, RuleGroupValidator::PRICING_MODE_TIERED ), true ) ) {
			return null;
		}

		$list_id       = isset( $resolution['list_id'] ) ? (int) $resolution['list_id'] : 0;
		$list_name     = isset( $resolution['list_name'] ) ? (string) $resolution['list_name'] : '';
		$rule_group_id = isset( $resolution['rule_group_id'] ) ? (int) $resolution['rule_group_id'] : 0;
		$product_id    = isset( $resolution['product_id'] ) ? (int) $resolution['product_id'] : 0;
		$unit_price    = isset( $resolution['b2b_unit_price'] ) && is_numeric( $resolution['b2b_unit_price'] ) ? (float) $resolution['b2b_unit_price'] : null;

		if ( $list_id <= 0 || '' === $list_name || $rule_group_id <= 0 || $product_id <= 0 || null === $unit_price ) {
			return null;
		}

		$range_id      = null;
		$range_min_qty = null;
		$range_max_qty = null;

		if ( RuleGroupValidator::PRICING_MODE_TIERED === $pricing_mode ) {
			if ( empty( $resolution['range_id'] ) || ! isset( $resolution['range_min_qty'] ) || null === $resolution['range_min_qty'] ) {
				return null;
			}

			$range_id      = (int) $resolution['range_id'];
			$range_min_qty = (int) $resolution['range_min_qty'];
			$range_max_qty = isset( $resolution['range_max_qty'] ) && null !== $resolution['range_max_qty']
				? (int) $resolution['range_max_qty']
				: null;

			if ( $range_id <= 0 ) {
				return null;
			}
		}

		$reference_regular_price = isset( $resolution['reference_regular_price'] ) && null !== $resolution['reference_regular_price'] && is_numeric( $resolution['reference_regular_price'] )
			? (float) $resolution['reference_regular_price']
			: null;

		// Offer fields: only recorded when an offer actually applied, so a
		// line with no offer keeps exactly the same 12-field snapshot it had
		// before this feature existed (an existing order is never rewritten,
		// and a new plain line never gains three empty meta rows).
		$discount_percent = isset( $resolution['discount_percent'] ) && null !== $resolution['discount_percent'] && is_numeric( $resolution['discount_percent'] )
			? (float) $resolution['discount_percent']
			: null;
		$discount_source  = ( null !== $discount_percent && ! empty( $resolution['discount_source'] ) )
			? (string) $resolution['discount_source']
			: null;
		$base_unit_price  = ( null !== $discount_percent && isset( $resolution['base_unit_price'] ) && is_numeric( $resolution['base_unit_price'] ) )
			? (float) $resolution['base_unit_price']
			: null;

		$variation_id = isset( $resolution['variation_id'] ) && null !== $resolution['variation_id']
			? (int) $resolution['variation_id']
			: null;

		return array(
			self::META_LIST_ID                 => $list_id,
			self::META_LIST_NAME_SNAPSHOT      => $list_name,
			self::META_RULE_GROUP_ID           => $rule_group_id,
			self::META_PRICING_MODE            => $pricing_mode,
			self::META_RANGE_ID                => $range_id,
			self::META_RANGE_MIN_QTY           => $range_min_qty,
			self::META_RANGE_MAX_QTY           => $range_max_qty,
			self::META_B2B_UNIT_PRICE          => $unit_price,
			self::META_BASE_UNIT_PRICE         => $base_unit_price,
			self::META_DISCOUNT_PERCENT        => $discount_percent,
			self::META_DISCOUNT_SOURCE         => $discount_source,
			self::META_REFERENCE_REGULAR_PRICE => $reference_regular_price,
			self::META_PRODUCT_ID              => $product_id,
			self::META_VARIATION_ID            => $variation_id,
			self::META_PLUGIN_VERSION          => (string) $plugin_version,
		);
	}
}
