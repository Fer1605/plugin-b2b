<?php
/**
 * One-time, resumable, idempotent background job that marks orders placed
 * before 1.4.0 as B2B, from the order-line audit meta they already carry
 * ( `_b2bpl_list_id`), which existed long before this release
 * introduced the order-level mark itself (spec.md US5/
 * point 7).
 *
 * Discovering *which* historical orders even have a B2B line requires one
 * read-only query against the line-item meta table: neither HPOS nor the
 * classic orders query can filter orders by a *line item's* meta (only by
 * order-level meta), so there is no CRUD-only way to find the candidates.
 * This is a narrow, explicitly documented exception to the plugin's
 * CRUD-only rule -- every *write* that follows still goes
 * exclusively through the `WC_Order` CRUD API, exactly like everywhere
 * else in this plugin.
 *
 * Processed in batches of `BATCH_SIZE` via Action Scheduler, one batch at a
 * time (a short-lived transient lock guards against two batches somehow
 * running concurrently), with the highest order id processed so far kept
 * as the resume cursor in `OPTION_CURSOR`. An order that already carries
 * the order-level mark (placed after upgrading, and therefore already
 * handled by `Order\OrderTypeAssigner`) is left untouched. Includes orders
 * in any status, trash included, so a restored order ends up correctly
 * marked too.
 *
 * @package B2BPriceLists\Order
 */

namespace B2BPriceLists\Order;

use B2BPriceLists\Support\Cache;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\Logger;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HistoricalOrdersBackfill
 */
class HistoricalOrdersBackfill {

	/**
	 * Action Scheduler hook processing one batch.
	 *
	 * @var string
	 */
	const HOOK = 'b2bpl_backfill_orders_batch';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	const AS_GROUP = 'b2b-price-lists';

	/**
	 * Option holding the highest order id already processed (the resume
	 * cursor).
	 *
	 * @var string
	 */
	const OPTION_CURSOR = 'b2bpl_backfill_cursor';

	/**
	 * Option holding the current run status (`STATUS_*`).
	 *
	 * @var string
	 */
	const OPTION_STATUS = 'b2bpl_backfill_status';

	/**
	 * Option holding running totals (`marked`/`skipped`/`failed`) for the
	 * progress notice.
	 *
	 * @var string
	 */
	const OPTION_TOTALS = 'b2bpl_backfill_totals';

	/**
	 * Option holding the plugin version this backfill was last scheduled
	 * for, so it runs again if a future release changes what it does, but
	 * never twice for the same version.
	 *
	 * @var string
	 */
	const OPTION_SCHEDULED_VERSION = 'b2bpl_backfill_scheduled_version';

	/**
	 * Transient guarding against two batches running at once.
	 *
	 * @var string
	 */
	const LOCK_TRANSIENT = 'b2bpl_backfill_lock';

	/**
	 * Transient throttling `maybe_recover()` to at most once per hour.
	 *
	 * @var string
	 */
	const RECOVERY_THROTTLE_TRANSIENT = 'b2bpl_backfill_recovery_throttle';

	/**
	 * Seconds a lock is held before it is considered stale.
	 *
	 * @var int
	 */
	const LOCK_TTL = 300;

