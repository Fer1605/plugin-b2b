<?php
/**
 * The WooCommerce side of the catalogue webhook: the only class in
 * this plugin that creates or changes a product, a product term or a media
 * file. Everything it does goes through WooCommerce's own CRUD objects
 * (`WC_Product_*`) and WordPress' own term and media functions -- never a
 * direct write to `wp_posts` or `wp_postmeta`, so every plugin listening for
 * a product change still hears it.
 *
 * Two markers are left on everything written here:
 *
 * - `_b2bpl_external_id`: the sending system's own identifier, so a product
 *   survives a SKU change instead of being created twice.
 * - `_b2bpl_image_source`: the URL an attached image came from, so the next
 *   sync skips a download it already did instead of filling the media
 *   library with copies of the same photo.
 *
 * @package B2BPriceLists\Webhook\Writer
 */

namespace B2BPriceLists\Webhook\Writer;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use B2BPriceLists\Webhook\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductWriter
 */
class ProductWriter implements CatalogWriter {

	/**
	 * Meta key holding the sending system's identifier.
	 *
	 * @var string
	 */
	const EXTERNAL_ID_META = '_b2bpl_external_id';

	/**
	 * Meta key holding the source URL of an imported image.
	 *
	 * @var string
	 */
	const IMAGE_SOURCE_META = '_b2bpl_image_source';

	/**
	 * Seconds allowed for one image download.
	 *
	 * @var int
	 */
	const IMAGE_TIMEOUT = 15;

	/**
	 * Largest image accepted, in bytes.
	 *
	 * @var int
	 */
	const IMAGE_MAX_BYTES = 8388608;

	/**
	 * Product id for a SKU, or 0.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	public function find_by_sku( $sku ) {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}

		return (int) wc_get_product_id_by_sku( (string) $sku );
	}

	/**
	 * Product id previously imported under an external id, or 0.
	 *
	 * @param string $external_id External identifier.
	 * @return int
	 */
	public function find_by_external_id( $external_id ) {
		$found = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::EXTERNAL_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup by an id this plugin itself wrote; there is no taxonomy equivalent.
				'meta_value'       => (string) $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
				'suppress_filters' => false,
			)
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * WooCommerce product type of an existing product, or an empty string.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function product_type( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		return $product ? (string) $product->get_type() : '';
	}

	/**
	 * Creates or updates a product with exactly the fields given.
	 *
	 * @param array<string,mixed> $fields Normalized product fields.
	 * @return array{id:int,created:bool}
	 */
	public function save_product( array $fields ) {
		$type       = isset( $fields['type'] ) ? (string) $fields['type'] : 'simple';
		$product_id = isset( $fields['id'] ) ? (int) $fields['id'] : $this->find_by_sku( (string) ( $fields['sku'] ?? '' ) );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
		$created    = ! $product;

		if ( ! $product ) {
			$product = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
			$product->set_sku( (string) $fields['sku'] );
		}

		$this->apply_common_fields( $product, $fields );

		if ( isset( $fields['attributes'] ) ) {
			$product->set_attributes( $this->build_attributes( (array) $fields['attributes'] ) );
		}

		$product->save();

		$id = (int) $product->get_id();

		if ( array_key_exists( 'external_id', $fields ) ) {
			update_post_meta( $id, self::EXTERNAL_ID_META, (string) $fields['external_id'] );
		}

		return array(
			'id'      => $id,
			'created' => $created,
		);
	}

	/**
	 * Applies the fields shared by products and variations. Only the keys
	 * present are written: an absent key leaves whatever the shop has.
	 *
	 * @param \WC_Product         $product Product or variation object.
	 * @param array<string,mixed> $fields  Normalized fields.
	 * @return void
	 */
	private function apply_common_fields( $product, array $fields ) {
		$setters = array(
			'name'              => 'set_name',
			'status'            => 'set_status',
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'sale_from'         => 'set_date_on_sale_from',
			'sale_to'           => 'set_date_on_sale_to',
			'manage_stock'      => 'set_manage_stock',
			'stock_quantity'    => 'set_stock_quantity',
			'stock_status'      => 'set_stock_status',
			'weight'            => 'set_weight',
			'length'            => 'set_length',
			'width'             => 'set_width',
			'height'            => 'set_height',
		);

		foreach ( $setters as $key => $setter ) {
			if ( array_key_exists( $key, $fields ) && method_exists( $product, $setter ) ) {
				$product->{$setter}( $fields[ $key ] );
			}
		}
	}

