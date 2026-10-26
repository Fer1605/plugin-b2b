<?php
/**
 * Customer-facing wholesale price table: a single renderer
 * reused by the configured automatic hook position and by the
 * `[b2b_price_list_table]` shortcode, and also used to build the
 * per-variation table HTML embedded in the WooCommerce variation AJAX
 * payload so switching variations updates the table without a page reload
 * or a custom endpoint.
 *
 * Deliberately independent of the previous wholesale plugin/theme: no
 * template override, no reference to `wholesale-table-fix.php` or any
 * child-theme function -- only official WooCommerce hooks and a shortcode.
 *
 * @package B2BPriceLists\Frontend
 */

namespace B2BPriceLists\Frontend;

use B2BPriceLists\Domain\PriceResolver;
use B2BPriceLists\Domain\RuleGroupValidator;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\TableSettings;
use Throwable;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriceTableRenderer
 */
class PriceTableRenderer {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE_TAG = 'b2b_price_list_table';

	/**
	 * Price resolution orchestrator.
	 *
	 * @var PriceResolver
	 */
	private $resolver;

	/**
	 * Product ids already rendered in this request ( deduplication),
	 * regardless of whether the render came from the automatic hook or the
	 * shortcode.
	 *
	 * @var array<int,bool>
	 */
	private $rendered_for_product = array();

	/**
	 * Constructor.
	 *
	 * @param PriceResolver|null $resolver Optional, for tests.
	 */
	public function __construct( ?PriceResolver $resolver = null ) {
		$this->resolver = $resolver ?? new PriceResolver();
	}

	/**
	 * Registers the shortcode always, and a single automatic hook when the
	 * configured position is not `manual_shortcode` (plus, for the two
	 * variation-only positions, a fallback hook so simple products still
	 * get a table -- architecture-analysis.md section 18).
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::SHORTCODE_TAG, array( $this, 'render_shortcode' ) );
		add_filter( 'woocommerce_available_variation', array( $this, 'filter_variation_data' ), 20, 3 );

		// The configured position (and therefore which automatic hook, if
		// any, gets registered) is only decided once translations are safe
		// to load (WordPress core warns/errors when `__()` runs before
		// 'init' -- `TableSettings::defaults()` calls it). `Plugin::init()`
		// itself runs on `plugins_loaded`, earlier than that, so the actual
		// decision is deferred to 'init'.
		add_action( 'init', array( $this, 'register_position_hook' ) );
	}

	/**
	 * Registers the single automatic hook (plus, for the two
	 * variation-only positions, a simple-product fallback hook) for the
	 * currently configured position. Deferred to 'init' -- see `register()`.
	 *
	 * @return void
	 */
	public function register_position_hook() {
		$settings = TableSettings::get();
		$position = $settings['position'];

		if ( TableSettings::POSITION_MANUAL_SHORTCODE === $position ) {
			return;
		}

		$hook_map = TableSettings::position_hook_map();

		if ( isset( $hook_map[ $position ] ) ) {
			add_action( $hook_map[ $position ], array( $this, 'render_for_current_product' ) );
		}

		$fallback_map = TableSettings::simple_product_fallback_hook_map();

		if ( isset( $fallback_map[ $position ] ) ) {
			add_action( $fallback_map[ $position ], array( $this, 'render_for_current_product_simple_fallback' ) );
		}
	}

