<?php
/**
 * Persists the order line item audit snapshot at checkout time,
 * exclusively via the `WC_Order_Item` CRUD API (`add_meta_data()`, saved by
 * the order's own subsequent `save()` -- never `update_post_meta()`, never
 * direct HPOS table access, never a SQL transaction of this plugin's own
 * around WooCommerce/HPOS order tables).
 *
 * `woocommerce_checkout_create_order_line_item` is the single hook used
 * here: `WC_Checkout::create_order_line_items()` (which fires it) is shared
 * verbatim by classic checkout and by the Store API/Checkout Block flow
 * (`Automattic\WooCommerce\StoreApi\Utilities\OrderController::update_order_from_cart()`
 * calls `wc()->checkout->create_order_line_items()` directly, confirmed
 * against the WooCommerce 10.9.4 source) -- one hook, both checkout
 * engines, never two competing implementations.
 *
 * @package B2BPriceLists\Order
 */

namespace B2BPriceLists\Order;

use B2BPriceLists\Domain\PriceResolver;
use B2BPriceLists\Support\Logger;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderAuditMeta
 */
class OrderAuditMeta {

	/**
	 * Price resolution orchestrator.
	 *
	 * @var PriceResolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param PriceResolver|null $resolver Optional, for tests.
	 */
	public function __construct( ?PriceResolver $resolver = null ) {
		$this->resolver = $resolver ?? new PriceResolver();
	}

	/**
	 * Registers every hook. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'attach_snapshot' ), 10, 4 );
	}

	/**
	 * flow: (1) resolve the final context for this exact line
	 * (same `PriceResolver::resolve()` used by the cart, with the line's
	 * final quantity); (2) build the complete snapshot in memory
	 * (`OrderSnapshotBuilder::build()`, which itself validates every
	 * required field before returning anything); (3) add every meta key to
	 * the `WC_Order_Item` -- never a partial set -- before the order's own
	 * subsequent `save()` persists it; (4) on any missing precondition or
	 * unexpected exception, log safely and write nothing, leaving the line
	 * item exactly as standard WooCommerce would have created it.
	 *
	 * @param WC_Order_Item_Product $item          The order line item being built.
	 * @param string                $cart_item_key Cart item key (unused).
	 * @param array                 $values         The cart item's data.
	 * @param WC_Order              $order          The order being created.
	 * @return void
	 */
	public function attach_snapshot( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key );

		try {
			if ( ! ( $item instanceof WC_Order_Item_Product ) || ! ( $order instanceof WC_Order ) || ! is_array( $values ) ) {
				return;
			}

			$product_id = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;

			if ( $product_id <= 0 ) {
				return;
			}

			$variation_id = ! empty( $values['variation_id'] ) ? (int) $values['variation_id'] : null;
			$quantity     = isset( $values['quantity'] ) ? (int) $values['quantity'] : 1;

			// Same current-user semantics as the cart resolution:
			// this hook only ever fires for the buyer's own live checkout
			// request (classic or Blocks), never for an admin-created order,
			// which does not go through `WC_Checkout::create_order_line_items()`.
			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return; // Guest checkout never has a B2B list.
			}

			$resolution = $this->resolver->resolve( $user_id, $product_id, $variation_id, $quantity );

			if ( empty( $resolution['applies'] ) ) {
				return; // Standard B2C line -- nothing to snapshot.
			}

			$snapshot = OrderSnapshotBuilder::build( $resolution, defined( 'B2BPL_VERSION' ) ? B2BPL_VERSION : '' );

			if ( null === $snapshot ) {
				Logger::error(
					'B2B order line item snapshot validation failed; the line was saved with the correct price but without an audit snapshot.',
					array(
						'order_id'     => $order->get_id(),
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
					)
				);

				return;
			}

			foreach ( $snapshot as $meta_key => $meta_value ) {
				if ( null === $meta_value ) {
					// Intentionally not applicable for this line (e.g. range
					// fields on a `fixed` line, or `variation_id` on a
					// simple product) -- never written as a literal null.
					continue;
				}

				$item->add_meta_data( $meta_key, $meta_value, true );
			}
		} catch ( Throwable $exception ) {
			Logger::error(
				'Unexpected exception while attaching the B2B order line item snapshot; the line was saved without one.',
				array( 'message' => $exception->getMessage() )
			);
		}
	}
}
