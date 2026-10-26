<?php
/**
 * Read-only, admin-only presentation of the order line item audit
 * snapshot, on the native WooCommerce order edit screen.
 *
 * Reads exclusively from the already-persisted line item meta
 * (`$item->get_meta()`) -- never a live `JOIN`/query against `b2bpl_*`
 * -- so it renders identically whether the originating list,
 * rule group or price range still exists or has since been permanently
 * deleted (TC-069/TC-070): the ids can be orphaned, the
 * denormalized values in the snapshot never are.
 *
 * `woocommerce_after_order_itemmeta` only fires on the admin order edit
 * screen (`includes/admin/meta-boxes/views/html-order-item.php`), never in
 * an email template, so this formatted block never appears in a customer
 * email regardless of what happens elsewhere. The raw `_b2bpl_*` meta rows
 * are additionally hidden from the admin screen's own generic meta table
 * (`woocommerce_hidden_order_itemmeta`) so this formatted summary is the
 * only place they are shown, never a duplicate raw dump.
 *
 * @package B2BPriceLists\Order
 */

namespace B2BPriceLists\Order;

use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Domain\RuleGroupValidator;
use Throwable;
use WC_Order_Item_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderSnapshotDisplay
 */
class OrderSnapshotDisplay {

	/**
	 * Registers every hook. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_raw_meta_keys' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render' ), 10, 3 );
	}

	/**
	 * Adds every meta key this plugin writes to WooCommerce's admin-screen
	 * "hidden order item meta" list, so the generic raw meta table never
	 * shows an unformatted duplicate of what `render()` already displays.
	 *
	 * @param array $hidden_keys Meta keys WooCommerce already hides.
	 * @return array
	 */
	public function hide_raw_meta_keys( $hidden_keys ) {
		return array_merge( (array) $hidden_keys, OrderSnapshotBuilder::meta_keys() );
	}

