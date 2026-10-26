<?php
/**
 * Resolves a webhook rule's `sku`/`variation_skus` (contracts/webhook-api.md,
 * R6/) into the WooCommerce product/variation ids
 * `Domain\RuleGroupService` needs, using `Domain\ProductLookup`'s
 * SKU-lookup-table search rather than `wc_get_product_id_by_sku()` (which
 * would hide a genuine duplicate by returning a single id).
 *
 * The two static helpers (`classify_matches()`/`validate_parent_type()`) are
 * pure and WordPress-independent, so the exact rules behind every error code
 * this class can return are unit-testable without a database; the instance
 * methods are the thin, WooCommerce-touching orchestration on top of them,
 * covered instead by the manual staging verification plan (see the delivery
 * report -- W3 has no `WP_UnitTestCase` harness, per tasks.md T027).
 *
 * `$products` is intentionally left untyped (rather than type-hinted to the
 * concrete `Domain\ProductLookup` class): the only two methods this class
 * calls on it (`find_ids_by_sku()`/`describe()`) are a small, stable surface,
 * and leaving the parameter untyped lets a unit test pass a plain PHP object
 * exposing just those two methods instead of a real `ProductLookup` (which
 * would call `wc_get_product()` and fatal outside of WordPress).
 *
 * @package B2BPriceLists\Webhook\Resolver
 */

namespace B2BPriceLists\Webhook\Resolver;

use B2BPriceLists\Domain\ProductLookup;
use B2BPriceLists\Domain\RuleGroupValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SkuResolver
 */
class SkuResolver {

	/**
	 * Product/variation lookup helper. Untyped on purpose -- see the class
	 * docblock.
	 *
	 * @var mixed
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param mixed $products `Domain\ProductLookup` instance (default), or a
	 *                         test double exposing `find_ids_by_sku()`/`describe()`.
	 */
	public function __construct( $products = null ) {
		$this->products = null !== $products ? $products : new ProductLookup();
	}

	/**
	 * Classifies a set of SKU-lookup matches: pure and unit-testable.
	 *
	 * @param int[] $ids Candidate ids (as returned by `ProductLookup::find_ids_by_sku()`).
	 * @return string `'not_found'`, `'ambiguous'` or `'ok'`.
	 */
	public static function classify_matches( array $ids ) {
		$count = count( array_unique( array_map( 'intval', $ids ) ) );

		if ( 0 === $count ) {
			return 'not_found';
		}

		if ( $count > 1 ) {
			return 'ambiguous';
		}

		return 'ok';
	}

	/**
	 * Whether a resolved product's WooCommerce type is compatible with a
	 * rule's `scope` (contract: `simple` needs a non-variable product,
	 * `all_variations`/`variation_group` need a variable one) -- the exact
	 * same condition `Domain\RuleGroupValidator::validate_scope()` checks
	 * from the panel, expressed here in terms of a product *type* string
	 * instead of an `is_variable_product` boolean, so it can run before
	 * `RuleGroupService` ever loads the product itself. Pure and
	 * unit-testable.
	 *
	 * @param string $product_type `Domain\ProductLookup::describe()`'s `type`.
	 * @param string $scope        One of `RuleGroupValidator::SCOPE_*`.
	 * @return string|null `'product_type_mismatch'`, or `null` when compatible.
	 */
	public static function validate_parent_type( $product_type, $scope ) {
		$is_variable = ( 'variable' === $product_type );

		if ( RuleGroupValidator::SCOPE_SIMPLE === $scope ) {
			return ( ! $is_variable && 'variation' !== $product_type ) ? null : 'product_type_mismatch';
		}

		return $is_variable ? null : 'product_type_mismatch';
	}

