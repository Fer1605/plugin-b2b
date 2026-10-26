<?php
/**
 * Persistence for `b2bpl_lists`.
 *
 * Pure data access: no business validation, no HTML, no capability
 * checks. Every method uses `$wpdb->prepare()`.
 *
 * @package B2BPriceLists\Repository
 */

namespace B2BPriceLists\Repository;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ListsRepository
 */
class ListsRepository {

	/**
	 * Finds a list by id.
	 *
	 * @param int $id List id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Schema::lists_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Finds every list among a batch of ids, in a single query (used by
	 * the read-only product info panel to fetch one page of lists at once
	 * instead of one query per list).
	 *
	 * @param int[] $ids List ids.
	 * @return array<int,array<string,mixed>> Keyed by list id.
	 */
	public function find_by_ids( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = Schema::lists_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$query = "SELECT * FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( $wpdb->prepare( $query, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL template, only the IN() placeholder count is dynamic.

		$by_id = array();

		foreach ( (array) $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		return $by_id;
	}

	/**
	 * Finds a list by its slug.
	 *
	 * @param string $slug Slug.
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( $slug ) {
		global $wpdb;

		$table = Schema::lists_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Finds a list by its `external_code` (webhook identifier, schema v5,
	 *). Every list created from the panel before 1.5.0, and most
	 * created there afterwards, have a `NULL` `external_code`; this never
	 * matches those, since MySQL's `WHERE external_code = %s` never matches
	 * a `NULL` column value.
	 *
	 * @param string $external_code External code.
	 * @return array<string,mixed>|null
	 */
	public function find_by_external_code( $external_code ) {
		global $wpdb;

		$table = Schema::lists_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE external_code = %s", (string) $external_code ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Whether an `external_code` is already in use by another list (service-layer
	 * uniqueness check, MySQL's `UNIQUE` column allows any number of
	 * `NULL`s, so it alone cannot reject a duplicate non-empty code before the
	 * INSERT/UPDATE -- this mirrors `slug_exists()`'s shape for exactly that
	 * reason).
	 *
	 * @param string   $external_code Candidate external code.
	 * @param int|null $exclude_id    List id to exclude (the list currently
	 *                                 being saved), if any.
	 * @return bool
	 */
	public function external_code_exists( $external_code, $exclude_id = null ) {
		global $wpdb;

		$table = Schema::lists_table();

		if ( null !== $exclude_id ) {
			$count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE external_code = %s AND id != %d", (string) $external_code, (int) $exclude_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE external_code = %s", (string) $external_code ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return (int) $count > 0;
	}

	/**
	 * Whether a slug is already in use by another list.
	 *
	 * @param string   $slug       Candidate slug.
	 * @param int|null $exclude_id List id to exclude from the check (the
	 *                             list currently being edited, if any).
	 * @return bool
	 */
	public function slug_exists( $slug, $exclude_id = null ) {
		global $wpdb;

		$table = Schema::lists_table();

		if ( null !== $exclude_id ) {
			$count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND id != %d", (string) $slug, (int) $exclude_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", (string) $slug ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return (int) $count > 0;
	}

	/**
	 * Returns every list, optionally filtered by status, newest first.
	 *
	 * @param string|null $status 'active', 'inactive', or null for all.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( $status = null ) {
		global $wpdb;

		$table = Schema::lists_table();

		if ( null !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name ASC", (string) $status ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Inserts a new list.
	 *
	 * @param array<string,mixed> $data Expects: name, slug, description,
	 *                                  status, discount_percent (nullable),
	 *                                  created_by, updated_by.
	 * @return int|false New list id, or false on failure.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$table = Schema::lists_table();
		$now   = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'name'             => (string) $data['name'],
				'slug'             => (string) $data['slug'],
				'description'      => isset( $data['description'] ) ? (string) $data['description'] : null,
				'status'           => (string) $data['status'],
				'discount_percent' => isset( $data['discount_percent'] ) && null !== $data['discount_percent']
					? (float) $data['discount_percent']
					: null,
				// `external_code` (schema v5): `NULL` for every list created
				// from the panel unless the optional field is filled in
				// (T026), always set for one created by the webhook.
				'external_code'    => ( isset( $data['external_code'] ) && '' !== $data['external_code'] )
					? (string) $data['external_code']
					: null,
				'created_by'       => (int) $data['created_by'],
				'updated_by'       => (int) $data['updated_by'],
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Updates an existing list.
	 *
	 * `external_code` is only ever written when the caller explicitly
	 * provides the key (`array_key_exists()`, not `isset()`, so an explicit
	 * `null` -- "clear it" -- is honored too): every existing panel call site
	 * omits it entirely, and this must never silently erase a webhook-set
	 * code on an ordinary panel save.
	 *
	 * @param int                 $id   List id.
	 * @param array<string,mixed> $data Expects: name, slug, description,
	 *                                  status, discount_percent (nullable),
	 *                                  updated_by, external_code (optional).
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$table = Schema::lists_table();

		$values  = array(
			'name'             => (string) $data['name'],
			'slug'             => (string) $data['slug'],
			'description'      => isset( $data['description'] ) ? (string) $data['description'] : null,
			'status'           => (string) $data['status'],
			'discount_percent' => isset( $data['discount_percent'] ) && null !== $data['discount_percent']
				? (float) $data['discount_percent']
				: null,
			'updated_by'       => (int) $data['updated_by'],
			'updated_at'       => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%f', '%d', '%s' );

		if ( array_key_exists( 'external_code', $data ) ) {
			$values['external_code'] = ( null !== $data['external_code'] && '' !== $data['external_code'] )
				? (string) $data['external_code']
				: null;
			$formats[]               = '%s';
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
	 * Deletes a single list row. Callers are responsible for cascading
	 * deletion of related rows within a transaction (see
	 * `B2BPriceLists\Domain\ListService`).
	 *
	 * @param int $id List id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$table = Schema::lists_table();

		$deleted = $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );

		return false !== $deleted;
	}
}
