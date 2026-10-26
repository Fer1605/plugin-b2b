<?php
/**
 * View: create/edit list form, with "Details" and "Users" tabs (the
 * "Groups" tab is rendered separately by `RuleGroupController`).
 *
 * Expected variables: $list (array|null), $pending (array|null), $tab
 * (string), $assigned_users (WP_User[]), $list_url (callable), $nonce_field
 * (string), $nonce_name (string).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\ListsController;
use B2BPriceLists\Admin\Menu;
use B2BPriceLists\Admin\UI;
use B2BPriceLists\Admin\View;
use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Domain\ListService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit         = null !== $list;
$list_id_value   = $is_edit ? (int) $list['id'] : 0;
$notice          = isset( $_GET['b2bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$pending_user_id = isset( $_GET['b2bpl_pending_user'] ) ? absint( $_GET['b2bpl_pending_user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Field values: what the admin just typed on a rejected submission first,
// then the stored list, then empty.
$values = array(
	'name'             => $is_edit ? (string) $list['name'] : '',
	'slug'             => $is_edit ? (string) $list['slug'] : '',
	'description'      => $is_edit ? (string) $list['description'] : '',
	'status'           => $is_edit ? (string) $list['status'] : ListService::STATUS_INACTIVE,
	'discount_percent' => $is_edit ? Discount::format_percent( Discount::normalize( $list['discount_percent'] ?? null ) ) : '',
	'external_code'    => $is_edit ? (string) ( $list['external_code'] ?? '' ) : '',
);

if ( is_array( $pending ) ) {
	foreach ( array_keys( $values ) as $field ) {
		if ( isset( $pending[ $field ] ) && is_scalar( $pending[ $field ] ) ) {
			$values[ $field ] = (string) $pending[ $field ];
		}
	}
}
$help_url = add_query_arg(
	array(
		'page'   => Menu::PAGE_SLUG,
		'action' => 'help',
	),
	admin_url( 'admin.php' )
);

// Page header. The status badge only makes sense once the list exists: on a
// brand-new one the stored status is still nothing at all.
$header_args = array(
	'title'    => $is_edit ? __( 'Edit price list', 'b2b-price-lists' ) : __( 'Add price list', 'b2b-price-lists' ),
	'subtitle' => $is_edit
		? __( 'Its details, the customers it covers and the prices they get', 'b2b-price-lists' )
		: __( 'Name the new list; its customers and prices come next', 'b2b-price-lists' ),
	'icon'     => 'dashicons-portfolio',
	'actions'  => array(
		array(
			'label' => __( 'User guide', 'b2b-price-lists' ),
			'url'   => $help_url,
			'icon'  => 'dashicons-book',
		),
	),
);

if ( $is_edit ) {
	$header_args['badge'] = ListService::STATUS_ACTIVE === $values['status']
		? array(
			'label' => __( 'Active', 'b2b-price-lists' ),
			'tone'  => 'ok',
			'icon'  => 'dashicons-yes-alt',
		)
		: array(
			'label' => __( 'Inactive', 'b2b-price-lists' ),
			'tone'  => 'off',
			'icon'  => 'dashicons-marker',
		);
}
?>
<div class="wrap b2bpl-admin">
	<?php
	// Same banner the rules screens carry: every tab under a list has to say
	// which list it belongs to, or they all look identical.
	if ( $is_edit ) {
		View::render(
			'list-context',
			array(
				'list_id' => $list_id_value,
				'list'    => $list,
			)
		);
	}

	UI::screen_header( $header_args );
	?>
	<hr class="wp-header-end">

	<p class="b2bpl-breadcrumb"><a href="<?php echo esc_url( call_user_func( $list_url ) ); ?>">&larr; <?php esc_html_e( 'Back to lists', 'b2b-price-lists' ); ?></a></p>

	<?php if ( 'saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'List saved.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'user-assigned' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'User assigned to this list.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'user-reassigned' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( ListsController::consume_admin_notice() ); ?></p></div>
	<?php elseif ( 'user-unassigned' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'User removed from this list.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'error' === $notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( ListsController::consume_error_notice() ); ?></p></div>
	<?php endif; ?>

	<?php
	if ( $is_edit ) {
		$details_url = call_user_func(
			$list_url,
			array(
				'action' => 'edit',
				'id'     => $list_id_value,
				'tab'    => 'details',
			)
		);
		$users_url   = call_user_func(
			$list_url,
			array(
				'action' => 'edit',
				'id'     => $list_id_value,
				'tab'    => 'users',
			)
		);
		$groups_url  = call_user_func(
			$list_url,
			array(
				'action' => 'edit',
				'id'     => $list_id_value,
				'tab'    => 'groups',
			)
		);

		UI::tabs(
			array(
				array(
					'label'  => __( 'Details', 'b2b-price-lists' ),
					'url'    => $details_url,
					'icon'   => 'dashicons-portfolio',
					'active' => 'details' === $tab,
				),
				array(
					'label'  => __( 'Assigned users', 'b2b-price-lists' ),
					'url'    => $users_url,
					'icon'   => 'dashicons-groups',
					'active' => 'users' === $tab,
				),
				array(
					'label'  => __( 'Products &amp; prices', 'b2b-price-lists' ),
					'url'    => $groups_url,
					'icon'   => 'dashicons-tag',
					'active' => false,
				),
			)
		);
	}
	?>

	<?php if ( ! $is_edit || 'details' === $tab ) : ?>
		<?php
		UI::hero(
			$is_edit
				? esc_html__( 'These are the settings of the list itself. The products it covers, and what they cost, live in the "Products & prices" tab.', 'b2b-price-lists' )
				: esc_html__( 'Give the list a name and save it. Its products, prices and customers are added right afterwards.', 'b2b-price-lists' ),
			'dashicons-lightbulb',
			true
		);
		?>
		<form method="post" action="<?php echo esc_url( call_user_func( $list_url ) ); ?>">
			<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
			<input type="hidden" name="b2bpl_action" value="save-list">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $list_id_value ); ?>">

			<?php UI::card_open( __( 'List details', 'b2b-price-lists' ), 'dashicons-portfolio', 'blue' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="b2bpl-name"><?php esc_html_e( 'Name', 'b2b-price-lists' ); ?></label></th>
					<td><input type="text" id="b2bpl-name" name="name" class="regular-text" required
						value="<?php echo esc_attr( $values['name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="b2bpl-slug"><?php esc_html_e( 'Slug', 'b2b-price-lists' ); ?></label></th>
					<td>
						<input type="text" id="b2bpl-slug" name="slug" class="regular-text"
							value="<?php echo esc_attr( $values['slug'] ); ?>"
							placeholder="<?php esc_attr_e( 'Leave empty to generate automatically from the name', 'b2b-price-lists' ); ?>">
						<p class="description"><?php esc_html_e( 'Unique identifier. Leave empty to auto-generate from the name; a numeric suffix is added automatically if it is already in use.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="b2bpl-description"><?php esc_html_e( 'Description', 'b2b-price-lists' ); ?></label></th>
					<td><textarea id="b2bpl-description" name="description" class="large-text" rows="4"><?php echo esc_textarea( $values['description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="b2bpl-status"><?php esc_html_e( 'Status', 'b2b-price-lists' ); ?></label></th>
					<td>
						<select id="b2bpl-status" name="status">
							<option value="<?php echo esc_attr( ListService::STATUS_ACTIVE ); ?>" <?php selected( ListService::STATUS_ACTIVE === $values['status'] ); ?>>
								<?php esc_html_e( 'Active', 'b2b-price-lists' ); ?>
							</option>
							<option value="<?php echo esc_attr( ListService::STATUS_INACTIVE ); ?>" <?php selected( ListService::STATUS_ACTIVE !== $values['status'] ); ?>>
								<?php esc_html_e( 'Inactive', 'b2b-price-lists' ); ?>
							</option>
						</select>
						<p class="description"><?php esc_html_e( 'Only an active list changes prices. Leave it inactive while you build it: nothing changes for anybody until you switch it on.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="b2bpl-list-discount"><?php esc_html_e( 'Offer for the whole list', 'b2b-price-lists' ); ?></label>
						<span class="b2bpl-optional"><?php esc_html_e( '(optional)', 'b2b-price-lists' ); ?></span>
					</th>
					<td>
						<span class="b2bpl-percent-field">
							<input type="text" inputmode="decimal" id="b2bpl-list-discount" name="discount_percent" class="small-text"
								value="<?php echo esc_attr( $values['discount_percent'] ); ?>" placeholder="0">
							<span class="b2bpl-percent-field__sign">%</span>
						</span>
						<p class="description"><?php esc_html_e( 'Takes this percentage off every price in this list — fixed prices and quantity ranges alike. Leave it empty for no offer.', 'b2b-price-lists' ); ?></p>
						<p class="description"><?php esc_html_e( 'A rule, or a single range inside a rule, can carry its own offer: when it does, that more specific percentage wins and this one does not apply to it. Percentages are never added together.', 'b2b-price-lists' ); ?></p>
						<p class="description"><?php esc_html_e( 'Example: with the list at 20%, a product priced at 50.00 is sold at 40.00 — and the customer sees 50.00 struck through next to it.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="b2bpl-external-code"><?php esc_html_e( 'External code', 'b2b-price-lists' ); ?></label>
						<span class="b2bpl-optional"><?php esc_html_e( '(optional)', 'b2b-price-lists' ); ?></span>
					</th>
					<td>
						<input type="text" id="b2bpl-external-code" name="external_code" class="regular-text"
							value="<?php echo esc_attr( $values['external_code'] ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. MAYORISTA-A', 'b2b-price-lists' ); ?>">
						<p class="description"><?php esc_html_e( 'Identifier used by an external system (via the webhook integration) to refer to this exact list. Leave it empty unless an external system needs it; it must be unique across every list.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
			</table>
			<?php UI::card_close(); ?>

			<?php submit_button( $is_edit ? __( 'Save changes', 'b2b-price-lists' ) : __( 'Create list', 'b2b-price-lists' ) ); ?>
		</form>
	<?php elseif ( 'users' === $tab ) : ?>
		<?php
		UI::hero(
			esc_html__( 'Only the customers listed here get this list\'s prices; everybody else keeps the store\'s normal ones.', 'b2b-price-lists' ),
			'dashicons-groups',
			true
		);

		UI::card_open( __( 'Currently assigned users', 'b2b-price-lists' ), 'dashicons-groups', 'teal' );
		?>
		<table class="wp-list-table widefat striped b2bpl-users-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'User', 'b2b-price-lists' ); ?></th>
					<th><?php esc_html_e( 'Email', 'b2b-price-lists' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'b2b-price-lists' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $assigned_users ) ) : ?>
					<tr>
						<td colspan="3" class="b2bpl-empty">
							<p><?php esc_html_e( 'No users assigned yet.', 'b2b-price-lists' ); ?></p>
							<p><?php esc_html_e( 'Until somebody is assigned, this list changes nothing for anybody.', 'b2b-price-lists' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $assigned_users as $user ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $user->display_name ); ?></strong>
							<span class="b2bpl-user-id">#<?php echo (int) $user->ID; ?></span>
						</td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( call_user_func( $list_url ) ); ?>" class="b2bpl-inline-form">
								<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
								<input type="hidden" name="b2bpl_action" value="unassign-user">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $list_id_value ); ?>">
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>">
								<button type="submit" class="button-link b2bpl-link-danger b2bpl-confirm-remove-user">
									<?php esc_html_e( 'Remove', 'b2b-price-lists' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php UI::card_close(); ?>

		<?php UI::card_open( __( 'Assign a user', 'b2b-price-lists' ), 'dashicons-plus-alt', 'green' ); ?>
		<form method="post" action="<?php echo esc_url( call_user_func( $list_url ) ); ?>">
			<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
			<input type="hidden" name="b2bpl_action" value="assign-user">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $list_id_value ); ?>">
			<?php if ( $pending_user_id ) : ?>
				<input type="hidden" name="confirm" value="1">
			<?php endif; ?>
			<p>
				<label for="b2bpl-user">
					<?php esc_html_e( 'User ID, username or email:', 'b2b-price-lists' ); ?>
				</label>
				<input type="text" id="b2bpl-user" name="user"
					value="<?php echo $pending_user_id ? esc_attr( (string) $pending_user_id ) : ''; ?>" class="regular-text">
				<button type="submit" class="button button-secondary">
					<?php echo $pending_user_id ? esc_html__( 'Confirm reassignment', 'b2b-price-lists' ) : esc_html__( 'Assign', 'b2b-price-lists' ); ?>
				</button>
			</p>
			<p class="description"><?php esc_html_e( 'Each user can be assigned to at most one price list. Assigning a user who already belongs to another list will ask for confirmation before moving them here.', 'b2b-price-lists' ); ?></p>
		</form>
		<?php UI::card_close(); ?>
	<?php endif; ?>
</div>
