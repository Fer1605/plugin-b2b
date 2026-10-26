<?php
/**
 * Monthly archives of the activity log: what the retention window removed
 * from the live log, kept as CSV text, one row per calendar month.
 *
 * @package B2BPriceLists\Activity
 */

namespace B2BPriceLists\Activity;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityArchiveRepository
 */
class ActivityArchiveRepository {

	/**
	 * Appends entries to the archive of their month, creating it on the
	 * first write. The purge runs daily, so one month's archive is filled a
	 * little at a time: new CSV lines go after the existing ones, and the
	 * count and the covered period grow with them.
	 *
	 * @param string $period   Month, `YYYY-MM`.
	 * @param string $content  CSV lines, without BOM or header.
	 * @param int    $entries  Number of entries in `$content`.
	 * @param string $first_at Oldest entry date in `$content` (site-local, MySQL format).
	 * @param string $last_at  Newest entry date in `$content` (site-local, MySQL format).
	 * @return bool Whether the write succeeded.
	 */
	public function append( $period, $content, $entries, $first_at, $last_at ) {
		global $wpdb;

		$table = Schema::activity_archives_table();
		$now   = current_time( 'mysql' );

		$sql = "INSERT INTO {$table} (period, entries, first_at, last_at, created_at, updated_at, content) VALUES (%s, %d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE content = CONCAT(content, VALUES(content)), entries = entries + VALUES(entries), first_at = LEAST(first_at, VALUES(first_at)), last_at = GREATEST(last_at, VALUES(last_at)), updated_at = VALUES(updated_at)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL, the only interpolation is the table name from Database\Schema.

		$result = $wpdb->query( $wpdb->prepare( $sql, (string) $period, (int) $entries, (string) $first_at, (string) $last_at, $now, $now, (string) $content ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return false !== $result;
	}

	/**
	 * Every archive, newest month first, without its content.
	 *
	 * @return array<int,array{id:int, period:string, entries:int, first_at:string, last_at:string, updated_at:string, bytes:int}>
	 */
	public function all() {
		global $wpdb;

		$table = Schema::activity_archives_table();
		$query = "SELECT id, period, entries, first_at, last_at, updated_at, LENGTH(content) AS bytes FROM {$table} ORDER BY period DESC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL, the only interpolation is the table name from Database\Schema.

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$archives = array();

		foreach ( (array) $rows as $row ) {
			$archives[] = array(
				'id'         => (int) $row['id'],
				'period'     => (string) $row['period'],
				'entries'    => (int) $row['entries'],
				'first_at'   => (string) $row['first_at'],
				'last_at'    => (string) $row['last_at'],
				'updated_at' => (string) $row['updated_at'],
				'bytes'      => (int) $row['bytes'],
			);
		}

		return $archives;
	}

	/**
	 * One archive, content included.
	 *
	 * @param int $id Archive id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Schema::activity_archives_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
