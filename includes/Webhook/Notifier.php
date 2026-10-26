<?php
/**
 * Notification emails for the webhook (site owner request, 2026-09):
 * "absolutely every error" -- every rejected request (`$http_status >= 400`,
 * with no exception for `401`/`404`/`429`) and every batch that finishes
 * `completed_with_errors` or `failed` -- reported to the addresses
 * configured in `Webhook\Settings`' `notification_emails`, without ever
 * flooding an administrator's inbox from scanner traffic hammering the
 * endpoint.
 *
 * Deliberately decoupled from `Webhook\IngestLogger`/`Webhook\BatchProcessor`:
 * neither references this class directly, they only ever fire
 * `HOOK_REQUEST_REJECTED`/`HOOK_BATCH_FINISHED` with a plain context array
 * (see each hook's own docblock at its `do_action()` call site) once they
 * have already decided the outcome is worth reporting
 * (`should_notify_rejection()`/`should_notify_batch_status()`, both pure and
 * reused there). This class is the only thing that ever calls `wp_mail()`
 * for the webhook, and the only place a mail failure is ever caught, so it
 * can never break the request/batch it describes.
 *
 * Throttled to at most `THROTTLE_MAX_PER_WINDOW` (30) individual emails per
 * rolling hour, site-wide, shared by both hooks: nothing is ever silently
 * dropped once that is reached -- every notification beyond it is appended
 * to a small, durable queue (`QUEUE_OPTION`, capped at `QUEUE_MAX_ITEMS`
 * entries plus an overflow counter for anything past that) and reported
 * instead as a single hourly digest email (`send_digest()`, scheduled via
 * Action Scheduler when available -- the same mechanism `Webhook\BatchQueue`
 * already uses -- or WP-Cron otherwise).
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Admin\IntegrationLogController;
use B2BPriceLists\Repository\Webhook\EventsRepository;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Notifier
 */
class Notifier {

	/**
	 * Fired by `Webhook\IngestLogger` for every request outcome that is an
	 * error.
	 *
	 * @var string
	 */
	const HOOK_REQUEST_REJECTED = 'b2bpl_webhook_request_rejected';

	/**
	 * Fired by `Webhook\BatchProcessor` once a batch finishes
	 * `completed_with_errors`/`failed`.
	 *
	 * @var string
	 */
	const HOOK_BATCH_FINISHED = 'b2bpl_webhook_batch_finished';

	/**
	 * Action Scheduler/WP-Cron hook that sends the pending digest, once an
	 * hour.
	 *
	 * @var string
	 */
	const DIGEST_HOOK = 'b2bpl_webhook_notify_digest';

	/**
	 * Transient holding the current throttle window's send counter.
	 *
	 * @var string
	 */
	const THROTTLE_TRANSIENT = 'b2bpl_webhook_notify_throttle';

	/**
	 * Throttle window length, in seconds. A literal rather than
	 * `HOUR_IN_SECONDS` (WordPress core), since the pure decision methods
	 * built on it stay unit-testable without WordPress loaded.
	 *
	 * @var int
	 */
	const THROTTLE_WINDOW_SECONDS = 3600;

	/**
	 * Individual emails allowed per rolling hour, site-wide, shared by both
	 * hooks.
	 *
	 * @var int
	 */
	const THROTTLE_MAX_PER_WINDOW = 30;

	/**
	 * Option (not a transient: this queue must survive up to an hour
	 * without being evicted by an object cache) holding whatever
	 * notifications could not be sent individually because the throttle
	 * above was already reached.
	 *
	 * @var string
	 */
	const QUEUE_OPTION = 'b2bpl_webhook_notify_queue';

	/**
	 * Maximum number of full entries kept in the digest queue; anything
	 * beyond this is still counted (`overflow`), never silently lost, just
	 * not itemized individually in the digest email.
	 *
	 * @var int
	 */
	const QUEUE_MAX_ITEMS = 500;

	/**
	 * Maximum number of item/request error entries included in a single
	 * notification email's body.
	 *
	 * @var int
	 */
	const MAX_ERROR_ENTRIES = 20;

