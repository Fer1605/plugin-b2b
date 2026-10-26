<?php
/**
 * Persistence for `b2bpl_webhook_items`.
 *
 * Pure data access: no business validation, no capability checks. Every
 * method uses `$wpdb->prepare()`. Rows are only ever written once a batch
 * actually starts processing (W3); this release only needs to read them
 * back for `GET /webhook?batch_id=`, which naturally returns an empty `items[]`
 * for a request still `queued`.
 *
 * @package B2BPriceLists\Repository\Webhook
 */

namespace B2BPriceLists\Repository\Webhook;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ItemsRepository
 */
class ItemsRepository {

	/**
	 * Inserts one item row (one operation from `operations[]`).
	 *
	 * @param array $data {
	 *     One item row.
	 *
	 *     @type int         $request_id   Request id.
	 *     @type int         $item_index   Position within `operations[]`.
	 *     @type string|null $ref          Emitter-supplied reference.
	 *     @type string      $entity       'list'|'products'|'user'.
	 *     @type string      $action       Operation action.
	 *     @type string|null $external_ref External identifier, for search.
	 *     @type string      $status       Defaults to 'pending'.
	 *     @type string      $input        Pre-encoded JSON.
	 * }
	 * @return int|false New row id, or `false` on failure (including a
	 *                    duplicate `(request_id, item_index)`).
	 */
	public function insert( array $data ) {
		global $wpdb;

		$table = Schema::webhook_items_table();
		$now   = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'request_id'   => (int) $data['request_id'],
				'item_index'   => (int) $data['item_index'],
				'ref'          => isset( $data['ref'] ) ? (string) $data['ref'] : null,
				'entity'       => (string) ( $data['entity'] ?? '' ),
				'action'       => (string) ( $data['action'] ?? '' ),
				'external_ref' => isset( $data['external_ref'] ) ? (string) $data['external_ref'] : null,
				'status'       => (string) ( $data['status'] ?? 'pending' ),
				'attempts'     => (int) ( $data['attempts'] ?? 0 ),
				'input'        => (string) ( $data['input'] ?? '' ),
				'result'       => isset( $data['result'] ) ? (string) $data['result'] : null,
				'errors'       => isset( $data['errors'] ) ? (string) $data['errors'] : null,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Updates an item's outcome after (re)processing.
	 *
	 * @param int                 $id   Item id.
	 * @param array<string,mixed> $data Any subset of: `status`, `attempts`,
	 *                                  `result`, `errors`, `external_ref`.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$table = Schema::webhook_items_table();

		$columns = array(
			'status'       => '%s',
			'attempts'     => '%d',
			'result'       => '%s',
			'errors'       => '%s',
			'external_ref' => '%s',
		);

		$values  = array();
		$formats = array();

		foreach ( $columns as $column => $format ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			$value             = $data[ $column ];
			$values[ $column ] = null === $value ? null : ( '%d' === $format ? (int) $value : (string) $value );
			$formats[]         = $format;
		}

		if ( empty( $values ) ) {
			return true;
		}

		$values['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		$updated = $wpdb->update( $table, $values, array( 'id' => (int) $id ), $formats, array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * Every item belonging to a request, in submission order.
	 *
	 * @param int $request_id Request id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_request( $request_id ) {
		global $wpdb;

		$table = Schema::webhook_items_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d ORDER BY item_index ASC", (int) $request_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A single item by its own id (W5 reprocess-one-item action).
	 *
	 * @param int $id Item id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Schema::webhook_items_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * A single item by its request id + position.
	 *
	 * @param int $request_id Request id.
	 * @param int $item_index Position within `operations[]`.
	 * @return array<string,mixed>|null
	 */
	public function find_by_request_and_index( $request_id, $item_index ) {
		global $wpdb;

		$table = Schema::webhook_items_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE request_id = %d AND item_index = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $request_id,
				(int) $item_index
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Every item for a request currently in a given status (used by the W5
	 * "reprocess failed items" action).
	 *
	 * @param int    $request_id Request id.
	 * @param string $status     'error', 'partial', etc.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_request_and_status( $request_id, $status ) {
		global $wpdb;

		$table = Schema::webhook_items_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE request_id = %d AND status = %s ORDER BY item_index ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $request_id,
				(string) $status
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes every item belonging to any of the given requests (W5
	 * retention purge only) -- always called before
	 * `RequestsRepository::delete_ids()` for the same ids, in the same
	 * transaction (neither table has a foreign key).
	 *
	 * @param int[] $request_ids Request ids.
	 * @return int Rows deleted.
	 */
	public function delete_by_request_ids( array $request_ids ) {
		global $wpdb;

		$request_ids = array_values(
			array_filter(
				array_map( 'intval', $request_ids ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);

		if ( empty( $request_ids ) ) {
			return 0;
		}

		$table        = Schema::webhook_items_table();
		$placeholders = implode( ',', array_fill( 0, count( $request_ids ), '%d' ) );

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE request_id IN ({$placeholders})", $request_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed SQL template, only the IN() placeholder count is dynamic; the sniff cannot see the %d tokens produced by array_fill().

		return false === $deleted ? 0 : (int) $deleted;
	}
}