	/**
	 * Orders processed per Action Scheduler run.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'running';
	const STATUS_DONE    = 'done';

	/**
	 * Registers the batch hook and the progress notice. Called once from
	 * `Plugin::init()`.
	 *
	 * Scheduling itself is deliberately never triggered directly from here
	 * (i.e. from `plugins_loaded`, which is when `Plugin::init()` runs):
	 * staging confirmed that calling `as_enqueue_async_action()` before
	 * Action Scheduler's own data store is ready silently creates nothing
	 * and returns `0` in Action Scheduler 3.x. `action_scheduler_init` is
	 * the action Action Scheduler itself fires once that store is ready, so
	 * scheduling always waits for it -- running immediately instead only
	 * when it has, exceptionally, already fired earlier in this same
	 * request.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'process_batch' ) );
		add_action( 'admin_notices', array( $this, 'render_progress_notice' ) );

		add_action( 'action_scheduler_init', array( __CLASS__, 'maybe_schedule' ) );

		if ( did_action( 'action_scheduler_init' ) ) {
			self::maybe_schedule();
		}

		// Self-healing watchdog: `maybe_schedule()` only ever runs once per
		// `scheduled_version`, so if its own enqueue silently failed in a
		// way this request could not detect (e.g. a host that clears the
		// Action Scheduler queue, or a race on `action_scheduler_init`
		// itself), nothing would ever retry without this. Throttled to at
		// most once per hour by `maybe_recover()` itself.
		add_action( 'admin_init', array( __CLASS__, 'maybe_recover' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'maybe_recover' ) );
	}

	/**
	 * Schedules the backfill once per plugin version that requires it.
	 * Safe to call multiple times: a no-op once already scheduled/completed
	 * for the running `B2BPL_VERSION`.
	 *
	 * `scheduled_version` -- which stops every future automatic retry from
	 * `register()` -- is only ever recorded once the action demonstrably
	 * exists in Action Scheduler (a non-zero id, or `as_has_scheduled_action()`
	 * confirming it independently): the staging bug this fixes was exactly
	 * `as_enqueue_async_action()` returning `0` while this method still
	 * recorded `scheduled_version` as if it had succeeded, permanently
	 * silencing every later retry.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$target_version = defined( 'B2BPL_VERSION' ) ? (string) B2BPL_VERSION : '';
		$scheduled_for  = get_option( self::OPTION_SCHEDULED_VERSION, '' );

		if ( '' !== $target_version && $scheduled_for === $target_version ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) && ! function_exists( 'as_schedule_single_action' ) ) {
			return; // Action Scheduler not available yet; tried again on the next `action_scheduler_init`.
		}

		if ( self::has_pending_or_running_action() ) {
			update_option( self::OPTION_SCHEDULED_VERSION, $target_version, false );
			return;
		}

		update_option( self::OPTION_STATUS, self::STATUS_PENDING, false );
		update_option( self::OPTION_CURSOR, 0, false );
		update_option( self::OPTION_TOTALS, self::normalize_totals( array() ), false );

		$action_id = 0;

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = (int) as_enqueue_async_action( self::HOOK, array(), self::AS_GROUP );
		} else {
			$action_id = (int) as_schedule_single_action( time(), self::HOOK, array(), self::AS_GROUP );
		}

		if ( $action_id <= 0 && ! self::has_pending_or_running_action() ) {
			// Enqueue did not actually create anything -- leave
			// `scheduled_version` unset so the very next `action_scheduler_init`
			// (or `maybe_recover()`) tries again, instead of recording a
			// false success that would silence every future retry.
			return;
		}

		update_option( self::OPTION_SCHEDULED_VERSION, $target_version, false );
	}

	/**
	 * Self-healing watchdog: re-enqueues the batch action when this
	 * version's backfill was already (believed) scheduled but Action
	 * Scheduler has no matching pending/running action and no batch is
	 * currently locked -- exactly the staging bug this fixes. Throttled to
	 * at most once per hour via a transient, and safe to call from more
	 * than one hook.
	 *
	 * @return void
	 */
	public static function maybe_recover() {
		if ( get_transient( self::RECOVERY_THROTTLE_TRANSIENT ) ) {
			return;
		}

		set_transient( self::RECOVERY_THROTTLE_TRANSIENT, 1, HOUR_IN_SECONDS );

		if ( ! function_exists( 'as_enqueue_async_action' ) && ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$target_version = defined( 'B2BPL_VERSION' ) ? (string) B2BPL_VERSION : '';
		$status         = (string) get_option( self::OPTION_STATUS, '' );
		$scheduled_for  = (string) get_option( self::OPTION_SCHEDULED_VERSION, '' );
		$locked         = (bool) get_transient( self::LOCK_TRANSIENT );
		$has_action     = self::has_pending_or_running_action();

		if ( ! self::should_enqueue( $status, $has_action, $locked, $scheduled_for, $target_version ) ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), self::AS_GROUP );
		} else {
			as_schedule_single_action( time(), self::HOOK, array(), self::AS_GROUP );
		}
	}

	/**
	 * Pure recovery decision, independent of any WordPress/Action Scheduler
	 * call, so it can be unit-tested directly against every combination
	 * `maybe_recover()` might read.
	 *
	 * Deliberately requires `$scheduled_version === $target_version`: a
	 * mismatch means this version's backfill has not been (successfully)
	 * initialized yet at all, which is `maybe_schedule()`'s own job, not a
	 * recovery -- `maybe_recover()` only ever re-enqueues work this exact
	 * version already believes it started.
	 *
	 * @param string $status            Current `OPTION_STATUS` value.
	 * @param bool   $has_action        Whether Action Scheduler already has
	 *                                  a pending/running action for `HOOK`.
	 * @param bool   $locked            Whether a batch is currently locked
	 *                                  (running right now, in this or
	 *                                  another request).
	 * @param string $scheduled_version Current `OPTION_SCHEDULED_VERSION` value.
	 * @param string $target_version    The running `B2BPL_VERSION`.
	 * @return bool
	 */
	public static function should_enqueue( $status, $has_action, $locked, $scheduled_version, $target_version ) {
		if ( self::STATUS_DONE === $status || '' === $status ) {
			return false;
		}

		if ( $scheduled_version !== $target_version ) {
			return false;
		}

		if ( $has_action || $locked ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether Action Scheduler currently has a pending or in-progress
	 * action for `HOOK`, using whichever of its own query functions this
	 * WooCommerce/Action Scheduler version provides.
	 *
	 * @return bool
	 */
	private static function has_pending_or_running_action() {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( self::HOOK, array(), self::AS_GROUP );
		}

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return (bool) as_next_scheduled_action( self::HOOK );
		}

		return false;
	}

	/**
	 * Processes one batch of candidate orders, then re-enqueues itself
	 * until no candidate remains. A failure processing one single order
	 * never stops the batch (`mark_order_safely()`); a failure anywhere
	 * else in the batch (e.g. the candidate query itself) never leaves the
	 * backfill permanently stuck either -- it is rescheduled with a short
	 * delay instead.
	 *
	 * @return void
	 */
	public function process_batch() {
		if ( ! $this->acquire_lock() ) {
			$this->reschedule( 60 );
			return;
		}

		try {
			$this->process_locked_batch();
		} catch ( Throwable $exception ) {
			Logger::error(
				'The B2B historical orders backfill batch stopped unexpectedly; it will retry shortly from its last saved cursor.',
				array( 'message' => $exception->getMessage() )
			);
			$this->reschedule( 60 );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * The actual batch work, run only while the lock is held. Split out of
	 * `process_batch()` so the exception handling wrapping it stays trivial
	 * to read.
	 *
	 * @return void
	 */
	private function process_locked_batch() {
		update_option( self::OPTION_STATUS, self::STATUS_RUNNING, false );

		$cursor     = (int) get_option( self::OPTION_CURSOR, 0 );
		$candidates = $this->fetch_candidate_order_ids( $cursor, self::BATCH_SIZE );

		if ( empty( $candidates ) ) {
			update_option( self::OPTION_STATUS, self::STATUS_DONE, false );

			return;
		}

		$totals = self::normalize_totals( get_option( self::OPTION_TOTALS, array() ) );

		foreach ( $candidates as $order_id ) {
			$result = $this->mark_order_safely( $order_id );
			$totals = self::apply_result_to_totals( $totals, $result );

			// The cursor advances past every candidate regardless of the
			// outcome -- including a failed one -- so a single order that
			// keeps throwing can never trap the backfill in an infinite
			// loop over the same id.
			$cursor = max( $cursor, $order_id );
		}

		update_option( self::OPTION_CURSOR, $cursor, false );
		update_option( self::OPTION_TOTALS, $totals, false );

		Cache::invalidate_b2b_orders_count();

		// Fewer candidates than a full batch means this was the last one --
		// mark done immediately rather than depending on a further
		// WP-Cron/Action Scheduler tick to discover an empty batch next.
		if ( count( $candidates ) < self::BATCH_SIZE ) {
			update_option( self::OPTION_STATUS, self::STATUS_DONE, false );

			return;
		}

		$this->reschedule( 0 );
	}

	/**
	 * Runs `mark_order()` for a single candidate, converting any exception
	 * into a `'failed'` result instead of letting it abort the whole batch.
	 *
	 * @param int $order_id Candidate order id.
	 * @return string `'marked'`, `'skipped'` or `'failed'`.
	 */
	private function mark_order_safely( $order_id ) {
		try {
			return $this->mark_order( $order_id );
		} catch ( Throwable $exception ) {
			Logger::error(
				'Could not mark a historical order as B2B; it is skipped and the backfill continues with the next one.',
				array(
					'order_id' => $order_id,
					'message'  => $exception->getMessage(),
				)
			);

			return 'failed';
		}
	}

	/**
	 * Normalizes a (possibly missing/corrupt) totals option value to the
	 * three counters this class tracks, defaulting each to `0`. Pure and
	 * unit-testable.
	 *
	 * @param mixed $totals Raw `OPTION_TOTALS` value.
	 * @return array{marked:int,skipped:int,failed:int}
	 */
	public static function normalize_totals( $totals ) {
		$totals = is_array( $totals ) ? $totals : array();

		return array_merge(
			array(
				'marked'  => 0,
				'skipped' => 0,
				'failed'  => 0,
			),
			$totals
		);
	}

	/**
	 * Applies one order's outcome to the running totals. Pure and
	 * unit-testable: an unrecognized `$result` is counted as `'failed'`
	 * rather than silently discarded, so a future new result value can
	 * never disappear from the progress notice unnoticed.
	 *
	 * @param array  $totals Already-normalized totals (`normalize_totals()`).
	 * @param string $result `'marked'`, `'skipped'` or `'failed'`.
	 * @return array
	 */
	public static function apply_result_to_totals( array $totals, $result ) {
		$totals = self::normalize_totals( $totals );
		$key    = isset( $totals[ $result ] ) ? $result : 'failed';

		++$totals[ $key ];

		return $totals;
	}

	/**
	 * Resolves which single list a historical order belongs to, from its
	 * lines' recorded `_b2bpl_list_id` values: the most frequent one; on a
	 * tie, whichever of the tied lists appears first among the lines
	 *. Pure and WordPress-independent so it can be
	 * unit-tested directly.
	 *
	 * @param array<int,int|null> $line_list_ids One entry per product line
	 *                                            item, in the order's own
	 *                                            line order; `null` for a
	 *                                            line with no recorded list.
	 * @return int|null The resolved list id, or `null` when every entry is
	 *                   `null` (no B2B line at all).
	 */
	public static function resolve_list_id_from_lines( array $line_list_ids ) {
		$counts      = array();
		$first_index = array();

		foreach ( $line_list_ids as $index => $list_id ) {
			if ( null === $list_id ) {
				continue;
			}

			$list_id = (int) $list_id;

			if ( ! isset( $counts[ $list_id ] ) ) {
				$counts[ $list_id ]      = 0;
				$first_index[ $list_id ] = $index;
			}

			++$counts[ $list_id ];
		}

		if ( empty( $counts ) ) {
			return null;
		}

		$max_count = max( $counts );
		$tied      = array();

		foreach ( $counts as $list_id => $count ) {
			if ( $count === $max_count ) {
				$tied[] = $list_id;
			}
		}

		if ( 1 === count( $tied ) ) {
			return $tied[0];
		}

		$best_list_id = null;
		$best_index   = null;

		foreach ( $tied as $list_id ) {
			$index = $first_index[ $list_id ];

			if ( null === $best_index || $index < $best_index ) {
				$best_index   = $index;
				$best_list_id = $list_id;
			}
		}

		return $best_list_id;
	}

	/**
	 * Marks a single candidate order, or determines it should be skipped.
	 *
	 * @param int $order_id Candidate order id.
	 * @return string `'marked'` or `'skipped'`.
	 */
	private function mark_order( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! ( $order instanceof WC_Order ) ) {
			return 'skipped';
		}

		if ( OrderTypeMeta::TYPE_B2B === $order->get_meta( OrderTypeMeta::META_ORDER_TYPE, true ) ) {
			return 'skipped'; // Already marked -- never re-decided or overwritten.
		}

		$line_list_ids   = array();
		$list_name_by_id = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$raw_list_id = $item->get_meta( OrderSnapshotBuilder::META_LIST_ID, true );
			$list_id     = ( '' === $raw_list_id || null === $raw_list_id ) ? null : (int) $raw_list_id;

			$line_list_ids[] = $list_id;

			if ( null !== $list_id && ! isset( $list_name_by_id[ $list_id ] ) ) {
				$name = (string) $item->get_meta( OrderSnapshotBuilder::META_LIST_NAME_SNAPSHOT, true );

				if ( '' !== $name ) {
					$list_name_by_id[ $list_id ] = $name;
				}
			}
		}

		$resolved_list_id = self::resolve_list_id_from_lines( $line_list_ids );

		if ( null === $resolved_list_id ) {
			return 'skipped'; // Defensive: the candidate query found a match, but no line resolved one on read.
		}

		$distinct_list_ids = array_unique(
			array_filter(
				$line_list_ids,
				static function ( $id ) {
					return null !== $id;
				}
			)
		);

		if ( count( $distinct_list_ids ) > 1 ) {
			Logger::error(
				'Historical order had lines recorded against more than one B2B list; used the most frequent one.',
				array(
					'order_id'         => $order_id,
					'resolved_list_id' => $resolved_list_id,
				)
			);
		}

		$list_name = isset( $list_name_by_id[ $resolved_list_id ] )
			? $list_name_by_id[ $resolved_list_id ]
			: sprintf( '#%d', $resolved_list_id );

		$b2b_lines = count(
			array_filter(
				$line_list_ids,
				static function ( $id ) use ( $resolved_list_id ) {
					return $id === $resolved_list_id;
				}
			)
		);

		$meta_values = array(
			OrderTypeMeta::META_ORDER_TYPE        => OrderTypeMeta::TYPE_B2B,
			OrderTypeMeta::META_ORDER_LIST_ID     => $resolved_list_id,
			OrderTypeMeta::META_ORDER_LIST_NAME   => $list_name,
			OrderTypeMeta::META_ORDER_B2B_LINES   => $b2b_lines,
			OrderTypeMeta::META_ORDER_TOTAL_LINES => count( $line_list_ids ),
		);

		foreach ( $meta_values as $meta_key => $meta_value ) {
			$order->update_meta_data( $meta_key, $meta_value );
		}

		$order->save();

		return 'marked';
	}

	/**
	 * The read-only candidate query ( documented
	 * exception): every historical order id with at least one line item
	 * carrying `_b2bpl_list_id`, above the resume cursor, oldest first.
	 * Every table name is a fixed, hard-coded `$wpdb` property -- never
	 * request input -- and the only variables are bound via `%d`/`%s`
	 * placeholders.
	 *
	 * @param int $after_id Resume cursor (exclusive lower bound).
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	private function fetch_candidate_order_ids( $after_id, $limit ) {
		global $wpdb;

		$items_table     = $wpdb->prefix . 'woocommerce_order_items';
		$item_meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

		$sql = "SELECT DISTINCT oi.order_id
			FROM {$item_meta_table} oim
			INNER JOIN {$items_table} oi ON oi.order_item_id = oim.order_item_id
			WHERE oim.meta_key = %s
			AND oi.order_id > %d
			ORDER BY oi.order_id ASC
			LIMIT %d";

		$ids = $wpdb->get_col(
			$wpdb->prepare( $sql, OrderSnapshotBuilder::META_LIST_ID, (int) $after_id, (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed table names built from $wpdb->prefix, never request input; every variable value is bound above.
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Attempts to acquire the run lock.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );

		return true;
	}

	/**
	 * Releases the run lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Re-enqueues the next batch, unless one is already pending.
	 *
	 * @param int $delay_seconds Delay before the next run, in seconds.
	 * @return void
	 */
	private function reschedule( $delay_seconds ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::HOOK ) ) {
			return;
		}

		as_schedule_single_action( time() + max( 0, (int) $delay_seconds ), self::HOOK, array(), self::AS_GROUP );
	}

	/**
	 * Discreet progress notice on this plugin's own screens and on the
	 * native orders screen while the backfill is running.
	 * Disappears on its own once the run reaches `STATUS_DONE`.
	 *
	 * @return void
	 */
	public function render_progress_notice() {
		$status = get_option( self::OPTION_STATUS, '' );

		if ( self::STATUS_DONE === $status || '' === $status ) {
			return;
		}

		if ( ! $this->on_relevant_screen() ) {
			return;
		}

		if ( ! Capabilities::current_user_can_manage() ) {
			return;
		}

		$totals = self::normalize_totals( get_option( self::OPTION_TOTALS, array() ) );
		$marked = (int) $totals['marked'];
		$failed = (int) $totals['failed'];

		$message = sprintf(
			/* translators: %d: number of historical orders already marked as B2B so far. */
			__( 'B2B Price Lists is updating historical orders in the background (%d marked so far). This notice disappears when it finishes.', 'b2b-price-lists' ),
			$marked
		);

		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of historical orders that could not be checked and were skipped. */
				__( '%d could not be checked and were skipped; see the error log for details.', 'b2b-price-lists' ),
				$failed
			);
		}

		echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Whether the current admin screen is either the native orders screen
	 * or one of this plugin's own screens.
	 *
	 * @return bool
	 */
	private function on_relevant_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		if ( OrdersListIntegration::orders_screen_id() === $screen->id ) {
			return true;
		}

		return false !== strpos( (string) $screen->id, 'b2b-price-lists' );
	}
}
