<?php
/**
 * Action Scheduler wiring for asynchronous webhook batch processing (R4/
 *): enqueues a validated batch, holds the FIFO
 * single-batch-at-a-time lock `Webhook\BatchProcessor` acquires before doing
 * any work, and a self-healing watchdog that re-enqueues a batch left
 * `queued` with no matching Action Scheduler action -- the same
 * `action_scheduler_init`/`admin_init` pattern already used by
 * `Order\HistoricalOrdersBackfill::maybe_recover()`.
 *
 * `as_enqueue_async_action()` is never called before Action Scheduler's own
 * `action_scheduler_init` has fired in the current request (staging finding
 * behind `HistoricalOrdersBackfill`'s own docblock): calling it earlier
 * silently creates nothing and returns `0`. A batch is therefore left
 * `queued` -- its row's own default state, requiring no extra write here --
 * whenever that has not happened yet, and the watchdog picks it up on the
 * very next request that does reach `action_scheduler_init`/`admin_init`.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Repository\Webhook\EventsRepository;
use B2BPriceLists\Repository\Webhook\RequestsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BatchQueue
 */
class BatchQueue {

	/**
	 * Action Scheduler hook that processes one batch run.
	 *
	 * @var string
	 */
	const HOOK = 'b2bpl_webhook_process_batch';

	/**
	 * Action Scheduler group, mirroring `Order\HistoricalOrdersBackfill::AS_GROUP`'s
	 * naming (kept separate from it so the two features' actions can be
	 * told apart on `Tools -> Scheduled Actions`).
	 *
	 * @var string
	 */
	const AS_GROUP = 'b2b-price-lists-webhook';

	/**
	 * Transient holding the FIFO single-batch-at-a-time lock (a
	 * transient, never a permanent option, so a run that dies mid-batch --
	 * a fatal error, a host killing a long request -- self-heals once the
	 * TTL below expires, instead of jamming every future batch forever).
	 *
	 * @var string
	 */
	const LOCK_TRANSIENT = 'b2bpl_webhook_queue_lock';

	/**
	 * Seconds a lock is held before it is considered stale.
	 *
	 * @var int
	 */
	const LOCK_TTL = 300;

	/**
	 * Transient throttling `maybe_recover_queue()`.
	 *
	 * @var string
	 */
	const RECOVERY_THROTTLE_TRANSIENT = 'b2bpl_webhook_queue_recovery_throttle';

	/**
	 * Seconds between two `maybe_recover_queue()` passes. Deliberately much
	 * shorter than `HistoricalOrdersBackfill`'s hourly throttle: a stuck
	 * webhook batch has to self-heal well within SC-003's 5-minute budget,
	 * not "eventually".
	 *
	 * @var int
	 */
	const RECOVERY_THROTTLE_SECONDS = 60;

	/**
	 * Registers every hook. Called once from `Plugin::init()`, unconditionally
	 * -- exactly like `Order\HistoricalOrdersBackfill`, scheduling itself
	 * only ever happens once Action Scheduler is actually ready.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( BatchProcessor::class, 'run' ) );
		add_action( 'b2bpl_webhook_batch_queued', array( __CLASS__, 'on_batch_queued' ) );

		add_action( 'action_scheduler_init', array( __CLASS__, 'maybe_recover_queue' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_recover_queue' ) );

		if ( did_action( 'action_scheduler_init' ) ) {
			self::maybe_recover_queue();
		}
	}

	/**
	 * `b2bpl_webhook_batch_queued` callback (fired by `Webhook\RestController`
	 * once a batch reaches `queued`): attempts to enqueue it immediately,
	 * but only while Action Scheduler is demonstrably ready. If it is not
	 * yet (or the enqueue attempt itself returns no id), the batch simply
	 * stays `queued` -- `maybe_recover_queue()` retries it on the next
	 * `action_scheduler_init`/`admin_init`.
	 *
	 * @param string $batch_id UUID of the newly queued batch.
	 * @return void
	 */
	public static function on_batch_queued( $batch_id ) {
		if ( ! did_action( 'action_scheduler_init' ) ) {
			return;
		}

		self::try_enqueue( $batch_id );
	}

