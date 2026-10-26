<?php
/**
 * Maps `product.upsert` and `product.discontinue` onto the
 * catalogue writer: creating and updating the WooCommerce products
 * themselves, as opposed to `ProductOperations`, which only puts B2B prices
 * on products that already exist.
 *
 * Two rules shape everything here, and they are the opposite of the ones the
 * price rules follow:
 *
 * - **A field that is not sent is not touched.** A shop keeps content the
 *   sending system does not have -- a description written by marketing, a
 *   retouched photo, a tag. Replacing the whole product on every sync would
 *   erase that work daily. Sending `null` does empty a field; omitting it
 *   leaves it alone.
 * - **Nothing is ever deleted.** `product.discontinue` takes a product out of
 *   sale and is reversible; a variation disappears only when it is sent with
 *   `delete: true`.
 *
 * Failure handling follows the same split as `ProductOperations`: the product
 * write itself failing is a business-rule failure and leaves the item in
 * `error`; a term, an image or one variation failing after the product was
 * written leaves the item `partial`, with the detail in `errors[]`, because
 * the product does exist and re-sending is harmless.
 *
 * @package B2BPriceLists\Webhook\Operations
 */

namespace B2BPriceLists\Webhook\Operations;

use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Webhook\Settings;
use B2BPriceLists\Webhook\Writer\CatalogWriter;
use B2BPriceLists\Webhook\Writer\ProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CatalogOperations
 */
class CatalogOperations {

	/**
	 * Product types this accepts.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'simple', 'variable' );

	/**
	 * Images accepted per product. A hard cap, not a setting: every image is
	 * an outbound download inside the batch's own time budget.
	 *
	 * @var int
	 */
	const MAX_IMAGES = 5;

	/**
	 * Variations accepted per product.
	 *
	 * @var int
	 */
	const MAX_VARIATIONS = 50;

	/**
	 * Catalogue writer.
	 *
	 * @var CatalogWriter
	 */
	private $writer;

	/**
	 * Webhook settings, resolved once.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param CatalogWriter|null       $writer   Optional, for tests.
	 * @param array<string,mixed>|null $settings Optional, for tests.
	 */
	public function __construct( ?CatalogWriter $writer = null, ?array $settings = null ) {
		$this->writer   = $writer ?? new ProductWriter();
		$this->settings = $settings;
	}

