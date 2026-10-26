<?php
/**
 * Pure price resolution algorithm (architecture-analysis.md section 9),
 * deliberately free of any WordPress/database access, so it can be covered
 * by fast unit tests -- exactly the same split already established by
 * `RuleGroupValidator` (pure) vs. `RuleGroupService` (WordPress-aware
 * orchestration) in the Hito A codebase. `Domain\PriceResolver` is the only
 * caller: it fetches the assigned list and the already-scoped `list_id`+
 * `product_id` rule group rows from the repositories/WooCommerce,
 * and hands them to `resolve()` as plain arrays.
 *
 * @package B2BPriceLists\Domain
 */

namespace B2BPriceLists\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriceResolutionEngine
 */
class PriceResolutionEngine {

	/**
	 * No assigned list for the current user (invitado, o usuario sin
	 * lista --). `PriceResolver` normally short-circuits before
	 * ever calling this engine for these two cases, but the engine handles
	 * a null list defensively too, so it remains a total, safe function
	 * for any input (defense in depth, never a fatal error).
	 *
	 * @var string
	 */
	const FALLBACK_NO_LIST = 'no_list';

	/**
	 * The assigned list exists but its status is not 'active'.
	 *
	 * @var string
	 */
	const FALLBACK_LIST_INACTIVE = 'list_inactive';

	/**
	 * No rule group of the applicable scope was found for this
	 * `list_id`+`product_id`(+variation) combination -- standard "no rule
	 * for this product in this list" case, not an error.
	 *
	 * @var string
	 */
	const FALLBACK_NO_RULE = 'no_rule';

	/**
	 * `resolve()` was called with a null `variation_id` for a variable
	 * product. A single "the" price cannot be resolved this way:
	 * callers must use `PriceResolver::resolve_variable_aggregate()`
	 * instead (architecture-analysis.md section 12/13).
	 *
	 * @var string
	 */
	const FALLBACK_VARIABLE_REQUIRES_VARIATION = 'variable_product_requires_variation';

	/**
	 * Data integrity error: more than one `simple` rule group
	 * exists for the same `list_id`+`product_id`. Never guessed between --
	 * always falls back to B2C, and the caller is expected to log this.
	 *
	 * @var string
	 */
	const FALLBACK_INTEGRITY_DUPLICATE_SIMPLE = 'integrity_duplicate_simple';

	/**
	 * Data integrity error: more than one `all_variations` rule
	 * group exists for the same `list_id`+`product_id`.
	 *
	 * @var string
	 */
	const FALLBACK_INTEGRITY_DUPLICATE_ALL_VARIATIONS = 'integrity_duplicate_all_variations';

	/**
	 * Data integrity error: the requested variation
	 * belongs to more than one `variation_group` within the same
	 * `list_id`+`product_id`.
	 *
	 * @var string
	 */
	const FALLBACK_INTEGRITY_DUPLICATE_VARIATION_MEMBERSHIP = 'integrity_duplicate_variation_membership';

	/**
	 * Data integrity error: a `tiered` rule group has no price ranges, or
	 * none of its ranges cover the resolved quantity (should never happen
	 * after valid administration input, RuleGroupValidator::validate_ranges()
	 * already requires full, gapless coverage -- this is a corruption
	 * safeguard, not an expected path).
	 *
	 * @var string
	 */
	const FALLBACK_INTEGRITY_MISSING_RANGE = 'integrity_missing_range';

	/**
	 * The requested quantity is above every tier this rule covers, because
	 * its last range (by minimum quantity) is deliberately closed (has a
	 * `max_qty`) rather than open-ended -- an expected, valid state (DEC
	 * point 3: "si existe máximo finito, no permitir superar ese máximo"),
	 * never a data-integrity problem. Presentation JS clamps the quantity
	 * input to this same ceiling so a buyer never reaches this path through
	 * the normal UI; this fallback is what keeps the server authoritative
	 * if that clamp is ever bypassed -- the buyer simply falls back to the
	 * product's standard (B2C) price for that line, exactly like any other
	 * "no rule applies" case, never a silent/incorrect B2B undercharge.
	 *
	 * @var string
	 */
	const FALLBACK_QUANTITY_EXCEEDS_RULE = 'quantity_exceeds_rule';