	/**
	 * Registers both hook consumers and the hourly digest schedule. Called
	 * once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_REQUEST_REJECTED, array( __CLASS__, 'handle_request_rejected' ) );
		add_action( self::HOOK_BATCH_FINISHED, array( __CLASS__, 'handle_batch_finished' ) );
		add_action( self::DIGEST_HOOK, array( __CLASS__, 'send_digest' ) );

		// Action Scheduler must not be queried before its own init (same
		// rule as `BatchQueue`).
		if ( did_action( 'action_scheduler_init' ) ) {
			self::schedule_digest();
		} else {
			add_action( 'action_scheduler_init', array( __CLASS__, 'schedule_digest' ) );
		}
	}

	/**
	 * Schedules the hourly digest, preferring Action Scheduler (the same
	 * mechanism `Webhook\BatchQueue` already uses) when it is available,
	 * falling back to WP-Cron's own built-in `hourly` schedule otherwise.
	 * Never schedules a second copy.
	 *
	 * @return void
	 */
	public static function schedule_digest() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::DIGEST_HOOK, array(), BatchQueue::AS_GROUP ) ) {
				as_schedule_recurring_action( time() + self::THROTTLE_WINDOW_SECONDS, self::THROTTLE_WINDOW_SECONDS, self::DIGEST_HOOK, array(), BatchQueue::AS_GROUP );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::DIGEST_HOOK ) ) {
			wp_schedule_event( time() + self::THROTTLE_WINDOW_SECONDS, 'hourly', self::DIGEST_HOOK );
		}
	}

	/**
	 * Clears the digest schedule. Called on uninstall.
	 *
	 * @return void
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::DIGEST_HOOK, array(), BatchQueue::AS_GROUP );
		}

		$timestamp = wp_next_scheduled( self::DIGEST_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::DIGEST_HOOK );
		}
	}

	/**
	 * Whether a request outcome is worth notifying about (site owner
	 * request, 2026-09: "absolutely every error", no exception for any
	 * particular status) -- every `$http_status >= 400`. Pure and
	 * unit-testable.
	 *
	 * @param int $http_status Final HTTP status.
	 * @return bool
	 */
	public static function should_notify_rejection( $http_status ) {
		return (int) $http_status >= 400;
	}

	/**
	 * Whether a batch's final status is worth notifying about. Pure and
	 * unit-testable.
	 *
	 * @param string $status Batch's final internal `status`.
	 * @return bool
	 */
	public static function should_notify_batch_status( $status ) {
		return in_array( (string) $status, array( 'completed_with_errors', 'failed' ), true );
	}

	/**
	 * `HOOK_REQUEST_REJECTED` callback.
	 *
	 * @param array<string,mixed> $context See `Webhook\IngestLogger`'s `do_action()` call site.
	 * @return void
	 */
	public static function handle_request_rejected( $context ) {
		$context = self::with_site_info( is_array( $context ) ? $context : array() );

		self::dispatch(
			self::build_rejection_subject( self::site_name() ),
			self::build_rejection_body( $context ),
			NotificationEmail::rejection( $context ),
			$context
		);
	}

	/**
	 * `HOOK_BATCH_FINISHED` callback.
	 *
	 * @param array<string,mixed> $context See `Webhook\BatchProcessor`'s `do_action()` call sites.
	 * @return void
	 */
	public static function handle_batch_finished( $context ) {
		$context = self::with_site_info( is_array( $context ) ? $context : array() );

		self::dispatch(
			self::build_batch_subject( self::site_name(), isset( $context['status'] ) ? (string) $context['status'] : '' ),
			self::build_batch_body( $context ),
			NotificationEmail::batch( $context ),
			$context
		);
	}

	/**
	 * Adds the two WordPress-touching fields every message builder accepts
	 * (`site_url`/`detail_url`) to an otherwise-pure context array.
	 *
	 * @param array<string,mixed> $context Context array (see either hook's docblock).
	 * @return array<string,mixed>
	 */
	private static function with_site_info( array $context ) {
		$context['site_url']   = home_url( '/' );
		$context['detail_url'] = self::detail_url_for( isset( $context['batch_id'] ) ? $context['batch_id'] : null );

		return $context;
	}

	/**
	 * A direct admin link to a batch's detail screen on the integration
	 * log, when its `batch_id` is known.
	 *
	 * @param string|null $batch_id Batch UUID, if known.
	 * @return string|null
	 */
	private static function detail_url_for( $batch_id ) {
		if ( empty( $batch_id ) || ! is_string( $batch_id ) ) {
			return null;
		}

		return IntegrationLogController::instance()->detail_url_for_batch_id( $batch_id );
	}

	/**
	 * Renders a structured notification as branded HTML.
	 *
	 * @param array<string,mixed> $email Structured email from `NotificationEmail`.
	 * @return string
	 */
	private static function render_html( array $email ) {
		return NotificationEmail::render( $email, NotificationEmail::palette(), self::site_name(), home_url( '/' ) );
	}

	/**
	 * Mail headers for HTML notifications.
	 *
	 * @return string[]
	 */
	private static function html_headers() {
		return array( 'Content-Type: text/html; charset=UTF-8' );
	}

	/**
	 * The site's own name, for the subject line.
	 *
	 * @return string
	 */
	private static function site_name() {
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Sends one notification email now, unless the site has no configured
	 * recipients (nothing is ever built or sent for an empty list) or the
	 * hourly throttle is already spent -- in which case it is queued for
	 * the next digest instead of being dropped. Wrapped so a `wp_mail()`
	 * failure, or anything else going wrong here, never breaks the
	 * request/batch this was fired for.
	 *
	 * @param string              $subject Email subject.
	 * @param string              $body    Email body (plain text).
	 * @param array<string,mixed> $email   Structured email (`NotificationEmail`).
	 * @param array<string,mixed> $context Original hook context (only
	 *                                      `request_id`, if present, is
	 *                                      used here, to attach a
	 *                                      throttled-notification event to
	 *                                      its own batch/request row).
	 * @return void
	 */
	private static function dispatch( $subject, $body, array $email, array $context ) {
		try {
			$emails = Settings::get()['notification_emails'];

			if ( empty( $emails ) ) {
				return;
			}

			$now   = time();
			$state = RequestGuard::rate_window_state( get_transient( self::THROTTLE_TRANSIENT ), $now, self::THROTTLE_WINDOW_SECONDS );

			if ( RequestGuard::is_count_over_limit( $state['count'], self::THROTTLE_MAX_PER_WINDOW ) ) {
				self::enqueue_for_digest( $subject, $body );
				self::record_throttled_event( $context );

				return;
			}

			++$state['count'];
			set_transient( self::THROTTLE_TRANSIENT, $state, RequestGuard::remaining_window_seconds( $state['window_start'], $now, self::THROTTLE_WINDOW_SECONDS ) );

			wp_mail( $emails, $subject, self::render_html( $email ), self::html_headers() );
		} catch ( Throwable $exception ) {
			self::log_send_failure( $exception );
		}
	}

	/**
	 * Appends one notification to the durable digest queue (pure): kept as
	 * long as `$max_items` allows, otherwise only counted (`overflow`) --
	 * nothing is ever silently lost, just not itemized individually once
	 * the queue is full.
	 *
	 * @param array{items:array<int,array{subject:string,body:string}>,overflow:int} $queue     Current queue state.
	 * @param array{subject:string,body:string}                                      $entry     Entry to append.
	 * @param int                                                                    $max_items `QUEUE_MAX_ITEMS`.
	 * @return array{items:array<int,array{subject:string,body:string}>,overflow:int}
	 */
	public static function append_to_queue( array $queue, array $entry, $max_items = self::QUEUE_MAX_ITEMS ) {
		$items    = isset( $queue['items'] ) && is_array( $queue['items'] ) ? $queue['items'] : array();
		$overflow = isset( $queue['overflow'] ) ? (int) $queue['overflow'] : 0;

		if ( count( $items ) < $max_items ) {
			$items[] = $entry;
		} else {
			++$overflow;
		}

		return array(
			'items'    => $items,
			'overflow' => $overflow,
		);
	}

	/**
	 * Persists one notification onto the digest queue.
	 *
	 * @param string $subject Email subject.
	 * @param string $body    Email body.
	 * @return void
	 */
	private static function enqueue_for_digest( $subject, $body ) {
		$queue = self::append_to_queue(
			self::get_queue(),
			array(
				'subject' => (string) $subject,
				'body'    => (string) $body,
			)
		);

		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * The digest queue's current, already-shaped state.
	 *
	 * @return array{items:array<int,array{subject:string,body:string}>,overflow:int}
	 */
	private static function get_queue() {
		return self::normalize_queue( get_option( self::QUEUE_OPTION, array() ) );
	}

	/**
	 * Defensive normalization of whatever `QUEUE_OPTION` currently holds
	 * into the shape every other method here expects.
	 *
	 * @param mixed $queue Raw option value.
	 * @return array{items:array<int,array{subject:string,body:string}>,overflow:int}
	 */
	private static function normalize_queue( $queue ) {
		return array(
			'items'    => ( is_array( $queue ) && isset( $queue['items'] ) && is_array( $queue['items'] ) ) ? $queue['items'] : array(),
			'overflow' => ( is_array( $queue ) && isset( $queue['overflow'] ) ) ? (int) $queue['overflow'] : 0,
		);
	}

	/**
	 * `DIGEST_HOOK` callback: sends one digest email covering every
	 * notification the throttle above deferred since the last run, then
	 * clears the queue -- cleared *before* the send attempt, so a `wp_mail()`
	 * failure can never cause the same backlog to be re-sent forever (a
	 * lost digest is preferable to an infinite retry loop; the underlying
	 * errors are, regardless, still permanently in the integration log
	 * itself).
	 *
	 * @return void
	 */
	public static function send_digest() {
		$queue = self::normalize_queue( get_option( self::QUEUE_OPTION, array() ) );

		if ( empty( $queue['items'] ) && 0 === $queue['overflow'] ) {
			return;
		}

		delete_option( self::QUEUE_OPTION );

		try {
			$emails = Settings::get()['notification_emails'];

			if ( empty( $emails ) ) {
				return;
			}

			wp_mail(
				$emails,
				self::build_digest_subject( self::site_name(), count( $queue['items'] ), $queue['overflow'] ),
				self::render_html( NotificationEmail::digest( $queue['items'], $queue['overflow'] ) ),
				self::html_headers()
			);
		} catch ( Throwable $exception ) {
			self::log_send_failure( $exception );
		}
	}

	/**
	 * Records the throttled-notification event onto its own request row,
	 * when one is known -- the only sink left (`error_log()`) when it is
	 * not (e.g. the log write for the original rejection itself had
	 * already failed).
	 *
	 * @param array<string,mixed> $context Original hook context.
	 * @return void
	 */
	private static function record_throttled_event( array $context ) {
		$request_id = isset( $context['request_id'] ) ? (int) $context['request_id'] : 0;

		if ( $request_id <= 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- no request row to attach this event to; the notification itself is still queued for the next digest.
			error_log( '[B2B Price Lists] Webhook notification email deferred to the hourly digest (throttle reached).' );

			return;
		}

		( new EventsRepository() )->insert(
			array(
				'request_id' => $request_id,
				'level'      => 'warning',
				'code'       => 'notification_email_throttled',
				'message'    => __( 'This notification was not sent individually because the hourly limit was reached; it will be included in the next digest email instead.', 'b2b-price-lists' ),
			)
		);
	}

	/**
	 * Last-resort sink for a mail failure -- must never break the request/
	 * batch a notification describes.
	 *
	 * @param Throwable $exception Exception caught around the send attempt.
	 * @return void
	 */
	private static function log_send_failure( Throwable $exception ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[B2B Price Lists] Could not send a webhook notification email: ' . $exception->getMessage() );
	}

	/**
	 * Up to `MAX_ERROR_ENTRIES` error entries, unchanged otherwise. Pure
	 * and unit-testable.
	 *
	 * @param array<int,mixed> $errors Candidate error entries.
	 * @param int              $max    `MAX_ERROR_ENTRIES`.
	 * @return array<int,mixed>
	 */
	public static function limit_error_entries( array $errors, $max = self::MAX_ERROR_ENTRIES ) {
		return array_slice( array_values( $errors ), 0, max( 0, (int) $max ) );
	}

	/**
	 * One error entry formatted as a single readable line, showing only
	 * whichever of `ref`/`action`/`code`/`message`/`path` is present. Pure
	 * and unit-testable.
	 *
	 * @param array<string,mixed> $error One error entry.
	 * @return string
	 */
	public static function format_error_line( array $error ) {
		$parts = array();

		foreach ( array( 'ref', 'action', 'code', 'message', 'path' ) as $key ) {
			if ( isset( $error[ $key ] ) && is_scalar( $error[ $key ] ) && '' !== $error[ $key ] ) {
				$parts[] = $key . '=' . $error[ $key ];
			}
		}

		return '- ' . implode( ', ', $parts );
	}

	/**
	 * The subject line of a rejected-request notification. Pure and
	 * unit-testable.
	 *
	 * @param string $site_name Site name.
	 * @return string
	 */
	public static function build_rejection_subject( $site_name ) {
		return sprintf(
			/* translators: %s: site name. */
			__( '[%s] B2B webhook: batch rejected', 'b2b-price-lists' ),
			$site_name
		);
	}

	/**
	 * The body of a rejected-request notification. Pure and unit-testable.
	 *
	 * @param array<string,mixed> $context Context array (see
	 *                                      `Webhook\IngestLogger`'s
	 *                                      `do_action()` call site), plus
	 *                                      `site_url`/`detail_url`
	 *                                      (`with_site_info()`).
	 * @return string
	 */
	public static function build_rejection_body( array $context ) {
		$lines = array();

		$lines[] = sprintf(
			/* translators: %s: site URL. */
			__( 'Site: %s', 'b2b-price-lists' ),
			isset( $context['site_url'] ) ? $context['site_url'] : ''
		);
		$lines[] = sprintf(
			/* translators: %s: batch UUID, or "unknown". */
			__( 'Batch: %s', 'b2b-price-lists' ),
			! empty( $context['batch_id'] ) ? $context['batch_id'] : __( 'unknown', 'b2b-price-lists' )
		);

		if ( ! empty( $context['endpoint'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: batch type (lists/users) or route kind. */
				__( 'Type: %s', 'b2b-price-lists' ),
				$context['endpoint']
			);
		}

		$lines[] = sprintf(
			/* translators: 1: HTTP method, 2: REST route. */
			__( 'Request: %1$s %2$s', 'b2b-price-lists' ),
			isset( $context['method'] ) ? $context['method'] : '',
			isset( $context['route'] ) ? $context['route'] : ''
		);
		$lines[] = sprintf(
			/* translators: %d: HTTP status code. */
			__( 'HTTP status: %d', 'b2b-price-lists' ),
			isset( $context['http_status'] ) ? (int) $context['http_status'] : 0
		);

		if ( ! empty( $context['rejection_code'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: machine-readable error code. */
				__( 'Reason: %s', 'b2b-price-lists' ),
				$context['rejection_code']
			);
		}

		if ( ! empty( $context['message'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: error message. */
				__( 'Message: %s', 'b2b-price-lists' ),
				$context['message']
			);
		}

		if ( ! empty( $context['username_attempted'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: username attempted (never the password). */
				__( 'Username attempted: %s', 'b2b-price-lists' ),
				$context['username_attempted']
			);
		}

		if ( ! empty( $context['ip'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: caller's IP address. */
				__( 'IP address: %s', 'b2b-price-lists' ),
				$context['ip']
			);
		}

		$lines[] = sprintf(
			/* translators: %s: date/time (UTC) the request was received. */
			__( 'Received: %s UTC', 'b2b-price-lists' ),
			isset( $context['received_at'] ) ? $context['received_at'] : ''
		);

		self::append_error_lines( $lines, $context );
		self::append_detail_line( $lines, $context );

		return implode( "\n", $lines );
	}

	/**
	 * The subject line of a batch-finished notification. Pure and
	 * unit-testable.
	 *
	 * @param string $site_name Site name.
	 * @param string $status    Batch's final `status` (`failed` or
	 *                           `completed_with_errors`).
	 * @return string
	 */
	public static function build_batch_subject( $site_name, $status ) {
		if ( 'failed' === $status ) {
			return sprintf(
				/* translators: %s: site name. */
				__( '[%s] B2B webhook: batch failed', 'b2b-price-lists' ),
				$site_name
			);
		}

		return sprintf(
			/* translators: %s: site name. */
			__( '[%s] B2B webhook: batch finished with errors', 'b2b-price-lists' ),
			$site_name
		);
	}

	/**
	 * The body of a batch-finished notification. Pure and unit-testable.
	 *
	 * @param array<string,mixed> $context Context array (see
	 *                                      `Webhook\BatchProcessor`'s
	 *                                      `do_action()` call sites), plus
	 *                                      `site_url`/`detail_url`
	 *                                      (`with_site_info()`).
	 * @return string
	 */
	public static function build_batch_body( array $context ) {
		$lines = array();

		$lines[] = sprintf(
			/* translators: %s: site URL. */
			__( 'Site: %s', 'b2b-price-lists' ),
			isset( $context['site_url'] ) ? $context['site_url'] : ''
		);
		$lines[] = sprintf(
			/* translators: %s: batch UUID, or "unknown". */
			__( 'Batch: %s', 'b2b-price-lists' ),
			! empty( $context['batch_id'] ) ? $context['batch_id'] : __( 'unknown', 'b2b-price-lists' )
		);

		if ( ! empty( $context['endpoint'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: batch type (lists/users). */
				__( 'Type: %s', 'b2b-price-lists' ),
				$context['endpoint']
			);
		}

		$lines[] = sprintf(
			/* translators: %s: batch's final status. */
			__( 'Status: %s', 'b2b-price-lists' ),
			isset( $context['status'] ) ? $context['status'] : ''
		);

		$counts = ( isset( $context['counts'] ) && is_array( $context['counts'] ) ) ? $context['counts'] : array();

		if ( ! empty( $counts ) ) {
			$lines[] = sprintf(
				/* translators: 1: total items, 2: successful items, 3: partially applied items, 4: failed items, 5: skipped items. */
				__( 'Items: %1$d total, %2$d success, %3$d partial, %4$d error, %5$d skipped', 'b2b-price-lists' ),
				(int) ( $counts['total'] ?? 0 ),
				(int) ( $counts['success'] ?? 0 ),
				(int) ( $counts['partial'] ?? 0 ),
				(int) ( $counts['error'] ?? 0 ),
				(int) ( $counts['skipped'] ?? 0 )
			);
		}

		if ( ! empty( $context['received_at'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: date/time (UTC) the batch was received. */
				__( 'Received: %s UTC', 'b2b-price-lists' ),
				$context['received_at']
			);
		}

		self::append_error_lines( $lines, $context );
		self::append_detail_line( $lines, $context );

		return implode( "\n", $lines );
	}

	/**
	 * Appends the "Errors:" block, when there is at least one error entry.
	 * Shared by `build_rejection_body()`/`build_batch_body()`.
	 *
	 * @param array<int,string>   $lines   Lines built so far (by reference).
	 * @param array<string,mixed> $context Context array.
	 * @return void
	 */
	private static function append_error_lines( array &$lines, array $context ) {
		$errors = self::limit_error_entries( is_array( $context['errors'] ?? null ) ? $context['errors'] : array() );

		if ( empty( $errors ) ) {
			return;
		}

		$lines[] = '';
		$lines[] = __( 'Errors:', 'b2b-price-lists' );

		foreach ( $errors as $error ) {
			$lines[] = self::format_error_line( is_array( $error ) ? $error : array() );
		}
	}

	/**
	 * Appends the "Detail:" admin link line, when known. Shared by
	 * `build_rejection_body()`/`build_batch_body()`.
	 *
	 * @param array<int,string>   $lines   Lines built so far (by reference).
	 * @param array<string,mixed> $context Context array.
	 * @return void
	 */
	private static function append_detail_line( array &$lines, array $context ) {
		if ( empty( $context['detail_url'] ) ) {
			return;
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: admin URL to this batch's detail screen. */
			__( 'Detail: %s', 'b2b-price-lists' ),
			$context['detail_url']
		);
	}

	/**
	 * The subject line of a digest email. Pure and unit-testable.
	 *
	 * @param string $site_name Site name.
	 * @param int    $count     Number of individually itemized notifications.
	 * @param int    $overflow  Additional notifications counted but not itemized.
	 * @return string
	 */
	public static function build_digest_subject( $site_name, $count, $overflow ) {
		$total = (int) $count + (int) $overflow;

		return sprintf(
			/* translators: 1: site name, 2: number of pending webhook notifications. */
			_n(
				'[%1$s] B2B webhook: %2$d pending notification',
				'[%1$s] B2B webhook: %2$d pending notifications',
				$total,
				'b2b-price-lists'
			),
			$site_name,
			$total
		);
	}

	/**
	 * The body of a digest email: every queued notification's own subject/
	 * body, one after another, plus how many more occurred beyond what was
	 * kept individually. Pure and unit-testable.
	 *
	 * @param array<int,array{subject:string,body:string}> $items    Queued notifications.
	 * @param int                                          $overflow Additional notifications counted but not itemized.
	 * @return string
	 */
	public static function build_digest_body( array $items, $overflow ) {
		$lines   = array();
		$lines[] = __( 'The following webhook notifications could not be sent individually because the hourly limit was reached:', 'b2b-price-lists' );

		foreach ( $items as $index => $item ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: 1: position within this digest, 2: original notification subject. */
				__( '#%1$d %2$s', 'b2b-price-lists' ),
				$index + 1,
				isset( $item['subject'] ) ? $item['subject'] : ''
			);
			$lines[] = isset( $item['body'] ) ? $item['body'] : '';
		}

		if ( (int) $overflow > 0 ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: number of additional notifications beyond what could be listed individually. */
				__( '%d additional notification(s) occurred and could not be listed individually.', 'b2b-price-lists' ),
				(int) $overflow
			);
		}

		return implode( "\n", $lines );
	}
}
