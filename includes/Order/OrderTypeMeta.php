<?php
/**
 * Pure decision + order-level meta builder for whether a whole order is
 * B2B: a customer with an active assigned list at
 * the moment of purchase makes the *entire* order B2B, independent of
 * whether any individual line actually received a B2B price (spec.md edge
 * case: "0 of N products with a special price" is still a B2B order, never
 * an error).
 *
 * Deliberately free of any WordPress/WooCommerce function call -- the same
 * split already established by `OrderSnapshotBuilder` (pure) versus
 * `Order\OrderTypeAssigner` (WordPress-aware orchestration, which resolves
 * the customer's assigned list and the order's line items and hands both
 * here). Always recomputed from scratch from the order's current line
 * items, never from an incremental counter, so re-running it against a
 * Store API draft that already has meta produces the exact same result a
 * fresh order would.
 *
 * @package B2BPriceLists\Order
 */

namespace B2BPriceLists\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderTypeMeta
 */
class OrderTypeMeta {

	/**
	 * Order-level meta keys, each under the plugin's `_b2bpl_`
	 * meta prefix.
	 *
	 * @var string
	 */
	const META_ORDER_TYPE        = '_b2bpl_order_type';
	const META_ORDER_LIST_ID     = '_b2bpl_order_list_id';
	const META_ORDER_LIST_NAME   = '_b2bpl_order_list_name';
	const META_ORDER_B2B_LINES   = '_b2bpl_order_b2b_lines';
	const META_ORDER_TOTAL_LINES = '_b2bpl_order_total_lines';

	/**
	 * The only order type value this plugin ever writes. A B2C order simply
	 * never receives this meta at all -- there is no `b2c` value.
	 *
	 * @var string
	 */
	const TYPE_B2B = 'b2b';

	/**
	 * Every meta key this class ever writes, used both to write the full
	 * set and to remove a stale mark in one pass (e.g. a Store API draft
	 * re-evaluated after the customer's list was deactivated mid-checkout).
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array(
			self::META_ORDER_TYPE,
			self::META_ORDER_LIST_ID,
			self::META_ORDER_LIST_NAME,
			self::META_ORDER_B2B_LINES,
			self::META_ORDER_TOTAL_LINES,
		);
	}

	/**
	 * Builds the order-level meta values, or `null` when the order is not
	 * B2B and must therefore carry none of them at all (guests, customers
	 * with no list, and customers whose list is not active).
	 *
	 * @param array<string,mixed>|null $assigned_list  The customer's currently
	 *                                                  assigned list row (as
	 *                                                  returned by
	 *                                                  `Repository\ListsRepository::find()`),
	 *                                                  or `null` when the
	 *                                                  order has no
	 *                                                  registered customer or
	 *                                                  the customer has no
	 *                                                  assignment at all.
	 * @param array<int,int|null>      $line_list_ids  One entry per product
	 *                                                  line item in the
	 *                                                  order, in any order:
	 *                                                  the line's recorded
	 *                                                  `_b2bpl_list_id`
	 *, or `null`
	 *                                                  for a line that
	 *                                                  received no B2B
	 *                                                  price.
	 * @return array<string,int|string>|null
	 */
	public static function build( ?array $assigned_list, array $line_list_ids ) {
		if ( null === $assigned_list ) {
			return null;
		}

		$status = isset( $assigned_list['status'] ) ? (string) $assigned_list['status'] : '';

		if ( 'active' !== $status ) {
			return null;
		}

		$list_id   = isset( $assigned_list['id'] ) ? (int) $assigned_list['id'] : 0;
		$list_name = isset( $assigned_list['name'] ) ? (string) $assigned_list['name'] : '';

		if ( $list_id <= 0 || '' === $list_name ) {
			return null;
		}

		$total_lines = count( $line_list_ids );
		$b2b_lines   = 0;

		foreach ( $line_list_ids as $line_list_id ) {
			if ( null !== $line_list_id && (int) $line_list_id === $list_id ) {
				++$b2b_lines;
			}
		}

		return array(
			self::META_ORDER_TYPE        => self::TYPE_B2B,
			self::META_ORDER_LIST_ID     => $list_id,
			self::META_ORDER_LIST_NAME   => $list_name,
			self::META_ORDER_B2B_LINES   => $b2b_lines,
			self::META_ORDER_TOTAL_LINES => $total_lines,
		);
	}
}
