<?php
/**
 * The only door between the catalogue webhook and WooCommerce.
 *
 * Everything in `Webhook\Operations\CatalogOperations` -- validating a
 * payload, deciding whether a product is created or updated, shaping the
 * per-item result -- is plain PHP and is unit-tested as such. Everything that
 * actually writes a product, a term or an image lives behind this interface,
 * which the test suite replaces with a fake. That split is what lets this
 * feature be tested at all: the suite runs without WordPress or WooCommerce
 * loaded (`tests/unit/bootstrap.php`).
 *
 * @package B2BPriceLists\Webhook\Writer
 */

namespace B2BPriceLists\Webhook\Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CatalogWriter
 */
interface CatalogWriter {

	/**
	 * Product id for a SKU, or 0 when no product has it.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	public function find_by_sku( $sku );

	/**
	 * Product id previously imported under an external id, or 0. Lets a
	 * product survive a SKU change in the sending system.
	 *
	 * @param string $external_id External identifier.
	 * @return int
	 */
	public function find_by_external_id( $external_id );

	/**
	 * WooCommerce product type of an existing product (`simple`,
	 * `variable`, …), or an empty string when it does not exist.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function product_type( $product_id );

	/**
	 * Creates or updates a product with exactly the fields given -- a field
	 * that is absent is left untouched, never emptied.
	 *
	 * @param array<string,mixed> $fields Normalized product fields.
	 * @return array{id:int,created:bool}
	 */
	public function save_product( array $fields );

	/**
	 * Creates or updates the variations of a variable product. Variations
	 * not mentioned are kept; only an entry marked `delete` is removed.
	 *
	 * @param int                            $parent_id  Parent product id.
	 * @param array<int,array<string,mixed>> $variations Normalized variations.
	 * @return array{created:int,updated:int,deleted:int,errors:array<int,array<string,string>>}
	 */
	public function save_variations( $parent_id, array $variations );

	/**
	 * Assigns categories, tags and brand.
	 *
	 * @param int                 $product_id Product id.
	 * @param array<string,mixed> $terms      `categories`, `tags`, `brand`.
	 * @param bool                $create     Whether a missing term may be created.
	 * @return array{assigned:array<string,int>,errors:array<int,array<string,string>>}
	 */
	public function set_terms( $product_id, array $terms, $create );

	/**
	 * Downloads and attaches images, skipping any whose source URL was
	 * already imported for this product.
	 *
	 * @param int                            $product_id Product id.
	 * @param array<int,array<string,mixed>> $images     Normalized images.
	 * @return array{imported:int,skipped:int,errors:array<int,array<string,string>>}
	 */
	public function import_images( $product_id, array $images );

	/**
	 * Takes a product out of sale without deleting it.
	 *
	 * @param int    $product_id Product id.
	 * @param string $mode       `draft` or `outofstock`.
	 * @return array{mode:string}
	 */
	public function discontinue( $product_id, $mode );
}
