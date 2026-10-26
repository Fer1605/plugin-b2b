<?php
/**
 * Admin-only AJAX product/variation search used by the rule group editor's
 * hierarchical selector. Paginated, capability-gated and
 * nonce-verified exactly like every other admin endpoint of this plugin --
 * this endpoint is not an exception (corrects RI-M7).
 *
 * Never registered with `wp_ajax_nopriv_*`.
 *
 * @package B2BPriceLists\Ajax
 */

namespace B2BPriceLists\Ajax;

use B2BPriceLists\Domain\ProductLookup;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\Nonces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductSearchController
 */
class ProductSearchController {

	/**
	 * Hard upper bound on page size, regardless of what the client asks
	 * for, so a manipulated request can never force loading the full
	 * catalog at once.
	 *
	 * @var int
	 */
	const MAX_PER_PAGE = 50;

	/**
	 * Registers the AJAX hooks. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_b2bpl_search_products', array( $this, 'handle_search_products' ) );
		add_action( 'wp_ajax_b2bpl_search_variations', array( $this, 'handle_search_variations' ) );
	}

	/**
	 * `wp_ajax_b2bpl_search_products`: paginated product search by name,
	 * SKU, ID, category and type.
	 *
	 * @return void
	 */
	public function handle_search_products() {
		check_ajax_referer( Nonces::AJAX_ACTION, 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'b2b-price-lists' ) ), 403 );
		}

		$term     = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$page     = isset( $_GET['page'] ) ? max( 1, absint( $_GET['page'] ) ) : 1;
		$per_page = isset( $_GET['per_page'] ) ? min( self::MAX_PER_PAGE, max( 1, absint( $_GET['per_page'] ) ) ) : 20;
		$type     = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$category = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( $_GET['category'] ) ) : '';
		$brand    = isset( $_GET['brand'] ) ? sanitize_title( wp_unslash( $_GET['brand'] ) ) : '';

		$args = array(
			'status'   => array( 'publish', 'private' ),
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => 'title',
			'order'    => 'ASC',
			'return'   => 'objects',
			'paginate' => true,
		);

		if ( in_array( $type, array( 'simple', 'variable' ), true ) ) {
			$args['type'] = $type;
		} else {
			$args['type'] = array( 'simple', 'variable' );
		}

		if ( '' !== $category ) {
			$args['category'] = array( $category );
		}

		if ( '' !== $brand && taxonomy_exists( 'product_brand' ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- admin-only, paginated, low-frequency search endpoint.
				array(
					'taxonomy' => 'product_brand',
					'field'    => 'slug',
					'terms'    => array( $brand ),
				),
			);
		}

		$exact_id_match = null;

		if ( '' !== $term && is_numeric( $term ) && wc_get_product( (int) $term ) ) {
			$exact_id_match = (int) $term;
		}

		$items    = array();
		$seen_ids = array();

		// An exact id match always leads: typing a bare product id is an
		// unambiguous request for that one product.
		if ( $exact_id_match && 1 === $page ) {
			$exact_product = wc_get_product( $exact_id_match );

			if ( $exact_product ) {
				$items[]                     = $this->format_product( $exact_product );
				$seen_ids[ $exact_id_match ] = true;
			}
		}

		// A partial SKU has to be searched for separately: WooCommerce's own
		// `s` argument only looks at the title/content, and its `sku`
		// argument (a `_sku LIKE` meta query) would be ANDed with `s` if both
		// were passed to the same call -- which would return nothing for the
		// common case of a code that appears in the SKU but not in the name.
		// Two queries is what makes "find it by name OR by SKU" behave the
		// way an operator expects.
		$sku_total = 0;

		if ( '' !== $term ) {
			$sku_result = $this->query_products( array_merge( $args, array( 'sku' => $term ) ) );
			$sku_total  = (int) $sku_result['total'];

			foreach ( $sku_result['products'] as $product ) {
				$product_id = (int) $product->get_id();

				if ( isset( $seen_ids[ $product_id ] ) ) {
					continue;
				}

				$seen_ids[ $product_id ] = true;
				$items[]                 = $this->format_product( $product );
			}

			$args['s'] = $term;
		}

		// The name query EXCLUDES everything the SKU pass already returned, so
		// the two result sets are disjoint. That is what keeps the merge
		// honest: a name match is never dropped to make room for a duplicate,
		// and `total` is a plain sum instead of a guess.
		if ( ! empty( $seen_ids ) ) {
			$args['exclude'] = array_keys( $seen_ids );
		}

		$name_result = $this->query_products( $args );

		foreach ( $name_result['products'] as $product ) {
			$product_id = (int) $product->get_id();

			if ( isset( $seen_ids[ $product_id ] ) ) {
				continue;
			}

			$seen_ids[ $product_id ] = true;
			$items[]                 = $this->format_product( $product );
		}

		$total = $sku_total + (int) $name_result['total'];
		$items = array_slice( $items, 0, $per_page );
		$items = array_slice( $items, 0, $per_page );

		wp_send_json_success(
			array(
				'items'    => $items,
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => $total,
				'has_more' => ( $page * $per_page ) < $total,
			)
		);
	}

	/**
	 * Runs one `wc_get_products()` call and normalizes its paginated
	 * response into a plain `{products, total}` shape.
	 *
	 * @param array $args `wc_get_products()` arguments.
	 * @return array{products:array<int,\WC_Product>, total:int}
	 */
	private function query_products( array $args ) {
		$result   = wc_get_products( $args );
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : (array) $result;
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );

		return array(
			'products' => $products,
			'total'    => $total,
		);
	}

	/**
	 * `wp_ajax_b2bpl_search_variations`: paginated list of a variable
	 * product's variations, in human-readable form.
	 *
	 * @return void
	 */
	public function handle_search_variations() {
		check_ajax_referer( Nonces::AJAX_ACTION, 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'b2b-price-lists' ) ), 403 );
		}

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$page       = isset( $_GET['page'] ) ? max( 1, absint( $_GET['page'] ) ) : 1;
		$per_page   = isset( $_GET['per_page'] ) ? min( self::MAX_PER_PAGE, max( 1, absint( $_GET['per_page'] ) ) ) : 50;

		$lookup = new ProductLookup();

		if ( ! $lookup->is_variable( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This product has no variations.', 'b2b-price-lists' ) ), 400 );
		}

		$all_variation_ids = $lookup->get_variation_ids( $product_id );
		$total             = count( $all_variation_ids );
		$slice             = array_slice( $all_variation_ids, ( $page - 1 ) * $per_page, $per_page );

		$items = array();

		foreach ( $slice as $variation_id ) {
			$sku_info = $lookup->get_variation_sku( $variation_id );

			$items[] = array(
				'id'           => $variation_id,
				'text'         => $lookup->get_variation_label( $variation_id ),
				'sku'          => $sku_info['sku'],
				'skuInherited' => $sku_info['inherited'],
			);
		}

		wp_send_json_success(
			array(
				'items'    => $items,
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => $total,
				'has_more' => ( $page * $per_page ) < $total,
			)
		);
	}

	/**
	 * Formats a single product for the JSON response, returning only the
	 * fields the admin selector needs.
	 *
	 * `text` is deliberately the exact same label
	 * `Domain\ProductLookup::get_product_label()` builds server-side, so the
	 * search box shows an identical string whether the product was just
	 * picked from the dropdown or rendered from an already-saved rule. `name`
	 * and `sku` are sent alongside it so the dropdown can lay out the name
	 * and its SKU as separate, scannable pieces instead of one long line.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	private function format_product( $product ) {
		$sku  = (string) $product->get_sku();
		$name = $product->get_name();
		$id   = (int) $product->get_id();

		$text = ( '' !== $sku )
			? sprintf( '%1$s (#%2$d, SKU: %3$s)', $name, $id, $sku )
			: sprintf( '%1$s (#%2$d)', $name, $id );

		return array(
			'id'   => $id,
			'name' => $name,
			'text' => $text,
			'type' => $product->get_type(),
			'sku'  => $sku,
		);
	}
}