	/**
	 * Builds WooCommerce attribute objects from the normalized payload.
	 * Custom (per-product) attributes on purpose: a global attribute
	 * taxonomy is a shop-wide decision, not something an outside system
	 * should create behind the shop's back.
	 *
	 * @param array<int,array<string,mixed>> $attributes Normalized attributes.
	 * @return WC_Product_Attribute[]
	 */
	private function build_attributes( array $attributes ) {
		$built    = array();
		$position = 0;

		foreach ( $attributes as $attribute ) {
			$object = new WC_Product_Attribute();
			$object->set_name( (string) $attribute['name'] );
			$object->set_options( (array) $attribute['options'] );
			$object->set_position( $position );
			$object->set_visible( ! empty( $attribute['visible'] ) );
			$object->set_variation( ! empty( $attribute['variation'] ) );

			$built[] = $object;
			++$position;
		}

		return $built;
	}

	/**
	 * Creates or updates variations. One that is not mentioned is kept.
	 *
	 * @param int                            $parent_id  Parent product id.
	 * @param array<int,array<string,mixed>> $variations Normalized variations.
	 * @return array{created:int,updated:int,deleted:int,errors:array<int,array<string,string>>}
	 */
	public function save_variations( $parent_id, array $variations ) {
		$report = array(
			'created' => 0,
			'updated' => 0,
			'deleted' => 0,
			'errors'  => array(),
		);

		foreach ( $variations as $variation ) {
			$sku         = (string) $variation['sku'];
			$existing_id = $this->find_by_sku( $sku );

			if ( ! empty( $variation['delete'] ) ) {
				if ( $existing_id > 0 ) {
					$object = wc_get_product( $existing_id );

					if ( $object ) {
						$object->delete( true );
						++$report['deleted'];
					}
				}

				continue;
			}

			$object = $existing_id > 0 ? wc_get_product( $existing_id ) : null;

			if ( $object && ! $object instanceof WC_Product_Variation ) {
				$report['errors'][] = array(
					'code'    => 'variation_sku_in_use',
					'message' => sprintf(
						/* translators: %s: SKU. */
						__( 'The SKU %s already belongs to a product that is not a variation.', 'b2b-price-lists' ),
						$sku
					),
					'path'    => 'data.variations',
				);

				continue;
			}

			$is_new = ! $object;

			if ( $is_new ) {
				$object = new WC_Product_Variation();
				$object->set_parent_id( (int) $parent_id );
				$object->set_sku( $sku );
			}

			if ( isset( $variation['attributes'] ) ) {
				$object->set_attributes( $this->variation_attributes( (array) $variation['attributes'] ) );
			}

			$this->apply_common_fields( $object, $variation );
			$object->save();

			if ( isset( $variation['image'] ) ) {
				$attachment_id = $this->sideload( (int) $parent_id, (string) $variation['image'] );

				if ( $attachment_id > 0 ) {
					$object->set_image_id( $attachment_id );
					$object->save();
				}
			}

			if ( $is_new ) {
				++$report['created'];
			} else {
				++$report['updated'];
			}
		}

		if ( ! empty( $variations ) ) {
			\WC_Product_Variable::sync( (int) $parent_id );
		}

		return $report;
	}

	/**
	 * Turns `{"Talla": "S"}` into the `attribute_talla => s` shape a
	 * variation stores, matching how WooCommerce keys a custom attribute.
	 *
	 * @param array<string,string> $attributes Attribute name => value.
	 * @return array<string,string>
	 */
	private function variation_attributes( array $attributes ) {
		$mapped = array();

		foreach ( $attributes as $name => $value ) {
			$mapped[ 'attribute_' . sanitize_title( (string) $name ) ] = (string) $value;
		}

		return $mapped;
	}

