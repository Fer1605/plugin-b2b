<?php
/**
 * Turns activity log entries into the CSV an administrator downloads -- the
 * manual export of the Activity screen, and the lines stored in each monthly
 * archive.
 *
 * The text is exactly what the screen shows (`ActivityFormatter`), in the
 * site's language, with dates and times in the site's time zone.
 *
 * @package B2BPriceLists\Activity
 */

namespace B2BPriceLists\Activity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityExporter
 */
class ActivityExporter {

	/**
	 * Entries read per query while writing an export.
	 *
	 * @var int
	 */
	const BATCH = 500;

	/**
	 * Activity repository.
	 *
	 * @var ActivityRepository
	 */
	private $activity;

	/**
	 * Constructor.
	 *
	 * @param ActivityRepository|null $activity Optional, for tests.
	 */
	public function __construct( ?ActivityRepository $activity = null ) {
		$this->activity = $activity ?? new ActivityRepository();
	}

	/**
	 * One stored entry as the text the CSV needs.
	 *
	 * Dates go through `mysql2date()`, which reads the stored value as the
	 * site-local time it was saved in (`current_time( 'mysql' )`) instead of
	 * shifting it again by the site's offset.
	 *
	 * @param array $entry Raw log row.
	 * @return array See `ActivityCsv::cells()`.
	 */
	public static function formatted( array $entry ) {
		$created = (string) ( $entry['created_at'] ?? '' );
		$user    = (string) ( $entry['user_name'] ?? '' );

		return array(
			'date'    => mysql2date( 'Y-m-d', $created, false ),
			'time'    => mysql2date( 'H:i', $created, false ),
			'user'    => '' !== $user ? $user : __( 'System', 'b2b-price-lists' ),
			'user_id' => (int) ( $entry['user_id'] ?? 0 ),
			'action'  => ActivityFormatter::action_label( (string) ( $entry['action'] ?? '' ) ),
			'list'    => (string) ( $entry['list_name'] ?? '' ),
			'summary' => ActivityFormatter::summary( $entry ),
			'details' => ActivityFormatter::details( $entry ),
		);
	}

	/**
	 * CSV lines (no BOM, no header) for a set of raw entries, in the order
	 * given.
	 *
	 * @param array $entries Raw log rows.
	 * @return string
	 */
	public static function lines( array $entries ) {
		$csv = '';

		foreach ( $entries as $entry ) {
			$csv .= ActivityCsv::entry_line( self::formatted( (array) $entry ) );
		}

		return $csv;
	}

	/**
	 * Writes a complete export -- BOM, header and every matching entry,
	 * newest first -- to an open stream, a batch at a time so a large log
	 * never has to fit in memory.
	 *
	 * @param resource $handle  Writable stream.
	 * @param array    $filters `ActivityRepository` filters; empty for the whole log.
	 * @return int Entries written.
	 */
	public function write( $handle, array $filters ) {
		fwrite( $handle, ActivityCsv::BOM . ActivityCsv::header_line() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming a download to php://output, not a file on disk.

		$written   = 0;
		$before_id = 0;

		do {
			$batch = $this->activity->export_batch( $filters, self::BATCH, $before_id );
			$size  = count( $batch );

			if ( 0 === $size ) {
				break;
			}

			fwrite( $handle, self::lines( $batch ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			$written  += $size;
			$last      = end( $batch );
			$before_id = (int) $last['id'];
		} while ( self::BATCH === $size );

		return $written;
	}

	/**
	 * A readable, filesystem-safe file name that says what the file holds.
	 *
	 * @param array $filters `ActivityRepository` filters.
	 * @return string
	 */
	public static function filename( array $filters ) {
		$parts = array( 'historial-listas-b2b' );

		if ( ! empty( $filters['from'] ) || ! empty( $filters['to'] ) ) {
			$parts[] = ( $filters['from'] ?? '' ) . '_' . ( $filters['to'] ?? '' );
		}

		$parts[] = ( ! empty( $filters['user_id'] ) || ! empty( $filters['list_id'] ) || ! empty( $filters['action'] ) )
			? 'filtrado'
			: ( empty( $filters['from'] ) && empty( $filters['to'] ) ? 'completo' : '' );

		$parts[] = 'descargado-' . current_time( 'Y-m-d' );

		return sanitize_file_name( implode( '-', array_filter( $parts ) ) . '.csv' );
	}

	/**
	 * File name of a monthly archive.
	 *
	 * @param string $period Month, `YYYY-MM`.
	 * @return string
	 */
	public static function archive_filename( $period ) {
		return sanitize_file_name( 'historial-listas-b2b-archivo-' . $period . '.csv' );
	}

	/**
	 * Sends the headers of a CSV download.
	 *
	 * @param string $filename File name offered to the browser.
	 * @return void
	 */
	public static function send_headers( $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}
}