	/**
	 * Successful resolution.
	 *
	 * @var string
	 */
	const REASON_OK = 'ok';

	/**
	 * Resolves the B2B unit price for a single product/variation and
	 * quantity, strictly within the rule groups of one already-assigned
	 * list (everything passed in via `$context['rule_groups']`
	 * must already be scoped to a single `list_id`+`product_id`combination
	 * -- this method never mixes rules from different lists).
	 *
	 * @param array $context {
	 *     Resolution context.
	 *
	 *     @type array|null $list         Assigned list row: at least `id`,
	 *                                    `name`, `status`. Null when the
	 *                                    caller could not resolve one (guest,
	 *                                    no assignment).
	 *     @type bool        $is_variable Whether the parent product is variable.
	 *     @type int|null    $variation_id Requested variation id, or null.
	 *     @type int         $quantity    Requested quantity (display purposes;
	 *                                    this milestone never enforces a
	 *                                    minimum against the cart, only
	 *                                    exposes it for the frontend/Fase 9).
	 *     @type int         $product_id  Parent product id.
	 *     @type array       $rule_groups Rule group rows for this exact
	 *                                    `list_id`+`product_id` (already
	 *                                    fetched by `PriceResolver`), each
	 *                                    with at least: id, scope,
	 *                                    pricing_mode, unit_price,
	 *                                    minimum_quantity, ranges (array,
	 *                                    tiered only), variation_ids (array,
	 *                                    variation_group only).
	 * }
	 * @return array Structured result, see `fallback()`/successful branch below.
	 */
	public static function resolve( array $context ) {
		$product_id   = (int) ( $context['product_id'] ?? 0 );
		$variation_id = isset( $context['variation_id'] ) && null !== $context['variation_id']
			? (int) $context['variation_id']
			: null;
		$quantity     = max( 1, (int) ( $context['quantity'] ?? 1 ) );
		$is_variable  = ! empty( $context['is_variable'] );
		$list         = isset( $context['list'] ) ? $context['list'] : null;
		$rule_groups  = isset( $context['rule_groups'] ) ? (array) $context['rule_groups'] : array();
		// WooCommerce's own configured number of price decimals, supplied by
		// `PriceResolver` -- this pure engine never calls WooCommerce itself
		// and never invents a rounding convention of its own.
		$decimals = isset( $context['price_decimals'] ) ? max( 0, (int) $context['price_decimals'] ) : 2;

		if ( null === $list || ! isset( $list['id'] ) ) {
			return self::fallback( self::FALLBACK_NO_LIST, $product_id, $variation_id );
		}

		$list_id       = (int) $list['id'];
		$list_name     = (string) ( $list['name'] ?? '' );
		$status        = (string) ( $list['status'] ?? '' );
		$list_discount = isset( $list['discount_percent'] ) ? $list['discount_percent'] : null;

		if ( 'active' !== $status ) {
			return self::fallback( self::FALLBACK_LIST_INACTIVE, $product_id, $variation_id, $list_id, $list_name );
		}

		if ( null === $variation_id && $is_variable ) {
			return self::fallback( self::FALLBACK_VARIABLE_REQUIRES_VARIATION, $product_id, $variation_id, $list_id, $list_name );
		}

		if ( null !== $variation_id ) {
			$candidate_or_fallback = self::select_variation_candidate( $rule_groups, $variation_id, $product_id, $list_id, $list_name );
		} else {
			$candidate_or_fallback = self::select_simple_candidate( $rule_groups, $product_id, $list_id, $list_name );
		}

		// A helper returned a finished fallback array (integrity error or
		// no rule found) instead of a candidate row.
		if ( isset( $candidate_or_fallback['applies'] ) ) {
			return $candidate_or_fallback;
		}

		$candidate = $candidate_or_fallback;

		return self::price_from_candidate( $candidate, $product_id, $variation_id, $list_id, $list_name, $quantity, $list_discount, $decimals );
	}

