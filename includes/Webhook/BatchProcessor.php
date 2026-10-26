<?php
/**
 * Processes webhook batches in FIFO order, one at a time (R4/,
 *-3): `Webhook\BatchQueue::HOOK`'s Action Scheduler callback,
 * which -- once the FIFO lock is held -- always picks whichever `queued`/
 * `processing` request has the oldest `received_at` across the *whole*
 * table, not necessarily the specific `$batch_id` Action Scheduler happened
 * to fire for. That is what makes strict arrival order hold even when a
 * batch's own enqueue attempt was delayed and picked up later by
 * `BatchQueue`'s recovery watchdog.
 *
 * Each run works through that batch's `pending` items in `item_index` order
 * until a time/count budget is spent, then re-enqueues itself (contract:
 * 20 s / 25 items). Every item runs inside `Database\Transaction::run()`
 * (a real transaction, or a `SAVEPOINT` when already nested) and under
 * `Webhook\Context::run()` with `actor_user_id` `0` and `actor_name`
 * "Webhook ({configured username})" (the webhook authenticates
 * with its own username/password, never a WordPress user, so there is no
 * `WP_User` id to run as -- `wp_set_current_user()` is never called here;
 * Action Scheduler already runs as user `0`).
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Database\Transaction;
use B2BPriceLists\Repository\Webhook\EventsRepository;
use B2BPriceLists\Repository\Webhook\ItemsRepository;
use B2BPriceLists\Repository\Webhook\RequestsRepository;
use B2BPriceLists\Support\Logger;
use B2BPriceLists\Webhook\Operations\ItemFailure;
use B2BPriceLists\Webhook\Operations\CatalogOperations;
use B2BPriceLists\Webhook\Operations\ListOperations;
use B2BPriceLists\Webhook\Operations\ProductOperations;
use B2BPriceLists\Webhook\Operations\UserOperations;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BatchProcessor
 */
class BatchProcessor {

	/**
	 * Maximum seconds spent processing items in a single Action Scheduler
	 * run before re-enqueuing (contract).
	 *
	 * @var int
	 */
	const BUDGET_SECONDS = 20;

	/**
	 * Maximum items processed in a single Action Scheduler run before
	 * re-enqueuing (contract).
	 *
	 * @var int
	 */
	const BUDGET_ITEMS = 25;

	/**
	 * `Webhook\BatchQueue::HOOK`'s Action Scheduler callback.
	 *
	 * @param string $batch_id UUID of the batch this run was enqueued for
	 *                          (used only as a fallback/diagnostic -- the
	 *                          actual batch worked on is always the oldest
	 *                          FIFO candidate, see the class docblock).
	 * @return void
	 */
	public static function run( $batch_id ) {
		if ( ! BatchQueue::acquire_lock() ) {
			BatchQueue::reschedule( $batch_id, 5 );

			return;
		}

		try {
			self::run_locked( $batch_id );
		} catch ( Throwable $exception ) {
			Logger::error(
				'The webhook batch processor stopped unexpectedly.',
				array(
					'batch_id' => $batch_id,
					'message'  => $exception->getMessage(),
				)
			);

			self::notify_run_failure( $batch_id, $exception );
		} finally {
			BatchQueue::release_lock();
		}
	}

	/**
	 * Fires `Notifier::HOOK_BATCH_FINISHED` for a whole-run crash (FR:
	 * notification emails) -- the one genuine `failed` batch outcome
	 * `determine_final_status()` never produces on its own (a per-item
	 * failure is always tallied as `completed_with_errors` there instead):
	 * a run that stopped before it could finish at all.
	 *
	 * @param string    $batch_id  UUID Action Scheduler fired this run for
	 *                              (the actual batch worked on may have been
	 *                              a different, older FIFO candidate -- see
	 *                              the class docblock -- so this is only
	 *                              ever a diagnostic hint, never trusted as
	 *                              the crashed batch's own id).
	 * @param Throwable $exception The exception that stopped this run.
	 * @return void
	 */
	private static function notify_run_failure( $batch_id, Throwable $exception ) {
		if ( ! Notifier::should_notify_batch_status( 'failed' ) ) {
			return;
		}

		/** This action is documented in `finalize_batch()`, below. */
		do_action(
			Notifier::HOOK_BATCH_FINISHED,
			array(
				'request_id'  => null,
				'batch_id'    => (string) $batch_id,
				'endpoint'    => null,
				'status'      => 'failed',
				'counts'      => array(),
				'received_at' => null,
				'errors'      => array(
					array(
						'code'    => 'internal_error',
						'message' => $exception->getMessage(),
					),
				),
			)
		);
	}

