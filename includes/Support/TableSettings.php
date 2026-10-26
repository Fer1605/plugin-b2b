<?php
/**
 * Persistence and strict whitelisting for the customer-facing price table
 * configuration: show/hide table, show/hide title,
 * title text, show/hide headers, quantity/price header text, and the single
 * enumerated table position. Stored as one `wp_options` row.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TableSettings
 */
class TableSettings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'b2bpl_table_settings';

	const POSITION_BEFORE_VARIATIONS      = 'before_variations';
	const POSITION_AFTER_VARIATIONS       = 'after_variations';
	const POSITION_BEFORE_ADD_TO_CART     = 'before_add_to_cart';
	const POSITION_AFTER_ADD_TO_CART      = 'after_add_to_cart';
	const POSITION_AFTER_ADD_TO_CART_FORM = 'after_add_to_cart_form';
	const POSITION_MANUAL_SHORTCODE       = 'manual_shortcode';

	/**
	 * Catalogue card layouts (see `Frontend\PriceMarkup`).
	 */
	const CARD_LAYOUT_INLINE  = 'inline';
	const CARD_LAYOUT_STACKED = 'stacked';

	/**
	 * The only 2 valid values of `card_layout` -- any other value received
	 * from a request is rejected, never silently coerced.
	 *
	 * @return string[]
	 */
	public static function valid_card_layouts() {
		return array(
			self::CARD_LAYOUT_INLINE,
			self::CARD_LAYOUT_STACKED,
		);
	}

	/**
	 * The only 6 valid values of `position` -- any other value
	 * received from a request is rejected, never silently coerced.
	 *
	 * @return string[]
	 */
	public static function valid_positions() {
		return array(
			self::POSITION_BEFORE_VARIATIONS,
			self::POSITION_AFTER_VARIATIONS,
			self::POSITION_BEFORE_ADD_TO_CART,
			self::POSITION_AFTER_ADD_TO_CART,
			self::POSITION_AFTER_ADD_TO_CART_FORM,
			self::POSITION_MANUAL_SHORTCODE,
		);
	}

	/**
	 * The verified WooCommerce hook for each automatic position
	 * (architecture-analysis.md section 18). `manual_shortcode` has no
	 * automatic hook by design.
	 *
	 * @return array<string,string>
	 */
	public static function position_hook_map() {
		return array(
			self::POSITION_BEFORE_VARIATIONS      => 'woocommerce_before_variations_form',
			self::POSITION_AFTER_VARIATIONS       => 'woocommerce_after_variations_form',
			self::POSITION_BEFORE_ADD_TO_CART     => 'woocommerce_before_add_to_cart_button',
			self::POSITION_AFTER_ADD_TO_CART      => 'woocommerce_after_add_to_cart_button',
			self::POSITION_AFTER_ADD_TO_CART_FORM => 'woocommerce_after_add_to_cart_form',
		);
	}

	/**
	 * The nearest equivalent hook used as a fallback on simple products for
	 * the two variation-only positions (architecture-analysis.md section
	 * 18: "Solo aplica a productos variables; en simples cae a la posición
	 * equivalente más cercana"), since `woocommerce_*_variations_form`
	 * never fires at all for a simple product.
	 *
	 * @return array<string,string>
	 */
	public static function simple_product_fallback_hook_map() {
		return array(
			self::POSITION_BEFORE_VARIATIONS => 'woocommerce_before_add_to_cart_button',
			self::POSITION_AFTER_VARIATIONS  => 'woocommerce_after_add_to_cart_button',
		);
	}

	/**
	 * Default settings, used when no option has been saved yet and as the
	 * base merged with any saved value (so a future new field always has a
	 * safe default even for a site that saved settings before it existed).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'show_table'                  => true,
			'show_title'                  => true,
			'title'                       => __( 'Wholesale pricing', 'b2b-price-lists' ),
			'show_headers'                => true,
			'qty_header'                  => __( 'Quantity', 'b2b-price-lists' ),
			'price_header'                => __( 'Price', 'b2b-price-lists' ),
			'position'                    => self::POSITION_BEFORE_ADD_TO_CART,
			// DEC: the struck-through regular price + exact B2B
			// price + minimum-quantity note shown right after a variation is
			// selected. Defaults to on for backward compatibility with every
			// site that already relied on this block before the setting
			// existed. Never controls the table or catalog/loop pricing.
			'show_inline_variation_price' => true,
			// How a catalogue card arranges the B2B price. `inline` is the
			// plugin's original one-line form; `stacked` is the two-line one
			// most wholesale storefront designs use (price and minimum on
			// top, reference price labelled underneath). Defaults to `inline`
			// so no existing site changes appearance on update.
			'card_layout'                 => self::CARD_LAYOUT_INLINE,
			// The same choice for the single product page, kept apart from
			// the cards on purpose: a narrow card usually wants two lines
			// while the product page, with room to spare, reads best on one.
			// Until 1.2.2 the product page silently followed `card_layout`
			// for variable products. Defaults to `inline`.
			'single_layout'               => self::CARD_LAYOUT_INLINE,
		);
	}

	/**
	 * Returns the current, fully-sanitized settings (saved values merged
	 * over the defaults).
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return self::sanitize( array_merge( $defaults, $saved ) );
	}

	/**
	 * Sanitizes and strictly whitelists a settings submission, falling back
	 * to the default for any missing/invalid field -- never trusts raw
	 * input for the `position` enum in particular (rejects any value
	 * outside the 6 valid options instead of coercing it).
	 *
	 * @param array<string,mixed> $input Raw or partially-sanitized input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();

		$position = isset( $input['position'] ) ? (string) $input['position'] : $defaults['position'];

		if ( ! in_array( $position, self::valid_positions(), true ) ) {
			$position = $defaults['position'];
		}

		$card_layout = isset( $input['card_layout'] ) ? (string) $input['card_layout'] : $defaults['card_layout'];

		if ( ! in_array( $card_layout, self::valid_card_layouts(), true ) ) {
			$card_layout = $defaults['card_layout'];
		}

		$single_layout = isset( $input['single_layout'] ) ? (string) $input['single_layout'] : $defaults['single_layout'];

		if ( ! in_array( $single_layout, self::valid_card_layouts(), true ) ) {
			$single_layout = $defaults['single_layout'];
		}

		return array(
			'show_table'                  => ! empty( $input['show_table'] ),
			'show_title'                  => ! empty( $input['show_title'] ),
			'title'                       => isset( $input['title'] ) && '' !== trim( (string) $input['title'] )
				? sanitize_text_field( (string) $input['title'] )
				: $defaults['title'],
			'show_headers'                => ! empty( $input['show_headers'] ),
			'qty_header'                  => isset( $input['qty_header'] ) && '' !== trim( (string) $input['qty_header'] )
				? sanitize_text_field( (string) $input['qty_header'] )
				: $defaults['qty_header'],
			'price_header'                => isset( $input['price_header'] ) && '' !== trim( (string) $input['price_header'] )
				? sanitize_text_field( (string) $input['price_header'] )
				: $defaults['price_header'],
			'position'                    => $position,
			'show_inline_variation_price' => ! empty( $input['show_inline_variation_price'] ),
			'card_layout'                 => $card_layout,
			'single_layout'               => $single_layout,
		);
	}

	/**
	 * Persists a sanitized settings submission.
	 *
	 * @param array<string,mixed> $input Raw input (sanitized internally).
	 * @return array<string,mixed> The settings actually persisted.
	 */
	public static function save( array $input ) {
		$sanitized = self::sanitize( $input );

		update_option( self::OPTION_NAME, $sanitized, false );

		return $sanitized;
	}
}