	/**
	 * Selects the applicable rule group for a requested variation:
	 * `variation_group` containing it first, `all_variations` as fallback
	 *. Detects duplicate-scope corruption along the way.
	 *
	 * @param array  $rule_groups  Candidate rows for this list_id+product_id.
	 * @param int    $variation_id Requested variation id.
	 * @param int    $product_id   Parent product id.
	 * @param int    $list_id      Assigned list id.
	 * @param string $list_name    Assigned list name.
	 * @return array Either a rule group row, or a finished fallback array
	 *               (distinguished by the presence of an `applies` key).
	 */
	private static function select_variation_candidate( array $rule_groups, $variation_id, $product_id, $list_id, $list_name ) {
		$variation_groups = array_values(
			array_filter(
				$rule_groups,
				static function ( $group ) use ( $variation_id ) {
					if ( RuleGroupValidator::SCOPE_VARIATION_GROUP !== ( $group['scope'] ?? '' ) ) {
						return false;
					}

					$variation_ids = array_map( 'intval', (array) ( $group['variation_ids'] ?? array() ) );

					return in_array( (int) $variation_id, $variation_ids, true );
				}
			)
		);

		if ( count( $variation_groups ) > 1 ) {
			return self::fallback( self::FALLBACK_INTEGRITY_DUPLICATE_VARIATION_MEMBERSHIP, $product_id, $variation_id, $list_id, $list_name );
		}

		if ( 1 === count( $variation_groups ) ) {
			return $variation_groups[0];
		}

		$all_variations_groups = self::filter_scope( $rule_groups, RuleGroupValidator::SCOPE_ALL_VARIATIONS );

		if ( count( $all_variations_groups ) > 1 ) {
			return self::fallback( self::FALLBACK_INTEGRITY_DUPLICATE_ALL_VARIATIONS, $product_id, $variation_id, $list_id, $list_name );
		}

		if ( 1 === count( $all_variations_groups ) ) {
			return $all_variations_groups[0];
		}

		return self::fallback( self::FALLBACK_NO_RULE, $product_id, $variation_id, $list_id, $list_name );
	}

	/**
	 * Selects the applicable `simple` rule group for a non-variable
	 * product. Detects duplicate-scope corruption.
	 *
	 * @param array  $rule_groups Candidate rows for this list_id+product_id.
	 * @param int    $product_id  Product id.
	 * @param int    $list_id     Assigned list id.
	 * @param string $list_name   Assigned list name.
	 * @return array Either a rule group row, or a finished fallback array.
	 */
	private static function select_simple_candidate( array $rule_groups, $product_id, $list_id, $list_name ) {
		$simple_groups = self::filter_scope( $rule_groups, RuleGroupValidator::SCOPE_SIMPLE );

		if ( count( $simple_groups ) > 1 ) {
			return self::fallback( self::FALLBACK_INTEGRITY_DUPLICATE_SIMPLE, $product_id, null, $list_id, $list_name );
		}

		if ( 1 === count( $simple_groups ) ) {
			return $simple_groups[0];
		}

		return self::fallback( self::FALLBACK_NO_RULE, $product_id, null, $list_id, $list_name );
	}

