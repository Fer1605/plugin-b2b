<?php
/**
 * WordPress/WooCommerce-aware orchestration around the pure
 * `PriceResolutionEngine`: resolves the current user's assigned list,
 * fetches the `list_id`+`product_id`-scoped rule groups with
 * their ranges/variation relationships, applies the two-level cache
 *, and guarantees the fallback-to-B2C invariant even on
 * an unexpected exception (TC-067) -- every public method here is safe to
 * call from any WooCommerce hook without risking a fatal error.
 *
 * @package B2BPriceLists\Domain
 */

namespace B2BPriceLists\Domain;

use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\ListUsersRepository;
use B2BPriceLists\Repository\PriceRangesRepository;
use B2BPriceLists\Repository\RuleGroupsRepository;
use B2BPriceLists\Repository\RuleGroupVariationsRepository;
use B2BPriceLists\Support\Cache;
use B2BPriceLists\Support\Logger;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriceResolver
 */
class PriceResolver {

	/**
	 * No authenticated user (guest).
	 *
	 * @var string
	 */
	const FALLBACK_GUEST = 'guest';

	/**
	 * The requested product/variation does not exist.
	 *
	 * @var string
	 */
	const FALLBACK_NOT_FOUND = 'not_found';

	/**
	 * An unexpected exception was caught and safely converted to the
	 * standard B2C fallback (TC-067) -- the storefront never breaks.
	 *
	 * @var string
	 */
	const FALLBACK_EXCEPTION = 'exception';

	/**
	 * List/user assignment repository.
	 *
	 * @var ListUsersRepository
	 */
	private $list_users;

	/**
	 * Lists repository.
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * Rule groups repository.
	 *
	 * @var RuleGroupsRepository
	 */
	private $rule_groups;

	/**
	 * Rule group/variation relationship repository.
	 *
	 * @var RuleGroupVariationsRepository
	 */
	private $variations;

	/**
	 * Price ranges repository.
	 *
	 * @var PriceRangesRepository
	 */
	private $ranges;

	/**
	 * WooCommerce product/variation lookup helper.
	 *
	 * @var ProductLookup
	 */
	private $products;

	/**
	 * Level 1 (request-scoped) cache of assigned list lookups, keyed by
	 * user id. Always active, even without a persistent object cache
	 * backend.
	 *
	 * @var array<int,array{exists:bool,list:array|null}>
	 */
	private static $request_list_cache = array();

	/**
	 * Level 1 (request-scoped) cache of enriched rule-group sets, keyed by
	 * "{list_id}:{product_id}".
	 *
	 * @var array<string,array>
	 */
	private static $request_groups_cache = array();

	/**
	 * Constructor.
	 *
	 * @param ListUsersRepository|null           $list_users Optional, for tests.
	 * @param ListsRepository|null               $lists      Optional, for tests.
	 * @param RuleGroupsRepository|null          $rule_groups Optional, for tests.
	 * @param RuleGroupVariationsRepository|null $variations Optional, for tests.
	 * @param PriceRangesRepository|null         $ranges     Optional, for tests.
	 * @param ProductLookup|null                 $products   Optional, for tests.
	 */
	public function __construct(
		?ListUsersRepository $list_users = null,
		?ListsRepository $lists = null,
		?RuleGroupsRepository $rule_groups = null,
		?RuleGroupVariationsRepository $variations = null,
		?PriceRangesRepository $ranges = null,
		?ProductLookup $products = null
	) {
		$this->list_users  = $list_users ?? new ListUsersRepository();
		$this->lists       = $lists ?? new ListsRepository();
		$this->rule_groups = $rule_groups ?? new RuleGroupsRepository();
		$this->variations  = $variations ?? new RuleGroupVariationsRepository();
		$this->ranges      = $ranges ?? new PriceRangesRepository();
		$this->products    = $products ?? new ProductLookup();
	}

	/**
	 * Clears the request-scoped (Level 1) caches. Exposed mainly for tests
	 * that run multiple scenarios within a single PHP process.
	 *
	 * @return void
	 */
	public static function reset_request_cache() {
		self::$request_list_cache   = array();
		self::$request_groups_cache = array();
	}

