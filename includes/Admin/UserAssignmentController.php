<?php
/**
 * "B2B price list" field on the WordPress user profile screen -- one of
 * the two paths to `b2bpl_list_users` (the other is the list editor's
 * "Assigned users" tab, `ListsController`). Both paths write through the
 * same `Domain\UserAssignmentService`, the single source of truth.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Domain\UserAssignmentService;
use B2BPriceLists\Order\OrdersListIntegration;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\Nonces;
use B2BPriceLists\Webhook\Operations\UserOperations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UserAssignmentController
 */
class UserAssignmentController {

	/**
	 * User assignment service.
	 *
	 * @var UserAssignmentService
	 */
	private $service;

	/**
	 * Lists repository.
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * Registers the profile screen hooks. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		$this->service = new UserAssignmentService();
		$this->lists   = new ListsRepository();

		add_action( 'show_user_profile', array( $this, 'render_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_field' ) );
	}

	/**
	 * Renders the "B2B price list" select on a user's profile screen.
	 *
	 * Visible only to users who may manage price lists themselves (a
	 * regular user editing their own profile never sees or controls their
	 * own price list assignment -- roles never determine B2B pricing,
	 *, and this field is a management action, not a personal
	 * preference).
	 *
	 * @param \WP_User $user The profile owner.
	 * @return void
	 */
	public function render_field( $user ) {
		if ( ! Capabilities::current_user_can_manage() ) {
			return;
		}

		$assigned_list = $this->service->get_assigned_list( $user->ID );
		$lists         = $this->lists->all();

		wp_nonce_field( Nonces::USER_PROFILE_ACTION, Nonces::FIELD_NAME );
		?>
		<h2><?php esc_html_e( 'B2B Price List', 'b2b-price-lists' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="b2bpl-user-list"><?php esc_html_e( 'Assigned list', 'b2b-price-lists' ); ?></label></th>
				<td>
					<select name="b2bpl_user_list" id="b2bpl-user-list">
						<option value="0"><?php esc_html_e( '— None (standard WooCommerce pricing) —', 'b2b-price-lists' ); ?></option>
						<?php foreach ( $lists as $list ) : ?>
							<option value="<?php echo esc_attr( (string) $list['id'] ); ?>" <?php selected( $assigned_list && (int) $assigned_list['id'] === (int) $list['id'] ); ?>>
								<?php echo esc_html( $list['name'] ); ?>
								<?php if ( 'inactive' === $list['status'] ) : ?>
									(<?php esc_html_e( 'inactive', 'b2b-price-lists' ); ?>)
								<?php endif; ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'A user can be assigned to at most one B2B price list. Selecting a different list replaces the current assignment. A plain customer given a list is also marked as a B2B account, which is only a label for telling wholesale customers apart: the price always comes from the list, never from the role. An account with any other role is left exactly as it is.', 'b2b-price-lists' ); ?>
					</p>
					<?php if ( $assigned_list && current_user_can( 'edit_shop_orders' ) ) : ?>
						<p>
							<a href="
							<?php
							echo esc_url(
								OrdersListIntegration::orders_url(
									array(
										'_customer_user'   => (int) $user->ID,
										'b2bpl_order_type' => 'b2b',
									)
								)
							);
							?>
										">
								<?php esc_html_e( 'View their B2B orders', 'b2b-price-lists' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php $this->render_webhook_origin_rows( $user ); ?>
		</table>
		<?php
	}

	/**
	 * Read-only "External code"/"Created via" rows (T032): the webhook's own
	 * `_b2bpl_external_code`/`_b2bpl_created_via` metadata, shown only when
	 * at least one of them is actually set. Nothing here is editable --
	 * these fields are only ever written by `Webhook\Operations\UserOperations`.
	 *
	 * @param \WP_User $user The profile owner.
	 * @return void
	 */
	private function render_webhook_origin_rows( $user ) {
		$external_code = get_user_meta( $user->ID, UserOperations::META_EXTERNAL_CODE, true );
		$created_via   = get_user_meta( $user->ID, UserOperations::META_CREATED_VIA, true );

		if ( '' === (string) $external_code && '' === (string) $created_via ) {
			return;
		}
		?>
		<?php if ( '' !== (string) $external_code ) : ?>
			<tr>
				<th><?php esc_html_e( 'External code', 'b2b-price-lists' ); ?></th>
				<td>
					<code><?php echo esc_html( (string) $external_code ); ?></code>
					<p class="description"><?php esc_html_e( 'Customer code from the external system, set by the webhook. Read-only.', 'b2b-price-lists' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
		<?php if ( '' !== (string) $created_via ) : ?>
			<tr>
				<th><?php esc_html_e( 'Created via', 'b2b-price-lists' ); ?></th>
				<td>
					<?php echo esc_html( 'webhook' === $created_via ? __( 'Webhook', 'b2b-price-lists' ) : __( 'Admin panel', 'b2b-price-lists' ) ); ?>
				</td>
			</tr>
		<?php endif; ?>
		<?php
	}

	/**
	 * Saves the "B2B price list" selection.
	 *
	 * @param int $user_id Edited user id.
	 * @return void
	 */
	public function save_field( $user_id ) {
		if ( ! isset( $_POST[ Nonces::FIELD_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ Nonces::FIELD_NAME ] ) ), Nonces::USER_PROFILE_ACTION ) ) {
			return;
		}

		if ( ! Capabilities::current_user_can_manage() ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['b2bpl_user_list'] ) ) {
			return;
		}

		$list_id = absint( wp_unslash( $_POST['b2bpl_user_list'] ) );

		if ( 0 === $list_id ) {
			$this->service->unassign( $user_id );

			return;
		}

		$this->service->assign( $user_id, $list_id );
	}
}
