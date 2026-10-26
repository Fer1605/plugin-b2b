<?php
/**
 * Cart price recalculation and minimum-quantity enforcement (Fase 9,
 *).
 *
 * Deliberately registers no `woocommerce_add_cart_item_data` handler at all
 *: the plugin never attaches any data -- variable or otherwise --
 * to a cart item, so WooCommerce's own `WC_Cart::generate_cart_id()` and
 * `WC_Cart::find_product_in_cart()` fusion behaviour is completely
 * untouched. The same product/variation added in two separate "add to
 * cart" actions merges into a single line the same way it always has,
 * purely native WooCommerce behaviour.
 *
 * The B2B unit price is instead resolved fresh, in server memory only, on
 * every `woocommerce_before_calculate_totals` -- using the current user,
 * their *currently* assigned list (via `Domain\PriceResolver`, which always
 * reads the live assignment/list/rule state, never a cached snapshot from
 * when the item was added), and the cart item's already-fused `quantity`.
 * This is exactly the same resolver call used by single product/catalog
 * display (Hito B) and, later, by the order line item snapshot --
 * one resolution engine, three integration points, never three competing
 * implementations.
 *
 * `woocommerce_add_to_cart_validation`/`woocommerce_update_cart_validation`
 * enforce minimum quantity at both entry points. Store API's own
 * `add_to_cart`/`cart-update-item` routes reuse this exact validation
 * automatically for the strictest quantities (see
 * `Frontend\PriceDisplay::filter_quantity_args()`, which the Store API
 * `QuantityLimits` utility already consumes via `wc_get_quantity_input_args()`
 * for the update-item route, and `woocommerce_add_to_cart_validation` itself,
 * which the Store API `add_to_cart` route invokes directly) -- verified
 * empirically against the WooCommerce 10.9.4 source before deciding no
 * `StoreApi\ExtendSchema`
 * class was needed for this milestone.
 *
 * @package B2BPriceLists\Cart
 */

namespace B2BPriceLists\Cart;

use B2BPriceLists\Domain\PriceResolver;
use B2BPriceLists\Frontend\PriceMarkup;
use B2BPriceLists\Support\Logger;
use Throwable;
use WC_Cart;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartPriceHandler
 */
