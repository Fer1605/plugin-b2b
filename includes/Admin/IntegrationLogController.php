<?php
/**
 * Admin screen for "Integration -> Log" (T041-T046, US4): the paginated,
 * filterable list of every webhook request received, its full detail (JSON
 * received/response/items/timeline, all pretty-printed and highlighted by
 * `Webhook\JsonFormatter`), reprocessing failed items, the downloads, and
 * the "stuck queue" notice shared with the Settings tab.
 *
 * Also the router for the whole "Integration" submenu page
 * (`render_page()`, whitelist in `tabs()`/`resolve_tab()`):
 * `?page=b2b-price-lists-integration&tab=settings` delegates entirely to
 * `IntegrationSettingsController`, `&tab=guide` renders the read-only
 * "Usage guide" (`Views/integration-guide.php`), and everything else is
 * this controller's own log viewer. Every action that writes something or
 * sends a file runs from `handle_early_actions()`, hooked on
 * `load-{$hook_suffix}` by `Admin\Menu` -- before any admin markup is sent,
 * the same reason `Admin\ActivityController`/`Admin\ListsController` do the
 * same.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Repository\Webhook\EventsRepository;
use B2BPriceLists\Repository\Webhook\ItemsRepository;
use B2BPriceLists\Repository\Webhook\RequestsRepository;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Webhook\BatchQueue;
use B2BPriceLists\Webhook\IntegrationDownloads;
use B2BPriceLists\Webhook\RestController;
use B2BPriceLists\Webhook\Settings as WebhookSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IntegrationLogController
 */
class IntegrationLogController {

	/**
	 * The submenu slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'b2b-price-lists-integration';

	/**
	 * Nonce action of every download link.
	 *
	 * @var string
	 */
	const NONCE_DOWNLOAD = 'b2bpl_integration_download';

	/**
	 * Nonce action of the reprocess actions (one item, or a whole batch's
	 * failed items).
	 *
	 * @var string
	 */
	const NONCE_REPROCESS = 'b2bpl_integration_reprocess';

	/**
	 * Nonce action of the "retry the queue" button.
	 *
	 * @var string
	 */
	const NONCE_RETRY_QUEUE = 'b2bpl_integration_retry_queue';

	/**
	 * How long a request may sit `queued` before the "stuck queue" notice
	 * (T046) considers it worth surfacing.
	 *
	 * @var int
	 */
	const STUCK_QUEUE_MINUTES = 15;

	/**
	 * Rows per page of the log listing.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Shared instance.
	 *
	 * @var IntegrationLogController|null
	 */
	private static $instance = null;

	/**
	 * Requests repository.
	 *
	 * @var RequestsRepository
	 */
	private $requests;

	/**
	 * Items repository.
	 *
	 * @var ItemsRepository
	 */
	private $items;

	/**
	 * Events repository.
	 *
	 * @var EventsRepository
	 */
	private $events;

	/**
	 * Returns the shared instance.
	 *
	 * @return IntegrationLogController
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
		$this->requests = new RequestsRepository();
		$this->items    = new ItemsRepository();
		$this->events   = new EventsRepository();
	}

	/**
	 * Every `?tab=` value this screen's router (`render_page()`) recognizes.
	 * `logs` is both a valid value and the fallback for anything else
	 * (including no `tab` at all), exactly as `render_page()` already
	 * behaved before `guide` existed. Pure and unit-testable, so the
	 * whitelist itself -- not just one call site -- is covered by a test.
	 *
	 * @return string[]
	 */
	public static function tabs() {
		return array( 'logs', 'settings', 'guide' );
	}

	/**
	 * Resolves a raw `?tab=` query value into one this screen's router
	 * actually recognizes, defaulting to `logs` for anything else (including
	 * an empty string). Pure and unit-testable.
	 *
	 * @param string $requested_tab Raw, already `sanitize_key()`-ed value.
	 * @return string One of `tabs()`.
	 */
	public static function resolve_tab( $requested_tab ) {
		return in_array( $requested_tab, self::tabs(), true ) ? $requested_tab : 'logs';
	}