	/**
	 * Automatic hook target for the configured position.
	 *
	 * @return void
	 */
	public function render_for_current_product() {
		global $product;

		if ( ! ( $product instanceof WC_Product ) ) {
			return;
		}

		echo $this->render_for_product( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_for_product() only ever returns internally-built, already-escaped markup (wc_price() + esc_html()).
	}

	/**
	 * Fallback hook target for `before_variations`/`after_variations`:
	 * only fires for a SIMPLE product, since the primary hook
	 * (`woocommerce_*_variations_form`) never fires at all outside a
	 * variable product's template.
	 *
	 * @return void
	 */
	public function render_for_current_product_simple_fallback() {
		global $product;

		if ( ! ( $product instanceof WC_Product ) || $product->is_type( 'variable' ) ) {
			return;
		}

		echo $this->render_for_product( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see render_for_current_product().
	}

	/**
	 * `[b2b_price_list_table]` shortcode: always reuses the same renderer,
	 * defaults to the current product in the loop/single product context.
	 *
	 * @param array $atts Shortcode attributes: `product_id` (optional).
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), (array) $atts, self::SHORTCODE_TAG );

		$product_id = absint( $atts['product_id'] );

		if ( ! $product_id ) {
			global $product;

			if ( $product instanceof WC_Product ) {
				$product_id = $product->get_id();
			}
		}

		if ( ! $product_id ) {
			return '';
		}

		return $this->render_for_product( $product_id );
	}

	/**
	 * Core renderer, reused by every entry point above. Never renders
	 * anything for a guest, a user without a list, or a product without an
	 * applicable rule.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function render_for_product( $product_id ) {
		$product_id = (int) $product_id;

		try {
			if ( isset( $this->rendered_for_product[ $product_id ] ) ) {
				if ( Capabilities::current_user_can_manage() ) {
					return "\n<!-- B2B Price Lists: table already rendered for product #{$product_id} in this request (automatic position + shortcode both configured for it). -->\n";
				}

				return '';
			}

			$this->rendered_for_product[ $product_id ] = true;

			$settings = TableSettings::get();

			if ( ! $settings['show_table'] ) {
				return '';
			}

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return '';
			}

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				return '';
			}

			if ( $product->is_type( 'variable' ) ) {
				return $this->render_variable_before_selection( $product_id, $settings );
			}

			$result = $this->resolver->resolve( $user_id, $product_id, null, 1 );

			if ( ! $result['applies'] ) {
				return '';
			}

			return $this->wrap_table( $product_id, $settings, $this->build_rows( $result, $settings ) );
		} catch ( Throwable $exception ) {
			unset( $exception );

			// TC-067: never break the page over the price table.
			return '';
		}
	}

	/**
	 * Renders the table for a variable product before a variation has been
	 * selected: the homogeneous rule's full table when every variation
	 * shares it, or an empty placeholder (swapped in by presentation JS
	 * once a variation is selected) when prices differ.
	 *
	 * @param int   $product_id Product id.
	 * @param array $settings   Table settings.
	 * @return string
	 */
	private function render_variable_before_selection( $product_id, array $settings ) {
		$user_id   = get_current_user_id();
		$aggregate = $this->resolver->resolve_variable_aggregate( $user_id, $product_id );

		if ( ! $aggregate['applies'] ) {
			return '';
		}

		if ( ! $aggregate['homogeneous'] ) {
			// Heterogeneous: no single table applies yet -- render an empty,
			// clearly-scoped placeholder that presentation JS fills in via
			// `data.b2bpl_price_table_html` once a variation is selected.
			return $this->placeholder_markup( $product_id );
		}

		$representative = $this->find_representative_result( $user_id, $product_id );

		if ( null === $representative ) {
			return $this->placeholder_markup( $product_id );
		}

		return $this->wrap_table( $product_id, $settings, $this->build_rows( $representative, $settings ) );
	}

	/**
	 * Finds the exact resolved rule (with its full range table) of the
	 * first covered variation of a product -- used to render the full
	 * table for the homogeneous case, since the aggregate summary
	 * (`resolve_variable_aggregate()`) intentionally only carries the entry
	 * price/minimum quantity, not the deeper tiers.
	 *
	 * @param int $user_id    User id.
	 * @param int $product_id Product id.
	 * @return array|null
	 */
	private function find_representative_result( $user_id, $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return null;
		}

		foreach ( $product->get_children() as $variation_id ) {
			$result = $this->resolver->resolve( $user_id, $product_id, (int) $variation_id, 1 );

			if ( $result['applies'] ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * `woocommerce_available_variation`: adds the full price table HTML for
	 * the selected variation to the AJAX payload, so presentation JS can
	 * swap it in without any custom endpoint (reuses the same official
	 * WooCommerce variation data WordPress already provides).
	 *
	 * @param array       $data      Variation payload.
	 * @param \WC_Product $product   Parent product.
	 * @param \WC_Product $variation Variation product.
	 * @return array
	 */
	public function filter_variation_data( $data, $product, $variation ) {
		try {
			unset( $product );

			if ( ! is_array( $data ) || ! isset( $variation ) ) {
				return $data;
			}

			$settings = TableSettings::get();

			if ( ! $settings['show_table'] ) {
				return $data;
			}

			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return $data;
			}

			$variation_id = method_exists( $variation, 'get_id' ) ? (int) $variation->get_id() : 0;
			$parent_id    = method_exists( $variation, 'get_parent_id' ) ? (int) $variation->get_parent_id() : 0;

			if ( ! $variation_id || ! $parent_id ) {
				return $data;
			}

			$result = $this->resolver->resolve( $user_id, $parent_id, $variation_id, 1 );

			if ( ! $result['applies'] ) {
				$data['b2bpl_price_table_html'] = '';

				return $data;
			}

			$data['b2bpl_price_table_html'] = $this->build_table_markup( $variation_id, $this->build_rows( $result, $settings ), $settings );

			return $data;
		} catch ( Throwable $exception ) {
			unset( $exception );

			return $data;
		}
	}

	/**
	 * Builds the row data (quantity range text, formatted price) for a
	 * resolved rule: a single row for `fixed`, one row per tier for
	 * `tiered`.
	 *
	 * A tier carrying an offer renders its own price as "struck base price +
	 * final price + -N% badge", so the table reads the same way as the main
	 * price does: the customer can see which tiers are on offer and which
	 * are not, row by row.
	 *
	 * @param array $result   `PriceResolver::resolve()` result, applies=true.
	 * @param array $settings Table settings (unused here, reserved for
	 *                        future per-row formatting options).
	 * @return array<int,array{qty_text:string, price_html:string, has_offer:bool}>
	 */
	private function build_rows( array $result, array $settings ) {
		unset( $settings );

		if ( RuleGroupValidator::PRICING_MODE_FIXED === $result['pricing_mode'] ) {
			return array(
				array(
					'qty_text'   => $this->format_quantity_range( (int) $result['minimum_quantity'], null ),
					'price_html' => $this->format_row_price(
						(float) $result['b2b_unit_price'],
						$result['base_unit_price'] ?? null,
						$result['discount_percent'] ?? null
					),
					'has_offer'  => isset( $result['discount_percent'] ) && null !== $result['discount_percent'],
				),
			);
		}

		$rows = array();

		foreach ( (array) ( $result['ranges'] ?? array() ) as $range ) {
			$max = ( isset( $range['max_qty'] ) && null !== $range['max_qty'] && '' !== $range['max_qty'] )
				? (int) $range['max_qty']
				: null;

			$rows[] = array(
				'qty_text'   => $this->format_quantity_range( (int) $range['min_qty'], $max ),
				'price_html' => $this->format_row_price(
					(float) $range['unit_price'],
					$range['base_unit_price'] ?? null,
					$range['discount_percent'] ?? null
				),
				'has_offer'  => isset( $range['discount_percent'] ) && null !== $range['discount_percent'],
			);
		}

		return $rows;
	}

	/**
	 * Formats one table cell's price: a plain amount, or -- when that tier
	 * carries an offer -- the struck-through pre-offer price, the final
	 * price and the shared "-N%" badge.
	 *
	 * @param float      $final_price Price the buyer pays for this tier.
	 * @param float|null $base_price  Price before the offer, when there is one.
	 * @param float|null $percent     Offer percentage, when there is one.
	 * @return string Already-safe HTML (`wc_price()` + escaped badge).
	 */
	private function format_row_price( $final_price, $base_price, $percent ) {
		$final_html = wc_price( $final_price );

		if ( null === $percent || null === $base_price ) {
			return $final_html;
		}

		return '<del aria-hidden="true">' . wc_price( (float) $base_price ) . '</del> <ins>' . $final_html . '</ins>'
			. PriceMarkup::badge( $percent );
	}

	/**
	 * Formats a quantity range as translatable text (the format
	 * itself is not administrable, only its translation).
	 *
	 * @param int      $min Minimum quantity.
	 * @param int|null $max Maximum quantity, or null for an open range.
	 * @return string
	 */
	private function format_quantity_range( $min, $max ) {
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

	/**
	 * Wraps a set of rows in the full table markup, honoring the
	 * show/hide-title and show/hide-headers settings.
	 *
	 * @param int   $product_id Product id (used for the wrapper's data attribute).
	 * @param array $settings   Table settings.
	 * @param array $rows       Rows built by `build_rows()`.
	 * @return string
	 */
	private function wrap_table( $product_id, array $settings, array $rows ) {
		if ( empty( $rows ) ) {
			return '';
		}

		$title_html = '';

		if ( $settings['show_title'] ) {
			$title_html = '<h3 class="b2bpl-price-table-title">' . esc_html( $settings['title'] ) . '</h3>';
		}

		$table_html = $this->build_table_markup( $product_id, $rows, $settings );

		return "\n" . '<div class="b2bpl-price-table-wrap" data-b2bpl-product-id="' . esc_attr( (string) $product_id ) . '">'
			. $title_html . $table_html . '</div>' . "\n";
	}

	/**
	 * Builds only the `<table>` element (no title, no outer wrapper) --
	 * reused both for the full server render and for the AJAX
	 * variation-swap payload, so the two never drift out of sync.
	 *
	 * @param int   $context_id Product or variation id (for a data attribute only).
	 * @param array $rows       Rows built by `build_rows()`.
	 * @param array $settings   Table settings.
	 * @return string
	 */
	private function build_table_markup( $context_id, array $rows, array $settings ) {
		if ( empty( $rows ) ) {
			return '';
		}

		$thead = '';

		if ( $settings['show_headers'] ) {
			$thead = '<thead><tr><th class="b2bpl-price-table-qty">' . esc_html( $settings['qty_header'] )
				. '</th><th class="b2bpl-price-table-price">' . esc_html( $settings['price_header'] ) . '</th></tr></thead>';
		}

		$tbody_rows = '';

		foreach ( $rows as $row ) {
			$row_class   = ! empty( $row['has_offer'] ) ? ' class="b2bpl-price-table-row--offer"' : '';
			$tbody_rows .= '<tr' . $row_class . '><td class="b2bpl-price-table-qty">' . esc_html( $row['qty_text'] ) . '</td>'
				. '<td class="b2bpl-price-table-price">' . $row['price_html'] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- price_html is always built by format_row_price() from wc_price() plus an escaped badge, already-safe HTML.
		}

		return '<table class="b2bpl-price-table" data-b2bpl-context-id="' . esc_attr( (string) $context_id ) . '">'
			. $thead . '<tbody>' . $tbody_rows . '</tbody></table>';
	}

	/**
	 * Empty placeholder for a heterogeneous variable product, filled in by
	 * presentation JS once a variation is selected.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	private function placeholder_markup( $product_id ) {
		return "\n" . '<div class="b2bpl-price-table-wrap b2bpl-price-table-placeholder" data-b2bpl-product-id="'
			. esc_attr( (string) $product_id ) . '"></div>' . "\n";
	}
}
