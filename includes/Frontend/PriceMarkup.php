<?php
/**
 * The single place where a B2B price is turned into HTML.
 *
 * Every price this plugin shows -- catalogue card, single product, the price
 * that replaces itself when a variation or a quantity changes -- is composed
 * here, from a plain array of already-resolved numbers ("parts"), never by
 * concatenating strings at the call site.
 *
 * That indirection exists for one concrete reason. A theme that wants a
 * different arrangement (say, the reference price on its own line under the
 * wholesale price, as several wholesale storefront designs do) used to have
 * no choice but to hook `woocommerce_get_price_html` after this plugin and
 * re-parse the HTML it had just produced -- regex out the `<del>`, regex out
 * the `<ins>`, guess where the minimum-quantity text ended -- because the
 * plugin exposed no structure of its own. That works right up until the
 * plugin's markup changes meaning: the `<del>` used to always hold the
 * retail price, and once offers arrived it can hold the pre-offer wholesale
 * price instead. A theme parsing HTML cannot tell those two apart, so it
 * silently mislabels one as the other.
 *
 * So the contract is now explicit and lives here:
 *
 * - `LAYOUT_STACKED` covers that common two-line arrangement as a setting,
 *   so no theme has to rebuild anything to get it;
 * - every piece carries its own class and `data-b2bpl-*` value, so a theme
 *   can restyle or rearrange with CSS alone;
 * - `b2bpl_price_parts` and `b2bpl_price_html` let anything else be composed
 *   from the numbers, never from parsing the output.
 *
 * @package B2BPriceLists\Frontend
 */

namespace B2BPriceLists\Frontend;

use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Support\TableSettings;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriceMarkup
 */
class PriceMarkup {

	/**
	 * One line: struck reference price, then the price paid, then the offer
	 * badge and the minimum quantity. The plugin's original arrangement.
	 *
	 * @var string
	 */
	const LAYOUT_INLINE = 'inline';

	/**
	 * Two lines: the price paid and its offer badge on the first, and the
	 * small print -- the minimum quantity and the reference price, labelled
	 * rather than struck -- together on a second line beneath it.
	 *
	 * @var string
	 */
	const LAYOUT_STACKED = 'stacked';

	/**
	 * Renders a resolved price.
	 *
	 * @param array           $parts {
	 *     Already-resolved numbers. Money values are floats; this method is
	 *     the only place that turns them into currency HTML.
	 *
	 *     @type string      $context          'single' or 'loop'.
	 *     @type float       $final_price      What the buyer pays per unit.
	 *     @type float|null  $base_price       The B2B price before the offer.
	 *     @type float|null  $regular_price    WooCommerce's own reference price.
	 *     @type string|null $regular_html     Native price HTML, when the
	 *                                         reference is a range and no
	 *                                         single float can express it.
	 *     @type float|null  $discount_percent Offer applied, if any.
	 *     @type int         $minimum_quantity Minimum purchasable quantity.
	 *     @type int         $product_id
	 *     @type int|null    $variation_id
	 * }
	 * @param WC_Product|null $product Product being priced, for filter consumers.
	 * @return string
	 */
	public static function render( array $parts, ?WC_Product $product = null ) {
		$parts = wp_parse_args(
			$parts,
			array(
				'context'          => 'loop',
				'final_price'      => null,
				'base_price'       => null,
				'regular_price'    => null,
				'regular_html'     => null,
				'discount_percent' => null,
				'minimum_quantity' => 1,
				'product_id'       => 0,
				'variation_id'     => null,
			)
		);

		/**
		 * Filters the resolved numbers before they become HTML.
		 *
		 * This is the supported way to change what a B2B price shows: the
		 * values arrive already resolved and unambiguous (which figure is the
		 * retail reference, which is the pre-offer wholesale price, which is
		 * the price actually charged), so nothing downstream has to infer
		 * them from markup.
		 *
		 * @param array           $parts   Resolved price parts.
		 * @param WC_Product|null $product Product being priced.
		 */
		$parts = apply_filters( 'b2bpl_price_parts', $parts, $product );

		$html = self::LAYOUT_STACKED === self::layout_for( $parts['context'] )
			? self::render_stacked( $parts )
			: self::render_inline( $parts );

		/**
		 * Filters the final price markup.
		 *
		 * Prefer `b2bpl_price_parts` where possible: it hands over data, not
		 * a string that has to be taken apart again.
		 *
		 * @param string          $html    Rendered markup.
		 * @param array           $parts   Resolved price parts.
		 * @param WC_Product|null $product Product being priced.
		 */
		return apply_filters( 'b2bpl_price_html', $html, $parts, $product );
	}