	/**
	 * Computes the final price/range payload for a resolved candidate rule
	 * group.
	 *
	 * Quantity below the rule's minimum: this milestone never rejects the
	 * request here (cart enforcement is Fase 9, out of scope) -- instead,
	 * the *effective* quantity used to pick a tiered range is clamped up to
	 * the minimum purchasable quantity, since that is the smallest amount a
	 * buyer could actually purchase and therefore the correct price to
	 * display by default. `minimum_quantity` is always exposed in the
	 * result so the frontend can render the input's `min` attribute and the
	 * "minimum quantity" notice ( preparation, enforced later).
	 *
	 * An offer (a percentage defined on the range, the rule group or the
	 * list -- most specific wins, see `Domain\Discount`) is applied here and
	 * only here, so `b2b_unit_price` is always the ONE final number the
	 * buyer pays: the cart, the order snapshot and every display path read
	 * that same field and never recompute a discount of their own. The
	 * pre-offer price is preserved alongside it as `base_unit_price`, which
	 * is what the storefront strikes through.
	 *
	 * @param array    $candidate     Rule group row.
	 * @param int      $product_id    Product id.
	 * @param int|null $variation_id  Variation id, or null.
	 * @param int      $list_id       Assigned list id.
	 * @param string   $list_name     Assigned list name.
	 * @param int      $quantity      Requested quantity.
	 * @param mixed    $list_discount Offer defined on the list, if any.
	 * @param int      $decimals      Store price decimals.
	 * @return array
	 */
	private static function price_from_candidate( array $candidate, $product_id, $variation_id, $list_id, $list_name, $quantity, $list_discount = null, $decimals = 2 ) {
		$pricing_mode  = (string) ( $candidate['pricing_mode'] ?? '' );
		$rule_discount = isset( $candidate['discount_percent'] ) ? $candidate['discount_percent'] : null;

		if ( RuleGroupValidator::PRICING_MODE_FIXED === $pricing_mode ) {
			$minimum_quantity = max( 1, (int) ( $candidate['minimum_quantity'] ?? 1 ) );
			// `?? null` and not `$candidate['unit_price']`: a corrupt row
			// missing the column entirely must fall back exactly like a row
			// whose value is NULL, never raise a notice on the way there.
			$base_price = isset( $candidate['unit_price'] ) && null !== $candidate['unit_price']
				? (float) $candidate['unit_price']
				: null;

			if ( null === $base_price ) {
				return self::fallback( self::FALLBACK_INTEGRITY_MISSING_RANGE, $product_id, $variation_id, $list_id, $list_name );
			}

			// A `fixed` rule has no ranges, so only the rule-level and
			// list-level offers can apply to it.
			$offer      = Discount::resolve( null, $rule_discount, $list_discount );
			$priced     = self::apply_offer_safely( $base_price, $offer, $decimals );
			$unit_price = $priced['price'];
			$offer      = $priced['offer'];

			return array(
				'applies'                 => true,
				'list_id'                 => $list_id,
				'list_name'               => $list_name,
				'rule_group_id'           => (int) $candidate['id'],
				'pricing_mode'            => $pricing_mode,
				'range_id'                => null,
				'range_min_qty'           => null,
				'range_max_qty'           => null,
				'minimum_quantity'        => $minimum_quantity,
				'b2b_unit_price'          => $unit_price,
				'base_unit_price'         => $base_price,
				'discount_percent'        => $offer['percent'],
				'discount_source'         => $offer['source'],
				'offer_discarded'         => $priced['discarded'],
				'reference_regular_price' => null,
				'product_id'              => $product_id,
				'variation_id'            => $variation_id,
				'fallback_code'           => self::REASON_OK,
			);
		}

		$ranges = self::apply_offer_to_ranges(
			self::sorted_ranges( (array) ( $candidate['ranges'] ?? array() ) ),
			$rule_discount,
			$list_discount,
			$decimals
		);

		if ( empty( $ranges ) ) {
			return self::fallback( self::FALLBACK_INTEGRITY_MISSING_RANGE, $product_id, $variation_id, $list_id, $list_name );
		}

		$minimum_quantity   = (int) $ranges[0]['min_qty'];
		$effective_quantity = max( (int) $quantity, $minimum_quantity );

		$applicable = null;

		foreach ( $ranges as $range ) {
			$min = (int) $range['min_qty'];
			$max = ( isset( $range['max_qty'] ) && null !== $range['max_qty'] && '' !== $range['max_qty'] )
				? (int) $range['max_qty']
				: null;

			if ( $effective_quantity >= $min && ( null === $max || $effective_quantity <= $max ) ) {
				$applicable = $range;
				break;
			}
		}

		if ( null === $applicable ) {
			$last     = $ranges[ count( $ranges ) - 1 ];
			$last_max = ( isset( $last['max_qty'] ) && null !== $last['max_qty'] && '' !== $last['max_qty'] )
				? (int) $last['max_qty']
				: null;

			if ( null !== $last_max && $effective_quantity > $last_max ) {
				// Not a corruption: the rule's last tier is deliberately
				// closed and this quantity is simply above it (point 3).
				// Presentation JS already clamps the input so a buyer never
				// reaches this through the normal UI -- this is what keeps
				// the server authoritative if that clamp is ever bypassed.
				return self::fallback( self::FALLBACK_QUANTITY_EXCEEDS_RULE, $product_id, $variation_id, $list_id, $list_name );
			}

			// Otherwise this should never happen:
			// RuleGroupValidator::validate_ranges() already guarantees full,
			// gapless, open-ended-last coverage at save time. Reaching this
			// line means the stored data no longer matches that invariant --
			// a genuine integrity error.
			return self::fallback( self::FALLBACK_INTEGRITY_MISSING_RANGE, $product_id, $variation_id, $list_id, $list_name );
		}

		$range_max = ( isset( $applicable['max_qty'] ) && null !== $applicable['max_qty'] && '' !== $applicable['max_qty'] )
			? (int) $applicable['max_qty']
			: null;

		return array(
			'applies'                 => true,
			'list_id'                 => $list_id,
			'list_name'               => $list_name,
			'rule_group_id'           => (int) $candidate['id'],
			'pricing_mode'            => $pricing_mode,
			'range_id'                => isset( $applicable['id'] ) ? (int) $applicable['id'] : null,
			'range_min_qty'           => (int) $applicable['min_qty'],
			'range_max_qty'           => $range_max,
			'minimum_quantity'        => $minimum_quantity,
			'b2b_unit_price'          => (float) $applicable['unit_price'],
			'base_unit_price'         => (float) $applicable['base_unit_price'],
			'discount_percent'        => $applicable['discount_percent'],
			'discount_source'         => $applicable['discount_source'],
			'offer_discarded'         => ! empty( $applicable['offer_discarded'] ),
			'reference_regular_price' => null,
			'product_id'              => $product_id,
			'variation_id'            => $variation_id,
			'fallback_code'           => self::REASON_OK,
			// Full, already-sorted range table of the resolved rule group --
			// additive field (beyond the quantity-resolved range above),
			// used exclusively by the customer-facing price table renderer
			// to list every tier, not just the one that applies to
			// the current quantity.
			'ranges'                  => $ranges,
		);
	}