	/**
	 * Assigns categories, tags and brand.
	 *
	 * @param int                 $product_id Product id.
	 * @param array<string,mixed> $terms      `categories`, `tags`, `brand`.
	 * @param bool                $create     Whether a missing term may be created.
	 * @return array{assigned:array<string,int>,errors:array<int,array<string,string>>}
	 */
	public function set_terms( $product_id, array $terms, $create ) {
		$assigned = array();
		$errors   = array();

		if ( isset( $terms['categories'] ) ) {
			$ids = array();

			foreach ( (array) $terms['categories'] as $path ) {
				$term_id = $this->resolve_path( (array) $path, 'product_cat', (bool) $create );

				if ( $term_id > 0 ) {
					$ids[] = $term_id;
					continue;
				}

				$errors[] = array(
					'code'    => 'category_not_found',
					'message' => sprintf(
						/* translators: %s: category path. */
						__( 'The category %s does not exist and creating categories is turned off.', 'b2b-price-lists' ),
						implode( ' > ', (array) $path )
					),
					'path'    => 'data.categories',
				);
			}

			if ( ! empty( $ids ) ) {
				wp_set_object_terms( (int) $product_id, $ids, 'product_cat' );
				$assigned['categories'] = count( $ids );
			}
		}

		if ( isset( $terms['tags'] ) ) {
			wp_set_object_terms( (int) $product_id, (array) $terms['tags'], 'product_tag', false );
			$assigned['tags'] = count( (array) $terms['tags'] );
		}

		if ( isset( $terms['brand'] ) ) {
			$taxonomy = Settings::catalog_brand_taxonomy();

			if ( '' === $taxonomy ) {
				$errors[] = array(
					'code'    => 'brand_taxonomy_missing',
					'message' => __( 'This shop has no brand taxonomy, so the brand was not saved.', 'b2b-price-lists' ),
					'path'    => 'data.brand',
				);
			} else {
				$brand = (string) $terms['brand'];

				if ( '' === $brand ) {
					wp_set_object_terms( (int) $product_id, array(), $taxonomy );
				} else {
					$term_id = $this->resolve_path( array( $brand ), $taxonomy, (bool) $create );

					if ( $term_id > 0 ) {
						wp_set_object_terms( (int) $product_id, array( $term_id ), $taxonomy );
						$assigned['brand'] = 1;
					} else {
						$errors[] = array(
							'code'    => 'brand_not_found',
							'message' => sprintf(
								/* translators: %s: brand name. */
								__( 'The brand %s does not exist and creating terms is turned off.', 'b2b-price-lists' ),
								$brand
							),
							'path'    => 'data.brand',
						);
					}
				}
			}
		}

		return array(
			'assigned' => $assigned,
			'errors'   => $errors,
		);
	}

	/**
	 * Resolves a term path (`Beauty > Cosmetics > Eyeliner`) to the id of
	 * its last level, creating the levels that are missing when allowed.
	 *
	 * @param array<int,string> $path     Term names, parent first.
	 * @param string            $taxonomy Taxonomy.
	 * @param bool              $create   Whether a missing term may be created.
	 * @return int Term id, or 0.
	 */
	private function resolve_path( array $path, $taxonomy, $create ) {
		$parent = 0;

		foreach ( $path as $name ) {
			$name = (string) $name;
			$term = get_term_by( 'name', $name, $taxonomy );

			if ( $term && (int) $term->parent !== $parent ) {
				// A term of the same name may exist elsewhere in the tree;
				// only the one under this parent is the one meant here.
				$term = false;

				foreach ( (array) get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'name'       => $name,
						'parent'     => $parent,
						'hide_empty' => false,
					)
				) as $candidate ) {
					$term = $candidate;
					break;
				}
			}

			if ( ! $term ) {
				if ( ! $create ) {
					return 0;
				}

				$inserted = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );

				if ( is_wp_error( $inserted ) ) {
					return 0;
				}