	/**
	 * The actual work, run only while the FIFO lock is held.
	 *
	 * @param string $batch_id See `run()`.
	 * @return void
	 */
	private static function run_locked( $batch_id ) {
		unset( $batch_id );

		$requests = new RequestsRepository();

		if ( ! Settings::is_enabled() ) {
			self::pause_all_active( $requests );

			return;
		}

		$next = self::select_next_batch( $requests->find_by_statuses( array( 'queued', 'processing' ), 50 ) );

		if ( null === $next ) {
			return;
		}

		self::process_batch_row( $requests, $next );
	}

	/**
	 * Pure FIFO selection: the candidate with the oldest `received_at`, ties
	 * broken by the lowest `id`. Unit-testable independent of the database.
	 *
	 * @param array<int,array<string,mixed>> $candidates Rows already
	 *                                                    filtered to
	 *                                                    `queued`/`processing`.
	 * @return array<string,mixed>|null `null` when `$candidates` is empty.
	 */
	public static function select_next_batch( array $candidates ) {
		$best = null;

		foreach ( $candidates as $row ) {
			if ( null === $best ) {
				$best = $row;
				continue;
			}

			$comparison = strcmp( (string) $row['received_at'], (string) $best['received_at'] );

			if ( $comparison < 0 || ( 0 === $comparison && (int) $row['id'] < (int) $best['id'] ) ) {
				$best = $row;
			}
		}

		return $best;
	}

	/**
	 * Pure budget decision: whether another item may start, given the
	 * elapsed time and how many have already been processed in this run.
	 * Unit-testable independent of the database/Action Scheduler.
	 *
	 * @param float $start_time       `microtime( true )` when this run's item
	 *                                 loop started.
	 * @param int   $budget_seconds   `BUDGET_SECONDS`.
	 * @param int   $items_processed  Items already processed in this run.
	 * @param int   $items_budget     `BUDGET_ITEMS`.
	 * @param float $now              `microtime( true )` "now" (parameterized
	 *                                 for tests, defaults to the real clock).
	 * @return bool
	 */
	public static function should_continue( $start_time, $budget_seconds, $items_processed, $items_budget, $now = null ) {
		if ( $items_processed >= $items_budget ) {
			return false;
		}

		$now = null === $now ? microtime( true ) : $now;

		return ( $now - $start_time ) < $budget_seconds;
	}

	/**
	 * The `entity` column value for a given action. Pure and unit-testable.
	 *
	 * @param string $action Operation action.
	 * @return string `list`, `products`, `user`, or `unknown`.
	 */
	public static function entity_for_action( $action ) {
		$action = (string) $action;

		if ( 0 === strpos( $action, 'list.' ) ) {
			return 'list';
		}

		if ( 0 === strpos( $action, 'products.' ) ) {
			return 'products';
		}

		if ( 0 === strpos( $action, 'product.' ) ) {
			return 'catalog';
		}

		if ( 0 === strpos( $action, 'user.' ) ) {
			return 'user';
		}

		return 'unknown';
	}

	/**
	 * The `external_ref` column value for one operation's `data` (contract:
	 * "external_code/email principal, para buscar"). Pure and unit-testable.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return string|null
	 */
	public static function external_ref_for_data( array $data ) {
		foreach ( array( 'external_code', 'list_external_code', 'email', 'sku' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== $data[ $key ] ) {
				return $data[ $key ];
			}
		}

		return null;
	}

