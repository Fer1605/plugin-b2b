<?php
/**
 * The CSV format of the activity log: shared by the manual export and the
 * monthly archives, so both files always have the same columns and read the
 * same way.
 *
 * Pure: it receives entries that are already formatted as text (dates,
 * sentences, detail lines) and only decides how that text becomes a CSV
 * that Excel, Google Sheets and Numbers open correctly. No database, no
 * WordPress state, which is what lets it be unit-tested on its own.
 *
 * @package B2BPriceLists\Activity
 */

namespace B2BPriceLists\Activity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityCsv
 */
class ActivityCsv {

	/**
	 * UTF-8 byte order mark. Without it Excel reads the file as ANSI and
	 * turns every accent and "ñ" into garbage.
	 *
	 * @var string
	 */
	const BOM = "\xEF\xBB\xBF";

	/**
	 * Line ending between rows. CRLF is what RFC 4180 specifies and what
	 * every spreadsheet expects; line breaks *inside* a cell stay as LF.
	 *
	 * @var string
	 */
	const EOL = "\r\n";

	/**
	 * First characters that make a spreadsheet treat a cell as a formula.
	 * List and product names are typed by people, so a name like
	 * `=HYPERLINK(...)` must never run when the file is opened.
	 *
	 * @var string
	 */
	const FORMULA_TRIGGERS = "=+-@\t";

	/**
	 * The column titles, in order.
	 *
	 * @return string[]
	 */
	public static function header() {
		return array(
			__( 'Date', 'b2b-price-lists' ),
			__( 'Time', 'b2b-price-lists' ),
			__( 'User', 'b2b-price-lists' ),
			__( 'User ID', 'b2b-price-lists' ),
			__( 'Action', 'b2b-price-lists' ),
			__( 'Price list', 'b2b-price-lists' ),
			__( 'What happened', 'b2b-price-lists' ),
			__( 'Details', 'b2b-price-lists' ),
		);
	}

	/**
	 * The cells of one entry, in the same order as `header()`.
	 *
	 * @param array $entry {
	 *     One entry, already formatted as text.
	 *
	 *     @type string   $date    Date.
	 *     @type string   $time    Time.
	 *     @type string   $user    Who did it.
	 *     @type int      $user_id Their user id, 0 when none.
	 *     @type string   $action  Action label.
	 *     @type string   $list    Price list name, or empty.
	 *     @type string   $summary The one-sentence description.
	 *     @type string[] $details One line per individual change.
	 * }
	 * @return string[]
	 */
	public static function cells( array $entry ) {
		$user_id = (int) ( $entry['user_id'] ?? 0 );

		return array(
			(string) ( $entry['date'] ?? '' ),
			(string) ( $entry['time'] ?? '' ),
			(string) ( $entry['user'] ?? '' ),
			$user_id > 0 ? (string) $user_id : '',
			(string) ( $entry['action'] ?? '' ),
			(string) ( $entry['list'] ?? '' ),
			(string) ( $entry['summary'] ?? '' ),
			// One cell, one change per line: the row stays one action, and the
			// spreadsheet shows every change underneath the other.
			implode( "\n", array_map( 'strval', (array) ( $entry['details'] ?? array() ) ) ),
		);
	}

	/**
	 * One CSV line, row ending included.
	 *
	 * @param string[] $cells Cell values.
	 * @return string
	 */
	public static function line( array $cells ) {
		return implode( ',', array_map( array( self::class, 'cell' ), $cells ) ) . self::EOL;
	}

	/**
	 * One CSV line for a formatted entry.
	 *
	 * @param array $entry See `cells()`.
	 * @return string
	 */
	public static function entry_line( array $entry ) {
		return self::line( self::cells( $entry ) );
	}

	/**
	 * The header line.
	 *
	 * @return string
	 */
	public static function header_line() {
		return self::line( self::header() );
	}

	/**
	 * Encodes one cell: always quoted, inner quotes doubled, line breaks
	 * normalised to LF, and a leading apostrophe when the value would
	 * otherwise start a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );

		if ( '' !== $value && false !== strpos( self::FORMULA_TRIGGERS, $value[0] ) ) {
			$value = "'" . $value;
		}

		return '"' . str_replace( '"', '""', $value ) . '"';
	}
}
