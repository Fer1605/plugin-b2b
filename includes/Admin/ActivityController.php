<?php
/**
 * Admin screen for the activity log: "WooCommerce -> B2B Price Lists ->
 * Activity".
 *
 * It never changes anything. Besides rendering the history it serves two
 * downloads -- the CSV export of what the screen is showing, and the monthly
 * archives -- both restricted to site administrators and protected by a
 * nonce. Filters travel in the query string so a filtered view can be
 * bookmarked or shared with a colleague.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Activity\ActivityArchiveRepository;
use B2BPriceLists\Activity\ActivityCsv;
use B2BPriceLists\Activity\ActivityExporter;
use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Activity\ActivityRepository;
use B2BPriceLists\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityController
 */
class ActivityController {

	/**
	 * The submenu slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'b2b-price-lists-activity';

	/**
	 * Nonce action of the CSV export.
	 *
	 * @var string
	 */
	const NONCE_EXPORT = 'b2bpl_activity_export';

	/**
	 * Nonce action of an archive download.
	 *
	 * @var string
	 */
	const NONCE_ARCHIVE = 'b2bpl_activity_archive';

	/**
	 * Shared instance.
	 *
	 * @var ActivityController|null
	 */
	private static $instance = null;

	/**
	 * Activity repository.
	 *
	 * @var ActivityRepository
	 */
	private $activity;

	/**
	 * Archive repository.
	 *
	 * @var ActivityArchiveRepository
	 */
	private $archives;

	/**
	 * Returns the shared instance.
	 *
	 * @return ActivityController
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->activity = new ActivityRepository();
		$this->archives = new ActivityArchiveRepository();
	}

	/**
	 * Builds a URL to this screen.
	 *
	 * Public (not private): the view calls it through `call_user_func()`
	 * from outside this class's scope.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public function page_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of the CSV export of exactly what the screen is showing: the
	 * current filters if there are any, the whole log if there are none.
	 *
	 * @param array $filters Filters as read by `read_filters()`.
	 * @return string
	 */
	public function export_url( array $filters ) {
		$args = array_filter(
			array(
				'month'       => $filters['month'] ?? '',
				'from'        => $filters['from'] ?? '',
				'to'          => $filters['to'] ?? '',
				'user_id'     => $filters['user_id'] ?? 0,
				'list_id'     => $filters['list_id'] ?? 0,
				'b2bpl_event' => $filters['action'] ?? '',
			)
		);

		$args['b2bpl_download'] = 'export';

		return wp_nonce_url( $this->page_url( $args ), self::NONCE_EXPORT );
	}

	/**
	 * URL to download one monthly archive.
	 *
	 * Public (not private): the view calls it through `call_user_func()`.
	 *
	 * @param int $archive_id Archive id.
	 * @return string
	 */
	public function archive_url( $archive_id ) {
		return wp_nonce_url(
			$this->page_url(
				array(
					'b2bpl_download' => 'archive',
					'archive'        => (int) $archive_id,
				)
			),
			self::NONCE_ARCHIVE
		);
	}

