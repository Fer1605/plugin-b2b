<?php
/**
 * View: lists overview screen.
 *
 * Expected variables: $lists (array), $list_url (callable).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\ListsController;
use B2BPriceLists\Admin\UI;
use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Support\Nonces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = isset( $_GET['b2bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Status figures, counted from the rows already loaded for the table below --
// no extra queries just to show them.
$active_lists   = 0;
$assigned       = 0;
$lists_on_offer = 0;

foreach ( $lists as $list_row ) {
	if ( 'active' === $list_row['status'] ) {
		++$active_lists;
	}

	$assigned += (int) $list_row['user_count'];

	if ( null !== Discount::normalize( $list_row['discount_percent'] ?? null ) ) {
		++$lists_on_offer;
	}
}
?>
<div class="wrap b2bpl-admin">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'B2B Price Lists', 'b2b-price-lists' ),
			'subtitle' => __( 'Special prices for the customers you choose', 'b2b-price-lists' ),
			'icon'     => 'dashicons-portfolio',
			'badge'    => $active_lists > 0
				? array(
					/* translators: %d: number of active price lists. */
					'label' => sprintf( _n( '%d active list', '%d active lists', $active_lists, 'b2b-price-lists' ), $active_lists ),
					'tone'  => 'ok',
					'icon'  => 'dashicons-yes-alt',
				)
				: array(
					'label' => __( 'No active list', 'b2b-price-lists' ),
					'tone'  => 'off',
					'icon'  => 'dashicons-marker',
				),
			'actions'  => array(
				array(
					'label'   => __( 'Add New', 'b2b-price-lists' ),
					'url'     => call_user_func( $list_url, array( 'action' => 'new' ) ),
					'icon'    => 'dashicons-plus-alt2',
					'primary' => true,
				),
			),
		)
	);
	?>
	<hr class="wp-header-end">

	<?php UI::main_nav( 'lists' ); ?>

	<?php if ( ! empty( $lists ) ) : ?>
		<div class="b2bpl-stats">
			<?php
			UI::stat( count( $lists ), __( 'Price lists', 'b2b-price-lists' ), __( 'Created in total', 'b2b-price-lists' ), 'dashicons-portfolio', 'blue' );
			UI::stat( $active_lists, __( 'Active lists', 'b2b-price-lists' ), __( 'Already applying prices', 'b2b-price-lists' ), 'dashicons-yes-alt', 'green' );
			UI::stat( $assigned, __( 'Assigned customers', 'b2b-price-lists' ), __( 'Across every list', 'b2b-price-lists' ), 'dashicons-groups', 'teal' );
			UI::stat( $lists_on_offer, __( 'Lists with an offer', 'b2b-price-lists' ), __( 'With a discount on the whole list', 'b2b-price-lists' ), 'dashicons-tag', 'amber' );
			?>
		</div>
	<?php endif; ?>

	<?php
	// The quick guide opens by itself while there is nothing to show yet
	// (the moment somebody actually needs it), and stays collapsed once
	// there are lists, so it never gets in the way of daily work. The full
	// manual is one click away either way.
	?>
	<details class="b2bpl-card b2bpl-quickstart"<?php echo empty( $lists ) ? ' open' : ''; ?>>
		<summary>
			<span class="dashicons dashicons-lightbulb b2bpl-quickstart__bulb" aria-hidden="true"></span>
			<span class="b2bpl-quickstart__title"><?php esc_html_e( 'How this plugin works', 'b2b-price-lists' ); ?></span>
			<span class="b2bpl-quickstart__hint"><?php esc_html_e( 'read this first', 'b2b-price-lists' ); ?></span>
		</summary>

		<div class="b2bpl-quickstart__body">
			<p>
				<?php esc_html_e( 'This plugin gives specific customers their own prices, without touching the prices everybody else sees. Guests, and customers who are not on any list, keep the store\'s normal prices exactly as before.', 'b2b-price-lists' ); ?>
			</p>

			<ol class="b2bpl-steps b2bpl-steps--compact">
				<li>
					<h3><?php esc_html_e( 'Create the list', 'b2b-price-lists' ); ?></h3>
					<p><?php esc_html_e( 'Name it after the customers it serves. Leave it inactive while you fill it in.', 'b2b-price-lists' ); ?></p>
				</li>
				<li>
					<h3><?php esc_html_e( 'Add products and prices', 'b2b-price-lists' ); ?></h3>
					<p><?php esc_html_e( 'One rule per product, in the "Products & prices" tab: a fixed price, or a table of quantity ranges. You can also put an offer (a % discount) on a range, on a rule, or on the whole list.', 'b2b-price-lists' ); ?></p>
				</li>
				<li>
					<h3><?php esc_html_e( 'Assign the customers', 'b2b-price-lists' ); ?></h3>
					<p><?php esc_html_e( 'In the "Assigned users" tab, by user ID, username or email. One customer belongs to one list.', 'b2b-price-lists' ); ?></p>
				</li>
				<li>
					<h3><?php esc_html_e( 'Activate it', 'b2b-price-lists' ); ?></h3>
					<p><?php esc_html_e( 'From that moment those customers see their prices everywhere: catalogue, product page, cart and checkout.', 'b2b-price-lists' ); ?></p>
				</li>
			</ol>

			<p class="b2bpl-note">
				<strong><?php esc_html_e( 'The price table appears on its own.', 'b2b-price-lists' ); ?></strong>
				<?php esc_html_e( 'You do not have to edit the product page: the quantity/price table is printed automatically for any customer whose list covers that product, and for nobody else.', 'b2b-price-lists' ); ?>
			</p>

			<p>
				<a href="<?php echo esc_url( call_user_func( $list_url, array( 'action' => 'help' ) ) ); ?>" class="button">
					<?php esc_html_e( 'Read the full user guide', 'b2b-price-lists' ); ?>
				</a>
			</p>
		</div>
	</details>

	<?php if ( 'deleted' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The list was permanently deleted.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'error' === $notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( ListsController::consume_error_notice() ); ?></p></div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped b2bpl-lists-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Status', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Offer', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Users assigned', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'b2b-price-lists' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $lists ) ) : ?>
				<tr>
					<td colspan="6" class="b2bpl-empty">
						<p><?php esc_html_e( 'No B2B price lists yet.', 'b2b-price-lists' ); ?></p>
						<a href="<?php echo esc_url( call_user_func( $list_url, array( 'action' => 'new' ) ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Create the first list', 'b2b-price-lists' ); ?>
						</a>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $lists as $list ) : ?>
					<tr>
						<td>
							<a href="
							<?php
							echo esc_url(
								call_user_func(
									$list_url,
									array(
										'action' => 'edit',
										'id'     => $list['id'],
									)
								)
							);
							?>
										">
								<strong><?php echo esc_html( $list['name'] ); ?></strong>
							</a>
						</td>
						<td><code><?php echo esc_html( $list['slug'] ); ?></code></td>
						<td>
							<span class="b2bpl-badge b2bpl-badge--<?php echo 'active' === $list['status'] ? 'active' : 'inactive'; ?>">
								<?php echo esc_html( 'active' === $list['status'] ? __( 'Active', 'b2b-price-lists' ) : __( 'Inactive', 'b2b-price-lists' ) ); ?>
							</span>
						</td>
						<td>
							<?php $list_offer = Discount::normalize( $list['discount_percent'] ?? null ); ?>
							<?php if ( null !== $list_offer ) : ?>
								<span class="b2bpl-badge b2bpl-badge--offer">-<?php echo esc_html( Discount::format_percent( $list_offer ) ); ?>%</span>
							<?php else : ?>
								<span class="b2bpl-muted">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $list['user_count'] ); ?></td>
						<td class="b2bpl-actions">
							<a href="
							<?php
							echo esc_url(
								call_user_func(
									$list_url,
									array(
										'action' => 'edit',
										'id'     => $list['id'],
									)
								)
							);
							?>
										">
								<?php esc_html_e( 'Edit', 'b2b-price-lists' ); ?>
							</a>
							|
							<a href="
							<?php
							echo esc_url(
								wp_nonce_url(
									call_user_func(
										$list_url,
										array(
											'action' => 'toggle-status',
											'id'     => $list['id'],
										)
									),
									Nonces::ADMIN_ACTION,
									Nonces::FIELD_NAME
								)
							);
							?>
										">
								<?php echo esc_html( 'active' === $list['status'] ? __( 'Deactivate', 'b2b-price-lists' ) : __( 'Activate', 'b2b-price-lists' ) ); ?>
							</a>
							|
							<a href="
							<?php
							echo esc_url(
								wp_nonce_url(
									call_user_func(
										$list_url,
										array(
											'action' => 'duplicate',
											'id'     => $list['id'],
										)
									),
									Nonces::ADMIN_ACTION,
									Nonces::FIELD_NAME
								)
							);
							?>
										">
								<?php esc_html_e( 'Duplicate', 'b2b-price-lists' ); ?>
							</a>
							|
							<a href="
							<?php
							echo esc_url(
								call_user_func(
									$list_url,
									array(
										'action' => 'delete',
										'id'     => $list['id'],
									)
								)
							);
							?>
										" class="b2bpl-link-danger">
								<?php esc_html_e( 'Delete permanently', 'b2b-price-lists' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
