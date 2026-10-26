<?php
/**
 * Daily retention purge for the webhook's own log tables (`b2bpl_webhook_requests`/
 * `_items`/`_events`, T045): removes whatever is older than
 * `Webhook\Settings::get()['retention_months']` (6, 12 or 24, default 12 --
 * the same choices `Activity\ActivityLog` has offered since 1.3.0), in
 * batches of `PURGE_BATCH` requests at a time so a large backlog is worked
 * through over a few daily runs instead of one long request, the same
 * pattern already used by `Activity\ActivityLog::purge_expired()`.
 *
 * Unlike the activity log, the webhook's own request/item/event rows are
 * **not** archived before deletion: only requires the retention
 * period itself to be configurable and "the purge itself to be recorded"
 *, not a second, indefinitely-growing copy
 * of exactly the data retention exists to eventually remove. What is kept
 * is a small, single-row summary of the most recent purge
 * (`LAST_PURGE_OPTION`), shown on the "Integration -> Settings" screen.
 *
 * Deletion order matters because none of the three tables has a foreign key
 * (same rationale as `Database\Schema::all_tables_for_purge()`'s docblock):
 * events and items are deleted first, by `request_id`, then the request rows
 * themselves -- all inside one `Database\Transaction::run()` per batch, so a
 * failure partway through never leaves an item/event pointing at an
 * already-deleted request, or a request deleted while its items survive.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Database\Transaction;
use B2BPriceLists\Repository\Webhook\EventsRepository;
use B2BPriceLists\Repository\Webhook\ItemsRepository;
use B2BPriceLists\Repository\Webhook\RequestsRepository;
use B2BPriceLists\Support\Logger;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RetentionPurger
 */
class RetentionPurger {

	/**
	 * Cron hook that applies the retention window.
	 *
	 * @var string
	 */
	const PURGE_HOOK = 'b2bpl_webhook_purge_log';

	/**
	 * Option holding a summary of the most recent purge pass, so an
	 * administrator can see it ran without searching server logs
	 *.
	 *
	 * @var string
	 */
	const LAST_PURGE_OPTION = 'b2bpl_webhook_last_purge';

	/**
	 * Requests (and their items/events) removed per pass, and the most
	 * passes one cron run may do -- identical values to
	 * `Activity\ActivityLog::PURGE_BATCH`/`PURGE_MAX_PASSES`, for the same
	 * reason (a very large backlog is worked through over a few days, never
	 * in one long request).
	 */
	const PURGE_BATCH      = 500;
	const PURGE_MAX_PASSES = 40;

	/**
	 * Registers the retention cron. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::PURGE_HOOK, array( self::class, 'purge_expired' ) );

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Clears the retention cron. Called on deactivation/uninstall.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::PURGE_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::PURGE_HOOK );
		}
	}

	/**
	 * The UTC cutoff before which a request is purged: pure, so the exact
	 * date arithmetic is unit-testable independent of the database/settings.
	 *
	 * @param int      $retention_months Retention window, in months.
	 * @param int|null $now              Reference Unix timestamp; `null` uses the real clock.
	 * @return string UTC `Y-m-d H:i:s`, matching `received_at`'s storage format.
	 */
	public static function retention_cutoff( $retention_months, $now = null ) {
		$retention_months = max( 1, (int) $retention_months );
		$now              = null === $now ? time() : (int) $now;

		return gmdate( 'Y-m-d H:i:s', strtotime( '-' . $retention_months . ' months', $now ) );
	}

	/**
	 * Whether another purge pass should run after one just processed
	 * `$batch_size` rows: only when that batch was exactly a full
	 * `PURGE_BATCH` (a partial batch means nothing older than the cutoff is
	 * left). Pure and unit-testable.
	 *
	 * @param int $batch_size Rows processed in the pass just finished.
	 * @return bool
	 */
	public static function should_continue( $batch_size ) {
		return self::PURGE_BATCH === (int) $batch_size;
	}

	/**
	 * Runs the purge: up to `PURGE_MAX_PASSES` batches of `PURGE_BATCH`
	 * requests (and their items/events) older than the configured
	 * retention window, then records a summary in `LAST_PURGE_OPTION`.
	 *
	 * @return int Requests removed.
	 */
	public static function purge_expired() {
		$requests_repo = new RequestsRepository();
		$items_repo    = new ItemsRepository();
		$events_repo   = new EventsRepository();

		$retention_months = (int) Settings::get()['retention_months'];
		$cutoff           = self::retention_cutoff( $retention_months );
		$removed          = 0;
		$passes           = 0;

		try {
			for ( $pass = 0; $pass < self::PURGE_MAX_PASSES; $pass++ ) {
				++$passes;

				$ids = $requests_repo->find_ids_received_before( $cutoff, self::PURGE_BATCH );

				if ( empty( $ids ) ) {
					break;
				}

				Transaction::run(
					static function () use ( $ids, $requests_repo, $items_repo, $events_repo ) {
						$events_repo->delete_by_request_ids( $ids );
						$items_repo->delete_by_request_ids( $ids );

						if ( count( $ids ) !== $requests_repo->delete_ids( $ids ) ) {
							throw new RuntimeException( 'Could not remove every expired webhook request in this batch.' );
						}
					}
				);

				$removed += count( $ids );

				if ( ! self::should_continue( count( $ids ) ) ) {
					break;
				}
			}
		} catch ( Throwable $exception ) {
			Logger::error(
				'Webhook log retention pass stopped; nothing past this point was deleted.',
				array(
					'removed' => $removed,
					'message' => $exception->getMessage(),
				)
			);
		}

		update_option(
			self::LAST_PURGE_OPTION,
			array(
				'ran_at'           => gmdate( 'Y-m-d H:i:s' ),
				'cutoff'           => $cutoff,
				'retention_months' => $retention_months,
				'requests_removed' => $removed,
				'passes'           => $passes,
			),
			false
		);

		return $removed;
	}

	/**
	 * The most recent purge summary, or `null` if the purge has never run
	 * yet on this site.
	 *
	 * @return array{ran_at:string,cutoff:string,retention_months:int,requests_removed:int,passes:int}|null
	 */
	public static function last_purge() {
		$summary = get_option( self::LAST_PURGE_OPTION, null );

		return is_array( $summary ) ? $summary : null;
	}
}
