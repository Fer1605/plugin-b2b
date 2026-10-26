<?php
/**
 * Persistence for `b2bpl_webhook_requests`.
 *
 * Pure data access: no business validation, no HTTP handling, no capability
 * checks. Every method uses `$wpdb->prepare()`.
 *
 * @package B2BPriceLists\Repository\Webhook
 */

namespace B2BPriceLists\Repository\Webhook;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RequestsRepository
 */
class RequestsRepository {

	/**
	 * Inserts the initial log row for a request, before it is validated or
	 * processed. Every field has a safe default so a caller
	 * can log as much as it already knows at this point (which, for the
	 * `401` security-line exception, is deliberately very little).
	 *
	 * @param array $data {
	 *     The initial log row for a request.
	 *
	 *     @type string      $batch_id           UUID, required.
	 *     @type string      $endpoint           'lists'|'users'|'ping'|'batches'|'unknown'.
	 *     @type string      $method             HTTP method.
	 *     @type string      $received_at        `Y-m-d H:i:s.v` (UTC), required.
	 *     @type int         $auth_user_id       0 when not authenticated.
	 *     @type string      $auth_user_login    Snapshot.
	 *     @type string|null $app_password_uuid  Application Password UUID.
	 *     @type string      $ip                 Caller's IP address.
	 *     @type string      $user_agent         Caller's user agent.
	 *     @type string|null $idempotency_key    Idempotency key, when known.
	 *     @type int         $http_status        HTTP status, when known.
	 *     @type string      $status             Internal status column value.
	 *     @type string|null $finished_at        `Y-m-d H:i:s` (UTC), only for
	 *                                            a synchronous, definitive
	 *                                            outcome decided at insert
	 * time.
	 *     @type string|null $rejection_code     Top-level error code, when any.
	 *     @type int         $body_bytes         Original body size in bytes.
	 *     @type string      $body_sha256        Hash of the original body.
	 *     @type bool        $body_truncated     Whether storage truncated the body.
	 *     @type string|null $headers            Pre-encoded JSON.
	 *     @type string|null $raw_body           `NULL` for the 401 exception.
	 *     @type string|null $payload            Pre-encoded JSON.
	 *     @type string|null $response_body    Pre-encoded JSON.
	 * }
	 * @return int|false New row id, or `false` on failure (including a
	 *                    duplicate `batch_id`/`(auth_user_id,idempotency_key)`).
	 */
	public function insert( array $data ) {
		global $wpdb;

		$table = Schema::webhook_requests_table();

		$inserted = $wpdb->insert(
			$table,
			array(
				'batch_id'          => (string) $data['batch_id'],
				'endpoint'          => (string) ( $data['endpoint'] ?? 'unknown' ),
				'method'            => (string) ( $data['method'] ?? '' ),
				'received_at'       => (string) $data['received_at'],
				'auth_user_id'      => (int) ( $data['auth_user_id'] ?? 0 ),
				'auth_user_login'   => (string) ( $data['auth_user_login'] ?? '' ),
				'app_password_uuid' => isset( $data['app_password_uuid'] ) ? (string) $data['app_password_uuid'] : null,
				'ip'                => (string) ( $data['ip'] ?? '' ),
				'user_agent'        => (string) ( $data['user_agent'] ?? '' ),
				'idempotency_key'   => isset( $data['idempotency_key'] ) ? (string) $data['idempotency_key'] : null,
				'http_status'       => (int) ( $data['http_status'] ?? 0 ),
				'status'            => (string) ( $data['status'] ?? '' ),
				// only ever provided by `Webhook\IngestLogger` for
				// a synchronous, definitive outcome decided at insert time
				// (a rejection before a row otherwise existed) -- `NULL` for
				// every other initial insert, exactly like the column's own
				// default.
				'finished_at'       => isset( $data['finished_at'] ) ? (string) $data['finished_at'] : null,
				'rejection_code'    => isset( $data['rejection_code'] ) ? (string) $data['rejection_code'] : null,
				'body_bytes'        => (int) ( $data['body_bytes'] ?? 0 ),
				'body_sha256'       => (string) ( $data['body_sha256'] ?? '' ),
				'body_truncated'    => ! empty( $data['body_truncated'] ) ? 1 : 0,
				'headers'           => isset( $data['headers'] ) ? (string) $data['headers'] : null,
				'raw_body'          => isset( $data['raw_body'] ) ? (string) $data['raw_body'] : null,
				'payload'           => isset( $data['payload'] ) ? (string) $data['payload'] : null,
				'response_body'     => isset( $data['response_body'] ) ? (string) $data['response_body'] : null,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Updates a request row once its outcome (or final processing state) is
	 * known. Only the keys present in `$data` are written; every other
	 * column is left untouched.
	 *
	 * @param int                 $id   Request id.
	 * @param array<string,mixed> $data Any subset of `insert()`'s keys, plus
	 *                                  `started_at`, `finished_at`,
	 *                                  `items_total`, `items_success`,
	 *                                  `items_partial`, `items_error`,
	 *                                  `items_skipped`, `attempts`, `summary`.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$table = Schema::webhook_requests_table();

		$columns = array(
			'started_at'      => '%s',
			'finished_at'     => '%s',
			'http_status'     => '%d',
			'status'          => '%s',
			'rejection_code'  => '%s',
			'body_bytes'      => '%d',
			'body_sha256'     => '%s',
			'body_truncated'  => '%d',
			'headers'         => '%s',
			'raw_body'        => '%s',
			'payload'         => '%s',
			'response_body'   => '%s',
			//: released to `NULL` when a request is rejected
			// before ever reaching `queued`, so the same key can be reused
			// on a corrected retry.
			'idempotency_key' => '%s',
			'items_total'     => '%d',
			'items_success'   => '%d',
			'items_partial'   => '%d',
			'items_error'     => '%d',
			'items_skipped'   => '%d',
			'attempts'        => '%d',
			'summary'         => '%s',
		);

		$values  = array();
		$formats = array();

		foreach ( $columns as $column => $format ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			$value = $data[ $column ];

			if ( null === $value ) {
				$values[ $column ] = null;
			} elseif ( '%d' === $format ) {
				$values[ $column ] = (int) $value;
			} else {
				$values[ $column ] = (string) $value;
			}

			$formats[] = $format;
		}

		if ( empty( $values ) ) {
			return true; // Nothing to update is not a failure.
		}

		$updated = $wpdb->update( $table, $values, array( 'id' => (int) $id ), $formats, array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * Finds a request by id.
	 *
	 * @param int $id Request id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Schema::webhook_requests_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Finds a request by its `batch_id` (used by `GET /webhook?batch_id=` and by
	 * the "duplicate idempotency key" 200 response, which reports the
	 * original batch).
	 *
	 * @param string $batch_id UUID.
	 * @return array<string,mixed>|null
	 */
	public function find_by_batch_id( $batch_id ) {
		global $wpdb;

		$table = Schema::webhook_requests_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s", (string) $batch_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Finds a request by the `(auth_user_id, idempotency_key)` unique pair
	 *: used to re-read the original row after an INSERT-first
	 * attempt fails against the unique index, never to decide up front
	 * whether to insert (which would race).
	 *
	 * @param int    $auth_user_id    Authenticated user id.
	 * @param string $idempotency_key Idempotency key.
	 * @return array<string,mixed>|null
	 */
	public function find_by_auth_user_and_idempotency_key( $auth_user_id, $idempotency_key ) {
		global $wpdb;

		$table = Schema::webhook_requests_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE auth_user_id = %d AND idempotency_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $auth_user_id,
				(string) $idempotency_key
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Every request in status `queued`, oldest first (used by the "stuck
	 * queue" admin notice in W5).
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_queued( $limit = 50 ) {
		return $this->find_by_statuses( array( 'queued' ), $limit );
	}

	/**
	 * Every request in any of the given statuses, oldest first (FIFO order,
	 *) -- used by `Webhook\BatchQueue`'s recovery watchdog
	 * and by `Webhook\BatchProcessor` to pick the next batch to work on.
	 *
	 * @param string[] $statuses Candidate statuses.
	 * @param int      $limit    Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_by_statuses( array $statuses, $limit = 50 ) {
		global $wpdb;

		$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );

		if ( empty( $statuses ) ) {
			return array();
		}

		$table        = Schema::webhook_requests_table();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$query  = "SELECT * FROM {$table} WHERE status IN ({$placeholders}) ORDER BY received_at ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$params = array_merge( $statuses, array( (int) $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed SQL template, only the IN() placeholder count is dynamic; the sniff cannot see the %d tokens produced by array_fill().

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every request still `queued` and received more than `$minutes` ago
	 * (W5 "stuck queue" admin notice, T046): a batch that never actually got
	 * an Action Scheduler action, or whose action silently failed, stays
	 * `queued` -- `Webhook\BatchQueue`'s own watchdog should have already
	 * recovered it, so a batch still found here past the threshold is worth
	 * surfacing to an administrator.
	 *
	 * @param int $minutes Age threshold, in minutes.
	 * @param int $limit   Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_stuck_queued( $minutes, $limit = 50 ) {
		global $wpdb;

		$table = Schema::webhook_requests_table();
		// `received_at` is stored in UTC; compared against a
		// UTC "now" for the same reason.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, (int) $minutes ) * 60 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'queued' AND received_at < %s ORDER BY received_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Builds the `WHERE` clause and bound params shared by `search()` and
	 * `count()`, following the same shape as
	 * `Activity\ActivityRepository::build_where()`.
	 *
	 * @param array<string,mixed> $filters {
	 *     Optional. Every key may be omitted or empty.
	 *
	 *     @type string $from         Inclusive start date, `Y-m-d` (matched against `received_at`, UTC).
	 *     @type string $to           Inclusive end date, `Y-m-d`.
	 *     @type string $endpoint     Exact `endpoint` match.
	 *     @type string $status       Exact `status` match.
	 *     @type int    $http_status  Exact `http_status` match.
	 *     @type int    $auth_user_id Exact `auth_user_id` match.
	 *     @type string $text         Free text matched against `batch_id`,
	 *                                `idempotency_key`, and the `ref`/
	 *                                `external_ref` of any of the request's items.
	 * }
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_where( array $filters ) {
		global $wpdb;

		$clauses = array();
		$params  = array();

		if ( ! empty( $filters['from'] ) ) {
			$clauses[] = 'received_at >= %s';
			$params[]  = $filters['from'] . ' 00:00:00';
		}

		if ( ! empty( $filters['to'] ) ) {
			$clauses[] = 'received_at <= %s';
			$params[]  = $filters['to'] . ' 23:59:59';
		}

		if ( ! empty( $filters['endpoint'] ) ) {
			$clauses[] = 'endpoint = %s';
			$params[]  = (string) $filters['endpoint'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$clauses[] = 'status = %s';
			$params[]  = (string) $filters['status'];
		}

		if ( ! empty( $filters['http_status'] ) ) {
			$clauses[] = 'http_status = %d';
			$params[]  = (int) $filters['http_status'];
		}

		if ( ! empty( $filters['auth_user_id'] ) ) {
			$clauses[] = 'auth_user_id = %d';
			$params[]  = (int) $filters['auth_user_id'];
		}

		$text = trim( (string) ( $filters['text'] ?? '' ) );

		if ( '' !== $text ) {
			$items_table = Schema::webhook_items_table();
			$like        = '%' . $wpdb->esc_like( $text ) . '%';

			$clauses[] = "( batch_id LIKE %s OR idempotency_key LIKE %s OR id IN ( SELECT request_id FROM {$items_table} WHERE external_ref LIKE %s OR ref LIKE %s ) )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL, the only interpolation is the table name from Database\Schema.
			array_push( $params, $like, $like, $like, $like );
		}

		return array(
			$clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			$params,
		);
	}

	/**
	 * One filtered, paginated page of requests, newest first (T041).
	 *
	 * @param array<string,mixed> $filters  See `build_where()`.
	 * @param int                 $per_page Rows per page.
	 * @param int                 $page     1-based page number.
	 * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, pages:int, per_page:int}
	 */
	public function search( array $filters, $per_page = 20, $page = 1 ) {
		global $wpdb;

		$table    = Schema::webhook_requests_table();
		$per_page = max( 1, (int) $per_page );

		list( $where, $params ) = $this->build_where( $filters );

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$pages  = max( 1, (int) ceil( $total / $per_page ) );
		$page   = min( max( 1, (int) $page ), $pages );
		$offset = ( $page - 1 ) * $per_page;

		$sql  = "SELECT * FROM {$table} {$where} ORDER BY received_at DESC, id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			ARRAY_A
		);

		return array(
			'rows'     => is_array( $rows ) ? $rows : array(),
			'total'    => $total,
			'page'     => $page,
			'pages'    => $pages,
			'per_page' => $per_page,
		);
	}

	/**
	 * Every request id received strictly before `$cutoff` (W5 retention
	 * purge), oldest first.
	 *
	 * @param string $cutoff UTC `Y-m-d H:i:s`, matching `received_at`'s storage format.
	 * @param int    $limit  Maximum rows (one purge batch).
	 * @return int[]
	 */
	public function find_ids_received_before( $cutoff, $limit = 500 ) {
		global $wpdb;

		$table = Schema::webhook_requests_table();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE received_at < %s ORDER BY received_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $cutoff,
				max( 1, (int) $limit )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Deletes request rows by id (retention purge only). The caller is
	 * responsible for deleting matching `webhook_items`/`webhook_events`
	 * rows in the same transaction first (neither table has a foreign key,
	 * by design -- see `Database\Schema::all_tables_for_purge()`'s docblock).
	 *
	 * @param int[] $ids Request ids.
	 * @return int Rows deleted.
	 */
	public function delete_ids( array $ids ) {
		global $wpdb;

		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = Schema::webhook_requests_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed SQL template, only the IN() placeholder count is dynamic; the sniff cannot see the %d tokens produced by array_fill().

		return false === $deleted ? 0 : (int) $deleted;
	}
}