	/**
	 * The layout that applies to a given context. Catalogue cards and the
	 * single product page each have their own setting (`card_layout` and
	 * `single_layout`): a narrow card and a roomy product page rarely want
	 * the same arrangement.
	 *
	 * @param string $context 'single' or 'loop'.
	 * @return string One of the LAYOUT_* constants.
	 */
	public static function layout_for( $context ) {
		$settings = TableSettings::get();
		$chosen   = 'loop' === $context ? $settings['card_layout'] : $settings['single_layout'];

		return self::LAYOUT_STACKED === $chosen
			? self::LAYOUT_STACKED
			: self::LAYOUT_INLINE;
	}

	/**
	 * One-line arrangement: `<del>reference</del> <ins>paid</ins> -N%`, with
	 * the minimum-quantity note after it.
	 *
	 * @param array $parts Resolved price parts.
	 * @return string
	 */
	private static function render_inline( array $parts ) {
		$final = '<ins class="b2bpl-price__final">' . self::money( $parts['final_price'] ) . '</ins>';

		$reference = self::reference( $parts );
		$inner     = '';

		if ( null !== $reference ) {
			// A native block that already carries its own <del> (a product on
			// sale in WooCommerce's own terms, or a price range) is never
			// wrapped in another one: nested strikethroughs look broken.
			$inner .= $reference['wrap']
				? '<del class="b2bpl-price__' . esc_attr( $reference['kind'] ) . '" aria-hidden="true">' . $reference['html'] . '</del> '
				: '<span class="b2bpl-price__' . esc_attr( $reference['kind'] ) . '">' . $reference['html'] . '</span> ';
		}

		$inner .= $final . self::badge( $parts['discount_percent'] );

		if ( 'loop' === $parts['context'] ) {
			$inner .= self::minimum_note( $parts, false );
		} else {
			$inner .= self::minimum_note( $parts, true );
		}

		return self::wrapper( $parts, self::LAYOUT_INLINE, $inner );
	}

	/**
	 * Two-line arrangement: on the first line only the figure the buyer acts
	 * on -- the price paid and, when there is one, its offer badge. Everything
	 * that qualifies that figure -- the minimum quantity and the reference
	 * price, labelled rather than struck -- goes together on the second line,
	 * in the small type, because both are the same kind of footnote and a card
	 * reads worse when one of them is stranded up beside the big number.
	 *
	 * The second line is always emitted -- empty when a product has neither
	 * footnote -- so that a grid of cards keeps its rows, and therefore its
	 * buttons, aligned whether or not a given product has a wholesale price
	 * at all.
	 *
	 * @param array $parts Resolved price parts.
	 * @return string
	 */
	private static function render_stacked( array $parts ) {
		$primary = '<span class="b2bpl-price__primary">'
			. '<ins class="b2bpl-price__final">' . self::money( $parts['final_price'] ) . '</ins>'
			. self::badge( $parts['discount_percent'] )
			. '</span>';

		$reference = self::reference( $parts );
		$secondary = ltrim( self::minimum_note( $parts, false ) );

		if ( null !== $reference ) {
			$secondary .= ( '' === $secondary ? '' : ' ' )
				. '<span class="b2bpl-price__reference b2bpl-price__reference--' . esc_attr( $reference['kind'] ) . '">'
				. '<span class="b2bpl-price__reference-label">' . esc_html( $reference['label'] ) . '</span> '
				. '<span class="b2bpl-price__reference-amount">' . $reference['html'] . '</span>'
				. '</span>';
		}

		return self::wrapper( $parts, self::LAYOUT_STACKED, $primary . '<span class="b2bpl-price__secondary">' . $secondary . '</span>' );
	}

	/**
	 * Decides which price the shopper is being asked to compare against, and
	 * what to call it.
	 *
	 * With an offer in force that is the wholesale price the offer was taken
	 * off -- comparing against the retail price instead would misstate the
	 * saving. Without one it is WooCommerce's own price, which on a catalogue
	 * card may be a range and therefore arrives as ready-made HTML.
	 *
	 * @param array $parts Resolved price parts.
	 * @return array{kind:string, label:string, html:string, wrap:bool}|null
	 */
	private static function reference( array $parts ) {
		if ( null !== $parts['discount_percent'] && null !== $parts['base_price'] ) {
			return array(
				'kind'  => 'base',
				'label' => __( 'Before', 'b2b-price-lists' ),
				'html'  => self::money( $parts['base_price'] ),
				'wrap'  => true,
			);
		}

		if ( null !== $parts['regular_price'] ) {
			return array(
				'kind'  => 'regular',
				'label' => __( 'Unit', 'b2b-price-lists' ),
				'html'  => self::money( $parts['regular_price'] ),
				'wrap'  => true,
			);
		}

		if ( ! empty( $parts['regular_html'] ) ) {
			return array(
				'kind'  => 'regular',
				'label' => __( 'Unit', 'b2b-price-lists' ),
				// Already a full native price block: possibly a range, or a
				// product WooCommerce itself considers on sale, in which case
				// it brings its own <del>/<ins> pair.
				'html'  => $parts['regular_html'],
				'wrap'  => false === strpos( $parts['regular_html'], '<del' ),
			);
		}

		return null;
	}

