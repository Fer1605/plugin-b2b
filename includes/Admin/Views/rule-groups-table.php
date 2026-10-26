<?php
/**
 * View: rule groups table for a list ("Products & prices" tab).
 *
 * Expected variables: $list_id (int), $list (array|null), $groups (array),
 * $tab_url (callable), $notice (string).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\ListsController;
use B2BPriceLists\Admin\Menu;
use B2BPriceLists\Admin\UI;
use B2BPriceLists\Admin\View;
use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Domain\RuleGroupValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$scope_labels = array(
	RuleGroupValidator::SCOPE_SIMPLE          => __( 'Simple product', 'b2b-price-lists' ),
	RuleGroupValidator::SCOPE_ALL_VARIATIONS  => __( 'All variations', 'b2b-price-lists' ),
	RuleGroupValidator::SCOPE_VARIATION_GROUP => __( 'Specific variations', 'b2b-price-lists' ),
);

// Deep-link from the read-only product info panel (section 17.1):
// highlights every row for this product, it never edits anything by itself.
$highlight_product_id = isset( $_GET['highlight_product'] ) ? absint( $_GET['highlight_product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only visual highlight, no state change.

$help_url = add_query_arg(
	array(
		'page'   => Menu::PAGE_SLUG,
		'action' => 'help',
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap b2bpl-admin">
	<?php
	View::render(
		'list-context',
		array(
			'list_id' => $list_id,
			'list'    => $list,
		)
	);
	?>

	<?php
	UI::screen_header(
		array(
			'title'    => __( 'Products & prices', 'b2b-price-lists' ),
			'subtitle' => __( 'One rule per product, with the price this list pays for it', 'b2b-price-lists' ),
			'icon'     => 'dashicons-money-alt',
			'badge'    => array(
				/* translators: %d: number of price rules in this list. */
				'label' => sprintf( _n( '%d rule', '%d rules', count( $groups ), 'b2b-price-lists' ), count( $groups ) ),
				'tone'  => empty( $groups ) ? 'off' : 'ok',
				'icon'  => empty( $groups ) ? 'dashicons-marker' : 'dashicons-yes-alt',
			),
			'actions'  => array(
				array(
					'label'   => __( 'Add rule', 'b2b-price-lists' ),
					'url'     => call_user_func( $tab_url, $list_id, array( 'group_action' => 'new' ) ),
					'icon'    => 'dashicons-plus-alt2',
					'primary' => true,
				),
				array(
					'label' => __( 'User guide', 'b2b-price-lists' ),
					'url'   => $help_url,
					'icon'  => 'dashicons-book',
				),
			),
		)
	);
	?>
	<hr class="wp-header-end">

	<p class="b2bpl-breadcrumb">
		<a href="<?php echo esc_url( call_user_func( $tab_url, $list_id, array( 'tab' => 'details' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to list details', 'b2b-price-lists' ); ?></a>
		&nbsp;|&nbsp;
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'All lists', 'b2b-price-lists' ); ?></a>
	</p>

	<?php if ( 'saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule saved.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'deleted' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule deleted.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'error' === $notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( ListsController::consume_error_notice() ); ?></p></div>
	<?php endif; ?>

	<?php UI::card_open( __( 'Rules in this list', 'b2b-price-lists' ), 'dashicons-tag', 'teal' ); ?>
	<table class="wp-list-table widefat fixed striped b2bpl-rule-groups-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Scope', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Price', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Offer', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'b2b-price-lists' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $groups ) ) : ?>
				<tr>
					<td colspan="5" class="b2bpl-empty">
						<p><?php esc_html_e( 'No product rules yet in this list.', 'b2b-price-lists' ); ?></p>
						<p><?php esc_html_e( 'Add one rule per product: pick the product, decide whether its price is fixed or by quantity ranges, and — if you want — put an offer on top.', 'b2b-price-lists' ); ?></p>
						<a href="<?php echo esc_url( call_user_func( $tab_url, $list_id, array( 'group_action' => 'new' ) ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Add the first rule', 'b2b-price-lists' ); ?>
						</a>
					</td>
				</tr>
			<?php endif; ?>
			<?php foreach ( $groups as $group ) : ?>
				<tr<?php echo ( $highlight_product_id && $highlight_product_id === (int) $group['product_id'] ) ? ' class="b2bpl-row-highlight" id="b2bpl-product-' . esc_attr( (string) $highlight_product_id ) . '"' : ''; ?>>
					<td>
						<strong><?php echo esc_html( '' !== $group['product_label'] ? $group['product_label'] : sprintf( '#%d', (int) $group['product_id'] ) ); ?></strong>
						<?php if ( ! empty( $group['name'] ) ) : ?>
							<br><em class="b2bpl-rule-name"><?php echo esc_html( $group['name'] ); ?></em>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $scope_labels[ $group['scope'] ] ?? $group['scope'] ); ?>
						<?php if ( ! empty( $group['variation_labels'] ) ) : ?>
							<br><small class="b2bpl-variation-summary"><?php echo esc_html( implode( ', ', $group['variation_labels'] ) ); ?></small>
						<?php endif; ?>
					</td>
					<td><?php echo wp_kses_post( $group['price_summary'] ); ?></td>
					<td>
						<?php if ( null !== $group['offer_summary']['percent'] ) : ?>
							<span class="b2bpl-badge b2bpl-badge--offer">-<?php echo esc_html( Discount::format_percent( $group['offer_summary']['percent'] ) ); ?>%</span>
							<br><small><?php esc_html_e( 'whole rule', 'b2b-price-lists' ); ?></small>
						<?php elseif ( $group['offer_summary']['ranges_on_offer'] > 0 ) : ?>
							<span class="b2bpl-badge b2bpl-badge--offer">
								<?php
								printf(
									/* translators: %d: how many ranges of this rule carry their own offer. */
									esc_html( _n( '%d range on offer', '%d ranges on offer', (int) $group['offer_summary']['ranges_on_offer'], 'b2b-price-lists' ) ),
									(int) $group['offer_summary']['ranges_on_offer']
								);
								?>
							</span>
						<?php else : ?>
							<span class="b2bpl-muted">—</span>
						<?php endif; ?>
					</td>
					<td class="b2bpl-actions">
						<a href="
						<?php
						echo esc_url(
							call_user_func(
								$tab_url,
								$list_id,
								array(
									'group_action' => 'edit',
									'group_id'     => $group['id'],
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
							call_user_func(
								$tab_url,
								$list_id,
								array(
									'group_action' => 'delete',
									'group_id'     => $group['id'],
								)
							)
						);
						?>
									" class="b2bpl-link-danger">
							<?php esc_html_e( 'Delete', 'b2b-price-lists' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php UI::card_close(); ?>
</div>