	/**
	 * Serves the downloads. Hooked on `load-{$hook_suffix}` of this screen,
	 * which runs before WordPress prints any admin markup -- the only point
	 * where a file can still be sent.
	 *
	 * @return void
	 */
	public function handle_early_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only routes the request; each branch verifies its own nonce below.
		$download = isset( $_GET['b2bpl_download'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_download'] ) ) : '';

		if ( '' === $download ) {
			return;
		}

		if ( ! Capabilities::current_user_can_export_activity() ) {
			wp_die(
				esc_html__( 'Only site administrators can download the activity log.', 'b2b-price-lists' ),
				esc_html__( 'Not allowed', 'b2b-price-lists' ),
				array( 'response' => 403 )
			);
		}

		if ( 'export' === $download ) {
			check_admin_referer( self::NONCE_EXPORT );
			$this->send_export();
		}

		if ( 'archive' === $download ) {
			check_admin_referer( self::NONCE_ARCHIVE );
			$this->send_archive( isset( $_GET['archive'] ) ? absint( $_GET['archive'] ) : 0 );
		}
	}

	/**
	 * Streams the CSV export and ends the request.
	 *
	 * @return void
	 */
	private function send_export() {
		$filters = $this->read_filters();

		unset( $filters['page'], $filters['month'] );

		ActivityExporter::send_headers( ActivityExporter::filename( $filters ) );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download, not a file on disk.

		( new ActivityExporter( $this->activity ) )->write( $output, $filters );

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Streams one monthly archive and ends the request.
	 *
	 * @param int $archive_id Archive id.
	 * @return void
	 */
	private function send_archive( $archive_id ) {
		$archive = $archive_id > 0 ? $this->archives->find( $archive_id ) : null;

		if ( ! $archive ) {
			wp_die(
				esc_html__( 'That archive does not exist.', 'b2b-price-lists' ),
				esc_html__( 'Not found', 'b2b-price-lists' ),
				array( 'response' => 404 )
			);
		}

		ActivityExporter::send_headers( ActivityExporter::archive_filename( (string) $archive['period'] ) );

		// The header is written at download time, in the site's current
		// language; the lines were formatted when they were archived.
		echo ActivityCsv::BOM . ActivityCsv::header_line() . (string) $archive['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file body, not HTML; every cell was encoded by ActivityCsv::cell().
		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		$filters   = $this->read_filters();
		$result    = $this->activity->find( $filters );
		$oldest    = $this->activity->oldest_date();
		$retention = ActivityLog::retention_months();

		View::render(
			'activity',
			array(
				'filters'          => $filters,
				'entries'          => $result['entries'],
				'total'            => $result['total'],
				'page'             => $result['page'],
				'pages'            => $result['pages'],
				'months'           => $this->activity->months(),
				'authors'          => $this->activity->authors(),
				'lists'            => $this->activity->lists(),
				'actions'          => ActivityLog::actions(),
				'oldest'           => $oldest,
				// When the oldest entry kept today will reach the retention
				// limit and move to its archive -- so an empty archives box
				// can say when it will stop being empty.
				'next_archive_at'  => '' !== $oldest ? gmdate( 'Y-m-d H:i:s', strtotime( '+' . $retention . ' months', (int) strtotime( $oldest ) ) ) : '',
				'retention_months' => $retention,
				'can_export'       => Capabilities::current_user_can_export_activity(),
				'export_url'       => $this->export_url( $filters ),
				'archives'         => $this->archives->all(),
				'archive_url'      => array( $this, 'archive_url' ),
				'page_url'         => array( $this, 'page_url' ),
			)
		);
	}

	/**
	 * Reads and sanitizes the filters from the query string.
	 *
	 * A `month` of `2026-09` is expanded into a from/to pair rather than
	 * being a filter of its own: the month rail and the date range are then
	 * the same mechanism, and picking a month followed by narrowing to a few
	 * days behaves the way anyone would expect.
	 *
	 * @return array<string,mixed>
	 */
	private function read_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen; every value below is sanitized and only ever used as a query filter.
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		$filters = array(
			'month'   => preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : '',
			'from'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '',
			'to'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '',
			'user_id' => isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0,
			'list_id' => isset( $_GET['list_id'] ) ? absint( $_GET['list_id'] ) : 0,
			'action'  => isset( $_GET['b2bpl_event'] ) ? sanitize_text_field( wp_unslash( $_GET['b2bpl_event'] ) ) : '',
			'page'    => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $filters['action'], ActivityLog::actions(), true ) ) {
			$filters['action'] = '';
		}

		// An explicit date range always wins over the month chip, so
		// narrowing inside a month keeps working.
		if ( '' !== $filters['month'] && '' === $filters['from'] && '' === $filters['to'] ) {
			$start = $filters['month'] . '-01';

			$filters['from'] = $start;
			$filters['to']   = gmdate( 'Y-m-t', (int) strtotime( $start ) );
		}

		return $filters;
	}
}