	/**
	 * Filters rule group rows by scope.
	 *
	 * @param array  $groups Rule group rows.
	 * @param string $scope  Scope to match.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_scope( array $groups, $scope ) {
		return array_values(
			array_filter(
				$groups,
				static function ( $group ) use ( $scope ) {
					$group_scope = isset( $group['scope'] ) ? $group['scope'] : '';

					return $scope === $group_scope;
				}
			)
		);
	}

	/**
	 * Sorts price ranges by `min_qty` ascending (the engine never trusts
	 * `sort_order` for evaluation, mirroring `PriceRangesRepository`).
	 *
	 * @param array $ranges Range rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sorted_ranges( array $ranges ) {
		usort(
			$ranges,
			static function ( $a, $b ) {
				return (int) ( $a['min_qty'] ?? 0 ) <=> (int) ( $b['min_qty'] ?? 0 );
			}
		);

		return array_values( $ranges );
	}

	/**
	 * Builds a "does not apply" result. Every fallback -- guest, no list,
	 * inactive list, no rule, or a data-integrity error -- converges here
	 * ( is a business-safety invariant, never a guess between
	 * ambiguous candidates).
	 *
	 * @param string      $code         One of the FALLBACK_* constants.
	 * @param int         $product_id   Product id.
	 * @param int|null    $variation_id Variation id, or null.
	 * @param int|null    $list_id      Assigned list id, when known.
	 * @param string|null $list_name    Assigned list name, when known.
	 * @return array
	 */
	private static function fallback( $code, $product_id, $variation_id, $list_id = null, $list_name = null ) {
		return array(
			'applies'                 => false,
			'list_id'                 => $list_id,
			'list_name'               => $list_name,
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
	 * Applies an offer, refusing to let it produce a price of zero.
	 *
	 * `Discount::validate()` already forbids a percentage of 100 or more, so
	 * a zero can only arise from ROUNDING: a small enough base price under a
	 * large enough percentage (0.40 at -99% rounds to 0.00 at two decimals,
	 * and any base under half a unit does at zero decimals, as in CLP/COP/JPY).
	 * Selling at zero is never an acceptable reading of "this is on offer",
	 * and nothing downstream would catch it -- `WC_Cart::set_price( '0' )`,
	 * the order snapshot and the invoice would all record a free item as if
	 * it were correct. So the offer is discarded for that price instead, the
	 * base price stands, and the caller is told (`discarded`) so it can log
	 * the condition for the administrator to fix.
	 *
	 * @param float $base_price Price before the offer (already > 0).
	 * @param array $offer      Result of `Discount::resolve()`.
	 * @param int   $decimals   Store price decimals.
	 * @return array{price:float, offer:array{percent:float|null,source:string|null}, discarded:bool}
	 */
	private static function apply_offer_safely( $base_price, array $offer, $decimals ) {
		$base_price = (float) $base_price;
		$price      = Discount::apply( $base_price, $offer['percent'], $decimals );

		if ( null !== $offer['percent'] && $price <= 0 ) {
			return array(
				'price'     => $base_price,
				'offer'     => array(
					'percent' => null,
					'source'  => null,
				),
				'discarded' => true,
			);
		}

		return array(
			'price'     => $price,
			'offer'     => $offer,
			'discarded' => false,
		);
	}

	/**
	 * Decorates every already-sorted range row with its resolved offer:
	 * `unit_price` becomes the FINAL price (offer applied), `base_unit_price`
	 * keeps the price as written in the admin, and `discount_percent`/
	 * `discount_source` say whether -- and from where -- an offer reached
	 * that tier.
	 *
	 * Rewriting `unit_price` in place is deliberate: every consumer of this
	 * array (the quantity-to-tier selection right below, the customer-facing
	 * price table, the frontend payload) then works with the real price
	 * without any of them needing to know that offers exist.
	 *
	 * @param array $ranges        Sorted range rows.
	 * @param mixed $rule_discount Offer defined on the rule group, if any.
	 * @param mixed $list_discount Offer defined on the list, if any.
	 * @param int   $decimals      Store price decimals.
	 * @return array<int,array<string,mixed>>
	 */
	private static function apply_offer_to_ranges( array $ranges, $rule_discount, $list_discount, $decimals ) {
		$decorated = array();

		foreach ( $ranges as $range ) {
			$base  = (float) $range['unit_price'];
			$offer = Discount::resolve(
				isset( $range['discount_percent'] ) ? $range['discount_percent'] : null,
				$rule_discount,
				$list_discount
			);

			$priced = self::apply_offer_safely( $base, $offer, $decimals );

			$range['base_unit_price']  = $base;
			$range['unit_price']       = $priced['price'];
			$range['discount_percent'] = $priced['offer']['percent'];
			$range['discount_source']  = $priced['offer']['source'];
			$range['offer_discarded']  = $priced['discarded'];

			$decorated[] = $range;
		}

		return $decorated;
	}
}
