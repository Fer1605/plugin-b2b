<?php
/**
 * Storefront asset registration for the price table/price display presentation layer.
 *
 * @package B2BPriceLists\Frontend
 */

namespace B2BPriceLists\Frontend;

use B2BPriceLists\Domain\PriceResolver;
use B2BPriceLists\Domain\UserAssignmentService;
use B2BPriceLists\Support\Assets as AssetVersion;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assets
 */
class Assets {

	/**
	 * Registers the `wp_enqueue_scripts` hook. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the storefront CSS everywhere WooCommerce prices might be
	 * rendered, and the presentation JS (variation quantity/table swap)
	 * only on single product pages, where the `.variations_form`
	 * events it listens to actually fire.
	 *
	 * Skipped entirely for guests and for logged-in users without an
	 * active assigned list: no `b2bpl-*` markup can ever appear
	 * for them, so the assets would only be dead weight on every page.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		if ( ! $this->current_user_has_active_list() ) {
			return;
		}

		wp_enqueue_style(
			'b2bpl-frontend',
			B2BPL_URL . 'assets/css/frontend.css',
			array(),
			AssetVersion::version( 'assets/css/frontend.css' )
		);

		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_script(
				'b2bpl-frontend',
				B2BPL_URL . 'assets/js/frontend.js',
				array( 'jquery' ),
				AssetVersion::version( 'assets/js/frontend.js' ),
				true
			);

			wp_localize_script( 'b2bpl-frontend', 'B2BPriceListsFrontend', $this->build_frontend_data() );
		}
	}

	/**
	 * Builds the small, already-scoped data payload presentation JS uses on
	 * the single product page: WooCommerce's own price formatting
	 * config (so JS can render amounts consistently with `wc_price()`
	 * without a further request), and -- for a simple product only -- its
	 * own resolved pricing at quantity 1 (a variable product's pricing
	 * arrives later, per-variation, through WooCommerce's own
	 * `woocommerce_available_variation` AJAX payload).
	 *
	 * The `show_inline_variation_price` setting itself is never sent here:
	 * it is only ever consulted server-side, by `PriceDisplay` deciding
	 * whether to include `b2bpl_pricing` in that AJAX payload at all --
	 * presentation JS only ever reacts to data actually being present, it
	 * never re-checks the setting on its own.
	 *
	 * @return array<string,mixed>
	 */
	private function build_frontend_data() {
		$data = array(
			'priceFormat'        => array(
				'decimals'          => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
				'decimalSeparator'  => function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.',
				'thousandSeparator' => function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',',
				'currencySymbol'    => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
				'currencyPos'       => get_option( 'woocommerce_currency_pos', 'left' ),
			),
			'simpleProduct'      => array( 'applies' => false ),
			// `{{qty}}` is a plain string token (not a printf placeholder) so
			// presentation JS can substitute it without re-implementing
			// sprintf(); mirrors PriceDisplay::format_exact_result()'s own
			// translated string exactly.
			'minQtyNoteTemplate' => sprintf(
				/* translators: %s: literal "{{qty}}" token, replaced client-side with the minimum quantity. */
				__( '(minimum quantity: %s)', 'b2b-price-lists' ),
				'{{qty}}'
			),
			// The product page's own layout setting, so a price rebuilt on a
			// quantity or variation change keeps the arrangement the server
			// rendered instead of snapping back to one line.
			'singleLayout'       => PriceMarkup::layout_for( 'single' ),
			// Short "from N u." note used by the two-line layout. Built from
			// the same plural string the server uses, with a sentinel number
			// swapped for the token afterwards: `_n()` needs a real number to
			// pick the plural form, and `%d` cannot carry a text token.
			'shortMinTemplate'   => str_replace(
				'987654',
				'{{qty}}',
				sprintf(
					/* translators: %d: minimum purchasable quantity, abbreviated to fit a catalogue card. */
					_n( 'from %d u.', 'from %d u.', 2, 'b2b-price-lists' ),
					987654
				)
			),
			'referenceLabels'    => array(
				'base'    => __( 'Before', 'b2b-price-lists' ),
				'regular' => __( 'Unit', 'b2b-price-lists' ),
			),
		);

		try {
			$product_id = get_queried_object_id();
			$user_id    = get_current_user_id();

			if ( $product_id && $user_id ) {
				$result = ( new PriceResolver() )->resolve( $user_id, (int) $product_id, null, 1 );

				if ( $result['applies'] ) {
					$data['simpleProduct'] = array_merge(
						array( 'applies' => true ),
						PriceResolver::to_frontend_payload( $result )
					);
				}
			}
		} catch ( Throwable $exception ) {
			unset( $exception ); // Never break asset enqueueing over this optional payload.
		}

		return $data;
	}

	/**
	 * Whether the current visitor is logged in and has an active list
	 * assigned. Guests and users without an active list never see any
	 * B2B markup, so their pages don't need these assets loaded.
	 *
	 * @return bool
	 */
	private function current_user_has_active_list() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$list = ( new UserAssignmentService() )->get_assigned_list( $user_id );

		return $list && 'active' === $list['status'];
	}
}
