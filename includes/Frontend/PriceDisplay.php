<?php
/**
 * Storefront price display: single product and catalog/loop
 *. Uses only official WooCommerce price filters
 * (`woocommerce_get_price_html`, `woocommerce_available_variation`,
 * `woocommerce_quantity_input_args`) -- never a template override, never a
 * global `.price`/`.qty` selector. Every callback wraps its call into
 * `Domain\PriceResolver` (which already never throws) and, as an additional
 * layer of defense at the exact WooCommerce integration point (TC-067),
 * discards any partial result and returns WooCommerce's own untouched price
 * on the first sign of trouble.
 *
 * @package B2BPriceLists\Frontend
 */

namespace B2BPriceLists\Frontend;

use B2BPriceLists\Domain\PriceResolver;
use B2BPriceLists\Support\TableSettings;
use Throwable;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriceDisplay
 */
class PriceDisplay {

	/**
	 * Price resolution orchestrator.
	 *
	 * @var PriceResolver
	 */
	private $resolver;

	/**
	 * Request-scoped aggregate results, keyed by product id, warmed by
	 * `preload_for_query()` for the products of the current main query
	 * (catalog/loop rendering must never issue one query per
	 * product card).
	 *
	 * @var array<int,array>
	 */
	private $batch_cache = array();

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
		add_filter( 'the_posts', array( $this, 'preload_for_query' ), 10, 1 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 20, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'filter_variation_data' ), 10, 3 );
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'filter_quantity_args' ), 10, 2 );
	}

	/**
	 * Warms the batch aggregate cache for every product in the main query's
	 * result set (shop, category, search, Elementor Loop Grid when it runs
	 * through the standard product query) -- one pass, not one query per
	 * card. Never alters the returned posts.
	 *
	 * @param array $posts Query result.
	 * @return array Unchanged.
	 */
	public function preload_for_query( $posts ) {
		try {
			if ( is_admin() || empty( $posts ) || ! is_array( $posts ) ) {
				return $posts;
			}

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return $posts;
			}

			$product_ids = array();

			foreach ( $posts as $post ) {
				if ( isset( $post->post_type ) && 'product' === $post->post_type ) {
					$product_ids[] = (int) $post->ID;
				}
			}

			if ( empty( $product_ids ) ) {
				return $posts;
			}

			$this->batch_cache = $this->batch_cache + $this->resolver->resolve_batch( $product_ids, $user_id );
		} catch ( Throwable $exception ) {
			unset( $exception ); // Never let a preload failure affect the query itself.
		}

		return $posts;
	}

	/**
	 * `woocommerce_get_price_html`: the single entry point for both single
	 * product and catalog/loop price display.
	 *
	 * @param string $price_html WooCommerce's own, unmodified price HTML.
	 * @param mixed  $product    Product object.
	 * @return string
	 */
	public function filter_price_html( $price_html, $product ) {
		try {
			if ( ! ( $product instanceof WC_Product ) ) {
				return $price_html;
			}

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return $price_html;
			}

			if ( $this->is_single_main_product( $product ) ) {
				return $this->render_single_price_html( $price_html, $product, $user_id );
			}

			return $this->render_loop_price_html( $price_html, $product, $user_id );
		} catch ( Throwable $exception ) {
			unset( $exception );

			// TC-067: on any unexpected failure, discard everything and
			// return WooCommerce's own price untouched.
			return $price_html;
		}
	}

	/**
	 * Whether this exact `get_price_html()` call is for the single main
	 * queried product (as opposed to, e.g., a related-products loop shown
	 * on that same single product page, which must use loop formatting).
	 *
	 * @param WC_Product $product Product being priced.
	 * @return bool
	 */
	private function is_single_main_product( WC_Product $product ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}

		if ( empty( $GLOBALS['wp_query'] ) || ! $GLOBALS['wp_query']->is_main_query() ) {
			return false;
		}

		return (int) get_queried_object_id() === (int) $product->get_id();
	}

	/**
	 * Single product price display: simple product gets its
	 * exact resolved price; a variable product before selection gets the
	 * homogeneous/heterogeneous aggregate, always preserving WooCommerce's
	 * own native regular price/range unaltered.
	 *
	 * @param string     $price_html Native price HTML.
	 * @param WC_Product $product    Product.
	 * @param int        $user_id    Current user id.
	 * @return string
	 */
	private function render_single_price_html( $price_html, WC_Product $product, $user_id ) {
		if ( $product->is_type( 'variable' ) ) {
			$aggregate = $this->resolver->resolve_variable_aggregate( $user_id, $product->get_id() );

			if ( $aggregate['applies'] && ! $aggregate['homogeneous'] && ! $aggregate['fully_covered'] ) {
				// Partial `variation_group` coverage: the
				// buyer hasn't picked a variation yet, and this plugin never
				// assumes the one they eventually pick will be covered --
				// show plain, unaltered B2C pricing plus a short heads-up
				// instead of a misleading "from Q..." aggregate built from
				// only some of the variations.
				return $price_html . ' <span class="b2bpl-partial-coverage-note">' . esc_html__( 'B2B pricing available on select variations.', 'b2b-price-lists' ) . '</span>';
			}

			// The product page's own context, not the card's: until 1.2.2 this
			// call defaulted to 'loop', so a variable product's main price
			// followed the catalogue card layout setting.
			return $this->format_aggregate( $price_html, $aggregate, $product, 'single' );
		}

		$result = $this->resolver->resolve( $user_id, $product->get_id(), null, 1 );

		if ( ! $result['applies'] ) {
			return $price_html;
		}

		return $this->format_exact_result( $result, $product );
	}

	/**
	 * Catalog/loop price display.
	 *
	 * @param string     $price_html Native price HTML.
	 * @param WC_Product $product    Product.
	 * @param int        $user_id    Current user id.
	 * @return string
	 */
	private function render_loop_price_html( $price_html, WC_Product $product, $user_id ) {
		$product_id = $product->get_id();

		$aggregate = $this->batch_cache[ $product_id ] ?? $this->resolver->resolve_variable_aggregate( $user_id, $product_id );

		return $this->format_aggregate( $price_html, $aggregate, $product );
	}

	/**
	 * The exact resolved price for a simple product or an already-selected
	 * variation: composed as data and handed to `PriceMarkup`, which is the
	 * only place in this plugin that decides what that data looks like.
	 *
	 * When an offer applies, the reference figure is the B2B price the offer
	 * was taken off (`base_unit_price`), not the retail price: that is the
	 * number the saving is measured against, so comparing against anything
	 * else would misstate it.
	 *
	 * @param array           $result  `PriceResolver::resolve()` result, applies=true.
	 * @param WC_Product|null $product Product being priced.
	 * @return string
	 */
	private function format_exact_result( array $result, ?WC_Product $product = null ) {
		return PriceMarkup::render(
			array(
				'context'          => 'single',
				'final_price'      => (float) $result['b2b_unit_price'],
				'base_price'       => isset( $result['base_unit_price'] ) ? $result['base_unit_price'] : null,
				'regular_price'    => $result['reference_regular_price'],
				'regular_html'     => null,
				'discount_percent' => isset( $result['discount_percent'] ) ? $result['discount_percent'] : null,
				'minimum_quantity' => (int) $result['minimum_quantity'],
				'product_id'       => (int) $result['product_id'],
				'variation_id'     => $result['variation_id'],
			),
			$product
		);
	}

	/**
	 * An aggregate result -- a variable product before a variation is chosen,
	 * or any catalogue/loop card: the homogeneous price when every covered
	 * variation shares one, otherwise the cheapest covered variation's, so a
	 * card always states a concrete starting price and its minimum rather
	 * than a bare "From Q...".
	 *
	 * @param string          $price_html Native price/range HTML.
	 * @param array           $aggregate  `PriceResolver` aggregate result.
	 * @param WC_Product|null $product    Product being priced.
	 * @param string          $context    'loop' for a catalogue card, 'single'
	 *                                    for the product page's main price.
	 * @return string
	 */
	private function format_aggregate( $price_html, array $aggregate, ?WC_Product $product = null, $context = 'loop' ) {
		if ( ! $aggregate['applies'] ) {
			return $price_html;
		}

		if ( $aggregate['homogeneous'] ) {
			$final   = $aggregate['unit_price'];
			$base    = $aggregate['base_unit_price'] ?? null;
			$percent = $aggregate['discount_percent'] ?? null;
			$minimum = (int) $aggregate['minimum_quantity'];
		} else {
			$final   = $aggregate['min_unit_price'];
			$base    = $aggregate['min_unit_price_base'] ?? null;
			$percent = $aggregate['min_unit_price_discount_percent'] ?? null;
			$minimum = (int) ( $aggregate['min_unit_price_minimum_quantity'] ?? 1 );
		}

		// A card's reference is a single number whenever the shop actually
		// has one -- including a product WooCommerce considers on sale,
		// where that number is the sale price the retail shopper would pay.
		// Handing over the native HTML instead nests WooCommerce's own
		// <del>/<ins> pair inside this plugin's, so an on-sale product read
		// as three prices at once (retail, sale, wholesale) with the
		// screen-reader text of the first two spelled out in between.
		// Only a genuine range -- variations that do not share one price --
		// still falls back to the native block.
		$reference = self::single_reference_price( $product );

		return PriceMarkup::render(
			array(
				'context'          => 'single' === $context ? 'single' : 'loop',
				'final_price'      => (float) $final,
				'base_price'       => $base,
				'regular_price'    => $reference,
				'regular_html'     => null === $reference ? $price_html : null,
				'discount_percent' => $percent,
				'minimum_quantity' => $minimum,
				'product_id'       => $product instanceof WC_Product ? (int) $product->get_id() : 0,
				'variation_id'     => null,
			),
			$product
		);
	}

	/**
	 * The one price a shopper without a wholesale list would pay, when such
	 * a number exists.
	 *
	 * Returns null only when it genuinely does not: a variable product whose
	 * covered variations sit at different prices, where any single figure
	 * would be a lie and WooCommerce's own range HTML is the honest answer.
	 *
	 * @param WC_Product|null $product Product being priced.
	 * @return float|null
	 */
	private static function single_reference_price( ?WC_Product $product = null ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		if ( $product->is_type( 'variable' ) ) {
			$min = $product->get_variation_price( 'min', true );
			$max = $product->get_variation_price( 'max', true );

			if ( '' === $min || '' === $max || (float) $min !== (float) $max ) {
				return null;
			}

			return (float) $min;
		}

		$price = $product->get_price();

		return ( '' === $price || null === $price ) ? null : (float) $price;
	}

	/**
	 * `woocommerce_available_variation`: overrides the price HTML shown for
	 * a specific selected variation with the exact resolved B2B price, and
	 * exposes the resolved minimum quantity so presentation-only JS
	 * (never calculating a price itself) can update the quantity input's
	 * `min` attribute, scoped to the current product form.
	 *
	 * @param array       $data      Variation payload WooCommerce already built.
	 * @param \WC_Product $product   Parent variable product.
	 * @param \WC_Product $variation Variation product.
	 * @return array
	 */
	public function filter_variation_data( $data, $product, $variation ) {
		try {
			unset( $product );

			$user_id = get_current_user_id();

			if ( ! $user_id || ! is_array( $data ) || ! isset( $variation ) ) {
				return $data;
			}

			$variation_id = method_exists( $variation, 'get_id' ) ? (int) $variation->get_id() : 0;
			$parent_id    = method_exists( $variation, 'get_parent_id' ) ? (int) $variation->get_parent_id() : 0;

			if ( ! $variation_id || ! $parent_id ) {
				return $data;
			}

			$result = $this->resolver->resolve( $user_id, $parent_id, $variation_id, 1 );

			if ( ! $result['applies'] ) {
				return $data;
			}

			$data['b2bpl_minimum_quantity'] = (int) $result['minimum_quantity'];
			$data['b2bpl_applies']          = true;

			if ( ! TableSettings::get()['show_inline_variation_price'] ) {
				// The inline block is turned off: leave WooCommerce's
				// own native `price_html` untouched and skip the raw pricing
				// payload entirely -- there is no DOM element for presentation
				// JS to keep recomputing.
				return $data;
			}

			$data['price_html'] = $this->format_exact_result( $result, $variation instanceof WC_Product ? $variation : null );
			// Raw, already-scoped pricing data: lets presentation JS
			// recompute this exact block as quantity changes, without any
			// further AJAX request.
			$data['b2bpl_pricing'] = PriceResolver::to_frontend_payload( $result );

			return $data;
		} catch ( Throwable $exception ) {
			unset( $exception );

			return $data;
		}
	}

	/**
	 * `woocommerce_quantity_input_args`: sets the quantity input's `min`
	 * attribute to the resolved minimum purchasable quantity, for
	 * a simple product, a homogeneous variable product (single minimum
	 * across every variation) before a variation is selected, or a specific
	 * variation product.
	 *
	 * This filter runs with the *variation* product object (not the parent)
	 * whenever WooCommerce renders a quantity input for an already-selected
	 * cart/order line -- notably the classic cart page (`cart.php`'s
	 * `woocommerce_quantity_input()` call, `$cart_item['data']`) and,
	 * since the Store API's `QuantityLimits` utility resolves through
	 * `wc_get_quantity_input_args()` (which applies this same filter), the
	 * Cart Block's `quantity_limits.minimum` too -- so a
	 * `WC_Product_Variation` must be resolved against its own parent id and
	 * variation id, never treated as if it were a standalone simple
	 * product's id (which would silently look up a nonexistent `simple`
	 * rule group and never apply). Heterogeneous variable products (no
	 * variation selected yet) keep the default here; their per-variation
	 * minimum is applied by presentation JS once a specific variation is
	 * selected (see `assets/js/frontend.js`) and, for the variation object
	 * itself once it becomes the cart line's product, by this same branch.
	 *
	 * @param array      $args    Quantity input args.
	 * @param WC_Product $product Product (parent variable, variation, or simple).
	 * @return array
	 */
	public function filter_quantity_args( $args, $product ) {
		try {
			if ( ! ( $product instanceof WC_Product ) ) {
				return $args;
			}

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return $args;
			}

			// Single product templates (simple.php, variation-add-to-cart-button.php)
			// pass their own `input_value` (defaulting to the product's
			// unfiltered native minimum) *before* this filter runs, and
			// WooCommerce core only falls back to `min_value` when
			// `input_value` is entirely unset -- so "the input
			// starts at the minimum" requires overwriting `input_value`
			// here explicitly too. This must stay scoped to `is_product()`:
			// the classic cart page's line-item quantity input reuses this
			// same filter but already passes the cart line's *actual*
			// current quantity as `input_value` (cart.php), which must
			// never be reset to the minimum on every page render.
			$is_single_product_page = function_exists( 'is_product' ) && is_product();

			if ( $product->is_type( 'variable' ) ) {
				$aggregate = $this->resolver->resolve_variable_aggregate( $user_id, $product->get_id() );

				if ( $aggregate['applies'] && $aggregate['homogeneous'] && $aggregate['minimum_quantity'] ) {
					$args['min_value'] = (int) $aggregate['minimum_quantity'];

					if ( $is_single_product_page ) {
						$args['input_value'] = $args['min_value'];
					}
				}

				return $args;
			}

			if ( $product->is_type( 'variation' ) ) {
				$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;

				if ( ! $parent_id ) {
					return $args;
				}

				$result = $this->resolver->resolve( $user_id, $parent_id, (int) $product->get_id(), 1 );

				if ( $result['applies'] && $result['minimum_quantity'] ) {
					$args['min_value'] = (int) $result['minimum_quantity'];

					if ( $is_single_product_page ) {
						$args['input_value'] = $args['min_value'];
					}
				}

				return $args;
			}

			$result = $this->resolver->resolve( $user_id, $product->get_id(), null, 1 );

			if ( $result['applies'] && $result['minimum_quantity'] ) {
				$args['min_value'] = (int) $result['minimum_quantity'];

				if ( $is_single_product_page ) {
					$args['input_value'] = $args['min_value'];
				}
			}

			return $args;
		} catch ( Throwable $exception ) {
			unset( $exception );

			return $args;
		}
	}
}