	/**
	 * Tallies item rows by status into the same counters
	 * `Webhook\RestController::handle_batch_status()` reports. Pure and
	 * unit-testable.
	 *
	 * @param array<int,array<string,mixed>> $items Item rows.
	 * @return array{total:int,success:int,partial:int,error:int,skipped:int,pending:int}
	 */
	public static function tally_counts( array $items ) {
		$counts = array(
			'total'   => count( $items ),
			'success' => 0,
			'partial' => 0,
			'error'   => 0,
			'skipped' => 0,
			'pending' => 0,
		);

		foreach ( $items as $item ) {
			$status = isset( $item['status'] ) ? (string) $item['status'] : 'pending';

			if ( array_key_exists( $status, $counts ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * The final batch `status` from its tallied item
	 * counts: `completed` when every item succeeded, `completed_with_errors`
	 * otherwise. Pure and unit-testable. `failed` is reserved for a
	 * whole-run exception (handled defensively in `run()`, never produced
	 * from a normal item tally).
	 *
	 * @param array{error:int,partial:int} $counts `tally_counts()`'s result.
	 * @return string
	 */
	public static function determine_final_status( array $counts ) {
		if ( 0 === (int) ( $counts['error'] ?? 0 ) && 0 === (int) ( $counts['partial'] ?? 0 ) ) {
			return 'completed';
		}

		return 'completed_with_errors';
	}

	/**
	 * Marks every currently active (`queued`/`processing`) request as
	 * `paused` (spec.md edge case: disabling the webhook with lots in the
	 * queue).
	 *
	 * @param RequestsRepository $requests Requests repository.
	 * @return void
	 */
	private static function pause_all_active( RequestsRepository $requests ) {
		foreach ( $requests->find_by_statuses( array( 'queued', 'processing' ) ) as $row ) {
			$requests->update( (int) $row['id'], array( 'status' => 'paused' ) );
			self::record_event(
				(int) $row['id'],
				null,
				'info',
				'batch_paused',
				__( 'The webhook was disabled while this batch was pending; it will resume once re-enabled.', 'b2b-price-lists' )
			);
		}
	}

	/**
	 * Processes one batch run: ensures its item rows exist (first run only),
	 * works through its `pending` items under budget, then either
	 * re-enqueues (items remain) or finalizes it.
	 *
	 * @param RequestsRepository  $requests Requests repository.
	 * @param array<string,mixed> $row      The batch row to process.
	 * @return void
	 */
	private static function process_batch_row( RequestsRepository $requests, array $row ) {
		$request_id = (int) $row['id'];

		if ( 'queued' === $row['status'] ) {
			$requests->update(
				$request_id,
				array(
					'status'     => 'processing',
					'started_at' => current_time( 'mysql', true ),
				)
			); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
			self::record_event( $request_id, null, 'info', 'processing_started', __( 'Batch processing started.', 'b2b-price-lists' ) );
		}

		self::ensure_items_exist( $request_id, $row );

		Context::run(
			array(
				'source'        => 'webhook',
				'batch_id'      => (string) $row['batch_id'],
				'actor_user_id' => 0,
				'actor_name'    => self::webhook_actor_name( $row['auth_user_login'] ?? '' ),
			),
			function () use ( $request_id ) {
				self::process_pending_items( $request_id );
			}
		);

		$remaining = ( new ItemsRepository() )->get_by_request_and_status( $request_id, 'pending' );

		if ( ! empty( $remaining ) ) {
			BatchQueue::reschedule( (string) $row['batch_id'], 0 );

			return;
		}

		self::finalize_batch( $requests, $request_id, $row );
	}

	/**
	 * Works through a request's `pending` items, in order, until the budget
	 * is spent.
	 *
	 * @param int $request_id Request id.
	 * @return void
	 */
	private static function process_pending_items( $request_id ) {
		$items_repo = new ItemsRepository();
		$pending    = $items_repo->get_by_request_and_status( $request_id, 'pending' );

		$start     = microtime( true );
		$processed = 0;

		foreach ( $pending as $item_row ) {
			if ( ! self::should_continue( $start, self::BUDGET_SECONDS, $processed, self::BUDGET_ITEMS ) ) {
				break;
			}

			self::process_item( $request_id, $item_row );
			++$processed;
		}
	}

	/**
	 * Creates one `webhook_items` row per operation, the first time this
	 * batch is worked on (a resumed run finds them already there and does
	 * nothing here -- items already `success`/`skipped`/`error` are never
	 * touched again by this method or by `process_pending_items()`).
	 *
	 * @param int                 $request_id Request id.
	 * @param array<string,mixed> $row        Request row (`payload` is read).
	 * @return void
	 */
	private static function ensure_items_exist( $request_id, array $row ) {
		$items_repo = new ItemsRepository();

		if ( ! empty( $items_repo->get_by_request( $request_id ) ) ) {
			return;
		}

		$payload    = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$operations = ( is_array( $payload ) && isset( $payload['operations'] ) && is_array( $payload['operations'] ) )
			? $payload['operations']
			: array();

		foreach ( $operations as $index => $operation ) {
			$action = isset( $operation['action'] ) ? (string) $operation['action'] : '';
			$data   = ( isset( $operation['data'] ) && is_array( $operation['data'] ) ) ? $operation['data'] : array();
			$ref    = isset( $operation['ref'] ) ? (string) $operation['ref'] : null;

			$items_repo->insert(
				array(
					'request_id'   => $request_id,
					'item_index'   => (int) $index,
					'ref'          => $ref,
					'entity'       => self::entity_for_action( $action ),
					'action'       => $action,
					'external_ref' => self::external_ref_for_data( $data ),
					'status'       => 'pending',
					'input'        => wp_json_encode( $data ),
				)
			);
		}
	}

	/**
	 * Processes a single item: dispatches its action inside its own
	 * transaction, and persists the outcome (success/partial/error/skipped)
	 * plus a timeline event. A business-rule failure (`ItemFailure`) and a
	 * genuinely unexpected exception are both caught here -- neither is
	 * allowed to stop the rest of the batch.
	 *
	 * @param int                 $request_id Request id.
	 * @param array<string,mixed> $item_row   Item row (`pending`).
	 * @return void
	 */
	private static function process_item( $request_id, array $item_row ) {
		$item_id    = (int) $item_row['id'];
		$item_index = (int) $item_row['item_index'];
		$action     = (string) $item_row['action'];
		$input      = json_decode( (string) $item_row['input'], true );
		$input      = is_array( $input ) ? $input : array();
		$attempts   = (int) $item_row['attempts'] + 1;

		$items_repo  = new ItemsRepository();
		$events_repo = new EventsRepository();

		try {
			$outcome = Transaction::run(
				function () use ( $action, $input ) {
					return self::dispatch( $action, $input );
				}
			);

			// M2 (independent review of W4/W5): flushed here, and only
			// here -- once `Transaction::run()` above has returned
			// *successfully*, meaning this item's own, truly outermost
			// transaction (depth `0` at the start of every `process_item()`
			// call) has actually committed. Every operation class
			// (`ListOperations`/`ProductOperations`/`UserOperations`) defers
			// its own activity-log entry/cache invalidation instead of
			// emitting them the moment one of its *nested* `Transaction::run()`
			// calls (a `SAVEPOINT`, released -- not truly committed -- at
			// that depth) returns, precisely so a later failure in the same
			// item can still roll them back before they are ever flushed.
			foreach ( (array) ( $outcome['pending_effects'] ?? array() ) as $effect ) {
				ProductOperations::flush_side_effects( $effect );
			}

			foreach ( (array) ( $outcome['events'] ?? array() ) as $event ) {
				$events_repo->insert(
					array(
						'request_id' => $request_id,
						'item_id'    => $item_id,
						'level'      => isset( $event['level'] ) ? (string) $event['level'] : 'info',
						'code'       => isset( $event['code'] ) ? (string) $event['code'] : 'item_note',
						'message'    => isset( $event['message'] ) ? (string) $event['message'] : '',
					)
				);
			}

			$status = isset( $outcome['status'] ) ? (string) $outcome['status'] : 'success';
			$errors = empty( $outcome['errors'] ) ? array() : array_map(
				function ( $entry ) use ( $item_index ) {
					return self::qualify_error_entry( $entry, $item_index );
				},
				(array) $outcome['errors']
			);

			$items_repo->update(
				$item_id,
				array(
					'status'       => $status,
					'attempts'     => $attempts,
					'result'       => isset( $outcome['result'] ) ? wp_json_encode( $outcome['result'] ) : null,
					'errors'       => empty( $errors ) ? null : wp_json_encode( $errors ),
					'external_ref' => isset( $outcome['external_ref'] ) && '' !== $outcome['external_ref'] ? (string) $outcome['external_ref'] : null,
				)
			);

			$events_repo->insert(
				array(
					'request_id' => $request_id,
					'item_id'    => $item_id,
					'level'      => 'error' === $status ? 'error' : 'info',
					'code'       => 'item_' . $status,
					'message'    => sprintf(
						/* translators: 1: item index, 2: action, 3: final status. */
						__( 'Item %1$d (%2$s) finished as "%3$s".', 'b2b-price-lists' ),
						$item_index,
						$action,
						$status
					),
				)
			);
		} catch ( ItemFailure $failure ) {
			$entry = self::qualify_error_entry( $failure->to_error_entry(), $item_index );

			$items_repo->update(
				$item_id,
				array(
					'status'   => 'error',
					'attempts' => $attempts,
					'errors'   => wp_json_encode( array( $entry ) ),
				)
			);

			$events_repo->insert(
				array(
					'request_id' => $request_id,
					'item_id'    => $item_id,
					'level'      => 'error',
					'code'       => 'item_error',
					'message'    => $failure->getMessage(),
					'context'    => wp_json_encode( $entry ),
				)
			);
		} catch ( Throwable $exception ) {
			Logger::error(
				'Unexpected error processing a webhook item.',
				array(
					'request_id' => $request_id,
					'item_id'    => $item_id,
					'action'     => $action,
					'message'    => $exception->getMessage(),
				)
			);

			$items_repo->update(
				$item_id,
				array(
					'status'   => 'error',
					'attempts' => $attempts,
					'errors'   => wp_json_encode(
						array(
							array(
								'code'    => 'internal_error',
								'message' => __( 'An unexpected error occurred while processing this item.', 'b2b-price-lists' ),
							),
						)
					),
				)
			);

			$events_repo->insert(
				array(
					'request_id' => $request_id,
					'item_id'    => $item_id,
					'level'      => 'error',
					'code'       => 'item_fatal',
					'message'    => __( 'An unexpected error occurred while processing this item.', 'b2b-price-lists' ),
				)
			);
		}
	}

	/**
	 * Prefixes an `errors[]` entry's `path` with `operations[{index}].`
	 * (contract example: `"operations[3].data.products[0].sku"`) -- every
	 * operation-specific class (`ListOperations`/`ProductOperations`) only
	 * ever knows its own `data.*` path, never which position it occupies
	 * within the batch's `operations[]` array, so this is the one place that
	 * adds it. Pure and unit-testable.
	 *
	 * @param array{code:string,message:string,path?:string} $entry      One error entry.
	 * @param int                                            $item_index Position within `operations[]`.
	 * @return array{code:string,message:string,path?:string}
	 */
	public static function qualify_error_entry( array $entry, $item_index ) {
		if ( isset( $entry['path'] ) && '' !== $entry['path'] ) {
			$entry['path'] = "operations[{$item_index}]." . $entry['path'];
		}

		return $entry;
	}

	/**
	 * Routes one item's `action` to `Webhook\Operations\ListOperations`/
	 * `ProductOperations`/`UserOperations`. Any unrecognized action fails
	 * safely with `internal_error` rather than being silently accepted or
	 * fataling.
	 *
	 * @param string              $action Operation action.
	 * @param array<string,mixed> $input  Operation `data`.
	 * @return array<string,mixed> {status?, result?, errors?, external_ref?}
	 * @throws ItemFailure On any business-rule failure.
	 */
	private static function dispatch( $action, array $input ) {
		if ( 0 === strpos( $action, 'list.' ) ) {
			$operations = new ListOperations();

			switch ( $action ) {
				case 'list.create':
					return $operations->create( $input );
				case 'list.update':
					return $operations->update( $input );
				case 'list.upsert':
					return $operations->upsert( $input );
				case 'list.delete':
					return $operations->delete( $input );
				case 'list.duplicate':
					return $operations->duplicate( $input );
			}
		}

		if ( 0 === strpos( $action, 'products.' ) ) {
			$operations = new ProductOperations();

			switch ( $action ) {
				case 'products.upsert':
					return $operations->upsert( $input );
				case 'products.remove':
					return $operations->remove( $input );
				case 'products.replace':
					return $operations->replace( $input );
			}
		}

		if ( 0 === strpos( $action, 'product.' ) ) {
			$operations = new CatalogOperations();

			switch ( $action ) {
				case 'product.upsert':
					return $operations->product_upsert( $input );
				case 'product.discontinue':
					return $operations->product_discontinue( $input );
			}
		}

		if ( 0 === strpos( $action, 'user.' ) ) {
			$operations = new UserOperations();

			switch ( $action ) {
				case 'user.upsert':
					return $operations->upsert( $input );
				case 'user.assign_list':
					return $operations->assign_list( $input );
				case 'user.unassign_list':
					return $operations->unassign_list( $input );
			}
		}

		throw new ItemFailure( 'internal_error', __( 'This action is not supported yet.', 'b2b-price-lists' ) );
	}

	/**
	 * Finalizes a batch once every item has left `pending`.
	 *
	 * @param RequestsRepository  $requests   Requests repository.
	 * @param int                 $request_id Request id.
	 * @param array<string,mixed> $row        Request row.
	 * @return void
	 */
	private static function finalize_batch( RequestsRepository $requests, $request_id, array $row ) {
		$items  = ( new ItemsRepository() )->get_by_request( $request_id );
		$counts = self::tally_counts( $items );
		$status = self::determine_final_status( $counts );

		$summary = array( 'counts' => $counts );

		$requests->update(
			$request_id,
			array(
				'status'        => $status,
				'finished_at'   => current_time( 'mysql', true ),
				'items_total'   => $counts['total'],
				'items_success' => $counts['success'],
				'items_partial' => $counts['partial'],
				'items_error'   => $counts['error'],
				'items_skipped' => $counts['skipped'],
				'attempts'      => (int) $row['attempts'] + 1,
				'summary'       => wp_json_encode( $summary ),
				'response_body' => wp_json_encode(
					array_merge(
						array(
							'batch_id' => $row['batch_id'],
							'status'   => $status,
						),
						$summary
					)
				),
			)
		);

		self::record_event(
			$request_id,
			null,
			'completed_with_errors' === $status ? 'warning' : 'info',
			'batch_done',
			sprintf(
				/* translators: %s: final batch status. */
				__( 'Batch finished with status "%s".', 'b2b-price-lists' ),
				$status
			)
		);

		self::maybe_notify_batch_finished( $request_id, $row, $status, $counts, $items );
	}

	/**
	 * Fires `Notifier::HOOK_BATCH_FINISHED` when a batch's own final status
	 * is worth notifying about (FR: notification emails) --
	 * `completed_with_errors` here; the genuine `failed` outcome is fired
	 * separately, from `notify_run_failure()`, since `finalize_batch()`
	 * itself never produces it (see `determine_final_status()`'s docblock).
	 *
	 * @param int                            $request_id Request id.
	 * @param array<string,mixed>            $row        Request row.
	 * @param string                         $status     Batch's final `status`.
	 * @param array<string,int>              $counts     `tally_counts()`'s result.
	 * @param array<int,array<string,mixed>> $items      Item rows.
	 * @return void
	 */
	private static function maybe_notify_batch_finished( $request_id, array $row, $status, array $counts, array $items ) {
		if ( ! Notifier::should_notify_batch_status( $status ) ) {
			return;
		}

		/**
		 * Fires once a batch finishes `completed_with_errors`/`failed`
		 * (FR: notification emails). Consumed by `Webhook\Notifier`,
		 * registered from `Plugin::init()`, which decides whether an email
		 * is actually sent (configured recipients, hourly throttle/digest
		 * queue).
		 *
		 * @param array<string,mixed> $context {
		 *     @type int|null    $request_id  Request id, when known.
		 *     @type string      $batch_id    Batch UUID.
		 *     @type string|null $endpoint    `lists`/`users`, when known.
		 *     @type string      $status      Batch's final `status`.
		 *     @type array       $counts      `tally_counts()`'s result.
		 *     @type string|null $received_at When the batch was received (MySQL UTC datetime).
		 *     @type array       $errors      Up to 20 item-level error entries.
		 * }
		 */
		do_action(
			Notifier::HOOK_BATCH_FINISHED,
			array(
				'request_id'  => (int) $request_id,
				'batch_id'    => (string) $row['batch_id'],
				'endpoint'    => isset( $row['endpoint'] ) ? (string) $row['endpoint'] : null,
				'status'      => $status,
				'counts'      => $counts,
				'received_at' => isset( $row['received_at'] ) ? (string) $row['received_at'] : null,
				'errors'      => self::batch_error_entries( $items ),
			)
		);
	}

	/**
	 * Up to `Notifier::MAX_ERROR_ENTRIES` item-level errors for a
	 * batch-finished notification, each decoded from its own stored
	 * `errors` JSON and tagged with the item's own `ref`/`action`
	 * (`Notifier`'s message builder shows `ref`, `action`, `code`,
	 * `message`, `path`). Pure and unit-testable.
	 *
	 * @param array<int,array<string,mixed>> $items Item rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function batch_error_entries( array $items ) {
		$entries = array();

		foreach ( $items as $item ) {
			if ( empty( $item['errors'] ) ) {
				continue;
			}

			$decoded = json_decode( (string) $item['errors'], true );

			if ( ! is_array( $decoded ) ) {
				continue;
			}

			foreach ( $decoded as $error ) {
				if ( ! is_array( $error ) ) {
					continue;
				}

				$entries[] = array_merge(
					array(
						'ref'    => isset( $item['ref'] ) ? (string) $item['ref'] : null,
						'action' => isset( $item['action'] ) ? (string) $item['action'] : null,
					),
					$error
				);

				if ( count( $entries ) >= Notifier::MAX_ERROR_ENTRIES ) {
					return $entries;
				}
			}
		}

		return $entries;
	}

	/**
	 * The activity-log actor name for a webhook batch:
	 * "Webhook ({configured username})", or the bare "Webhook" when the
	 * request row does not carry a username (defensive; every row inserted
	 * by `Webhook\IngestLogger` does once authenticated). Pure and
	 * unit-testable.
	 *
	 * @param string $auth_user_login `webhook_requests.auth_user_login` (the
	 *                                 configured webhook username, snapshotted
	 *                                 at request time).
	 * @return string
	 */
	public static function webhook_actor_name( $auth_user_login ) {
		$auth_user_login = trim( (string) $auth_user_login );

		if ( '' === $auth_user_login ) {
			return __( 'Webhook', 'b2b-price-lists' );
		}

		return sprintf(
			/* translators: %s: configured webhook username. */
			__( 'Webhook (%s)', 'b2b-price-lists' ),
			$auth_user_login
		);
	}

	/**
	 * Records a batch/item-level timeline event.
	 *
	 * @param int      $request_id Request id.
	 * @param int|null $item_id    Item id, or `null` for a request-level event.
	 * @param string   $level      `debug`|`info`|`warning`|`error`.
	 * @param string   $code       Event code.
	 * @param string   $message    Human-readable message.
	 * @return void
	 */
	private static function record_event( $request_id, $item_id, $level, $code, $message ) {
		( new EventsRepository() )->insert(
			array(
				'request_id' => (int) $request_id,
				'item_id'    => $item_id,
				'level'      => (string) $level,
				'code'       => (string) $code,
				'message'    => (string) $message,
			)
		);
	}
}
