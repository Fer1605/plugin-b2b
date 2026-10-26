<?php
/**
 * Cleanup hooks triggered by deletion of WordPress/WooCommerce entities
 * that the plugin's own tables reference.
 *
 * @package B2BPriceLists\Cleanup
 */

namespace B2BPriceLists\Cleanup;

use B2BPriceLists\Domain\RuleGroupService;
use B2BPriceLists\Repository\ListUsersRepository;
use B2BPriceLists\Repository\RuleGroupVariationsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EntityCleanup
 */
class EntityCleanup {

	/**
	 * Registers every cleanup hook. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public static function register() {
		// A WordPress user is being permanently deleted: drop their list
		// assignment row (TC-covered implicitly by the UNIQUE(user_id)
		// constraint no longer applying to a deleted user).
		add_action( 'delete_user', array( self::class, 'on_user_deleted' ) );

		// A post (product or product variation) is being permanently
		// deleted. `before_delete_post` fires for the real delete, not for
		// moving to trash, so an accidentally trashed product does not
		// lose its rules.
		add_action( 'before_delete_post', array( self::class, 'on_post_deleted' ) );
	}

	/**
	 * Removes the `b2bpl_list_users` row for a deleted WordPress user.
	 *
	 * @param int $user_id Deleted user id.
	 * @return void
	 */
	public static function on_user_deleted( $user_id ) {
		( new ListUsersRepository() )->delete_by_user_id( (int) $user_id );
	}

	/**
	 * Removes rule groups (product deletion, TC-062) or a single variation
	 * relationship (variation deletion, TC-061), depending on the post
	 * type of the entity being permanently deleted.
	 *
	 * @param int $post_id Deleted post id.
	 * @return void
	 */
	public static function on_post_deleted( $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( 'product' === $post_type ) {
			( new RuleGroupService() )->delete_all_for_product( (int) $post_id );
		} elseif ( 'product_variation' === $post_type ) {
			( new RuleGroupVariationsRepository() )->delete_by_variation_id( (int) $post_id );
		}
	}
}