	/**
	 * Every status a webhook request row may hold, for the
	 * filter dropdown and the colored status label.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'received', 'rejected', 'queued', 'processing', 'completed', 'completed_with_errors', 'failed', 'paused', 'duplicate' );
	}

	/**
	 * Every `endpoint` value a webhook request row may hold
	 * (`Webhook\IngestLogger::resolve_endpoint_from_route()`), for the
	 * filter dropdown.
	 *
	 * @return string[]
	 */
	public static function endpoints() {
		return array( 'webhook', 'ping', 'batches', 'lists', 'users', 'catalog', 'unknown' );
	}

	/**
	 * A short, human sentence explaining a status, for a non-expert reading
	 * the log.
	 *
	 * @param string $status One of `statuses()`.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'received'              => __( 'Received', 'b2b-price-lists' ),
			'rejected'              => __( 'Rejected', 'b2b-price-lists' ),
			'queued'                => __( 'Queued', 'b2b-price-lists' ),
			'processing'            => __( 'Processing', 'b2b-price-lists' ),
			'completed'             => __( 'Completed', 'b2b-price-lists' ),
			'completed_with_errors' => __( 'Completed, with errors', 'b2b-price-lists' ),
			'failed'                => __( 'Failed', 'b2b-price-lists' ),
			'paused'                => __( 'Paused (webhook disabled)', 'b2b-price-lists' ),
			'duplicate'             => __( 'Duplicate (already processed)', 'b2b-price-lists' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * A short explanation of what a status means, for a non-expert opening
	 * the batch's detail (SC-005: "sin revisar logs del servidor").
	 *
	 * @param string $status One of `statuses()`.
	 * @return string
	 */
	public static function status_explanation( $status ) {
		$explanations = array(
			'received'              => __( 'The request arrived and is being checked.', 'b2b-price-lists' ),
			'rejected'              => __( 'The request was refused before anything was processed (bad credentials, malformed data, or over a limit). Nothing in it was applied.', 'b2b-price-lists' ),
			'queued'                => __( 'The request was accepted and is waiting its turn to be processed, in the order it arrived.', 'b2b-price-lists' ),
			'processing'            => __( 'The request is being processed right now.', 'b2b-price-lists' ),
			'completed'             => __( 'Every item in this batch finished successfully.', 'b2b-price-lists' ),
			'completed_with_errors' => __( 'The batch finished, but at least one item failed or partially failed. See the items below for detail.', 'b2b-price-lists' ),
			'failed'                => __( 'Processing this batch stopped unexpectedly. It can be reprocessed once the cause is fixed.', 'b2b-price-lists' ),
			'paused'                => __( 'The webhook was turned off while this batch was pending. It will resume automatically once it is turned back on.', 'b2b-price-lists' ),
			'duplicate'             => __( 'This request reused an idempotency key already used by an earlier, already-processed request; it was not processed again.', 'b2b-price-lists' ),
		);

		return isset( $explanations[ $status ] ) ? $explanations[ $status ] : '';
	}

	/**
	 * A short, human label for an `endpoint` value.
	 *
	 * @param string $endpoint One of `endpoints()`.
	 * @return string
	 */
	public static function endpoint_label( $endpoint ) {
		$labels = array(
			'webhook' => __( 'Webhook', 'b2b-price-lists' ),
			'ping'    => __( 'Connectivity check', 'b2b-price-lists' ),
			'lists'   => __( 'Lists and products', 'b2b-price-lists' ),
			'users'   => __( 'Users', 'b2b-price-lists' ),
			'catalog' => __( 'Catalogue products', 'b2b-price-lists' ),
			'batches' => __( 'Batch status query', 'b2b-price-lists' ),
			'unknown' => __( 'Unknown route', 'b2b-price-lists' ),
		);

		return isset( $labels[ $endpoint ] ) ? $labels[ $endpoint ] : (string) $endpoint;
	}

