<?php
/**
 * Persistence for `b2bpl_rule_group_variations` (the N:M relationship
 * between a `variation_group` rule group and the WooCommerce variations it
 * covers).
 *
 * @package B2BPriceLists\Repository
 */

namespace B2BPriceLists\Repository;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RuleGroupVariationsRepository
 */
class RuleGroupVariationsRepository {

	/**
	 * Returns every variation id covered by a rule group.
	 *
	 * @param int $rule_group_id Rule group id.
	 * @return int[]
	 */
	public function get_variation_ids( $rule_group_id ) {
		global $wpdb;

		$table = Schema::rule_group_variations_table();

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT variation_id FROM {$table} WHERE rule_group_id = %d ORDER BY variation_id ASC", (int) $rule_group_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Returns the variation ids covered by a batch of rule group ids in a
	 * single query, grouped by `rule_group_id` (catalog/resolver
	 * batch reads must never issue one query per rule group).
	 *
	 * @param int[] $rule_group_ids Rule group ids.
	 * @return array<int,int[]> Keyed by rule_group_id.
	 */
	public function get_variation_ids_by_rule_group_ids( array $rule_group_ids ) {
		global $wpdb;

		$rule_group_ids = array_values( array_unique( array_map( 'intval', $rule_group_ids ) ) );

		if ( empty( $rule_group_ids ) ) {
			return array();
		}

		$table        = Schema::rule_group_variations_table();
		$placeholders = implode( ',', array_fill( 0, count( $rule_group_ids ), '%d' ) );

		$query = "SELECT rule_group_id, variation_id FROM {$table} WHERE rule_group_id IN ({$placeholders}) ORDER BY rule_group_id ASC, variation_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( $wpdb->prepare( $query, $rule_group_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL template, only the IN() placeholder count is dynamic.

		$grouped = array();

		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['rule_group_id'] ][] = (int) $row['variation_id'];
		}

		return $grouped;
	}

	/**
	 * Finds every OTHER `variation_group` rule group (within the same list
	 * and product) that already contains one of the given variation ids.
	 *
	 * Used to enforce a variation must never belong to more than
	 * one `variation_group` within the same list/product (TC-066).
	 *
	 * @param int   $list_id           List id.
	 * @param int   $product_id        Parent product id.
	 * @param int[] $variation_ids     Candidate variation ids being saved.
	 * @param int   $exclude_group_id  Rule group id to exclude (the one
	 *                                 currently being saved), 0 when creating.
	 * @return array<int,array{variation_id:int, rule_group_id:int, rule_group_name:?string}>
	 *         One row per conflicting variation found.
	 */
	public function find_conflicting_variations( $list_id, $product_id, array $variation_ids, $exclude_group_id = 0 ) {
		global $wpdb;

		$variation_ids = array_map( 'intval', $variation_ids );

		if ( empty( $variation_ids ) ) {
			return array();
		}

		$rule_groups_table = Schema::rule_groups_table();
		$variations_table  = Schema::rule_group_variations_table();

		$placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );

		$query = "SELECT rgv.variation_id AS variation_id, rgv.rule_group_id AS rule_group_id, rg.name AS rule_group_name
			FROM {$variations_table} rgv
			INNER JOIN {$rule_groups_table} rg ON rg.id = rgv.rule_group_id
			WHERE rg.list_id = %d
			AND rg.product_id = %d
			AND rg.scope = 'variation_group'
			AND rg.id != %d
			AND rgv.variation_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$params = array_merge( array( (int) $list_id, (int) $product_id, (int) $exclude_group_id ), $variation_ids );

		$rows = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $query is a fixed, hard-coded SQL template with %d/IN(%d,...) placeholders only; the dynamic part is exclusively the placeholder count, not user input.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Replaces the full set of variations covered by a rule group.
	 *
	 * @param int   $rule_group_id Rule group id.
	 * @param int[] $variation_ids New, complete set of variation ids.
	 * @return void
	 */
	public function replace_for_group( $rule_group_id, array $variation_ids ) {
		global $wpdb;

		$table = Schema::rule_group_variations_table();

		$this->delete_by_rule_group_id( $rule_group_id );

		foreach ( array_unique( array_map( 'intval', $variation_ids ) ) as $variation_id ) {
			$wpdb->insert(
				$table,
				array(
					'rule_group_id' => (int) $rule_group_id,
					'variation_id'  => (int) $variation_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Deletes every relationship row for a rule group.
	 *
	 * @param int $rule_group_id Rule group id.
	 * @return bool
	 */
	public function delete_by_rule_group_id( $rule_group_id ) {
		global $wpdb;

		$table = Schema::rule_group_variations_table();

		$deleted = $wpdb->delete( $table, array( 'rule_group_id' => (int) $rule_group_id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Deletes every relationship row for a list of rule group ids (used
	 * when cascading a list or product deletion).
	 *
	 * @param int[] $rule_group_ids Rule group ids.
	 * @return void
	 */
	public function delete_by_rule_group_ids( array $rule_group_ids ) {
		foreach ( $rule_group_ids as $rule_group_id ) {
			$this->delete_by_rule_group_id( $rule_group_id );
		}
	}

	/**
	 * Cleanup hook target: removes the relationship row(s) for a
	 * WooCommerce variation that is being permanently deleted.
	 *
	 * @param int $variation_id Variation id.
	 * @return bool
	 */
	public function delete_by_variation_id( $variation_id ) {
		global $wpdb;

		$table = Schema::rule_group_variations_table();

		$deleted = $wpdb->delete( $table, array( 'variation_id' => (int) $variation_id ), array( '%d' ) );

		return false !== $deleted;
	}
}