class CartPriceHandler {

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
		// Priority 20: runs after any earlier price-affecting hook (e.g. a
		// coupon or another plugin's own recalculation -- this
		// plugin never implements its own coupon policy, it only makes sure
		// its own `set_price()` call is the final word for the products it
		// actually governs), while still running well before WooCommerce
		// computes subtotals/totals from the adjusted price.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'recalculate_cart_prices' ), 20, 1 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'filter_cart_item_price' ), 20, 3 );

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart_quantity' ), 10, 5 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_update_cart_quantity' ), 10, 4 );
	}

	/**
	 * `woocommerce_before_calculate_totals`: resolves and applies the B2B
	 * unit price for every cart line, fresh on every call, using the
	 * cart's own already-fused `quantity`. Never touches a line
	 * whose resolution does not apply (`applies === false`) -- WooCommerce's
	 * own price stands untouched, which is what keeps a mixed B2B+B2C cart
	 * (or any product this plugin has no rule for) correct without any
	 * extra branching here.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return void
	 */
	public function recalculate_cart_prices( $cart ) {
		try {
			if ( ! ( $cart instanceof WC_Cart ) ) {
				return;
			}

			// Standard WooCommerce guard (documented pattern for this hook):
			// classic wp-admin screens never build totals from a shopper's
			// `WC_Cart` the way the storefront/Store API do, so skip there
			// unless this really is an AJAX request that legitimately needs
			// cart totals recalculated.
			if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
				return;
			}

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				// Guest: standard WooCommerce pricing, untouched.
				return;
			}

			$cart_items = $cart->get_cart();

			// Warms the resolver's list_id+product_id rule group cache for
			// every distinct product in the cart with a single batched query
			//, instead of letting each line below trigger its own
			// query on a cold cache -- `resolve_batch()`'s aggregate return
			// value is discarded here; only its cache side effect is used,
			// since each line still needs `resolve()`'s exact per-variation
			// result (price, minimum quantity, range).
			$product_ids = array();

			foreach ( $cart_items as $cart_item ) {
				if ( ! empty( $cart_item['product_id'] ) ) {
					$product_ids[] = (int) $cart_item['product_id'];
				}
			}

			if ( ! empty( $product_ids ) ) {
				$this->resolver->resolve_batch( $product_ids, $user_id );
			}

			foreach ( $cart_items as $cart_item ) {
				if ( empty( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) {
					continue;
				}

				$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

				if ( $product_id <= 0 ) {
					continue;
				}

				$variation_id = ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : null;
				$quantity     = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;

				$result = $this->resolver->resolve( $user_id, $product_id, $variation_id, $quantity );

				if ( ! empty( $result['applies'] ) ) {
					$cart_item['data']->set_price( (string) $result['b2b_unit_price'] );

					// With an offer in force, the price the saving is measured
					// against is the wholesale price the offer was taken off,
					// not the retail price -- the same rule the product page
					// follows. Everything downstream that compares "regular"
					// with "price" (the Cart/Checkout blocks' struck price and
					// "Save" badge, a theme's per-line "was" amount or cart
					// saving total) then states the real offer. This is the
					// in-memory product object of this cart line only; the
					// catalogue product is never touched or saved.
					if ( null !== ( $result['discount_percent'] ?? null ) && null !== ( $result['base_unit_price'] ?? null ) ) {
						$cart_item['data']->set_regular_price( (string) $result['base_unit_price'] );
					}
				}
			}
		} catch ( Throwable $exception ) {
			Logger::error(
				'Unexpected exception while recalculating B2B cart prices; standard WooCommerce pricing left untouched for this pass.',
				array( 'message' => $exception->getMessage() )
			);
		}
	}

	/**
	 * `woocommerce_cart_item_price`: the unit price of a cart line as the
	 * classic cart, the mini cart and any theme template built on this filter
	 * print it. A B2B line shows the price it is compared against struck
	 * through, the price charged, and the "-N%" badge when an offer applies
	 * -- so an offer reads the same in the cart as on the product page.
	 *
	 * Display only: the amount charged was already set on the line by
	 * `recalculate_cart_prices()`. On any failure the incoming HTML is
	 * returned untouched.
	 *
	 * @param string $price_html    Price HTML so far.
	 * @param array  $cart_item     Cart line.
	 * @param string $cart_item_key Cart line key.
	 * @return string
	 */
	public function filter_cart_item_price( $price_html, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		try {
			$product = $cart_item['data'] ?? null;
			$user_id = get_current_user_id();

			if ( ! $user_id || ! $product instanceof \WC_Product || empty( $cart_item['product_id'] ) ) {
				return $price_html;
			}

			$result = $this->resolver->resolve(
				$user_id,
				(int) $cart_item['product_id'],
				! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : null,
				isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1
			);

			if ( empty( $result['applies'] ) ) {
				return $price_html;
			}

			$final   = (float) wc_get_price_to_display( $product, array( 'price' => (float) $result['b2b_unit_price'] ) );
			$percent = $result['discount_percent'] ?? null;

			if ( null !== $percent && null !== ( $result['base_unit_price'] ?? null ) ) {
				$reference = (float) wc_get_price_to_display( $product, array( 'price' => (float) $result['base_unit_price'] ) );
			} else {
				$regular   = (float) $product->get_regular_price();
				$reference = $regular > 0 ? (float) wc_get_price_to_display( $product, array( 'price' => $regular ) ) : 0.0;
			}

			$html = '';

			if ( $reference > $final ) {
				$html .= '<del class="b2bpl-price__' . ( null !== $percent ? 'base' : 'regular' ) . '" aria-hidden="true">' . wc_price( $reference ) . '</del> ';
			}

			$html .= '<ins class="b2bpl-price__final">' . wc_price( $final ) . '</ins>' . PriceMarkup::badge( $percent );

			return '<span class="b2bpl-price b2bpl-price--cart">' . $html . '</span>';
		} catch ( Throwable $exception ) {
			unset( $exception );

			return $price_html;
		}
	}

	/**
	 * `woocommerce_add_to_cart_validation`: rejects (never
	 * silently corrects) any attempt to add a quantity below the applicable
	 * rule's minimum purchasable quantity -- classic "Add to cart" forms,
	 * AJAX add-to-cart, and the Store API `add_to_cart` route (which invokes
	 * this exact filter, verified against the WooCommerce 10.9.4 source).
	 *
	 * @param bool  $passed          Whether validation has passed so far.
	 * @param int   $product_id      Product id.
	 * @param int   $quantity        Requested quantity.
	 * @param int   $variation_id    Variation id (0 for a simple product).
	 * @param array $variation_data  Variation attribute data (unused).
	 * @param array $cart_item_data  Extra cart item data (unused).
	 * @return bool
	 */
	public function validate_add_to_cart_quantity( $passed, $product_id, $quantity, $variation_id = 0, $variation_data = array(), $cart_item_data = array() ) {
		unset( $variation_data, $cart_item_data );

		try {
			if ( ! $passed ) {
				return $passed;
			}

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return $passed;
			}

			$variation_id = ! empty( $variation_id ) ? (int) $variation_id : null;

			// Quantity does not affect which rule group governs the minimum
			// (`minimum_quantity` is independent of the requested quantity,
			// see `Domain\PriceResolutionEngine`), so `1` is passed here
			// purely to satisfy `resolve()`'s signature.
			$result = $this->resolver->resolve( $user_id, (int) $product_id, $variation_id, 1 );

			return $this->enforce_minimum( $passed, $result, (float) $quantity );
		} catch ( Throwable $exception ) {
			Logger::error(
				'Unexpected exception during B2B add-to-cart minimum quantity validation; falling back to standard WooCommerce validation.',
				array( 'message' => $exception->getMessage() )
			);

			return $passed;
		}
	}

	/**
	 * `woocommerce_update_cart_validation`: rejects any attempt to
	 * update an existing cart line to a quantity below the applicable
	 * rule's minimum. Returning `false` here is the entire mechanism behind
	 * "conserve the last valid quantity": WooCommerce's own cart
	 * page handler never calls `WC_Cart::set_quantity()` when this filter
	 * returns `false`, so the line's previous, valid quantity is left
	 * completely untouched -- no reversal logic of this plugin's own.
	 *
	 * @param bool   $passed        Whether validation has passed so far.
	 * @param string $cart_item_key Cart item key (unused).
	 * @param array  $values        The cart item's current data.
	 * @param float  $quantity      Newly requested quantity.
	 * @return bool
	 */
	public function validate_update_cart_quantity( $passed, $cart_item_key, $values, $quantity ) {
		unset( $cart_item_key );

		try {
			if ( ! $passed ) {
				return $passed;
			}

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return $passed;
			}

			$product_id = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;

			if ( $product_id <= 0 ) {
				return $passed;
			}

			$variation_id = ! empty( $values['variation_id'] ) ? (int) $values['variation_id'] : null;

			$result = $this->resolver->resolve( $user_id, $product_id, $variation_id, 1 );

			return $this->enforce_minimum( $passed, $result, (float) $quantity );
		} catch ( Throwable $exception ) {
			Logger::error(
				'Unexpected exception during B2B cart update minimum quantity validation; falling back to standard WooCommerce validation.',
				array( 'message' => $exception->getMessage() )
			);

			return $passed;
		}
	}

	/**
	 * Shared minimum-quantity enforcement: defers the actual
	 * decision to the pure `MinimumQuantityPolicy`, then rejects with a
	 * clear `wc_add_notice()` error when it fails. Never used to fall back
	 * to B2C -- when a rule applies, the buyer either meets the minimum or
	 * the request is rejected outright.
	 *
	 * @param bool  $passed   Whether validation has passed so far.
	 * @param array $result   `PriceResolver::resolve()` result.
	 * @param float $quantity Requested/target quantity.
	 * @return bool
	 */
	private function enforce_minimum( $passed, array $result, $quantity ) {
		if ( MinimumQuantityPolicy::is_quantity_valid( $result, $quantity ) ) {
			return $passed;
		}

		wc_add_notice(
			sprintf(
				/* translators: %d: minimum purchasable quantity. */
				__( 'The minimum quantity you can purchase for this product is %d.', 'b2b-price-lists' ),
				MinimumQuantityPolicy::resolve_minimum( $result )
			),
			'error'
		);

		return false;
	}
}