	/**
	 * Resolves the parent SKU of a rule (contract: `sku` -- a simple
	 * product's own SKU for `scope=simple`, or the variable parent's SKU for
	 * `scope=all_variations`/`variation_group`).
	 *
	 * @param string $sku   SKU as submitted.
	 * @param string $scope One of `RuleGroupValidator::SCOPE_*`.
	 * @return array{product_id:int}|array{error:string,message:string} `error`
	 *               is one of `sku_not_found`, `sku_ambiguous`,
	 *               `product_type_mismatch`.
	 */
	public function resolve_parent( $sku, $scope ) {
		$sku = trim( (string) $sku );

		if ( '' === $sku ) {
			return self::error( 'sku_not_found', __( 'The product SKU is required.', 'b2b-price-lists' ) );
		}

		$ids            = $this->products->find_ids_by_sku( $sku );
		$classification = self::classify_matches( $ids );

		if ( 'not_found' === $classification ) {
			return self::error(
				'sku_not_found',
				sprintf(
					/* translators: %s: SKU submitted by the caller. */
					__( 'No published product with the SKU "%s" was found.', 'b2b-price-lists' ),
					$sku
				)
			);
		}

		if ( 'ambiguous' === $classification ) {
			return self::error(
				'sku_ambiguous',
				sprintf(
					/* translators: %s: SKU submitted by the caller. */
					__( 'More than one product uses the SKU "%s"; it cannot be resolved automatically.', 'b2b-price-lists' ),
					$sku
				)
			);
		}

		$info = $this->products->describe( $ids[0] );

		if ( null === $info || empty( $info['published'] ) ) {
			// A trashed/draft product is treated as inexistent (contract
			// edge case), the same `sku_not_found` a caller would see for a
			// SKU that was never assigned to anything.
			return self::error(
				'sku_not_found',
				sprintf(
					/* translators: %s: SKU submitted by the caller. */
					__( 'No published product with the SKU "%s" was found.', 'b2b-price-lists' ),
					$sku
				)
			);
		}

		$mismatch = self::validate_parent_type( $info['type'], $scope );

		if ( null !== $mismatch ) {
			return self::error(
				$mismatch,
				sprintf(
					/* translators: %s: SKU submitted by the caller. */
					__( 'The product with SKU "%s" is not the right type for this rule\'s scope.', 'b2b-price-lists' ),
					$sku
				)
			);
		}

		return array( 'product_id' => (int) $info['id'] );
	}

	/**
	 * Resolves a `variation_group` rule's `variation_skus[]` against an
	 * already-resolved parent product id.
	 *
	 * @param int      $parent_id      Parent product id (from `resolve_parent()`).
	 * @param string[] $variation_skus SKUs as submitted.
	 * @return array{variation_ids:int[]}|array{error:string,message:string} `error`
	 *               is one of `variation_sku_missing`, `sku_ambiguous`,
	 *               `variation_mismatch`.
	 */
	public function resolve_variations( $parent_id, array $variation_skus ) {
		$parent_id = (int) $parent_id;
		$ids       = array();

		foreach ( $variation_skus as $sku ) {
			$sku     = trim( (string) $sku );
			$matches = $this->products->find_ids_by_sku( $sku );

			$classification = self::classify_matches( $matches );

			if ( 'not_found' === $classification ) {
				// Deliberately not `sku_not_found`: within this method the
				// parent is already known and variable, so the far more
				// likely explanation (R6) is a real variation that simply
				// has no SKU of its own yet in WooCommerce, not a code that
				// was never assigned to anything.
				return self::error(
					'variation_sku_missing',
					sprintf(
						/* translators: %s: variation SKU submitted by the caller. */
						__( 'No variation has its own SKU "%s". Assign it a SKU in WooCommerce first, then retry.', 'b2b-price-lists' ),
						$sku
					)
				);
			}

			if ( 'ambiguous' === $classification ) {
				return self::error(
					'sku_ambiguous',
					sprintf(
						/* translators: %s: variation SKU submitted by the caller. */
						__( 'More than one product/variation uses the SKU "%s"; it cannot be resolved automatically.', 'b2b-price-lists' ),
						$sku
					)
				);
			}

			$info = $this->products->describe( $matches[0] );

			if ( null === $info || 'variation' !== $info['type'] || $parent_id !== (int) $info['parent_id'] ) {
				return self::error(
					'variation_mismatch',
					sprintf(
						/* translators: %s: variation SKU submitted by the caller. */
						__( 'The SKU "%s" does not identify a variation of the product this rule belongs to.', 'b2b-price-lists' ),
						$sku
					)
				);
			}

			$ids[] = (int) $info['id'];
		}

		return array( 'variation_ids' => $ids );
	}

	/**
	 * Builds the `array{error, message}` shape used by every failure return
	 * above.
	 *
	 * @param string $code    Item error code (contract).
	 * @param string $message Human-readable message.
	 * @return array{error:string,message:string}
	 */
	private static function error( $code, $message ) {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}