	/**
	 * Attempts to enqueue one batch run, verifying that Action Scheduler
	 * actually returned a positive action id ("encolado
	 * verificado (id > 0)") -- never trusted blindly.
	 *
	 * @param string $batch_id UUID of the batch.
	 * @return int Action id, or `0` when the enqueue did not (yet) succeed.
	 */
	public static function try_enqueue( $batch_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}

		return (int) as_enqueue_async_action( self::HOOK, array( (string) $batch_id ), self::AS_GROUP );
	}

	/**
	 * Self-healing watchdog, throttled to at most once every
	 * `RECOVERY_THROTTLE_SECONDS`: re-enqueues every `queued` batch with no
	 * matching Action Scheduler action, and resumes every `paused` batch
	 * once the webhook is enabled again (spec.md edge case: "se reanudan al
	 * reactivarlo").
	 *
	 * @return void
	 */
	public static function maybe_recover_queue() {
		if ( get_transient( self::RECOVERY_THROTTLE_TRANSIENT ) ) {
			return;
		}

		set_transient( self::RECOVERY_THROTTLE_TRANSIENT, 1, self::RECOVERY_THROTTLE_SECONDS );

		$requests = new RequestsRepository();

		if ( Settings::is_enabled() ) {
			foreach ( $requests->find_by_statuses( array( 'paused' ) ) as $row ) {
				$requests->update( (int) $row['id'], array( 'status' => 'queued' ) );
				self::record_event( (int) $row['id'], 'queue_resumed', __( 'The webhook was re-enabled; this batch resumes processing.', 'b2b-price-lists' ) );
			}
		}

		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		foreach ( $requests->find_by_statuses( array( 'queued', 'processing' ) ) as $row ) {
			$batch_id = (string) $row['batch_id'];

			if ( as_has_scheduled_action( self::HOOK, array( $batch_id ), self::AS_GROUP ) ) {
				continue;
			}

			if ( self::try_enqueue( $batch_id ) <= 0 ) {
				continue;
			}

			self::record_event( (int) $row['id'], 'queue_recovered', __( 'This batch had no scheduled action; it was re-enqueued by the recovery watchdog.', 'b2b-price-lists' ) );
		}
	}

	/**
	 * Records a watchdog-level event on a request.
	 *
	 * @param int    $request_id Request id.
	 * @param string $code       Event code.
	 * @param string $message    Human-readable message.
	 * @return void
	 */
	private static function record_event( $request_id, $code, $message ) {
		( new EventsRepository() )->insert(
			array(
				'request_id' => (int) $request_id,
				'level'      => 'info',
				'code'       => (string) $code,
				'message'    => (string) $message,
			)
		);
	}

	/**
	 * Attempts to acquire the FIFO single-batch-at-a-time lock.
	 *
	 * @return bool
	 */
	public static function acquire_lock() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );

		return true;
	}

	/**
	 * Releases the FIFO lock.
	 *
	 * @return void
	 */
	public static function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Re-enqueues a batch run after a short delay (used when the lock could
	 * not be acquired, or when a batch's own budget ran out mid-processing
	 * and it still has pending items).
	 *
	 * @param string $batch_id      UUID of the batch.
	 * @param int    $delay_seconds Delay before the next run, in seconds.
	 * @return void
	 */
	public static function reschedule( $batch_id, $delay_seconds = 0 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action( time() + max( 0, (int) $delay_seconds ), self::HOOK, array( (string) $batch_id ), self::AS_GROUP );
	}

	/**
	 * Forces an immediate `maybe_recover_queue()` pass, bypassing its own
	 * throttle transient (W5/T046: the admin "Retry the queue" notice
	 * button). An administrator clicking that button is an explicit,
	 * one-off request to try right now, not the automatic background
	 * watchdog `maybe_recover_queue()` protects with `RECOVERY_THROTTLE_SECONDS`
	 * against every page load re-running it -- so this clears that
	 * throttle first, then runs the exact same recovery logic.
	 *
	 * @return void
	 */
	public static function force_recover_queue() {
		delete_transient( self::RECOVERY_THROTTLE_TRANSIENT );
		self::maybe_recover_queue();
	}

	/**
	 * Unschedules every pending/in-progress action in this group
	 *. Called unconditionally from `uninstall.php`, independent
	 * of the data-purge opt-in: leaving scheduled work behind for a plugin
	 * that no longer exists is never correct, whether or not its business
	 * data is also being purged.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::AS_GROUP );
		}
	}
}
