<?php
/**
 * Persistence for `b2bpl_list_users` (the single source of truth for
 * user -> list assignment).
 *
 * @package B2BPriceLists\Repository
 */

namespace B2BPriceLists\Repository;

use B2BPriceLists\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ListUsersRepository
 */
class ListUsersRepository {

	/**
	 * Returns the list id assigned to a user, or null when the user has no
	 * list.
	 *
	 * @param int $user_id User id.
	 * @return int|null
	 */
	public function get_list_id_for_user( $user_id ) {
		global $wpdb;

		$table = Schema::list_users_table();

		$list_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT list_id FROM {$table} WHERE user_id = %d", (int) $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null === $list_id ? null : (int) $list_id;
	}

	/**
	 * Returns every user id assigned to a given list.
	 *
	 * @param int $list_id List id.
	 * @return int[]
	 */
	public function get_user_ids_for_list( $list_id ) {
		global $wpdb;

		$table = Schema::list_users_table();

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT user_id FROM {$table} WHERE list_id = %d ORDER BY user_id ASC", (int) $list_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of users currently assigned to a list.
	 *
	 * @param int $list_id List id.
	 * @return int
	 */
	public function count_for_list( $list_id ) {
		global $wpdb;

		$table = Schema::list_users_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE list_id = %d", (int) $list_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Assigns a list to a user, replacing any previous assignment (
	 * at most one list per user, enforced here and by the UNIQUE(user_id)
	 * schema constraint as defense in depth).
	 *
	 * @param int $user_id User id.
	 * @param int $list_id List id.
	 * @return bool
	 */
	public function assign( $user_id, $list_id ) {
		global $wpdb;

		$table = Schema::list_users_table();

		$existing = $this->get_list_id_for_user( $user_id );

		if ( null !== $existing ) {
			$updated = $wpdb->update(
				$table,
				array( 'list_id' => (int) $list_id ),
				array( 'user_id' => (int) $user_id ),
				array( '%d' ),
				array( '%d' )
			);

			return false !== $updated;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'list_id'    => (int) $list_id,
				'user_id'    => (int) $user_id,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);

		return (bool) $inserted;
	}

	/**
	 * Removes a user's assignment entirely (the user returns to standard
	 * B2C behavior).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function unassign( $user_id ) {
		global $wpdb;

		$table = Schema::list_users_table();

		$deleted = $wpdb->delete( $table, array( 'user_id' => (int) $user_id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Removes every assignment for a list (used when a list is deleted).
	 *
	 * @param int $list_id List id.
	 * @return bool
	 */
	public function delete_by_list_id( $list_id ) {
		global $wpdb;

		$table = Schema::list_users_table();

		$deleted = $wpdb->delete( $table, array( 'list_id' => (int) $list_id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Cleanup hook target: removes the assignment row for a WordPress user
	 * that is being deleted.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function delete_by_user_id( $user_id ) {
		return $this->unassign( $user_id );
	}
}
