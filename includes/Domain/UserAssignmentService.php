<?php
/**
 * Single source of truth for user -> list assignment, used
 * identically by the WordPress user profile screen and by the list
 * editor, so both paths write through the same code path onto
 * `b2bpl_list_users`.
 *
 * @package B2BPriceLists\Domain
 */

namespace B2BPriceLists\Domain;

use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\ListUsersRepository;
use B2BPriceLists\Support\Cache;
use B2BPriceLists\Support\RoleInstaller;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UserAssignmentService
 */
class UserAssignmentService {

	/**
	 * List/user assignment repository.
	 *
	 * @var ListUsersRepository
	 */
	private $list_users;

	/**
	 * Lists repository.
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * The side effects (activity log entry + cache invalidation) of the most
	 * recent `assign()`/`unassign()` call made with `$emit_side_effects = false`
	 * (M2, independent review of W4/W5 -- the same deferred-effects pattern
	 * `Domain\RuleGroupService` already uses), held here
	 * instead of emitted immediately until the caller
	 * (`Webhook\Operations\UserOperations`) has finished the whole webhook
	 * item and `Webhook\BatchProcessor::process_item()`'s own, truly
	 * outermost transaction has actually committed. `null` whenever nothing
	 * is pending.
	 *
	 * @var array{log: array{action:string,context:array}|null, cache_user_ids:int[]}|null
	 */
	private $pending_side_effects = null;

	/**
	 * Constructor.
	 *
	 * @param ListUsersRepository|null $list_users Optional, for tests.
	 * @param ListsRepository|null     $lists      Optional, for tests.
	 */
	public function __construct( ?ListUsersRepository $list_users = null, ?ListsRepository $lists = null ) {
		$this->list_users = $list_users ?? new ListUsersRepository();
		$this->lists      = $lists ?? new ListsRepository();
	}

	/**
	 * Returns the list currently assigned to a user, or null.
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|null
	 */
	public function get_assigned_list( $user_id ) {
		$list_id = $this->list_users->get_list_id_for_user( (int) $user_id );

		if ( null === $list_id ) {
			return null;
		}

		return $this->lists->find( $list_id );
	}

	/**
	 * Assigns a list to a user, replacing any previous assignment
	 * (at most one list per user).
	 *
	 * @param int  $user_id           User id.
	 * @param int  $list_id           List id.
	 * @param bool $emit_side_effects When `true` (default, the panel's
	 *                                 unchanged behavior), the activity log
	 *                                 entry and cache invalidation happen
	 *                                 immediately. When `false` (M2,
	 *                                 independent review of W4/W5, used by
	 *                                 `Webhook\Operations\UserOperations`),
	 *                                 both are instead stashed for the
	 *                                 caller to read back with
	 *                                 `take_pending_side_effects()` and emit
	 *                                 only once the whole webhook item's own,
	 *                                 wider transaction has actually
	 *                                 committed.
	 * @return true|WP_Error
	 */
	public function assign( $user_id, $list_id, $emit_side_effects = true ) {
		$list_id = (int) $list_id;
		$list    = $this->lists->find( $list_id );

		if ( ! $list ) {
			return new WP_Error( 'b2bpl_not_found', __( 'The selected list could not be found.', 'b2b-price-lists' ) );
		}

		// Read before the write: a reassignment is the interesting case, and
		// afterwards there is no trace of where the customer came from.
		$previous = $this->get_assigned_list( (int) $user_id );

		if ( ! $this->list_users->assign( (int) $user_id, $list_id ) ) {
			return new WP_Error( 'b2bpl_save_failed', __( 'The user could not be assigned to the list.', 'b2b-price-lists' ) );
		}

		$changes = array();

		if ( $previous && (int) $previous['id'] !== $list_id ) {
			$changes[] = array(
				'field' => 'moved_from_list',
				'label' => (string) $previous['name'],
				'id'    => (int) $previous['id'],
			);
		}

		// a price list is what makes an account wholesale, so a
		// plain retail customer given one from the panel is labelled a B2B
		// account on the spot, which is what lets the shop tell its
		// wholesale customers apart later. Deliberately only on this path:
		// the webhook says what kind of account it is with its own
		// `customer_type` field and must keep deciding that for itself.
		// Never the other way round either -- unassigning leaves the role
		// alone -- and `promote_to_b2b()` refuses any account that is not
		// exactly a `customer`, so no existing permission is ever touched.
		if ( $emit_side_effects && RoleInstaller::promote_to_b2b( (int) $user_id ) ) {
			$changes[] = array(
				'field' => 'role_changed_to',
				'label' => RoleInstaller::b2b_role(),
			);
		}

		$log_event = array(
			'action'  => ActivityLog::USER_ASSIGNED,
			'context' => array(
				'object_type'  => 'user',
				'object_id'    => (int) $user_id,
				'object_label' => self::user_label( (int) $user_id ),
				'list_id'      => $list_id,
				'list_name'    => (string) $list['name'],
				'changes'      => $changes,
			),
		);

		if ( $emit_side_effects ) {
			Cache::invalidate_user( $user_id );
			ActivityLog::record( $log_event['action'], $log_event['context'] );
		} else {
			$this->pending_side_effects = array(
				'log'            => $log_event,
				'cache_user_ids' => array( (int) $user_id ),
			);
		}

		return true;
	}

	/**
	 * Reads back and clears the side effects stashed by the most recent
	 * `assign()`/`unassign()` call made with `$emit_side_effects = false`.
	 *
	 * @return array{log: array{action:string,context:array}|null, cache_user_ids:int[]}|null
	 *               `null` when nothing is pending (either `assign()`/`unassign()`
	 *               was never called with `$emit_side_effects = false`, or
	 *               this method already consumed the one pending result).
	 */
	public function take_pending_side_effects() {
		$pending                    = $this->pending_side_effects;
		$this->pending_side_effects = null;

		return $pending;
	}

	/**
	 * A readable name for a customer, snapshotted into the log so the entry
	 * survives the account being deleted.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function user_label( $user_id ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return '#' . (int) $user_id;
		}

		return $user->display_name ? $user->display_name : $user->user_login;
	}

	/**
	 * Removes a user's assignment (the user returns to standard B2C
	 * pricing).
	 *
	 * @param int  $user_id           User id.
	 * @param bool $emit_side_effects Same meaning and default as `assign()`'s
	 *                                 own parameter (M2, independent review
	 *                                 of W4/W5).
	 * @return true
	 */
	public function unassign( $user_id, $emit_side_effects = true ) {
		$previous = $this->get_assigned_list( (int) $user_id );

		$this->list_users->unassign( (int) $user_id );

		$log_event = $previous
			? array(
				'action'  => ActivityLog::USER_UNASSIGNED,
				'context' => array(
					'object_type'  => 'user',
					'object_id'    => (int) $user_id,
					'object_label' => self::user_label( (int) $user_id ),
					'list_id'      => (int) $previous['id'],
					'list_name'    => (string) $previous['name'],
				),
			)
			: null;

		if ( $emit_side_effects ) {
			Cache::invalidate_user( $user_id );

			if ( null !== $log_event ) {
				ActivityLog::record( $log_event['action'], $log_event['context'] );
			}
		} else {
			$this->pending_side_effects = array(
				'log'            => $log_event,
				'cache_user_ids' => array( (int) $user_id ),
			);
		}

		return true;
	}

	/**
	 * Returns every user id assigned to a given list.
	 *
	 * @param int $list_id List id.
	 * @return int[]
	 */
	public function get_users_for_list( $list_id ) {
		return $this->list_users->get_user_ids_for_list( (int) $list_id );
	}
}
