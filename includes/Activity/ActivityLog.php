<?php
/**
 * Write side of the activity log: who did what, to what, and when.
 *
 * Recorded from the service layer (`Domain\ListService`,
 * `Domain\RuleGroupService`, `Domain\UserAssignmentService`,
 * `Admin\SettingsController`) rather than from the controllers, so an action
 * is logged wherever it is triggered from -- the list editor, the user
 * profile screen, or any future programmatic caller -- and only once the
 * write has actually succeeded.
 *
 * Every row is a snapshot: the user's name, the list's name and the labels
 * inside `changes` are stored as they read at that moment, so the entry still
 * makes sense after the list, the rule or even the user is deleted. That is
 * the whole point of an audit trail, and it is why this table has no foreign
 * keys and is never cascaded.
 *
 * Nothing here ever interrupts the action being logged: a logging failure is
 * swallowed, because losing a log line is an annoyance and failing a price
 * change because of one is a real problem.
 *
 * @package B2BPriceLists\Activity
 */

namespace B2BPriceLists\Activity;

use B2BPriceLists\Database\Schema;
use B2BPriceLists\Database\Transaction;
use B2BPriceLists\Support\Logger;
use B2BPriceLists\Webhook\Context;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityLog
 */
class ActivityLog {

	/**
	 * Action names. Kept as stable, machine-readable strings: the human
	 * sentence is built at render time, so a log written today still reads
	 * correctly if the site changes language tomorrow.
	 */
	const LIST_CREATED     = 'list.created';
	const LIST_UPDATED     = 'list.updated';
	const LIST_DELETED     = 'list.deleted';
	const LIST_DUPLICATED  = 'list.duplicated';
	const RULE_CREATED     = 'rule.created';
	const RULE_UPDATED     = 'rule.updated';
	const RULE_DELETED     = 'rule.deleted';
	const USER_ASSIGNED    = 'user.assigned';
	const USER_UNASSIGNED  = 'user.unassigned';
	const SETTINGS_UPDATED = 'settings.updated';

	/**
	 * Catalogue actions: the webhook creating, updating or
	 * retiring a WooCommerce product. Recorded here as well as in the
	 * webhook's own batch log, because this screen is where somebody asks
	 * "who changed this, and when" without knowing a batch even exists.
	 */
	const PRODUCT_CREATED      = 'product.created';
	const PRODUCT_UPDATED      = 'product.updated';
	const PRODUCT_DISCONTINUED = 'product.discontinued';

	/**
	 * Cron hook that applies the retention window.
	 *
	 * @var string
	 */
	const PURGE_HOOK = 'b2bpl_purge_activity_log';

	/**
	 * Option holding the retention window, in months.
	 *
	 * @var string
	 */
	const RETENTION_OPTION = 'b2bpl_activity_retention_months';

	/**
	 * Default retention window, in months.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_MONTHS = 6;

	/**
	 * The only retention windows offered. Three months was dropped in 1.3.0:
	 * too short to settle a question about a price change a customer raises
	 * a season later.
	 *
	 * @var int[]
	 */
	const RETENTION_CHOICES = array( 6, 12, 24 );

	/**
	 * Entries archived and removed per purge pass, and the most passes one
	 * cron run may do, so a very large backlog is worked through over a few
	 * days instead of in one long request.
	 */
	const PURGE_BATCH      = 500;
	const PURGE_MAX_PASSES = 40;

	/**
	 * Every action this plugin records, for the screen's filter dropdown.
	 *
	 * @return string[]
	 */
	public static function actions() {
		return array(
			self::LIST_CREATED,
			self::LIST_UPDATED,
			self::LIST_DUPLICATED,
			self::LIST_DELETED,
			self::RULE_CREATED,
			self::RULE_UPDATED,
			self::RULE_DELETED,
			self::USER_ASSIGNED,
			self::USER_UNASSIGNED,
			self::PRODUCT_CREATED,
			self::PRODUCT_UPDATED,
			self::PRODUCT_DISCONTINUED,
			self::SETTINGS_UPDATED,
		);
	}

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
	 * The retention windows offered in Settings, in months.
	 *
	 * @return int[]
	 */
	public static function retention_choices() {
		return self::RETENTION_CHOICES;
	}