	/**
	 * `woocommerce_after_order_itemmeta`: renders a formatted summary of the
	 * snapshot for a line item that has one. A line item without a
	 * `_b2bpl_list_id` meta value is a standard B2C line (no rule applied
	 * at checkout, or the plugin was not active/applicable) -- nothing is
	 * rendered for it, exactly like before this plugin existed.
	 *
	 * @param int              $item_id Order item id (unused, kept for the hook signature).
	 * @param WC_Order_Item    $item    Order item.
	 * @param \WC_Product|bool $product Product object, or false (unused).
	 * @return void
	 */
	public function render( $item_id, $item, $product ) {
		unset( $item_id, $product );

		try {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				return;
			}

			$list_id = $item->get_meta( OrderSnapshotBuilder::META_LIST_ID, true );

			if ( '' === $list_id || null === $list_id ) {
				return;
			}

			$list_name               = (string) $item->get_meta( OrderSnapshotBuilder::META_LIST_NAME_SNAPSHOT, true );
			$rule_group_id           = $item->get_meta( OrderSnapshotBuilder::META_RULE_GROUP_ID, true );
			$pricing_mode            = (string) $item->get_meta( OrderSnapshotBuilder::META_PRICING_MODE, true );
			$range_min_qty           = $item->get_meta( OrderSnapshotBuilder::META_RANGE_MIN_QTY, true );
			$range_max_qty           = $item->get_meta( OrderSnapshotBuilder::META_RANGE_MAX_QTY, true );
			$unit_price              = $item->get_meta( OrderSnapshotBuilder::META_B2B_UNIT_PRICE, true );
			$base_unit_price         = $item->get_meta( OrderSnapshotBuilder::META_BASE_UNIT_PRICE, true );
			$discount_percent        = $item->get_meta( OrderSnapshotBuilder::META_DISCOUNT_PERCENT, true );
			$discount_source         = (string) $item->get_meta( OrderSnapshotBuilder::META_DISCOUNT_SOURCE, true );
			$reference_regular_price = $item->get_meta( OrderSnapshotBuilder::META_REFERENCE_REGULAR_PRICE, true );
			$plugin_version          = (string) $item->get_meta( OrderSnapshotBuilder::META_PLUGIN_VERSION, true );

			echo '<div class="b2bpl-order-item-snapshot">';
			echo '<p class="b2bpl-order-item-snapshot-title"><strong>' . esc_html__( 'B2B Price Lists', 'b2b-price-lists' ) . '</strong></p>';
			echo '<ul class="b2bpl-order-item-snapshot-fields">';

			echo '<li>' . esc_html__( 'List:', 'b2b-price-lists' ) . ' ' . esc_html( $list_name )
				. ' <span class="description">(#' . esc_html( (string) (int) $list_id ) . ')</span></li>';

			if ( '' !== $rule_group_id && null !== $rule_group_id ) {
				echo '<li>' . esc_html__( 'Rule group:', 'b2b-price-lists' ) . ' #' . esc_html( (string) (int) $rule_group_id ) . '</li>';
			}

			if ( RuleGroupValidator::PRICING_MODE_TIERED === $pricing_mode && '' !== $range_min_qty && null !== $range_min_qty ) {
				echo '<li>' . esc_html__( 'Pricing:', 'b2b-price-lists' ) . ' ' . esc_html__( 'Tiered', 'b2b-price-lists' )
					. ' -- ' . esc_html( $this->format_range_text( (int) $range_min_qty, ( '' === $range_max_qty || null === $range_max_qty ) ? null : (int) $range_max_qty ) ) . '</li>';
			} else {
				echo '<li>' . esc_html__( 'Pricing:', 'b2b-price-lists' ) . ' ' . esc_html__( 'Fixed', 'b2b-price-lists' ) . '</li>';
			}

			if ( '' !== $unit_price && null !== $unit_price ) {
				echo '<li>' . esc_html__( 'B2B unit price:', 'b2b-price-lists' ) . ' ' . wp_kses_post( wc_price( (float) $unit_price ) ) . '</li>';
			}

			if ( '' !== $discount_percent && null !== $discount_percent ) {
				echo '<li>' . esc_html__( 'Offer applied:', 'b2b-price-lists' ) . ' -'
					. esc_html( Discount::format_percent( (float) $discount_percent ) ) . '%'
					. ' <span class="description">(' . esc_html( $this->discount_source_label( $discount_source ) ) . ')</span></li>';
			}

			if ( '' !== $base_unit_price && null !== $base_unit_price ) {
				echo '<li>' . esc_html__( 'Price before the offer:', 'b2b-price-lists' ) . ' ' . wp_kses_post( wc_price( (float) $base_unit_price ) ) . '</li>';
			}

			if ( '' !== $reference_regular_price && null !== $reference_regular_price ) {
				echo '<li>' . esc_html__( 'Reference regular price:', 'b2b-price-lists' ) . ' ' . wp_kses_post( wc_price( (float) $reference_regular_price ) ) . '</li>';
			}

			if ( '' !== $plugin_version && null !== $plugin_version ) {
				echo '<li class="description">' . esc_html__( 'Recorded by B2B Price Lists', 'b2b-price-lists' ) . ' ' . esc_html( $plugin_version ) . '</li>';
			}

			echo '</ul>';
			echo '</div>';
		} catch ( Throwable $exception ) {
			unset( $exception );

			// Never break the order edit screen over a display-only failure.
		}
	}

	/**
	 * Human-readable name of the level an offer came from, so an order can
	 * be audited years later without looking up which of the three offer
	 * levels was in force at the time.
	 *
	 * @param string $source One of the `Discount::SOURCE_*` values.
	 * @return string
	 */
	private function discount_source_label( $source ) {
		switch ( $source ) {
			case Discount::SOURCE_RANGE:
				return __( 'offer on the price range', 'b2b-price-lists' );

			case Discount::SOURCE_RULE:
				return __( 'offer on the product rule', 'b2b-price-lists' );

			case Discount::SOURCE_LIST:
				return __( 'offer on the whole list', 'b2b-price-lists' );

			default:
				return __( 'unknown origin', 'b2b-price-lists' );
		}
	}

	/**
	 * Formats a quantity range for the admin snapshot display (independent,
	 * intentionally small duplication of
	 * `Frontend\PriceTableRenderer::format_quantity_range()` -- this class
	 * must never depend on the storefront rendering layer).
	 *
	 * @param int      $min Minimum quantity.
	 * @param int|null $max Maximum quantity, or null for an open range.
	 * @return string
	 */
	private function format_range_text( $min, $max ) {
		if ( null === $max ) {
			/* translators: %d: minimum quantity of an open-ended range. */
			return sprintf( __( '%d+ units', 'b2b-price-lists' ), $min );
		}

		if ( $min === $max ) {
			/* translators: %d: exact quantity. */
			return sprintf( __( '%d unit(s)', 'b2b-price-lists' ), $min );
		}

		/* translators: 1: minimum quantity, 2: maximum quantity. */
		return sprintf( __( '%1$d to %2$d units', 'b2b-price-lists' ), $min, $max );
	}
}
