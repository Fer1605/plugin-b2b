<?php
/**
 * Persistence for `b2bpl_price_ranges` (only populated when a rule group's
 * `pricing_mode` is `tiered`).
 *
 * @package B2BPriceLists\Repository
 */

namespace B2BPriceLists\Repository;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriceRangesRepository
 */
class PriceRangesRepository {

	/**
	 * Returns every range for a rule group, ordered by `min_qty` ascending
	 * (the resolution engine, out of scope for this milestone, must always
	 * evaluate ranges in this order regardless of `sort_order`).
	 *
	 * @param int $rule_group_id Rule group id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_rule_group( $rule_group_id ) {
		global $wpdb;

		$table = Schema::price_ranges_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE rule_group_id = %d ORDER BY min_qty ASC", (int) $rule_group_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns every range for a batch of rule group ids in a single query,
	 * grouped by `rule_group_id` and each already ordered by `min_qty`
	 * ascending (catalog/resolver batch reads must never issue one
	 * query per rule group).
	 *
	 * @param int[] $rule_group_ids Rule group ids.
	 * @return array<int,array<int,array<string,mixed>>> Keyed by rule_group_id.
	 */
	public function get_by_rule_group_ids( array $rule_group_ids ) {
		global $wpdb;

		$rule_group_ids = array_values( array_unique( array_map( 'intval', $rule_group_ids ) ) );

		if ( empty( $rule_group_ids ) ) {
			return array();
		}

		$table        = Schema::price_ranges_table();
		$placeholders = implode( ',', array_fill( 0, count( $rule_group_ids ), '%d' ) );

		$query = "SELECT * FROM {$table} WHERE rule_group_id IN ({$placeholders}) ORDER BY rule_group_id ASC, min_qty ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( $wpdb->prepare( $query, $rule_group_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL template, only the IN() placeholder count is dynamic.

		$grouped = array();

		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['rule_group_id'] ][] = $row;
		}

		return $grouped;
	}

	/**
	 * Replaces the full set of ranges for a rule group with a new,
	 * already-validated set (see `Domain\RuleGroupValidator`).
	 *
	 * @param int                            $rule_group_id Rule group id.
	 * @param array<int,array<string,mixed>> $ranges        Each item:
	 *                                                       min_qty, max_qty
	 *                                                       (nullable), unit_price,
	 *                                                       discount_percent
	 *                                                       (nullable), sort_order.
	 * @return void
	 */
	public function replace_for_group( $rule_group_id, array $ranges ) {
		global $wpdb;

		$table = Schema::price_ranges_table();
		$now   = current_time( 'mysql' );

		$this->delete_by_rule_group_id( $rule_group_id );

		foreach ( $ranges as $range ) {
			$wpdb->insert(
				$table,
				array(
					'rule_group_id'    => (int) $rule_group_id,
					'min_qty'          => (int) $range['min_qty'],
					'max_qty'          => ( isset( $range['max_qty'] ) && null !== $range['max_qty'] && '' !== $range['max_qty'] )
						? (int) $range['max_qty']
						: null,
					'unit_price'       => (float) $range['unit_price'],
					'discount_percent' => ( ! isset( $range['discount_percent'] ) || null === $range['discount_percent'] || '' === $range['discount_percent'] )
						? null
						: (float) $range['discount_percent'],
					'sort_order'       => isset( $range['sort_order'] ) ? (int) $range['sort_order'] : 0,
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%d', '%d', '%f', '%f', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Deletes every range row for a rule group.
	 *
	 * @param int $rule_group_id Rule group id.
	 * @return bool
	 */
	public function delete_by_rule_group_id( $rule_group_id ) {
		global $wpdb;

		$table = Schema::price_ranges_table();

		$deleted = $wpdb->delete( $table, array( 'rule_group_id' => (int) $rule_group_id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Deletes every range row for a list of rule group ids (used when
	 * cascading a list or product deletion).
	 *
	 * @param int[] $rule_group_ids Rule group ids.
	 * @return void
	 */
	public function delete_by_rule_group_ids( array $rule_group_ids ) {
		foreach ( $rule_group_ids as $rule_group_id ) {
			$this->delete_by_rule_group_id( $rule_group_id );
		}
	}
}
