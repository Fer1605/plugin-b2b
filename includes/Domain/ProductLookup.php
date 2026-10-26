<?php
/**
 * Thin wrapper around WooCommerce's own product/variation APIs.
 *
 * Isolates every direct `wc_get_product()`/`WC_Product` call behind one
 * class, so the rest of the domain/admin layer never talks to WooCommerce
 * data structures directly.
 *
 * @package B2BPriceLists\Domain
 */

namespace B2BPriceLists\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductLookup
 */
class ProductLookup {

	/**
	 * Whether a product id currently exists (any status WooCommerce
	 * itself considers valid via `wc_get_product()`).
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public function exists( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		return false !== $product && null !== $product;
	}

	/**
	 * Whether a product is a variable product (has variations).
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public function is_variable( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product ) {
			return false;
		}

		return $product->is_type( 'variable' );
	}

	/**
	 * Returns every variation id belonging to a variable product.
	 *
	 * @param int $product_id Parent product id.
	 * @return int[]
	 */
	public function get_variation_ids( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return array();
		}

		return array_map( 'intval', $product->get_children() );
	}

	/**
	 * A short, human-readable label for a product ("Name (#123, SKU: ABC)").
	 *
	 * @param int $product_id Product id.
	 * @return string Empty string when the product cannot be found.
	 */
	public function get_product_label( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product ) {
			return '';
		}

		$sku = $product->get_sku();

		if ( '' !== $sku ) {
			return sprintf( '%1$s (#%2$d, SKU: %3$s)', $product->get_name(), $product->get_id(), $sku );
		}

		return sprintf( '%1$s (#%2$d)', $product->get_name(), $product->get_id() );
	}

	/**
	 * Every product/variation id whose *own* SKU (never inherited -- see the
	 * docblock of `get_variation_sku()`) exactly matches a given string,
	 * queried against `wc_product_meta_lookup` (R6/) rather than
	 * `wc_get_product_id_by_sku()`, which returns a single id and would hide
	 * a genuine duplicate. `wc_product_meta_lookup.sku` is populated
	 * straight from each product/variation's own `_sku` postmeta, never from
	 * a parent's -- so a variation with no SKU of its own simply has an
	 * empty value there and is correctly never matched by a non-empty
	 * search string, which is exactly what lets the webhook tell "no such
	 * SKU anywhere" apart from "this variation has no SKU of its own"
	 * (`variation_sku_missing`, decided by the caller).
	 *
	 * @param string $sku Exact SKU to search for.
	 * @return int[] Product/variation ids matching exactly, in no particular
	 *               order. Empty when nothing matches; more than one entry
	 *               means the SKU is ambiguous.
	 */
	public function find_ids_by_sku( $sku ) {
		global $wpdb;

		$sku = trim( (string) $sku );

		if ( '' === $sku ) {
			return array();
		}

		$table = $wpdb->prefix . 'wc_product_meta_lookup';

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT product_id FROM {$table} WHERE sku = %s", $sku ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * A small, webhook-oriented description of a single product/variation
	 * id: its WooCommerce product type (`simple`, `variable`, `variation`,
	 * etc.), its parent id (`0` for anything without one) and whether its
	 * post status is `publish` -- everything `Webhook\Resolver\SkuResolver`
	 * needs to classify a SKU match, without exposing a `WC_Product` object
	 * outside of this class (see the class docblock).
	 *
	 * @param int $id Product or variation id.
	 * @return array{id:int,type:string,parent_id:int,published:bool}|null
	 *               `null` when the id does not resolve to a WooCommerce
	 *               product/variation at all.
	 */
	public function describe( $id ) {
		$id      = (int) $id;
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return null;
		}

		return array(
			'id'        => $product->get_id(),
			'type'      => (string) $product->get_type(),
			'parent_id' => (int) $product->get_parent_id(),
			// A product in the trash or still a draft must read exactly like
			// an inexistent one to the webhook (contract edge case): checked
			// via `get_post_status()` rather than `WC_Product::get_status()`,
			// since the latter can return a cached/derived value in some
			// WooCommerce versions -- `get_post_status()` is always the
			// authoritative source `wc_get_product()` itself is built on.
			'published' => 'publish' === get_post_status( $product->get_id() ),
		);
	}

	/**
	 * The SKU of a product or variation.
	 *
	 * A variation with no SKU of its own inherits its parent's in
	 * WooCommerce's own storefront/reporting logic, so this returns the
	 * parent's too -- flagged as inherited by `get_variation_sku_label()`,
	 * never presented as if the variation had been given its own code.
	 *
	 * @param int $product_id Product or variation id.
	 * @return string Empty string when there is no SKU anywhere.
	 */
	public function get_sku( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product ) {
			return '';
		}

		return (string) $product->get_sku();
	}

	/**
	 * The SKU to show for a variation, and whether it is the variation's
	 * own or inherited from its parent product.
	 *
	 * @param int $variation_id Variation id.
	 * @return array{sku:string, inherited:bool}
	 */
	public function get_variation_sku( $variation_id ) {
		$variation = wc_get_product( (int) $variation_id );

		if ( ! $variation ) {
			return array(
				'sku'       => '',
				'inherited' => false,
			);
		}

		// `get_sku( 'edit' )` is the ONLY way to ask a variation for its own
		// SKU: in the default 'view' context `WC_Product_Variation::get_sku()`
		// deliberately falls back to the parent product's SKU (verified
		// against the WooCommerce source), so reading it there would report
		// an inherited code as if the variation had been given one of its
		// own. The 'view' call below is then used precisely *for* that
		// inheritance, leaving WooCommerce the single authority on what a
		// variation's effective SKU is.
		$own_sku = (string) $variation->get_sku( 'edit' );

		if ( '' !== $own_sku ) {
			return array(
				'sku'       => $own_sku,
				'inherited' => false,
			);
		}

		$inherited_sku = (string) $variation->get_sku();

		return array(
			'sku'       => $inherited_sku,
			'inherited' => '' !== $inherited_sku,
		);
	}

	/**
	 * A human-readable label for a variation, built from its attribute
	 * values (e.g. "Blue dye - Box - M"), never just the raw id, and always
	 * carrying the SKU when the variation (or, failing that, its parent)
	 * has one -- an inherited SKU is explicitly marked as the parent's so
	 * nobody mistakes it for a code assigned to that one variation.
	 *
	 * @param int $variation_id Variation id.
	 * @return string Empty string when the variation cannot be found.
	 */
	public function get_variation_label( $variation_id ) {
		$variation = wc_get_product( (int) $variation_id );

		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return '';
		}

		$attributes = $variation->get_attributes();
		$parts      = array();

		foreach ( $attributes as $attribute_name => $attribute_value ) {
			if ( '' === $attribute_value ) {
				$parts[] = ucwords( str_replace( array( '-', '_' ), ' ', $attribute_name ) ) . ': ' . __( 'Any', 'b2b-price-lists' );
				continue;
			}

			$parts[] = wc_attribute_label( $attribute_name, $variation ) . ': ' . $attribute_value;
		}

		$label = implode( ' / ', $parts );

		if ( '' === $label ) {
			$label = $variation->get_formatted_name();
		}

		$sku_info = $this->get_variation_sku( $variation->get_id() );

		if ( '' !== $sku_info['sku'] ) {
			return sprintf(
				$sku_info['inherited']
					/* translators: 1: variation attributes, 2: variation id, 3: parent product SKU. */
					? __( '%1$s (#%2$d, SKU of the parent product: %3$s)', 'b2b-price-lists' )
					/* translators: 1: variation attributes, 2: variation id, 3: variation SKU. */
					: __( '%1$s (#%2$d, SKU: %3$s)', 'b2b-price-lists' ),
				wp_strip_all_tags( $label ),
				$variation->get_id(),
				$sku_info['sku']
			);
		}

		return sprintf( '%1$s (#%2$d)', wp_strip_all_tags( $label ), $variation->get_id() );
	}
}