	/**
	 * `product.upsert`: creates the product when its SKU is unknown, and
	 * otherwise updates exactly the fields sent.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string,status?:string,errors?:array}
	 * @throws ItemFailure When the payload cannot produce a product at all.
	 */
	public function product_upsert( array $data ) {
		$this->assert_enabled();

		$sku  = self::require_sku( $data );
		$type = self::read_type( $data );

		$product_id = $this->locate( $sku, $data );
		$is_new     = 0 === $product_id;

		if ( ! $is_new ) {
			$existing_type = (string) $this->writer->product_type( $product_id );

			// Turning a simple product into a variable one (or back) throws
			// away prices, stock and variations. A feed must never do that
			// by accident: it is reported and nothing is written.
			if ( '' !== $existing_type && $existing_type !== $type ) {
				throw new ItemFailure(
					'product_type_mismatch',
					sprintf(
						/* translators: 1: SKU, 2: existing product type, 3: product type sent. */
						__( 'The product with SKU %1$s is of type %2$s and the batch sends it as %3$s. Change it in the shop first.', 'b2b-price-lists' ),
						$sku,
						$existing_type,
						$type
					),
					'data.type'
				);
			}
		}

		$fields = $this->build_fields( $data, $sku, $type, $is_new );

		// Carry the id when the product was found by its external id: the
		// writer would otherwise look it up by SKU, miss it, and create a
		// second product for the same article.
		if ( ! $is_new ) {
			$fields['id'] = $product_id;
		}
		$variations = 'variable' === $type ? self::read_variations( $data ) : array();

		$saved = $this->writer->save_product( $fields );

		$result = array(
			'product_id' => (int) $saved['id'],
			'sku'        => $sku,
			'type'       => $type,
			'created'    => ! empty( $saved['created'] ),
		);

		$errors = array();

		if ( ! empty( $variations ) ) {
			$written              = $this->writer->save_variations( (int) $saved['id'], $variations );
			$result['variations'] = array(
				'created' => (int) $written['created'],
				'updated' => (int) $written['updated'],
				'deleted' => (int) $written['deleted'],
			);
			$errors               = array_merge( $errors, $written['errors'] );
		}

		$terms = self::read_terms( $data );

		if ( ! empty( $terms ) ) {
			$assigned        = $this->writer->set_terms( (int) $saved['id'], $terms, $this->setting( 'catalog_create_terms', true ) );
			$result['terms'] = $assigned['assigned'];
			$errors          = array_merge( $errors, $assigned['errors'] );
		}

		$images = self::read_images( $data );

		if ( ! empty( $images ) ) {
			$imported         = $this->writer->import_images( (int) $saved['id'], $images );
			$result['images'] = array(
				'imported' => (int) $imported['imported'],
				'skipped'  => (int) $imported['skipped'],
			);
			$errors           = array_merge( $errors, $imported['errors'] );
		}

		$outcome = array(
			'result'          => $result,
			'external_ref'    => $sku,
			// The batch log already keeps the raw JSON; this is the entry a
			// shop manager reads in Activity, where nobody knows what a
			// batch is. Flushed by the processor once the item's own
			// transaction has committed.
			'pending_effects' => array(
				'log' => array(
					'action'  => $result['created'] ? ActivityLog::PRODUCT_CREATED : ActivityLog::PRODUCT_UPDATED,
					'context' => array(
						'object_type'  => 'product',
						'object_id'    => (int) $saved['id'],
						'object_label' => self::label( $sku, $fields ),
						'changes'      => self::describe_write( $sku, $type, $fields, $result ),
					),
				),
			),
		);

		// The product is written. Anything that failed afterwards is worth
		// reporting but does not undo it: `partial`, never `error`.
		if ( ! empty( $errors ) ) {
			$outcome['status'] = 'partial';
			$outcome['errors'] = $errors;
		}

		return $outcome;
	}

