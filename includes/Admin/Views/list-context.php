<?php
/**
 * View partial: the "which list am I inside?" banner.
 *
 * Rendered at the top of every screen that lives below a list (its rules
 * table, the rule editor, the rule deletion confirmation). Without it those
 * screens look identical no matter which list you opened, which is exactly
 * how a price ends up in the wrong list.
 *
 * Expected variables: $list_id (int), $list (array|null).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\UI;
use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Domain\ListService;
use B2BPriceLists\Order\OrdersListIntegration;
use B2BPriceLists\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$b2bpl_is_active = is_array( $list ) && ListService::STATUS_ACTIVE === $list['status'];
$b2bpl_offer     = is_array( $list ) ? Discount::normalize( $list['discount_percent'] ?? null ) : null;

// "B2B orders for this list" button (1.4.1, feedback: the previous small
// text link "was easy to miss"): shown only when the list actually exists
// yet (never for a brand-new, unsaved list) and only to a user who can
// both manage this plugin and see orders at all.
// Visible even at zero -- with wording that says so plainly -- rather than
// hidden, so the button's presence does not itself imply "this list has
// orders".
$b2bpl_show_orders_button = is_array( $list ) && Capabilities::current_user_can_manage() && current_user_can( 'edit_shop_orders' );
$b2bpl_orders_count       = $b2bpl_show_orders_button ? OrdersListIntegration::count_for_list( (int) $list_id ) : 0;
?>
<div class="b2bpl-list-context">
	<?php
	// Same tinted mark the page header and the section cards use, so the
	// banner reads as part of the screen rather than a stray strip.
	echo UI::icon( 'dashicons-portfolio', 'blue' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
	?>
	<span class="b2bpl-list-context__eyebrow"><?php esc_html_e( 'Price list', 'b2b-price-lists' ); ?></span>
	<span class="b2bpl-list-context__name">
		<?php
		echo esc_html(
			is_array( $list ) && '' !== $list['name']
				? $list['name']
				: sprintf( '#%d', (int) $list_id )
		);
		?>
	</span>
	<span class="b2bpl-badge b2bpl-badge--<?php echo $b2bpl_is_active ? 'active' : 'inactive'; ?>">
		<?php echo esc_html( $b2bpl_is_active ? __( 'Active', 'b2b-price-lists' ) : __( 'Inactive', 'b2b-price-lists' ) ); ?>
	</span>
	<?php if ( null !== $b2bpl_offer ) : ?>
		<span class="b2bpl-badge b2bpl-badge--offer">
			<?php
			printf(
				/* translators: %s: offer percentage applied to the whole list. */
				esc_html__( 'Offer on the whole list: -%s%%', 'b2b-price-lists' ),
				esc_html( Discount::format_percent( $b2bpl_offer ) )
			);
			?>
		</span>
	<?php endif; ?>
	<span class="b2bpl-list-context__id">#<?php echo esc_html( (string) (int) $list_id ); ?></span>
	<?php if ( $b2bpl_show_orders_button ) : ?>
		<a
			class="button b2bpl-list-context__orders-button"
			href="<?php echo esc_url( OrdersListIntegration::orders_url( array( 'b2bpl_order_list' => (int) $list_id ) ) ); ?>"
			title="<?php echo esc_attr( OrdersListIntegration::orders_button_title( $b2bpl_orders_count ) ); ?>"
		>
			<span class="dashicons dashicons-cart" aria-hidden="true"></span>
			<?php echo esc_html( OrdersListIntegration::orders_button_label( $b2bpl_orders_count ) ); ?>
		</a>
	<?php endif; ?>
</div>