	/**
	 * The configured retention window in months. Anything that is not one
	 * of the offered choices -- including the 3 months earlier versions
	 * allowed -- reads as the default, so no site keeps a window the screen
	 * can no longer show.
	 *
	 * @return int
	 */
	public static function retention_months() {
		$months = (int) get_option( self::RETENTION_OPTION, self::DEFAULT_RETENTION_MONTHS );

		return in_array( $months, self::RETENTION_CHOICES, true ) ? $months : self::DEFAULT_RETENTION_MONTHS;
	}

	/**
	 * The date before which entries leave the live log, in the same
	 * site-local frame the entries are stored in.
	 *
	 * @return string MySQL datetime.
	 */
	public static function retention_cutoff() {
		return gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::retention_months() . ' months', (int) current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- entries are stored in site-local time via current_time( 'mysql' ), so the cutoff has to be computed in that same frame.
	}

	/**
	 * Records one action.
	 *
	 * @param string $action  One of the constants above.
	 * @param array  $context {
	 *     What was acted on, and what changed.
	 *
	 *     @type string   $object_type    'list', 'rule', 'user' or 'settings'.
	 *     @type int      $object_id      Id of the thing acted on.
	 *     @type string   $object_label   Human label, snapshotted.
	 *     @type int      $list_id        List the action belongs to, when any.
	 *     @type string   $list_name      List name, snapshotted.
	 *     @type array    $changes        Result of `ChangeSet`, or any extra facts.
	 *     @type string   $source         Who triggered the action: 'admin'
	 * (default) or 'webhook' (
	 *). When omitted, falls
	 *                                    back to `Webhook\Context::get()`'s
	 *                                    active value, then to 'admin' --
	 *                                    identical to this method's behavior
	 *                                    before 1.5.0, for every caller that
	 *                                    never sets an active context.
	 *     @type string   $batch_id       Webhook batch UUID, when `source`
	 *                                    is 'webhook'. Same fallback chain
	 *                                    as `source`, defaulting to `null`.
	 *     @type int      $actor_user_id  Who performed the action. When
	 *                                    omitted, falls back to the active
	 *                                    context, then to
	 *                                    `get_current_user_id()` -- the
	 *                                    integration user the webhook batch
	 * processor runs as, or
	 *                                    the logged-in admin, exactly as
	 *                                    before.
	 *     @type string   $actor_name     Snapshotted display name for
	 *                                    `$actor_user_id`. Same fallback
	 *                                    chain, defaulting to the current
	 *                                    user's own display name.
	 * }
	 * @return void
	 */
	public static function record( $action, array $context = array() ) {
		try {
			global $wpdb;

			$active = Context::get();

			$source = isset( $context['source'] ) && '' !== (string) $context['source']
				? (string) $context['source']
				: ( isset( $active['source'] ) ? (string) $active['source'] : 'admin' );

			$batch_id = isset( $context['batch_id'] )
				? (string) $context['batch_id']
				: ( isset( $active['batch_id'] ) ? (string) $active['batch_id'] : null );

			if ( isset( $context['actor_user_id'] ) || isset( $context['actor_name'] ) ) {
				$actor_user_id = (int) ( $context['actor_user_id'] ?? 0 );
				$actor_name    = (string) ( $context['actor_name'] ?? '' );
			} elseif ( isset( $active['actor_user_id'] ) || isset( $active['actor_name'] ) ) {
				$actor_user_id = (int) ( $active['actor_user_id'] ?? 0 );
				$actor_name    = (string) ( $active['actor_name'] ?? '' );
			} else {
				// Identical to this method's behavior before 1.5.0: the
				// currently logged-in user, snapshotted so a later-deleted
				// user does not turn every past entry into a bare numeric id.
				$user          = wp_get_current_user();
				$actor_user_id = (int) get_current_user_id();
				$actor_name    = $user && $user->exists() ? (string) $user->display_name : '';
			}

			$wpdb->insert(
				Schema::activity_table(),
				array(
					'created_at'   => current_time( 'mysql' ),
					'user_id'      => $actor_user_id,
					'user_name'    => $actor_name,
					'action'       => (string) $action,
					'object_type'  => (string) ( $context['object_type'] ?? '' ),
					'object_id'    => (int) ( $context['object_id'] ?? 0 ),
					'object_label' => (string) ( $context['object_label'] ?? '' ),
					'list_id'      => (int) ( $context['list_id'] ?? 0 ),
					'list_name'    => (string) ( $context['list_name'] ?? '' ),
					'changes'      => ! empty( $context['changes'] ) ? wp_json_encode( $context['changes'] ) : null,
					'source'       => $source,
					'batch_id'     => $batch_id,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		} catch ( Throwable $exception ) {
			// Never let the audit trail break the action it is auditing.
			Logger::error(
				'Could not write an activity log entry.',
				array(
					'action'  => $action,
					'message' => $exception->getMessage(),
				)
			);
		}
	}

	/**
	 * Moves entries older than the retention window into their monthly
	 * archive, then removes them from the live log. Runs daily.
	 *
	 * Nothing is ever deleted without having been archived first: each pass
	 * writes a batch into the archive of every month it covers and deletes
	 * exactly those ids, inside one transaction. If the archive write fails,
	 * the transaction rolls back, nothing is deleted, and the next day's run
	 * tries again.
	 *
	 * @return int Entries archived and removed.
	 */
	public static function purge_expired() {
		$activity = new ActivityRepository();
		$archives = new ActivityArchiveRepository();
		$cutoff   = self::retention_cutoff();
		$moved    = 0;

		try {
			for ( $pass = 0; $pass < self::PURGE_MAX_PASSES; $pass++ ) {
				$batch = $activity->expired_batch( $cutoff, self::PURGE_BATCH );

				if ( empty( $batch ) ) {
					break;
				}

				Transaction::run(
					static function () use ( $batch, $activity, $archives ) {
						foreach ( self::group_by_month( $batch ) as $period => $entries ) {
							$first = reset( $entries );
							$last  = end( $entries );

							if ( ! $archives->append( $period, ActivityExporter::lines( $entries ), count( $entries ), (string) $first['created_at'], (string) $last['created_at'] ) ) {
								throw new RuntimeException( 'Could not write the activity archive for ' . esc_html( $period ) . '.' );
							}
						}

						$ids = array_map(
							static function ( $entry ) {
								return (int) $entry['id'];
							},
							$batch
						);

						if ( count( $ids ) !== (int) $activity->delete_ids( $ids ) ) {
							throw new RuntimeException( 'Could not remove the archived activity entries.' );
						}
					}
				);

				$moved += count( $batch );

				if ( count( $batch ) < self::PURGE_BATCH ) {
					break;
				}
			}
		} catch ( Throwable $exception ) {
			Logger::error(
				'Activity log retention pass stopped; nothing past this point was deleted.',
				array(
					'moved'   => $moved,
					'message' => $exception->getMessage(),
				)
			);
		}

		return $moved;
	}

	/**
	 * Groups entries by the calendar month they were recorded in, keeping
	 * their order.
	 *
	 * @param array $entries Raw log rows, oldest first.
	 * @return array<string,array> Month `YYYY-MM` => entries.
	 */
	private static function group_by_month( array $entries ) {
		$groups = array();

		foreach ( $entries as $entry ) {
			$groups[ substr( (string) $entry['created_at'], 0, 7 ) ][] = $entry;
		}

		return $groups;
	}

	/**
	 * Clears the retention cron. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::PURGE_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::PURGE_HOOK );
		}
	}
}
