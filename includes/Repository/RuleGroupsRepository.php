<?php
/**
 * Persistence for `b2bpl_rule_groups`.
 *
 * @package B2BPriceLists\Repository
 */

namespace B2BPriceLists\Repository;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RuleGroupsRepository
 */
class RuleGroupsRepository {

	/**
	 * Finds a rule group by id.
	 *
	 * @param int $id Rule group id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Returns every rule group belonging to a list, newest first.
	 *
	 * @param int $list_id List id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_list( $list_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE list_id = %d ORDER BY product_id ASC, id ASC", (int) $list_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns every rule group for a given list and parent product.
	 *
	 * @param int $list_id    List id.
	 * @param int $product_id Parent product id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_list_and_product( $list_id, $product_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE list_id = %d AND product_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $list_id,
				(int) $product_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns every rule group for a given list across a batch of parent
	 * products, in a single query (catalog/loop rendering must
	 * never issue one query per product; always scoped by
	 * `list_id`, never by `product_id` alone).
	 *
	 * @param int   $list_id     List id.
	 * @param int[] $product_ids Parent product ids present on the current page.
	 * @return array<int,array<string,mixed>> Empty array when $product_ids is empty.
	 */
	public function get_by_list_and_products( $list_id, array $product_ids ) {
		global $wpdb;

		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );

		if ( empty( $product_ids ) ) {
			return array();
		}

		$table        = Schema::rule_groups_table();
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		$query  = "SELECT * FROM {$table} WHERE list_id = %d AND product_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$params = array_merge( array( (int) $list_id ), $product_ids );

		$rows = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $query is a fixed, hard-coded SQL template with %d/IN(%d,...) placeholders only; the dynamic part is exclusively the placeholder count, not user input.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns every rule group for a single product across a batch of list
	 * ids, in a single query (used by the product info panel to fetch all
	 * groups for one page of lists at once instead of one query per list).
	 *
	 * @param int   $product_id Product id.
	 * @param int[] $list_ids   List ids present on the current page.
	 * @return array<int,array<string,mixed>> Empty array when $list_ids is empty.
	 */
	public function get_by_product_and_list_ids( $product_id, array $list_ids ) {
		global $wpdb;

		$list_ids = array_values( array_unique( array_map( 'intval', $list_ids ) ) );

		if ( empty( $list_ids ) ) {
			return array();
		}

		$table        = Schema::rule_groups_table();
		$placeholders = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );

		$query  = "SELECT * FROM {$table} WHERE product_id = %d AND list_id IN ({$placeholders}) ORDER BY list_id DESC, id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$params = array_merge( array( (int) $product_id ), $list_ids );