	/**
	 * The minimum-quantity note.
	 *
	 * @param array $parts Resolved price parts.
	 * @param bool  $long  Whether to use the long wording (single product
	 *                     page) rather than the abbreviated one that fits a
	 *                     catalogue card.
	 * @return string
	 */
	private static function minimum_note( array $parts, $long ) {
		$minimum = (int) $parts['minimum_quantity'];

		if ( $minimum <= 1 ) {
			return '';
		}

		if ( $long ) {
			return ' <span class="b2bpl-min-qty-note">' . esc_html(
				sprintf(
					/* translators: %d: minimum purchasable quantity. */
					__( '(minimum quantity: %d)', 'b2b-price-lists' ),
					$minimum
				)
			) . '</span>';
		}

		return ' <em class="b2bpl-price__min">' . esc_html(
			sprintf(
				/* translators: %d: minimum purchasable quantity, abbreviated to fit a catalogue card. */
				_n( 'from %d u.', 'from %d u.', $minimum, 'b2b-price-lists' ),
				$minimum
			)
		) . '</em>';
	}

	/**
	 * The "-20%" badge.
	 *
	 * @param float|null $percent Offer percentage.
	 * @return string
	 */
	public static function badge( $percent ) {
		if ( null === $percent ) {
			return '';
		}

		return ' <span class="b2bpl-discount-badge">-' . esc_html( Discount::format_percent( $percent ) ) . '%</span>';
	}

	/**
	 * The outer element, carrying the raw numbers as data attributes so a
	 * theme or a page builder can read them without parsing anything.
	 *
	 * @param array  $parts  Resolved price parts.
	 * @param string $layout Layout in use.
	 * @param string $inner  Inner markup.
	 * @return string
	 */
	private static function wrapper( array $parts, $layout, $inner ) {
		// The context travels as a class of its own: a card in a grid and the
		// price beside a product title want the same pieces but not always the
		// same alignment, and a theme should be able to tell them apart in CSS
		// without reading data attributes.
		$context = 'loop' === $parts['context'] ? 'loop' : 'single';

		$attributes = array(
			'class'              => 'b2bpl-price b2bpl-price--' . $layout . ' b2bpl-price--' . $context,
			'data-b2bpl-final'   => self::amount_attribute( $parts['final_price'] ),
			'data-b2bpl-min-qty' => (string) max( 1, (int) $parts['minimum_quantity'] ),
			'data-b2bpl-product' => (string) (int) $parts['product_id'],
		);

		if ( null !== $parts['base_price'] ) {
			$attributes['data-b2bpl-base'] = self::amount_attribute( $parts['base_price'] );
		}

		if ( null !== $parts['regular_price'] ) {
			$attributes['data-b2bpl-regular'] = self::amount_attribute( $parts['regular_price'] );
		}

		if ( null !== $parts['discount_percent'] ) {
			$attributes['data-b2bpl-discount'] = Discount::format_percent( $parts['discount_percent'] );
		}

		if ( ! empty( $parts['variation_id'] ) ) {
			$attributes['data-b2bpl-variation'] = (string) (int) $parts['variation_id'];
		}

		$rendered = '';

		foreach ( $attributes as $name => $value ) {
			$rendered .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		return '<span' . $rendered . '>' . $inner . '</span>';
	}

	/**
	 * Formats an amount as currency HTML, via WooCommerce's own `wc_price()`.
	 *
	 * @param float|null $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		return null === $amount ? '' : wc_price( (float) $amount );
	}

	/**
	 * Formats an amount for a data attribute: a plain machine-readable
	 * number, never a localized or symbol-carrying string.
	 *
	 * @param float|null $amount Amount.
	 * @return string
	 */
	private static function amount_attribute( $amount ) {
		if ( null === $amount ) {
			return '';
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return number_format( (float) $amount, max( 0, $decimals ), '.', '' );
	}
}