				$parent = (int) $inserted['term_id'];
				continue;
			}

			$parent = (int) $term->term_id;
		}

		return $parent;
	}

	/**
	 * Downloads and attaches images, skipping the ones already imported.
	 *
	 * @param int                            $product_id Product id.
	 * @param array<int,array<string,mixed>> $images     Normalized images.
	 * @return array{imported:int,skipped:int,errors:array<int,array<string,string>>}
	 */
	public function import_images( $product_id, array $images ) {
		$report = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		$attachment_ids = array();

		foreach ( $images as $image ) {
			$source   = (string) $image['src'];
			$existing = $this->find_attachment_by_source( $source );

			if ( $existing > 0 ) {
				$attachment_ids[] = $existing;
				++$report['skipped'];
				continue;
			}

			$attachment_id = $this->sideload( (int) $product_id, $source, (string) ( $image['alt'] ?? '' ) );

			if ( 0 === $attachment_id ) {
				$report['errors'][] = array(
					'code'    => 'image_download_failed',
					'message' => sprintf(
						/* translators: %s: image URL. */
						__( 'The image %s could not be downloaded; the product was saved without it.', 'b2b-price-lists' ),
						$source
					),
					'path'    => 'data.images',
				);

				continue;
			}

			$attachment_ids[] = $attachment_id;
			++$report['imported'];
		}

		if ( ! empty( $attachment_ids ) ) {
			$product = wc_get_product( (int) $product_id );

			if ( $product ) {
				$product->set_image_id( (int) array_shift( $attachment_ids ) );
				$product->set_gallery_image_ids( array_map( 'intval', $attachment_ids ) );
				$product->save();
			}
		}

		return $report;
	}

	/**
	 * The attachment previously imported from a source URL, or 0.
	 *
	 * @param string $source Source URL.
	 * @return int
	 */
	private function find_attachment_by_source( $source ) {
		$found = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::IMAGE_SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Deduplication lookup on this plugin's own meta.
				'meta_value'       => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
				'suppress_filters' => false,
			)
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * Downloads one image and attaches it to a product, refusing anything
	 * that is not a real image or is larger than the cap.
	 *
	 * @param int    $product_id Product id the attachment belongs to.
	 * @param string $source     Source URL.
	 * @param string $alt        Alternative text.
	 * @return int Attachment id, or 0 on any failure.
	 */
	private function sideload( $product_id, $source, $alt = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp = download_url( $source, self::IMAGE_TIMEOUT );

		if ( is_wp_error( $temp ) ) {
			return 0;
		}

		$size = (int) filesize( $temp );
		$type = wp_check_filetype( $temp );
		$info = @getimagesize( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A non-image file makes this emit a warning; the false return is the answer we want.

		if ( $size > self::IMAGE_MAX_BYTES || false === $info ) {
			wp_delete_file( $temp );

			return 0;
		}

		$name = basename( wp_parse_url( $source, PHP_URL_PATH ) );

		if ( '' === $name || false === strpos( $name, '.' ) ) {
			$name = 'producto-' . $product_id . '-' . substr( md5( $source ), 0, 8 ) . ( $type['ext'] ? '.' . $type['ext'] : '.jpg' );
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $temp,
			),
			$product_id,
			'' === $alt ? null : $alt
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $temp );

			return 0;
		}

		update_post_meta( (int) $attachment_id, self::IMAGE_SOURCE_META, $source );

		if ( '' !== $alt ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		return (int) $attachment_id;
	}

	/**
	 * Takes a product out of sale without deleting it.
	 *
	 * @param int    $product_id Product id.
	 * @param string $mode       `draft` or `outofstock`.
	 * @return array{mode:string}
	 */
	public function discontinue( $product_id, $mode ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product ) {
			return array( 'mode' => $mode );
		}

		if ( 'outofstock' === $mode ) {
			$product->set_stock_status( 'outofstock' );
		} else {
			$product->set_status( 'draft' );
		}

		$product->save();

		return array( 'mode' => $mode );
	}
}