	/**
	 * Builds the minimal, already-scoped raw pricing payload used by
	 * presentation JS to recompute a resolved single
	 * product/variation price live as the buyer changes quantity, without
	 * any further AJAX request. Only ever built from an `applies = true`
	 * `resolve()` result -- strictly limited to that one product/variation's
	 * own resolved rule, never any other list/rule data.
	 *
	 * @param array $result `resolve()` result, `applies = true`.
	 * @return array{pricingMode:string, minimumQuantity:int, regularPrice:float|null, unitPrice:float|null, basePrice:float|null, discountPercent:float|null, ranges:array<int,array{minQty:int,maxQty:int|null,unitPrice:float,basePrice:float,discountPercent:float|null}>}
	 */
	public static function to_frontend_payload( array $result ) {
		$payload = array(
			'pricingMode'     => (string) $result['pricing_mode'],
			'minimumQuantity' => (int) $result['minimum_quantity'],
			'regularPrice'    => $result['reference_regular_price'],
			'unitPrice'       => null,
			// Offer fields (the price as written in the admin, and the
			// percentage taken off it): presentation JS strikes `basePrice`
			// through and shows `unitPrice`, mirroring exactly what the
			// server already rendered -- it never computes a discount itself.
			'basePrice'       => null,
			'discountPercent' => null,
			'ranges'          => array(),
		);

		if ( RuleGroupValidator::PRICING_MODE_FIXED === $result['pricing_mode'] ) {
			$payload['unitPrice']       = (float) $result['b2b_unit_price'];
			$payload['basePrice']       = isset( $result['base_unit_price'] ) && null !== $result['base_unit_price']
				? (float) $result['base_unit_price']
				: (float) $result['b2b_unit_price'];
			$payload['discountPercent'] = isset( $result['discount_percent'] ) ? $result['discount_percent'] : null;

			return $payload;
		}

		$payload['ranges'] = array_map(
			static function ( $range ) {
				return array(
					'minQty'          => (int) $range['min_qty'],
					'maxQty'          => ( isset( $range['max_qty'] ) && null !== $range['max_qty'] && '' !== $range['max_qty'] ) ? (int) $range['max_qty'] : null,
					'unitPrice'       => (float) $range['unit_price'],
					'basePrice'       => isset( $range['base_unit_price'] ) && null !== $range['base_unit_price']
						? (float) $range['base_unit_price']
						: (float) $range['unit_price'],
					'discountPercent' => isset( $range['discount_percent'] ) ? $range['discount_percent'] : null,
				);
			},
			(array) ( $result['ranges'] ?? array() )
		);

		return $payload;
	}