	/**
	 * `product.discontinue`: takes the product out of sale. A SKU that does
	 * not exist is `skipped`, not an error -- a sending system that no
	 * longer carries an article should not have to know whether this shop
	 * ever did.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string,status?:string}
	 * @throws ItemFailure When the catalogue module is off or the SKU is missing.
	 */
	public function product_discontinue( array $data ) {
		$this->assert_enabled();

		$sku        = self::require_sku( $data );
		$product_id = $this->locate( $sku, $data );

		if ( 0 === $product_id ) {
			return array(
				'result'       => array(
					'sku'     => $sku,
					'skipped' => true,
					'reason'  => 'product_not_found',
				),
				'external_ref' => $sku,
				'status'       => 'skipped',
			);
		}

		$mode = isset( $data['mode'] ) && 'outofstock' === $data['mode'] ? 'outofstock' : 'draft';
		$done = $this->writer->discontinue( $product_id, $mode );

		return array(
			'result'          => array(
				'product_id' => $product_id,
				'sku'        => $sku,
				'mode'       => (string) $done['mode'],
			),
			'external_ref'    => $sku,
			'pending_effects' => array(
				'log' => array(
					'action'  => ActivityLog::PRODUCT_DISCONTINUED,
					'context' => array(
						'object_type'  => 'product',
						'object_id'    => $product_id,
						'object_label' => $sku,
						'changes'      => array(
							array(
								'field'   => 'mode',
								'to'      => (string) $done['mode'],
								'initial' => true,
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The label this product is remembered by in the activity history: its
	 * name when one was sent, and always its SKU, which is what somebody
	 * searches by months later.
	 *
	 * @param string              $sku    SKU.
	 * @param array<string,mixed> $fields Normalized fields.
	 * @return string
	 */
	private static function label( $sku, array $fields ) {
		$name = isset( $fields['name'] ) ? trim( (string) $fields['name'] ) : '';

		return '' === $name ? $sku : $name . ' (' . $sku . ')';
	}

	/**
	 * The facts worth keeping about one write, in the shape the activity
	 * screen renders: what kind of product it is, which fields the batch
	 * actually wrote, and how much came with it. Deliberately the field
	 * *names*, not their values -- the values are already in the batch log,
	 * and the history is meant to be skimmed.
	 *
	 * @param string              $sku    SKU.
	 * @param string              $type   Product type.
	 * @param array<string,mixed> $fields Normalized fields.
	 * @param array<string,mixed> $result Per-item result.
	 * @return array<int,array<string,mixed>>
	 */
	private static function describe_write( $sku, $type, array $fields, array $result ) {
		$written = array_values(
			array_diff( array_keys( $fields ), array( 'sku', 'type', 'id' ) )
		);

		$changes = array(
			array(
				'field'   => 'sku',
				'to'      => $sku,
				'initial' => true,
			),
			array(
				'field'   => 'product_type',
				'to'      => $type,
				'initial' => true,
			),
		);

		if ( ! empty( $written ) ) {
			$changes[] = array(
				'field'   => 'fields_written',
				'to'      => implode( ', ', $written ),
				'initial' => true,
			);
		}

		foreach ( array( 'variations', 'images', 'terms' ) as $group ) {
			if ( empty( $result[ $group ] ) || ! is_array( $result[ $group ] ) ) {
				continue;
			}

			$parts = array();

			foreach ( $result[ $group ] as $key => $count ) {
				$parts[] = $key . ': ' . (int) $count;
			}

			$changes[] = array(
				'field'   => 'terms' === $group ? 'categories' : $group,
				'to'      => implode( ', ', $parts ),
				'initial' => true,
			);
		}

		return $changes;
	}

	/**
	 * Refuses every catalogue operation while the module is off. Writing
	 * the shop's own catalogue is a different kind of power from the rest
	 * of the webhook, so a site has to turn it on deliberately.
	 *
	 * @return void
	 * @throws ItemFailure When the catalogue module is disabled.
	 */
	private function assert_enabled() {
		if ( $this->setting( 'catalog_enabled', false ) ) {
			return;
		}

		throw new ItemFailure(
			'catalog_disabled',
			__( 'Creating products from the webhook is turned off for this site. Turn it on in Integration → Settings.', 'b2b-price-lists' ),
			'type'
		);
	}

	/**
	 * Finds the product this operation refers to: by SKU first, and by the
	 * external id when the SKU is unknown, so a renamed SKU updates the
	 * same product instead of creating a second one.
	 *
	 * @param string              $sku  SKU.
	 * @param array<string,mixed> $data Operation `data`.
	 * @return int Product id, or 0.
	 */
	private function locate( $sku, array $data ) {
		$product_id = (int) $this->writer->find_by_sku( $sku );

		if ( $product_id > 0 ) {
			return $product_id;
		}

		$external_id = isset( $data['external_id'] ) ? trim( (string) $data['external_id'] ) : '';

		return '' === $external_id ? 0 : (int) $this->writer->find_by_external_id( $external_id );
	}

	/**
	 * Builds the writer's field list, carrying only what the payload
	 * actually sent (plus what a brand-new product cannot do without).
	 *
	 * @param array<string,mixed> $data   Operation `data`.
	 * @param string              $sku    SKU.
	 * @param string              $type   Product type.
	 * @param bool                $is_new Whether the product is being created.
	 * @return array<string,mixed>
	 * @throws ItemFailure On a missing name or price at creation, or an invalid value.
	 */
	private function build_fields( array $data, $sku, $type, $is_new ) {
		$fields = array(
			'sku'  => $sku,
			'type' => $type,
		);

		$name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';

		if ( '' !== $name ) {
			$fields['name'] = $name;
		} elseif ( $is_new ) {
			throw new ItemFailure(
				'name_required',
				__( 'A new product needs a name.', 'b2b-price-lists' ),
				'data.name'
			);
		}

		if ( $is_new ) {
			$fields['status'] = 'draft' === $this->setting( 'catalog_status', 'publish' ) ? 'draft' : 'publish';
		}

		if ( isset( $data['status'] ) ) {
			$status = (string) $data['status'];

			if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
				throw new ItemFailure(
					'invalid_status',
					__( 'The product status must be publish or draft.', 'b2b-price-lists' ),
					'data.status'
				);
			}

			$fields['status'] = $status;
		}

		$prices = self::read_prices( $data, 'data' );

		// A simple product cannot be created without a price: it would go on
		// sale at no price at all.
		if ( $is_new && 'simple' === $type && ! isset( $prices['regular_price'] ) ) {
			throw new ItemFailure(
				'price_required',
				__( 'A new simple product needs a regular price.', 'b2b-price-lists' ),
				'data.regular_price'
			);
		}

		$fields = array_merge( $fields, $prices );

		foreach ( array( 'description', 'short_description', 'external_id' ) as $text_field ) {
			if ( array_key_exists( $text_field, $data ) ) {
				$fields[ $text_field ] = null === $data[ $text_field ] ? '' : (string) $data[ $text_field ];
			}
		}

		$fields = array_merge( $fields, self::read_stock( $data ), self::read_shipping( $data ) );

		if ( isset( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
			$fields['attributes'] = self::read_attributes( $data['attributes'] );
		}

		return $fields;
	}

	/**
	 * Reads and validates `regular_price`/`sale_price` and the sale window,
	 * for either a product or one variation.
	 *
	 * @param array<string,mixed> $data Source array.
	 * @param string              $path Path prefix for error reporting.
	 * @return array<string,mixed>
	 * @throws ItemFailure On a non-numeric or negative price, or a sale price above the regular one.
	 */
	private static function read_prices( array $data, $path ) {
		$prices = array();

		foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			if ( null === $data[ $key ] || '' === $data[ $key ] ) {
				$prices[ $key ] = '';
				continue;
			}

			if ( ! is_numeric( $data[ $key ] ) || (float) $data[ $key ] < 0 ) {
				throw new ItemFailure(
					'invalid_price',
					__( 'Prices must be a number of zero or more.', 'b2b-price-lists' ),
					$path . '.' . $key
				);
			}

			$prices[ $key ] = (string) ( 0 + $data[ $key ] );
		}

		if ( isset( $prices['regular_price'], $prices['sale_price'] )
			&& '' !== $prices['regular_price'] && '' !== $prices['sale_price']
			&& (float) $prices['sale_price'] > (float) $prices['regular_price']
		) {
			throw new ItemFailure(
				'invalid_sale_price',
				__( 'The sale price cannot be higher than the regular price.', 'b2b-price-lists' ),
				$path . '.sale_price'
			);
		}

		foreach ( array( 'sale_from', 'sale_to' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$prices[ $key ] = null === $data[ $key ] ? '' : (string) $data[ $key ];
			}
		}

		return $prices;
	}

	/**
	 * Reads the stock fields that were sent.
	 *
	 * @param array<string,mixed> $data Source array.
	 * @return array<string,mixed>
	 */
	private static function read_stock( array $data ) {
		$stock = array();

		if ( array_key_exists( 'manage_stock', $data ) ) {
			$stock['manage_stock'] = (bool) $data['manage_stock'];
		}

		if ( array_key_exists( 'stock_quantity', $data ) && null !== $data['stock_quantity'] ) {
			$stock['stock_quantity'] = (int) $data['stock_quantity'];
		}

		if ( isset( $data['stock_status'] ) && in_array( $data['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$stock['stock_status'] = (string) $data['stock_status'];
		}

		return $stock;
	}

	/**
	 * Reads weight and dimensions.
	 *
	 * @param array<string,mixed> $data Source array.
	 * @return array<string,mixed>
	 */
	private static function read_shipping( array $data ) {
		$shipping = array();

		if ( array_key_exists( 'weight', $data ) ) {
			$shipping['weight'] = null === $data['weight'] ? '' : (string) $data['weight'];
		}

		if ( isset( $data['dimensions'] ) && is_array( $data['dimensions'] ) ) {
			foreach ( array( 'length', 'width', 'height' ) as $axis ) {
				if ( array_key_exists( $axis, $data['dimensions'] ) ) {
					$shipping[ $axis ] = null === $data['dimensions'][ $axis ] ? '' : (string) $data['dimensions'][ $axis ];
				}
			}
		}

		return $shipping;
	}

	/**
	 * Normalizes `attributes[]`. An attribute marked `variation` is one of
	 * the axes a variable product's combinations are built from; the rest
	 * are plain product attributes.
	 *
	 * @param array<int,mixed> $raw Raw attributes.
	 * @return array<int,array<string,mixed>>
	 * @throws ItemFailure On an attribute with no name or no options.
	 */
	private static function read_attributes( array $raw ) {
		$attributes = array();

		foreach ( $raw as $index => $attribute ) {
			$path = 'data.attributes[' . $index . ']';

			if ( ! is_array( $attribute ) ) {
				throw new ItemFailure( 'invalid_attribute', __( 'Each attribute must be an object.', 'b2b-price-lists' ), $path );
			}

			$name = isset( $attribute['name'] ) ? trim( (string) $attribute['name'] ) : '';

			if ( '' === $name ) {
				throw new ItemFailure( 'invalid_attribute', __( 'Each attribute needs a name.', 'b2b-price-lists' ), $path . '.name' );
			}

			$options = array();

			foreach ( (array) ( $attribute['options'] ?? array() ) as $option ) {
				$option = trim( (string) $option );

				if ( '' !== $option ) {
					$options[] = $option;
				}
			}

			if ( empty( $options ) ) {
				throw new ItemFailure( 'invalid_attribute', __( 'Each attribute needs at least one option.', 'b2b-price-lists' ), $path . '.options' );
			}

			$attributes[] = array(
				'name'      => $name,
				'options'   => array_values( array_unique( $options ) ),
				'visible'   => ! isset( $attribute['visible'] ) || ! empty( $attribute['visible'] ),
				'variation' => ! empty( $attribute['variation'] ),
			);
		}

		return $attributes;
	}

	/**
	 * Normalizes `variations[]`: every variation is identified by its own
	 * SKU, which is also what a B2B `variation_group` rule points at.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array<int,array<string,mixed>>
	 * @throws ItemFailure On a variation with no SKU or no attributes, or on too many.
	 */
	private static function read_variations( array $data ) {
		$raw = isset( $data['variations'] ) && is_array( $data['variations'] ) ? $data['variations'] : array();

		if ( count( $raw ) > self::MAX_VARIATIONS ) {
			throw new ItemFailure(
				'too_many_variations',
				sprintf(
					/* translators: %d: maximum number of variations per product. */
					__( 'A product accepts up to %d variations per send.', 'b2b-price-lists' ),
					self::MAX_VARIATIONS
				),
				'data.variations'
			);
		}

		$variations = array();

		foreach ( $raw as $index => $variation ) {
			$path = 'data.variations[' . $index . ']';

			if ( ! is_array( $variation ) ) {
				throw new ItemFailure( 'invalid_variation', __( 'Each variation must be an object.', 'b2b-price-lists' ), $path );
			}

			$sku = isset( $variation['sku'] ) ? trim( (string) $variation['sku'] ) : '';

			if ( '' === $sku ) {
				throw new ItemFailure( 'variation_sku_required', __( 'Each variation needs its own SKU.', 'b2b-price-lists' ), $path . '.sku' );
			}

			$entry = array( 'sku' => $sku );

			if ( ! empty( $variation['delete'] ) ) {
				$entry['delete'] = true;
				$variations[]    = $entry;
				continue;
			}

			$attributes = isset( $variation['attributes'] ) && is_array( $variation['attributes'] ) ? $variation['attributes'] : array();

			if ( empty( $attributes ) ) {
				throw new ItemFailure(
					'variation_attributes_required',
					__( 'Each variation needs the attribute values that identify it.', 'b2b-price-lists' ),
					$path . '.attributes'
				);
			}

			$entry['attributes'] = array();

			foreach ( $attributes as $name => $value ) {
				$entry['attributes'][ trim( (string) $name ) ] = trim( (string) $value );
			}

			$entry = array_merge( $entry, self::read_prices( $variation, $path ), self::read_stock( $variation ), self::read_shipping( $variation ) );

			if ( isset( $variation['image'] ) ) {
				$entry['image'] = self::read_image_source( $variation['image'], $path . '.image' );
			}

			$variations[] = $entry;
		}

		return $variations;
	}

	/**
	 * Normalizes `categories[]`, `tags[]` and `brand`. A category is a full
	 * path from parent to child, so a shop ends up with the same tree the
	 * sending system has.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array<string,mixed>
	 * @throws ItemFailure On a malformed category path.
	 */
	private static function read_terms( array $data ) {
		$terms = array();

		if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$paths = array();

			foreach ( $data['categories'] as $index => $category ) {
				$path  = 'data.categories[' . $index . ']';
				$chain = array();

				foreach ( is_array( $category ) ? $category : array( $category ) as $level ) {
					$level = trim( (string) $level );

					if ( '' !== $level ) {
						$chain[] = $level;
					}
				}

				if ( empty( $chain ) ) {
					throw new ItemFailure( 'invalid_category', __( 'A category cannot be empty.', 'b2b-price-lists' ), $path );
				}

				$paths[] = $chain;
			}

			$terms['categories'] = $paths;
		}

		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$tags = array();

			foreach ( $data['tags'] as $tag ) {
				$tag = trim( (string) $tag );

				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}

			$terms['tags'] = array_values( array_unique( $tags ) );
		}

		if ( array_key_exists( 'brand', $data ) ) {
			$terms['brand'] = null === $data['brand'] ? '' : trim( (string) $data['brand'] );
		}

		return $terms;
	}

	/**
	 * Normalizes `images[]`, keeping the order given.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array<int,array<string,mixed>>
	 * @throws ItemFailure On too many images or an unusable source.
	 */
	private static function read_images( array $data ) {
		$raw = isset( $data['images'] ) && is_array( $data['images'] ) ? $data['images'] : array();

		if ( count( $raw ) > self::MAX_IMAGES ) {
			throw new ItemFailure(
				'too_many_images',
				sprintf(
					/* translators: %d: maximum number of images per product. */
					__( 'A product accepts up to %d images per send.', 'b2b-price-lists' ),
					self::MAX_IMAGES
				),
				'data.images'
			);
		}

		$images = array();

		foreach ( $raw as $index => $image ) {
			$path   = 'data.images[' . $index . ']';
			$source = is_array( $image ) ? ( $image['src'] ?? '' ) : $image;

			$images[] = array(
				'src'      => self::read_image_source( $source, $path ),
				'alt'      => is_array( $image ) && isset( $image['alt'] ) ? (string) $image['alt'] : '',
				'position' => count( $images ),
			);
		}

		return $images;
	}

	/**
	 * Validates one image source: an absolute http(s) URL and nothing else.
	 *
	 * @param mixed  $source Raw source.
	 * @param string $path   Path for error reporting.
	 * @return string
	 * @throws ItemFailure When the source is not an http(s) URL.
	 */
	private static function read_image_source( $source, $path ) {
		$source = is_string( $source ) ? trim( $source ) : '';

		if ( '' === $source || ! preg_match( '#^https?://#i', $source ) ) {
			throw new ItemFailure(
				'invalid_image_url',
				__( 'Each image must be an http or https address the site can download.', 'b2b-price-lists' ),
				$path
			);
		}

		return $source;
	}

	/**
	 * The SKU of an operation, which is its identity.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return string
	 * @throws ItemFailure When no SKU was sent.
	 */
	private static function require_sku( array $data ) {
		$sku = isset( $data['sku'] ) ? trim( (string) $data['sku'] ) : '';

		if ( '' === $sku ) {
			throw new ItemFailure(
				'sku_required',
				__( 'Every catalogue operation needs a SKU.', 'b2b-price-lists' ),
				'data.sku'
			);
		}

		return $sku;
	}

	/**
	 * The product type of an operation.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return string
	 * @throws ItemFailure On a type this plugin does not create.
	 */
	private static function read_type( array $data ) {
		$type = isset( $data['type'] ) ? trim( (string) $data['type'] ) : 'simple';

		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new ItemFailure(
				'invalid_product_type',
				__( 'The product type must be simple or variable.', 'b2b-price-lists' ),
				'data.type'
			);
		}

		return $type;
	}

	/**
	 * One setting, resolved lazily so the class can be built without
	 * WordPress in the test suite.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value when the setting is missing.
	 * @return mixed
	 */
	private function setting( $key, $fallback ) {
		if ( null === $this->settings ) {
			$this->settings = Settings::get();
		}

		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $fallback;
	}
}