		$rows = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL template, only the IN() placeholder count is dynamic.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns every rule group referencing a given product, across every
	 * list (used by the product info panel in a later phase, and by
	 * product-deletion cleanup in this one).
	 *
	 * @param int $product_id Product id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_product( $product_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d", (int) $product_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Distinct list ids referencing a given product via at least one rule
	 * group, one page at a time (used by the read-only product info panel,
	 * section 17.1, to avoid loading every list a heavily-shared
	 * product belongs to at once -- the panel is paginated by LIST, not by
	 * raw rule-group row, since a single list can contribute more than one
	 * row for the same product, e.g. an `all_variations` group plus one or
	 * more `variation_group` rows).
	 *
	 * @param int $product_id Product id.
	 * @param int $page       1-based page number.
	 * @param int $per_page   Lists per page.
	 * @return int[]
	 */
	public function get_distinct_list_ids_for_product_paginated( $product_id, $page, $per_page ) {
		global $wpdb;

		$table  = Schema::rule_groups_table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT list_id FROM {$table} WHERE product_id = %d ORDER BY list_id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $product_id,
				(int) $per_page,
				$offset
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of distinct lists referencing a given product via at least one
	 * rule group (used to paginate the product info panel).
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public function count_distinct_lists_for_product( $product_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT list_id) FROM {$table} WHERE product_id = %d", (int) $product_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Number of distinct products covered by a list's rule groups (used to
	 * inform the deletion confirmation screen).
	 *
	 * @param int $list_id List id.
	 * @return int
	 */
	public function count_distinct_products_for_list( $list_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT product_id) FROM {$table} WHERE list_id = %d", (int) $list_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Finds a rule group by its `rule_code` within a single list (schema v5,
	 * unique per `list_id`, not globally -- the same code may be
	 * reused across different lists).
	 *
	 * @param int    $list_id   List id.
	 * @param string $rule_code Rule code.
	 * @return array<string,mixed>|null
	 */
	public function find_by_rule_code( $list_id, $rule_code ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE list_id = %d AND rule_code = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $list_id,
				(string) $rule_code
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Finds the single rule group that a webhook `products.upsert`/`remove`
	 * entry identifies (contract: list + SKU + `scope`, plus `rule_code` for
	 * `variation_group`, since a list can hold more than one
	 * `variation_group` rule for the same parent product).
	 *
	 * @param int         $list_id    List id.
	 * @param int         $product_id Parent product id (already resolved from SKU).
	 * @param string      $scope      One of `Domain\RuleGroupValidator::SCOPE_*`.
	 * @param string|null $rule_code  Required (non-null) only for `variation_group`.
	 * @return array<string,mixed>|null
	 */
	public function find_by_list_product_scope( $list_id, $product_id, $scope, $rule_code = null ) {
		if ( null !== $rule_code ) {
			$row = $this->find_by_rule_code( $list_id, $rule_code );

			// A `rule_code` belongs to exactly one list+product+scope
			// combination by construction (it is only ever set together with
			// them); this extra match guards against a caller passing a
			// `rule_code` that exists in this list but for a different
			// product/scope, which must be treated as "not this rule",
			// never silently reused.
			if ( null !== $row
				&& (int) $row['product_id'] === (int) $product_id
				&& (string) $row['scope'] === (string) $scope
			) {
				return $row;
			}

			return null;
		}

		$matches = $this->get_by_list_and_product( (int) $list_id, (int) $product_id );

		foreach ( $matches as $match ) {
			if ( (string) $match['scope'] === (string) $scope ) {
				return $match;
			}
		}

		return null;
	}

	/**
	 * Inserts a new rule group.
	 *
	 * @param array<string,mixed> $data Expects: list_id, product_id, scope,
	 *                                  pricing_mode, unit_price (nullable),
	 *                                  discount_percent (nullable),
	 *                                  minimum_quantity, name (nullable),
	 *                                  rule_code (optional).
	 * @return int|false
	 */
	public function insert( array $data ) {
		global $wpdb;

		$table = Schema::rule_groups_table();
		$now   = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'list_id'          => (int) $data['list_id'],
				'product_id'       => (int) $data['product_id'],
				'scope'            => (string) $data['scope'],
				'pricing_mode'     => (string) $data['pricing_mode'],
				'unit_price'       => null === $data['unit_price'] ? null : (float) $data['unit_price'],
				'discount_percent' => ( ! isset( $data['discount_percent'] ) || null === $data['discount_percent'] )
					? null
					: (float) $data['discount_percent'],
				'minimum_quantity' => (int) $data['minimum_quantity'],
				'name'             => isset( $data['name'] ) && '' !== $data['name'] ? (string) $data['name'] : null,
				// `rule_code` (schema v5): set only by the webhook, for
				// `variation_group` rules identified from outside.
				'rule_code'        => ( isset( $data['rule_code'] ) && '' !== $data['rule_code'] )
					? (string) $data['rule_code']
					: null,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%d', '%s', '%s', '%f', '%f', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Updates an existing rule group.
	 *
	 * `rule_code` is only ever written when the caller explicitly provides
	 * the key (`array_key_exists()`), exactly like `ListsRepository::update()`'s
	 * `external_code` -- the panel never passes it, so an ordinary panel
	 * save must never erase a webhook-set code.
	 *
	 * @param int                 $id   Rule group id.
	 * @param array<string,mixed> $data Same shape as insert(), minus list_id
	 *                                  (a rule group is never moved to a
	 *                                  different list). `product_id` is
	 *                                  editable --
	 *                                  callers are responsible for the
	 *                                  scope/conflict/orphan-cleanup
	 *                                  validation before calling this.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$values  = array(
			'product_id'       => (int) $data['product_id'],
			'scope'            => (string) $data['scope'],
			'pricing_mode'     => (string) $data['pricing_mode'],
			'unit_price'       => null === $data['unit_price'] ? null : (float) $data['unit_price'],
			'discount_percent' => ( ! isset( $data['discount_percent'] ) || null === $data['discount_percent'] )
				? null
				: (float) $data['discount_percent'],
			'minimum_quantity' => (int) $data['minimum_quantity'],
			'name'             => isset( $data['name'] ) && '' !== $data['name'] ? (string) $data['name'] : null,
			'updated_at'       => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%s', '%s', '%f', '%f', '%d', '%s', '%s' );

		if ( array_key_exists( 'rule_code', $data ) ) {
			$values['rule_code'] = ( null !== $data['rule_code'] && '' !== $data['rule_code'] ) ? (string) $data['rule_code'] : null;
			$formats[]           = '%s';
		}

		$updated = $wpdb->update(
			$table,
			$values,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Deletes a single rule group row. Callers must delete its ranges and
	 * variation relationships first (see `Domain\RuleGroupService`).
	 *
	 * @param int $id Rule group id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$deleted = $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Returns the ids of every rule group belonging to a list (used by the
	 * cascading list-deletion service, so it can delete each group's
	 * ranges/variations before removing the group rows themselves).
	 *
	 * @param int $list_id List id.
	 * @return int[]
	 */
	public function get_ids_by_list( $list_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE list_id = %d", (int) $list_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Returns the ids of every rule group belonging to a product, across
	 * every list (used by the product-deletion cleanup hook).
	 *
	 * @param int $product_id Product id.
	 * @return int[]
	 */
	public function get_ids_by_product( $product_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE product_id = %d", (int) $product_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Deletes every rule group row for a list. Only removes the group rows
	 * themselves; callers are responsible for deleting dependent ranges
	 * and variation relationships first.
	 *
	 * @param int $list_id List id.
	 * @return bool
	 */
	public function delete_by_list_id( $list_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$deleted = $wpdb->delete( $table, array( 'list_id' => (int) $list_id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Finds every OTHER rule group within the same `list_id`+`product_id`
	 * that already uses a given scope (used to enforce at most one
	 * `simple` and at most one `all_variations` rule group per `list_id`+
	 * `product_id` -- never evaluated by `product_id` alone, since the same
	 * product can have independent rules in several lists simultaneously).
	 *
	 * Only meaningful for `scope='simple'` or `scope='all_variations'`;
	 * `variation_group` allows multiple rows by design and is not
	 * expected to be passed here.
	 *
	 * @param int    $list_id          List id.
	 * @param int    $product_id       Parent product id.
	 * @param string $scope            'simple' or 'all_variations'.
	 * @param int    $exclude_group_id Rule group id to exclude (the one
	 *                                 currently being saved), 0 when creating.
	 * @return array<int,array<string,mixed>> Conflicting rows, if any.
	 */
	public function find_duplicate_scope( $list_id, $product_id, $scope, $exclude_group_id = 0 ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE list_id = %d AND product_id = %d AND scope = %s AND id != %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $list_id,
				(int) $product_id,
				(string) $scope,
				(int) $exclude_group_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes every rule group row for a product. Only removes the group
	 * rows themselves; callers are responsible for deleting dependent
	 * ranges and variation relationships first.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public function delete_by_product_id( $product_id ) {
		global $wpdb;

		$table = Schema::rule_groups_table();

		$deleted = $wpdb->delete( $table, array( 'product_id' => (int) $product_id ), array( '%d' ) );

		return false !== $deleted;
	}
}
