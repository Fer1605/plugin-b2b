<?php
/**
 * Persistence for `b2bpl_webhook_events`.
 *
 * Pure data access: no business validation, no capability checks. Every
 * method uses `$wpdb->prepare()`.
 *
 * @package B2BPriceLists\Repository\Webhook
 */

namespace B2BPriceLists\Repository\Webhook;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EventsRepository
 */
class EventsRepository {

	/**
	 * Inserts one timeline event.
	 *
	 * @param array $data {
	 *     One timeline event.
	 *
	 *     @type int         $request_id    Request id.
	 *     @type int|null    $item_id       Item id, when the event is scoped to one.
	 *     @type string      $level         'debug'|'info'|'warning'|'error'.
	 *     @type string      $code          Machine-readable event code.
	 *     @type string      $message       Human-readable message.
	 *     @type string|null $context       Pre-encoded JSON.
	 *     @type int         $actor_user_id Defaults to 0.
	 * }
	 * @return int|false New row id, or `false` on failure.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$table = Schema::webhook_events_table();

		$inserted = $wpdb->insert(
			$table,
			array(
				'request_id'    => (int) $data['request_id'],
				'item_id'       => isset( $data['item_id'] ) && null !== $data['item_id'] ? (int) $data['item_id'] : null,
				'created_at'    => current_time( 'mysql' ),
				'level'         => (string) ( $data['level'] ?? 'info' ),
				'code'          => (string) ( $data['code'] ?? '' ),
				// `message` is `varchar(500)`; truncated defensively so an
				// unexpectedly long message never fails the whole insert.
				'message'       => mb_substr( (string) ( $data['message'] ?? '' ), 0, 500 ),
				'context'       => isset( $data['context'] ) ? (string) $data['context'] : null,
				'actor_user_id' => (int) ( $data['actor_user_id'] ?? 0 ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * The full timeline for a request, oldest first.
	 *
	 * @param int $request_id Request id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_request( $request_id ) {
		global $wpdb;

		$table = Schema::webhook_events_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d ORDER BY created_at ASC, id ASC", (int) $request_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The timeline for a single item within a request, oldest first.
	 *
	 * @param int $request_id Request id.
	 * @param int $item_id    Item id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_item( $request_id, $item_id ) {
		global $wpdb;

		$table = Schema::webhook_events_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE request_id = %d AND item_id = %d ORDER BY created_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $request_id,
				(int) $item_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes every event belonging to any of the given requests (W5
	 * retention purge only) -- always called before
	 * `ItemsRepository::delete_by_request_ids()`/`RequestsRepository::delete_ids()`
	 * for the same ids, in the same transaction.
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

		$table        = Schema::webhook_events_table();
		$placeholders = implode( ',', array_fill( 0, count( $request_ids ), '%d' ) );

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE request_id IN ({$placeholders})", $request_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed SQL template, only the IN() placeholder count is dynamic; the sniff cannot see the %d tokens produced by array_fill().

		return false === $deleted ? 0 : (int) $deleted;
	}
}
