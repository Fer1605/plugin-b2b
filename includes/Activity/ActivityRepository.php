<?php
/**
 * Read side of the activity log: filtered, paginated queries for the
 * "Activity" screen, plus the per-month counts that drive its month rail.
 *
 * @package B2BPriceLists\Activity
 */

namespace B2BPriceLists\Activity;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityRepository
 */
class ActivityRepository {

	/**
	 * Entries per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 40;

	/**
	 * Returns one page of entries, newest first.
	 *
	 * @param array $filters {
	 *     Optional. Every key may be omitted or empty.
	 *
	 *     @type string $from    Inclusive start date, `Y-m-d`.
	 *     @type string $to      Inclusive end date, `Y-m-d`.
	 *     @type int    $user_id Filter by author.
	 *     @type int    $list_id Filter by list.
	 *     @type string $action  Filter by action name.
	 *     @type int    $page    1-based page number.
	 * }
	 * @return array{entries:array<int,array<string,mixed>>, total:int, page:int, pages:int}
	 */
	public function find( array $filters = array() ) {
		global $wpdb;

		$table = Schema::activity_table();
		$page  = max( 1, (int) ( $filters['page'] ?? 1 ) );

		list( $where, $params ) = $this->build_where( $filters );

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page   = min( $page, $pages );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		$sql  = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $params, array( self::PER_PAGE, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			ARRAY_A
		);

		return array(
			'entries' => is_array( $rows ) ? $rows : array(),
			'total'   => $total,
			'page'    => $page,
			'pages'   => $pages,
		);
	}

	/**
	 * Number of entries matching the filters.
	 *
	 * @param array $filters Same shape as `find()`; `page` is ignored.
	 * @return int
	 */
	public function count( array $filters = array() ) {
		global $wpdb;

		$table = Schema::activity_table();

		list( $where, $params ) = $this->build_where( $filters );

		$sql = "SELECT COUNT(*) FROM {$table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * One batch of entries for an export, newest first, continuing below a
	 * given id.
	 *
	 * Paged by id rather than by OFFSET: somebody may change a price while a
	 * long export is being written, and with OFFSET every new entry would
	 * shift the pages and repeat a row. Ids only ever grow, so "below the
	 * last id already written" stays exact.
	 *
	 * @param array $filters   Same shape as `find()`; `page` is ignored.
	 * @param int   $limit     Batch size.
	 * @param int   $before_id Only entries with a lower id; 0 for the first batch.
	 * @return array<int,array<string,mixed>>
	 */
	public function export_batch( array $filters, $limit, $before_id = 0 ) {
		global $wpdb;

		$table = Schema::activity_table();

		if ( (int) $before_id > 0 ) {
			$filters['before_id'] = (int) $before_id;
		}

		list( $where, $params ) = $this->build_where( $filters );

		$sql  = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $params, array( max( 1, (int) $limit ) ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The oldest entries older than a cutoff, oldest first -- what the next
	 * purge pass has to archive and remove.
	 *
	 * @param string $cutoff Site-local date, MySQL format.
	 * @param int    $limit  Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function expired_batch( $cutoff, $limit ) {
		global $wpdb;

		$table = Schema::activity_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE created_at < %s ORDER BY created_at ASC, id ASC LIMIT %d", (string) $cutoff, max( 1, (int) $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes entries by id. Used only by the purge, and only after those
	 * exact entries have been written to their archive.
	 *
	 * @param int[] $ids Entry ids.
	 * @return int|false Rows deleted, or false on error.
	 */
	public function delete_ids( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = Schema::activity_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "DELETE FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL template, only the IN() placeholder count is dynamic.

		return $wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Entry counts per calendar month, newest first -- the month rail.
	 *
	 * @return array<int,array{month:string, label_year:int, label_month:int, total:int}>
	 */
	public function months() {
		global $wpdb;

		$table = Schema::activity_table();

		$rows = $wpdb->get_results(
			"SELECT DATE_FORMAT( created_at, '%Y-%m' ) AS month, COUNT(*) AS total
			 FROM {$table} GROUP BY month ORDER BY month DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			ARRAY_A
		);

		$months = array();

		foreach ( (array) $rows as $row ) {
			list( $year, $month ) = array_pad( explode( '-', (string) $row['month'] ), 2, '1' );

			$months[] = array(
				'month'       => (string) $row['month'],
				'label_year'  => (int) $year,
				'label_month' => (int) $month,
				'total'       => (int) $row['total'],
			);
		}

		return $months;
	}

	/**
	 * The distinct authors present in the log, for the "user" filter.
	 * Built from the log itself, not from the user table: somebody who no
	 * longer exists must still be filterable.
	 *
	 * @return array<int,array{user_id:int, user_name:string}>
	 */
	public function authors() {
		global $wpdb;

		$table = Schema::activity_table();

		$query = "SELECT user_id, MAX(user_name) AS user_name FROM {$table} GROUP BY user_id ORDER BY user_name ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL, the only interpolation is the table name from Database\Schema.

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$authors = array();

		foreach ( (array) $rows as $row ) {
			$authors[] = array(
				'user_id'   => (int) $row['user_id'],
				'user_name' => (string) $row['user_name'],
			);
		}

		return $authors;
	}

	/**
	 * The distinct lists present in the log, for the "list" filter. Also
	 * built from the log, so a deleted list stays selectable.
	 *
	 * @return array<int,array{list_id:int, list_name:string}>
	 */
	public function lists() {
		global $wpdb;

		$table = Schema::activity_table();

		$query = "SELECT list_id, MAX(list_name) AS list_name FROM {$table} WHERE list_id > 0 GROUP BY list_id ORDER BY list_name ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL, the only interpolation is the table name from Database\Schema.

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$lists = array();

		foreach ( (array) $rows as $row ) {
			$lists[] = array(
				'list_id'   => (int) $row['list_id'],
				'list_name' => (string) $row['list_name'],
			);
		}

		return $lists;
	}

	/**
	 * The date of the oldest entry still kept, so the screen can state what
	 * the log actually covers rather than just promising a window.
	 *
	 * @return string Empty string when the log is empty.
	 */
	public function oldest_date() {
		global $wpdb;

		$table = Schema::activity_table();

		return (string) $wpdb->get_var( "SELECT MIN(created_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Builds the WHERE clause and its bound parameters.
	 *
	 * @param array $filters Same shape as `find()`.
	 * @return array{0:string, 1:array}
	 */
	private function build_where( array $filters ) {
		$clauses = array();
		$params  = array();

		if ( ! empty( $filters['from'] ) ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $filters['from'] . ' 00:00:00';
		}

		if ( ! empty( $filters['to'] ) ) {
			$clauses[] = 'created_at <= %s';
			$params[]  = $filters['to'] . ' 23:59:59';
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$clauses[] = 'user_id = %d';
			$params[]  = (int) $filters['user_id'];
		}

		if ( ! empty( $filters['list_id'] ) ) {
			$clauses[] = 'list_id = %d';
			$params[]  = (int) $filters['list_id'];
		}

		if ( ! empty( $filters['action'] ) ) {
			$clauses[] = 'action = %s';
			$params[]  = (string) $filters['action'];
		}

		if ( ! empty( $filters['before_id'] ) ) {
			$clauses[] = 'id < %d';
			$params[]  = (int) $filters['before_id'];
		}

		return array(
			$clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			$params,
		);
	}
}