	/**
	 * A short, human label for one item's status.
	 *
	 * @param string $status `webhook_items.status`.
	 * @return string
	 */
	public static function item_status_label( $status ) {
		$labels = array(
			'pending' => __( 'Pending', 'b2b-price-lists' ),
			'success' => __( 'Successful', 'b2b-price-lists' ),
			'partial' => __( 'Partially applied', 'b2b-price-lists' ),
			'error'   => __( 'Failed', 'b2b-price-lists' ),
			'skipped' => __( 'Skipped', 'b2b-price-lists' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Builds a URL to the logs tab.
	 *
	 * Public (not private): views call it through `call_user_func()`.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public function logs_url( array $args = array() ) {
		$base = array(
			'page' => self::PAGE_SLUG,
			'tab'  => 'logs',
		);

		return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Builds a URL to the settings tab.
	 *
	 * @return string
	 */
	public function settings_url() {
		return $this->logs_url( array( 'tab' => 'settings' ) );
	}

	/**
	 * Builds a URL to the usage guide tab.
	 *
	 * Public (not private): the view calls it through `call_user_func()`,
	 * same as `logs_url()`/`settings_url()`.
	 *
	 * @return string
	 */
	public function guide_url() {
		return $this->logs_url( array( 'tab' => 'guide' ) );
	}

	/**
	 * URL to a single batch's detail screen.
	 *
	 * Public (not private): views (including `Admin\Views\activity`) call
	 * it through `call_user_func()`/directly, to link a webhook-sourced
	 * activity entry straight to its batch.
	 *
	 * @param int $request_id `webhook_requests.id`.
	 * @return string
	 */
	public function detail_url( $request_id ) {
		return $this->logs_url(
			array(
				'view' => 'detail',
				'id'   => (int) $request_id,
			)
		);
	}

	/**
	 * URL to a single batch's detail screen, looked up by its public
	 * `batch_id` (UUID) rather than the internal `webhook_requests.id`
	 * (T041/T042's own links use the numeric id directly; this exists
	 * specifically for `Admin\Views\activity`, which only stores `batch_id`
	 * on a webhook-sourced entry, per `b2bpl_activity.batch_id`).
	 *
	 * @param string $batch_id UUID.
	 * @return string
	 */
	public function detail_url_for_batch_id( $batch_id ) {
		return $this->logs_url(
			array(
				'view'  => 'detail',
				'batch' => (string) $batch_id,
			)
		);
	}

	/**
	 * Routes the "Integration" submenu page between its two tabs. Registered
	 * as the page callback in `Admin\Menu`.
	 *
	 * @return void
	 */
	public function render_page() {
		$tab = isset( $_GET['tab'] ) ? self::resolve_tab( sanitize_key( wp_unslash( $_GET['tab'] ) ) ) : 'logs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector.

		if ( 'settings' === $tab ) {
			IntegrationSettingsController::instance()->render();

			return;
		}

		if ( 'guide' === $tab ) {
			$this->render_guide();

			return;
		}

		$this->render_logs();
	}

	/**
	 * Renders either the log list or a single batch's detail, depending on
	 * `?view=`.
	 *
	 * @return void
	 */
	private function render_logs() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view/id selector.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

		if ( 'detail' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$request_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

			// `detail_url_for_batch_id()` (used by `Admin\Views\activity`) links
			// here by `batch_id` instead, since that is the only identifier a
			// webhook-sourced activity entry stores.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup, same as the `id` branch above.
			if ( 0 === $request_id && isset( $_GET['batch'] ) ) {
				$batch      = $this->requests->find_by_batch_id( sanitize_text_field( wp_unslash( $_GET['batch'] ) ) );
				$request_id = null === $batch ? 0 : (int) $batch['id'];
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$this->render_detail( $request_id );

			return;
		}

		$this->render_list();
	}

	/**
	 * Renders the "Usage guide" tab (non-expert-facing documentation of the
	 * webhook itself: URL, authentication, batch format, actions, limits and
	 * common responses). Same capability as the log tab
	 * (`Capabilities::current_user_can_manage()`) -- unlike the Settings
	 * tab, reading this guide never exposes a secret or lets anyone change
	 * the webhook's own configuration, so it needs no stricter check.
	 *
	 * Read-only: it never touches data, so it needs no nonce and no POST
	 * branch of its own (same reasoning as `ListsController::render_help()`).
	 *
	 * @return void
	 */
	private function render_guide() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		View::render(
			'integration-guide',
			array(
				'logs_url'                  => array( $this, 'logs_url' ),
				'webhook_url'               => RestController::url(),
				'limits'                    => WebhookSettings::fixed_limits(),
				'can_configure_integration' => Capabilities::can_configure_integration(),
			)
		);
	}

	/**
	 * Renders the paginated, filterable request list.
	 *
	 * @return void
	 */
	private function render_list() {
		$filters = $this->read_filters();
		$result  = $this->requests->search( $filters, self::PER_PAGE, (int) $filters['page'] );
		$stuck   = $this->requests->find_stuck_queued( self::STUCK_QUEUE_MINUTES );

		View::render(
			'integration-log',
			array(
				'filters'                  => $filters,
				'rows'                     => $result['rows'],
				'total'                    => $result['total'],
				'page'                     => $result['page'],
				'pages'                    => $result['pages'],
				'statuses'                 => self::statuses(),
				'endpoints'                => self::endpoints(),
				'stuck_count'              => count( $stuck ),
				'can_export'               => Capabilities::current_user_can_export_integration_log(),
				'detail_url'               => array( $this, 'detail_url' ),
				'logs_url'                 => array( $this, 'logs_url' ),
				'retry_queue_nonce_action' => self::NONCE_RETRY_QUEUE,
				'retry_queue_action_url'   => $this->logs_url(),
			)
		);
	}

	/**
	 * Renders one batch's full detail: metadata, JSON received (formatted
	 * and original), response, items, and timeline.
	 *
	 * @param int $request_id `webhook_requests.id`.
	 * @return void
	 */
	private function render_detail( $request_id ) {
		$row = $request_id > 0 ? $this->requests->find( $request_id ) : null;

		if ( null === $row ) {
			wp_die(
				esc_html__( 'That batch does not exist.', 'b2b-price-lists' ),
				esc_html__( 'Not found', 'b2b-price-lists' ),
				array( 'response' => 404 )
			);
		}

		$items  = $this->items->get_by_request( $request_id );
		$events = $this->events->get_by_request( $request_id );

		View::render(
			'integration-log-detail',
			array(
				'row'                          => $row,
				'items'                        => $items,
				'events'                       => $events,
				'can_export'                   => Capabilities::current_user_can_export_integration_log(),
				'can_reprocess_batch'          => self::has_reprocessable_items( $items ),
				'logs_url'                     => array( $this, 'logs_url' ),
				'download_url'                 => function ( $file ) use ( $request_id ) {
					return $this->build_download_url( $request_id, $file );
				},
				'reprocess_item_nonce_action'  => self::NONCE_REPROCESS,
				'reprocess_batch_nonce_action' => self::NONCE_REPROCESS,
				'has_zip'                      => class_exists( 'ZipArchive' ),
			)
		);
	}

	/**
	 * A download URL for one batch's detail screen (nonce included).
	 *
	 * @param int    $request_id Request id.
	 * @param string $file       One of `send_download()`'s recognized values.
	 * @return string
	 */
	private function build_download_url( $request_id, $file ) {
		return wp_nonce_url(
			$this->logs_url(
				array(
					'view'           => 'detail',
					'id'             => (int) $request_id,
					'b2bpl_download' => (string) $file,
				)
			),
			self::NONCE_DOWNLOAD
		);
	}

	/**
	 * Whether at least one item is `error`/`partial` (T043: only then is
	 * "reprocess this batch's failed items" offered).
	 *
	 * @param array<int,array<string,mixed>> $items Item rows.
	 * @return bool
	 */
	public static function has_reprocessable_items( array $items ) {
		foreach ( $items as $item ) {
			if ( self::is_reprocessable_status( (string) ( $item['status'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a single item status may be reprocessed (T043: `error` or
	 * `partial`). Pure and unit-testable.
	 *
	 * @param string $status Item status.
	 * @return bool
	 */
	public static function is_reprocessable_status( $status ) {
		return in_array( $status, array( 'error', 'partial' ), true );
	}

	/**
	 * Whether a request's own status must be pushed back to `queued`
	 * before a reprocess attempt can be picked up again by
	 * `Webhook\BatchProcessor` (which only ever looks at
	 * `queued`/`processing` rows). Pure and unit-testable.
	 *
	 * @param string $status Request status.
	 * @return bool
	 */
	public static function needs_requeue( $status ) {
		return in_array( $status, array( 'completed', 'completed_with_errors', 'failed' ), true );
	}

	/**
	 * Reads and sanitizes the log list's filters from the query string.
	 *
	 * @return array<string,mixed>
	 */
	private function read_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen; every value below is sanitized and only ever used as a query filter.
		$from     = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to       = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$endpoint = isset( $_GET['endpoint'] ) ? sanitize_key( wp_unslash( $_GET['endpoint'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		$filters = array(
			'from'         => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '',
			'to'           => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '',
			'endpoint'     => in_array( $endpoint, self::endpoints(), true ) ? $endpoint : '',
			'status'       => in_array( $status, self::statuses(), true ) ? $status : '',
			'http_status'  => isset( $_GET['http_status'] ) ? absint( $_GET['http_status'] ) : 0,
			'auth_user_id' => isset( $_GET['auth_user_id'] ) ? absint( $_GET['auth_user_id'] ) : 0,
			'text'         => isset( $_GET['text'] ) ? sanitize_text_field( wp_unslash( $_GET['text'] ) ) : '',
			'page'         => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $filters;
	}

	/**
	 * Serves every early action for this screen: settings save, downloads,
	 * reprocessing, and the "retry the queue" button. Hooked on
	 * `load-{$hook_suffix}` by `Admin\Menu`, which runs before any admin
	 * markup is sent -- the only point a redirect or a file download can
	 * still happen.
	 *
	 * @return void
	 */
	public function handle_early_actions() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'logs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only used to route to a handler; each handler verifies its own nonce below.
		$post_action = isset( $_POST['b2bpl_action'] ) ? sanitize_key( wp_unslash( $_POST['b2bpl_action'] ) ) : '';

		if ( 'save-integration-settings' === $post_action ) {
			IntegrationSettingsController::instance()->handle_save();

			return;
		}

		if ( 'save-webhook-username' === $post_action ) {
			IntegrationSettingsController::instance()->handle_save_username();

			return;
		}

		if ( 'generate-webhook-password' === $post_action ) {
			IntegrationSettingsController::instance()->handle_generate_password();

			return;
		}

		if ( 'set-webhook-password' === $post_action ) {
			IntegrationSettingsController::instance()->handle_set_password();

			return;
		}

		if ( 'reprocess-item' === $post_action ) {
			$this->handle_reprocess_item();

			return;
		}

		if ( 'reprocess-batch' === $post_action ) {
			$this->handle_reprocess_batch();

			return;
		}

		if ( 'retry-queue' === $post_action ) {
			$this->handle_retry_queue();

			return;
		}

		if ( 'logs' === $tab ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only routes the request; the download branch verifies its own nonce below.
			$download = isset( $_GET['b2bpl_download'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_download'] ) ) : '';

			if ( '' !== $download ) {
				$this->handle_download( $download );
			}
		}
	}

	/**
	 * Marks the given items `pending` again and (re)queues their batch
	 * (T043).
	 *
	 * @param array<string,mixed> $request  Request row.
	 * @param int[]               $item_ids Item ids to reprocess.
	 * @return void
	 */
	private function requeue_items( array $request, array $item_ids ) {
		$actor      = wp_get_current_user();
		$actor_name = ( $actor && $actor->exists() ) ? (string) $actor->display_name : '';

		foreach ( $item_ids as $item_id ) {
			$this->items->update( (int) $item_id, array( 'status' => 'pending' ) );

			$this->events->insert(
				array(
					'request_id'    => (int) $request['id'],
					'item_id'       => (int) $item_id,
					'level'         => 'info',
					'code'          => 'reprocess_requested',
					'message'       => sprintf(
						/* translators: %s: administrator's display name. */
						__( 'Reprocessing requested by %s.', 'b2b-price-lists' ),
						'' !== $actor_name ? $actor_name : __( 'an administrator', 'b2b-price-lists' )
					),
					'actor_user_id' => get_current_user_id(),
				)
			);
		}

		if ( self::needs_requeue( (string) $request['status'] ) ) {
			$this->requests->update( (int) $request['id'], array( 'status' => 'queued' ) );
		}

		/** This action is documented in `Webhook\RestController::handle_batch_submission()`. */
		do_action( 'b2bpl_webhook_batch_queued', (string) $request['batch_id'] );
	}

	/**
	 * `b2bpl_action=reprocess-item`.
	 *
	 * @return void
	 */
	private function handle_reprocess_item() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		check_admin_referer( self::NONCE_REPROCESS );

		$item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$item    = $item_id > 0 ? $this->items->find( $item_id ) : null;

		if ( null === $item ) {
			wp_die( esc_html__( 'That item does not exist.', 'b2b-price-lists' ), '', array( 'response' => 404 ) );
		}

		$request = $this->requests->find( (int) $item['request_id'] );

		if ( null === $request ) {
			wp_die( esc_html__( 'That batch does not exist.', 'b2b-price-lists' ), '', array( 'response' => 404 ) );
		}

		if ( self::is_reprocessable_status( (string) $item['status'] ) ) {
			$this->requeue_items( $request, array( (int) $item['id'] ) );
		}

		wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'reprocessing', $this->detail_url( (int) $request['id'] ) ) );
		exit;
	}

	/**
	 * `b2bpl_action=reprocess-batch` ("reprocess every failed item of this batch").
	 *
	 * @return void
	 */
	private function handle_reprocess_batch() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		check_admin_referer( self::NONCE_REPROCESS );

		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$request    = $request_id > 0 ? $this->requests->find( $request_id ) : null;

		if ( null === $request ) {
			wp_die( esc_html__( 'That batch does not exist.', 'b2b-price-lists' ), '', array( 'response' => 404 ) );
		}

		$target_ids = array();

		foreach ( $this->items->get_by_request( $request_id ) as $item ) {
			if ( self::is_reprocessable_status( (string) $item['status'] ) ) {
				$target_ids[] = (int) $item['id'];
			}
		}

		if ( ! empty( $target_ids ) ) {
			$this->requeue_items( $request, $target_ids );
		}

		wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'reprocessing', $this->detail_url( $request_id ) ) );
		exit;
	}

	/**
	 * `b2bpl_action=retry-queue` (T046): forces an immediate
	 * `Webhook\BatchQueue` recovery pass.
	 *
	 * @return void
	 */
	private function handle_retry_queue() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		check_admin_referer( self::NONCE_RETRY_QUEUE );

		BatchQueue::force_recover_queue();

		$redirect_to = isset( $_POST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) ) : $this->logs_url();

		wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'queue-retried', $redirect_to ) );
		exit;
	}

	/**
	 * Serves one download from a batch's detail screen (T044). Every
	 * download requires `manage_options`
	 * (`Capabilities::current_user_can_export_integration_log()`) -- viewing
	 * the same content on screen only requires `manage_b2b_price_lists`.
	 *
	 * @param string $file One of: `received-original`, `received-formatted`,
	 *                     `response`, `items`, `events-json`, `events-txt`, `zip`.
	 * @return void
	 */
	private function handle_download( $file ) {
		if ( ! Capabilities::current_user_can_export_integration_log() ) {
			wp_die(
				esc_html__( 'Only site administrators can download the integration log.', 'b2b-price-lists' ),
				esc_html__( 'Not allowed', 'b2b-price-lists' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE_DOWNLOAD );

		$request_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above via check_admin_referer().
		$row        = $request_id > 0 ? $this->requests->find( $request_id ) : null;

		if ( null === $row ) {
			wp_die( esc_html__( 'That batch does not exist.', 'b2b-price-lists' ), '', array( 'response' => 404 ) );
		}

		( new IntegrationDownloads( $this->items, $this->events ) )->send( $row, $file );
	}
}