	/**
	 * Resolves the B2B price for a single product/variation and quantity,
	 * for the given user. This is the entry point used by single product
	 * display (after a variation is selected) and by product/order-line
	 * level resolution.
	 *
	 * Never throws: any unexpected exception is caught and converted into
	 * the standard B2C fallback (TC-067), so a WooCommerce price hook can
	 * always call this safely.
	 *
	 * @param int      $user_id      User id (0 for guests).
	 * @param int      $product_id   Parent product id.
	 * @param int|null $variation_id Variation id, or null for a simple product.
	 * @param int      $quantity     Requested quantity (display purposes).
	 * @return array Same structured shape as `PriceResolutionEngine::resolve()`.
	 */
	public function resolve( $user_id, $product_id, $variation_id, $quantity = 1 ) {
		$product_id   = (int) $product_id;
		$variation_id = null !== $variation_id ? (int) $variation_id : null;
		$quantity     = max( 1, (int) $quantity );

		try {
			$user_id = (int) $user_id;

			if ( $user_id <= 0 ) {
				return $this->fallback_result( self::FALLBACK_GUEST, $product_id, $variation_id );
			}

			$list = $this->get_assigned_list( $user_id );

			if ( null === $list ) {
				return $this->fallback_result( PriceResolutionEngine::FALLBACK_NO_LIST, $product_id, $variation_id );
			}

			if ( ! $this->products->exists( $product_id ) ) {
				return $this->fallback_result( self::FALLBACK_NOT_FOUND, $product_id, $variation_id );
			}

			$is_variable = $this->products->is_variable( $product_id );

			if ( null !== $variation_id && ! in_array( $variation_id, $this->products->get_variation_ids( $product_id ), true ) ) {
				return $this->fallback_result( self::FALLBACK_NOT_FOUND, $product_id, $variation_id );
			}

			$groups = $this->get_rule_groups_with_children( (int) $list['id'], $product_id );

			$result = PriceResolutionEngine::resolve(
				array(
					'list'           => $list,
					'is_variable'    => $is_variable,
					'variation_id'   => $variation_id,
					'quantity'       => $quantity,
					'product_id'     => $product_id,
					'rule_groups'    => $groups,
					'price_decimals' => self::price_decimals(),
				)
			);

			$this->maybe_log_integrity_error( $result );
			$this->maybe_log_discarded_offer( $result );

			if ( $result['applies'] ) {
				$result['reference_regular_price'] = $this->get_reference_regular_price( $product_id, $variation_id );
			}

			return $result;
		} catch ( Throwable $exception ) {
			Logger::error(
				'Unexpected exception during price resolution, falling back to standard WooCommerce pricing.',
				array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'message'      => $exception->getMessage(),
				)
			);

			return $this->fallback_result( self::FALLBACK_EXCEPTION, $product_id, $variation_id );
		}
	}

	/**
	 * Resolves the aggregate B2B pricing state of a variable product for a
	 * given user, before any specific variation has been selected
	 * (architecture-analysis.md sections 12/13): whether a
	 * single homogeneous price/minimum-quantity applies to every variation,
	 * or whether prices differ and only a "starting from" price can be shown.
	 *
	 * Never throws (TC-067): any unexpected exception safely resolves to
	 * `applies = false` (pure B2C display).
	 *
	 * @param int $user_id    User id (0 for guests).
	 * @param int $product_id Parent product id.
	 * @return array{
	 *     applies:bool, homogeneous:bool|null, list_id:int|null,
	 *     list_name:string|null, unit_price:float|null,
	 *     minimum_quantity:int|null, min_unit_price:float|null,
	 *     fallback_code:string
	 * }
	 */
	public function resolve_variable_aggregate( $user_id, $product_id ) {
		$product_id = (int) $product_id;

		// Delegates to resolve_batch() (single item) so single-product
		// "before selection" display and catalog/loop rendering always
		// share the exact same aggregation logic and output shape -- never
		// two competing implementations of "homogeneous vs. heterogeneous".
		$results = $this->resolve_batch( array( $product_id ), $user_id );

		return $results[ $product_id ] ?? $this->aggregate_fallback( self::FALLBACK_NOT_FOUND );
	}

	/**
	 * Resolves the catalog/loop display state for a batch of products in a
	 * single pass: one query for the user's assigned list, one
	 * batched query for rule groups across every requested product, one
	 * batched query for ranges and one for variation relationships --
	 * never one query per product, never AJAX per card.
	 *
	 * @param int[] $product_ids Parent product ids present on the current page.
	 * @param int   $user_id     User id (0 for guests).
	 * @return array<int,array> Keyed by product_id, same shape as
	 *                          `resolve_variable_aggregate()`.
	 */
	public function resolve_batch( array $product_ids, $user_id ) {
		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );

		if ( empty( $product_ids ) ) {
			return array();
		}

		try {
			$user_id = (int) $user_id;

			if ( $user_id <= 0 ) {
				return $this->fill_fallback_batch( $product_ids, self::FALLBACK_GUEST );
			}

			$list = $this->get_assigned_list( $user_id );

			if ( null === $list ) {
				return $this->fill_fallback_batch( $product_ids, PriceResolutionEngine::FALLBACK_NO_LIST );
			}

			$raw_groups = $this->rule_groups->get_by_list_and_products( (int) $list['id'], $product_ids );
			$enriched   = $this->enrich_groups( $raw_groups );

			$grouped_by_product = array();

			foreach ( $enriched as $group ) {
				$grouped_by_product[ (int) $group['product_id'] ][] = $group;
			}

			$results = array();

			foreach ( $product_ids as $product_id ) {
				$groups = $grouped_by_product[ $product_id ] ?? array();

				// Warms the same per-list_id+product_id cache used by
				// get_rule_groups_with_children(), so a later individual
				// resolve() call for one of these products (e.g. once the
				// shopper selects a specific variation) never re-issues the
				// query this batch call already paid for.
				$this->store_rule_groups_cache( (int) $list['id'], $product_id, $groups );

				if ( $this->products->is_variable( $product_id ) ) {
					$results[ $product_id ] = $this->aggregate_for_variable( $list, $product_id, $groups );
				} else {
					$results[ $product_id ] = $this->aggregate_for_simple( $list, $product_id, $groups );
				}
			}

			return $results;
		} catch ( Throwable $exception ) {
			Logger::error(
				'Unexpected exception during batch price resolution, falling back to standard WooCommerce pricing.',
				array(
					'product_ids' => $product_ids,
					'message'     => $exception->getMessage(),
				)
			);

			return $this->fill_fallback_batch( $product_ids, self::FALLBACK_EXCEPTION );
		}
	}

	/**
	 * Aggregate resolution for a single simple product, from
	 * already-fetched groups (no extra DB queries) -- shares the same
	 * output shape as the variable-product aggregate so rendering code can
	 * treat both uniformly.
	 *
	 * @param array $assigned_list Assigned list row.
	 * @param int   $product_id    Product id.
	 * @param array $groups        Enriched rule group rows for this list/product.
	 * @return array
	 */
	private function aggregate_for_simple( array $assigned_list, $product_id, array $groups ) {
		$result = PriceResolutionEngine::resolve(
			array(
				'list'           => $assigned_list,
				'is_variable'    => false,
				'variation_id'   => null,
				'quantity'       => 1,
				'product_id'     => $product_id,
				'rule_groups'    => $groups,
				'price_decimals' => self::price_decimals(),
			)
		);

		$this->maybe_log_integrity_error( $result );

		if ( ! $result['applies'] ) {
			return $this->aggregate_fallback( $result['fallback_code'], (int) $assigned_list['id'], (string) $assigned_list['name'] );
		}

		return array(
			'applies'                         => true,
			'homogeneous'                     => true,
			'fully_covered'                   => true,
			'list_id'                         => (int) $assigned_list['id'],
			'list_name'                       => (string) $assigned_list['name'],
			'unit_price'                      => $result['b2b_unit_price'],
			'base_unit_price'                 => $result['base_unit_price'],
			'discount_percent'                => $result['discount_percent'],
			'minimum_quantity'                => $result['minimum_quantity'],
			'min_unit_price'                  => null,
			'min_unit_price_base'             => null,
			'min_unit_price_discount_percent' => null,
			'min_unit_price_minimum_quantity' => null,
			'fallback_code'                   => PriceResolutionEngine::REASON_OK,
		);
	}

	/**
	 * Aggregate resolution for a variable product across all of its
	 * variations (architecture-analysis.md section 12): determines whether
	 * a single price/minimum-quantity applies uniformly (homogeneous), or
	 * whether only a "starting from" price can be shown (heterogeneous).
	 *
	 * Homogeneity is judged on exactly what the catalog/single-product
	 * "before selection" view actually shows -- the resolved unit price and
	 * minimum quantity at each variation's own entry point (
	 * "Q80.00 desde 3 unidades" text never reveals deeper tiers) -- not on
	 * comparing full range tables variation by variation. Two different
	 * `variation_group` rows that happen to resolve to the same entry price
	 * and minimum quantity are therefore correctly treated as homogeneous,
	 * consistent with what the buyer would actually see.
	 *
	 * @param array $assigned_list Assigned list row.
	 * @param int   $product_id    Parent product id.
	 * @param array $groups        Enriched rule group rows for this list/product.
	 * @return array
	 */
	private function aggregate_for_variable( array $assigned_list, $product_id, array $groups ) {
		$variation_ids = $this->products->get_variation_ids( $product_id );

		if ( empty( $variation_ids ) ) {
			return $this->aggregate_fallback( PriceResolutionEngine::FALLBACK_NO_RULE, (int) $assigned_list['id'], (string) $assigned_list['name'] );
		}

		$covered_signatures = array();
		$covered_prices     = array();

		foreach ( $variation_ids as $variation_id ) {
			$result = PriceResolutionEngine::resolve(
				array(
					'list'           => $assigned_list,
					'is_variable'    => true,
					'variation_id'   => $variation_id,
					'quantity'       => 1,
					'product_id'     => $product_id,
					'rule_groups'    => $groups,
					'price_decimals' => self::price_decimals(),
				)
			);

			$this->maybe_log_integrity_error( $result );

			if ( ! $result['applies'] ) {
				continue;
			}

			// The signature is what the shopper actually reads on a card
			// (final price + minimum quantity), so two variations that land
			// on the same final price through different offers are still
			// correctly "homogeneous" -- the base price is carried along
			// only so the struck-through figure can be rendered.
			$covered_signatures[] = $result['b2b_unit_price'] . '|' . $result['minimum_quantity'];
			$covered_prices[]     = array(
				'unit_price'       => $result['b2b_unit_price'],
				'base_unit_price'  => $result['base_unit_price'],
				'discount_percent' => $result['discount_percent'],
				'minimum_quantity' => $result['minimum_quantity'],
			);
		}

		if ( empty( $covered_prices ) ) {
			return $this->aggregate_fallback( PriceResolutionEngine::FALLBACK_NO_RULE, (int) $assigned_list['id'], (string) $assigned_list['name'] );
		}

		// Fase 14 (point 6/7): a product is only "partially covered" when
		// SOME variations have no applicable rule at all -- never confused
		// with "homogeneous" (which is about every covered variation
		// sharing the exact same price/minimum, not about how many
		// variations are covered). `all_variations` always covers every
		// variation by construction, so it can never be partial here.
		$fully_covered = count( $covered_prices ) === count( $variation_ids );

		$is_homogeneous = $fully_covered && 1 === count( array_unique( $covered_signatures ) );

		if ( $is_homogeneous ) {
			$representative = $covered_prices[0];

			return array(
				'applies'                         => true,
				'homogeneous'                     => true,
				'fully_covered'                   => true,
				'list_id'                         => (int) $assigned_list['id'],
				'list_name'                       => (string) $assigned_list['name'],
				'unit_price'                      => $representative['unit_price'],
				'base_unit_price'                 => $representative['base_unit_price'],
				'discount_percent'                => $representative['discount_percent'],
				'minimum_quantity'                => $representative['minimum_quantity'],
				'min_unit_price'                  => null,
				'min_unit_price_base'             => null,
				'min_unit_price_discount_percent' => null,
				'min_unit_price_minimum_quantity' => null,
				'fallback_code'                   => PriceResolutionEngine::REASON_OK,
			);
		}

		$min_unit_price = min( array_column( $covered_prices, 'unit_price' ) );
		$representative = null;

		foreach ( $covered_prices as $candidate ) {
			if ( (float) $candidate['unit_price'] === (float) $min_unit_price ) {
				$representative = $candidate;
				break;
			}
		}

		return array(
			'applies'                         => true,
			'homogeneous'                     => false,
			'fully_covered'                   => $fully_covered,
			'list_id'                         => (int) $assigned_list['id'],
			'list_name'                       => (string) $assigned_list['name'],
			'unit_price'                      => null,
			'base_unit_price'                 => null,
			'discount_percent'                => null,
			'minimum_quantity'                => null,
			'min_unit_price'                  => $min_unit_price,
			'min_unit_price_base'             => $representative ? $representative['base_unit_price'] : null,
			'min_unit_price_discount_percent' => $representative ? $representative['discount_percent'] : null,
			'min_unit_price_minimum_quantity' => $representative ? $representative['minimum_quantity'] : null,
			'fallback_code'                   => PriceResolutionEngine::REASON_OK,
		);
	}

	/**
	 * Resolves and caches (two levels) the assigned list for a
	 * user.
	 *
	 * @param int $user_id User id.
	 * @return array|null List row (id, name, status), or null when the
	 *                     user has no assignment.
	 */
	private function get_assigned_list( $user_id ) {
		$user_id = (int) $user_id;

		if ( isset( self::$request_list_cache[ $user_id ] ) ) {
			return self::$request_list_cache[ $user_id ]['list'];
		}

		$cache_key = 'b2bpl_user_list_' . $user_id;
		$cached    = wp_cache_get( $cache_key, Cache::GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			self::$request_list_cache[ $user_id ] = $cached;

			return $cached['list'];
		}

		$list_id = $this->list_users->get_list_id_for_user( $user_id );
		$list    = null;

		if ( null !== $list_id ) {
			$found = $this->lists->find( $list_id );

			if ( $found ) {
				$list = array(
					'id'               => (int) $found['id'],
					'name'             => (string) $found['name'],
					'status'           => (string) $found['status'],
					// List-wide offer (nullable): the least specific of the
					// three offer levels, applied by the engine only when
					// neither the rule group nor the range defines its own.
					'discount_percent' => isset( $found['discount_percent'] ) && null !== $found['discount_percent']
						? (float) $found['discount_percent']
						: null,
				);
			}
		}

		$entry = array(
			'exists' => null !== $list,
			'list'   => $list,
		);

		self::$request_list_cache[ $user_id ] = $entry;
		wp_cache_set( $cache_key, $entry, Cache::GROUP );

		return $list;
	}

	/**
	 * Resolves and caches (two levels) the rule groups for a
	 * single `list_id`+`product_id` combination (cache keys always
	 * include both, never `product_id` alone), enriched with their ranges
	 * and variation relationships.
	 *
	 * @param int $list_id    Assigned list id.
	 * @param int $product_id Parent product id.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_rule_groups_with_children( $list_id, $product_id ) {
		$list_id    = (int) $list_id;
		$product_id = (int) $product_id;
		$cache_key  = self::rule_groups_cache_key( $list_id, $product_id );

		if ( isset( self::$request_groups_cache[ $cache_key ] ) ) {
			return self::$request_groups_cache[ $cache_key ];
		}

		$cached = wp_cache_get( $cache_key, Cache::GROUP );

		if ( is_array( $cached ) ) {
			self::$request_groups_cache[ $cache_key ] = $cached;

			return $cached;
		}

		$groups   = $this->rule_groups->get_by_list_and_product( $list_id, $product_id );
		$enriched = $this->enrich_groups( $groups );

		$this->store_rule_groups_cache( $list_id, $product_id, $enriched );

		return $enriched;
	}

	/**
	 * Writes an already-enriched rule group set to both cache levels
	 *, keyed by `list_id`+`product_id` (never
	 * `product_id` alone).
	 *
	 * @param int   $list_id    Assigned list id.
	 * @param int   $product_id Parent product id.
	 * @param array $enriched   Enriched rule group rows (possibly empty).
	 * @return void
	 */
	private function store_rule_groups_cache( $list_id, $product_id, array $enriched ) {
		$cache_key = self::rule_groups_cache_key( $list_id, $product_id );

		self::$request_groups_cache[ $cache_key ] = $enriched;
		wp_cache_set( $cache_key, $enriched, Cache::GROUP );
	}

	/**
	 * Cache key for one `list_id`+`product_id` rule-group set (
	 * never `product_id` alone), carrying the list's cache generation so an
	 * admin edit invalidates it on any object cache backend, including those
	 * where `wp_cache_flush_group()` is a silent no-op.
	 *
	 * @param int $list_id    Assigned list id.
	 * @param int $product_id Parent product id.
	 * @return string
	 */
	private static function rule_groups_cache_key( $list_id, $product_id ) {
		return 'b2bpl_rulegroups_' . (int) $list_id . '_v' . Cache::list_version( (int) $list_id ) . '_' . (int) $product_id;
	}

	/**
	 * Enriches a set of raw rule group rows with their price ranges
	 * (tiered groups) and variation ids (variation_group rows), using
	 * batched queries -- never one query per rule group.
	 *
	 * @param array $groups Raw rule group rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function enrich_groups( array $groups ) {
		if ( empty( $groups ) ) {
			return array();
		}

		$tiered_ids          = array();
		$variation_group_ids = array();

		foreach ( $groups as $group ) {
			if ( RuleGroupValidator::PRICING_MODE_TIERED === $group['pricing_mode'] ) {
				$tiered_ids[] = (int) $group['id'];
			}

			if ( RuleGroupValidator::SCOPE_VARIATION_GROUP === $group['scope'] ) {
				$variation_group_ids[] = (int) $group['id'];
			}
		}

		$ranges_by_group     = ! empty( $tiered_ids ) ? $this->ranges->get_by_rule_group_ids( $tiered_ids ) : array();
		$variations_by_group = ! empty( $variation_group_ids ) ? $this->variations->get_variation_ids_by_rule_group_ids( $variation_group_ids ) : array();

		$enriched = array();

		foreach ( $groups as $group ) {
			$id = (int) $group['id'];

			$group['ranges']        = $ranges_by_group[ $id ] ?? array();
			$group['variation_ids'] = $variations_by_group[ $id ] ?? array();

			$enriched[] = $group;
		}

		return $enriched;
	}

	/**
	 * Reads the current WooCommerce regular price for a product/variation,
	 * as a float (only ever the native WooCommerce value, never
	 * computed by this plugin).
	 *
	 * @param int      $product_id   Product id.
	 * @param int|null $variation_id Variation id, when applicable.
	 * @return float|null Null when the product/regular price is unavailable.
	 */
	private function get_reference_regular_price( $product_id, $variation_id ) {
		$target = wc_get_product( ! empty( $variation_id ) ? $variation_id : $product_id );

		if ( ! $target ) {
			return null;
		}

		$regular_price = $target->get_regular_price();

		return ( '' === $regular_price || null === $regular_price ) ? null : (float) $regular_price;
	}

	/**
	 * Logs a data-integrity fallback (never exposed to the buyer).
	 *
	 * @param array $result Engine result.
	 * @return void
	 */
	private function maybe_log_integrity_error( array $result ) {
		if ( $result['applies'] ) {
			return;
		}

		if ( 0 !== strpos( (string) $result['fallback_code'], 'integrity_' ) ) {
			return;
		}

		Logger::error(
			'Price rule data integrity error detected, falling back to standard WooCommerce pricing.',
			array(
				'fallback_code' => $result['fallback_code'],
				'list_id'       => $result['list_id'],
				'product_id'    => $result['product_id'],
				'variation_id'  => $result['variation_id'],
			)
		);
	}

	/**
	 * Logs an offer the engine refused to apply because it would have priced
	 * the item at zero. The buyer is charged the correct (undiscounted)
	 * price either way; this is what tells the administrator that one of
	 * their percentages is unusable for that product.
	 *
	 * @param array $result Engine result.
	 * @return void
	 */
	private function maybe_log_discarded_offer( array $result ) {
		if ( empty( $result['offer_discarded'] ) ) {
			return;
		}

		Logger::error(
			'An offer was discarded because it would have priced this item at zero; the price before the offer was charged instead.',
			array(
				'list_id'       => $result['list_id'],
				'rule_group_id' => $result['rule_group_id'],
				'product_id'    => $result['product_id'],
				'variation_id'  => $result['variation_id'],
				'base_price'    => $result['base_unit_price'],
			)
		);
	}

	/**
	 * Builds a "does not apply" result for `resolve()`.
	 *
	 * @param string   $code         Fallback code.
	 * @param int      $product_id   Product id.
	 * @param int|null $variation_id Variation id.
	 * @return array
	 */
	private function fallback_result( $code, $product_id, $variation_id ) {
		return array(
			'applies'                 => false,
			'list_id'                 => null,
			'list_name'               => null,
			'rule_group_id'           => null,
			'pricing_mode'            => null,
			'range_id'                => null,
			'range_min_qty'           => null,
			'range_max_qty'           => null,
			'minimum_quantity'        => null,
			'b2b_unit_price'          => null,
			'base_unit_price'         => null,
			'discount_percent'        => null,
			'discount_source'         => null,
			'offer_discarded'         => false,
			'reference_regular_price' => null,
			'product_id'              => $product_id,
			'variation_id'            => $variation_id,
			'fallback_code'           => $code,
		);
	}

	/**
	 * WooCommerce's own configured number of price decimals, handed to the
	 * pure engine so offer rounding matches the store's currency settings
	 * exactly (the engine itself never calls WooCommerce).
	 *
	 * @return int
	 */
	private static function price_decimals() {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	/**
	 * Builds a "does not apply" result for the aggregate/batch shape.
	 *
	 * @param string      $code      Fallback code.
	 * @param int|null    $list_id   Assigned list id, when known.
	 * @param string|null $list_name Assigned list name, when known.
	 * @return array
	 */
	private function aggregate_fallback( $code, $list_id = null, $list_name = null ) {
		return array(
			'applies'                         => false,
			'homogeneous'                     => null,
			'fully_covered'                   => null,
			'list_id'                         => $list_id,
			'list_name'                       => $list_name,
			'unit_price'                      => null,
			'base_unit_price'                 => null,
			'discount_percent'                => null,
			'minimum_quantity'                => null,
			'min_unit_price'                  => null,
			'min_unit_price_base'             => null,
			'min_unit_price_discount_percent' => null,
			'min_unit_price_minimum_quantity' => null,
			'fallback_code'                   => $code,
		);
	}

	/**
	 * Fills a batch result array with the same fallback for every product.
	 *
	 * @param int[]  $product_ids Product ids.
	 * @param string $code        Fallback code.
	 * @return array<int,array>
	 */
	private function fill_fallback_batch( array $product_ids, $code ) {
		$results = array();

		foreach ( $product_ids as $product_id ) {
			$results[ $product_id ] = $this->aggregate_fallback( $code );
		}

		return $results;
	}
}
