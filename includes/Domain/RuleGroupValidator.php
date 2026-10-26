<?php
/**
 * Pure business-rule validation for rule groups, prices and ranges.
 *
 * Deliberately free of any WordPress/database access, so it can be
 * covered by fast unit tests: all product-type/duplicate-variation facts
 * are passed in by the caller (`Domain\RuleGroupService`), which is the
 * only place allowed to query WordPress/WooCommerce or the repositories.
 *
 * @package B2BPriceLists\Domain
 */

namespace B2BPriceLists\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RuleGroupValidator
 */
class RuleGroupValidator {

	const SCOPE_SIMPLE          = 'simple';
	const SCOPE_ALL_VARIATIONS  = 'all_variations';
	const SCOPE_VARIATION_GROUP = 'variation_group';

	const PRICING_MODE_FIXED  = 'fixed';
	const PRICING_MODE_TIERED = 'tiered';

	/**
	 * Validates a rule group submission as a whole.
	 *
	 * @param array $input {
	 *     Rule group submission.
	 *
	 *     @type string     $scope               One of the SCOPE_* constants.
	 *     @type bool        $is_variable_product Whether the parent product is variable.
	 *     @type int[]      $variation_ids       Only relevant for 'variation_group'.
	 *     @type string     $pricing_mode        One of the PRICING_MODE_* constants.
	 *     @type float|null $unit_price          Only relevant for 'fixed'.
	 *     @type int        $minimum_quantity    Only relevant for 'fixed'.
	 *     @type mixed      $discount_percent    Optional rule-wide offer (empty = none).
	 *     @type mixed      $list_discount_percent Offer already set on the list, if any.
	 *     @type int        $price_decimals      Store price decimals (default 2).
	 *     @type array      $ranges              Only relevant for 'tiered'.
	 * }
	 * @return string[] Translated error messages; empty array means valid.
	 */
	public static function validate( array $input ) {
		$errors = array();

		$scope               = isset( $input['scope'] ) ? (string) $input['scope'] : '';
		$is_variable_product = ! empty( $input['is_variable_product'] );
		$variation_ids       = isset( $input['variation_ids'] ) ? (array) $input['variation_ids'] : array();
		$pricing_mode        = isset( $input['pricing_mode'] ) ? (string) $input['pricing_mode'] : '';

		$errors = array_merge( $errors, self::validate_scope( $scope, $is_variable_product, $variation_ids ) );
		$errors = array_merge( $errors, self::validate_pricing_mode( $pricing_mode, $input ) );
		$errors = array_merge(
			$errors,
			Discount::validate(
				$input['discount_percent'] ?? null,
				__( 'Offer for this rule', 'b2b-price-lists' )
			)
		);

		return $errors;
	}

	/**
	 * Validates `scope` against the real product type (TC: simple scope on
	 * a variable product, or variation_group/all_variations on a simple
	 * product, must both be rejected).
	 *
	 * @param string $scope               Requested scope.
	 * @param bool   $is_variable_product Whether the parent product is variable.
	 * @param array  $variation_ids       Variation ids selected (variation_group only).
	 * @return string[]
	 */
	public static function validate_scope( $scope, $is_variable_product, array $variation_ids ) {
		$errors = array();

		$valid_scopes = array( self::SCOPE_SIMPLE, self::SCOPE_ALL_VARIATIONS, self::SCOPE_VARIATION_GROUP );

		if ( ! in_array( $scope, $valid_scopes, true ) ) {
			$errors[] = __( 'Invalid rule scope.', 'b2b-price-lists' );

			return $errors;
		}

		if ( self::SCOPE_SIMPLE === $scope && $is_variable_product ) {
			$errors[] = __( 'The "simple product" scope cannot be used on a variable product. Use "all variations" or a variation group instead.', 'b2b-price-lists' );
		}

		if ( self::SCOPE_SIMPLE !== $scope && ! $is_variable_product ) {
			$errors[] = __( '"All variations" and "variation group" scopes can only be used on a variable product.', 'b2b-price-lists' );
		}

		if ( self::SCOPE_VARIATION_GROUP === $scope && empty( $variation_ids ) ) {
			$errors[] = __( 'Select at least one variation for a variation group rule.', 'b2b-price-lists' );
		}

		return $errors;
	}

	/**
	 * Validates the pricing fields for the declared `pricing_mode`
	 * (fixed and tiered are first-class, mutually exclusive
	 * modes -- a fixed price is never stored as a "1+" range, and a tiered
	 * group never populates `unit_price`/`minimum_quantity`).
	 *
	 * @param string $pricing_mode One of the PRICING_MODE_* constants.
	 * @param array  $input        Same shape documented in validate().
	 * @return string[]
	 */
	public static function validate_pricing_mode( $pricing_mode, array $input ) {
		$errors = array();

		if ( ! in_array( $pricing_mode, array( self::PRICING_MODE_FIXED, self::PRICING_MODE_TIERED ), true ) ) {
			$errors[] = __( 'Invalid pricing mode.', 'b2b-price-lists' );

			return $errors;
		}

		$rule_discount = isset( $input['discount_percent'] ) ? $input['discount_percent'] : null;
		$list_discount = isset( $input['list_discount_percent'] ) ? $input['list_discount_percent'] : null;
		$decimals      = isset( $input['price_decimals'] ) ? (int) $input['price_decimals'] : 2;

		if ( self::PRICING_MODE_FIXED === $pricing_mode ) {
			$unit_price       = isset( $input['unit_price'] ) ? $input['unit_price'] : null;
			$minimum_quantity = isset( $input['minimum_quantity'] ) ? (int) $input['minimum_quantity'] : 1;

			if ( null === $unit_price || '' === $unit_price || ! is_numeric( $unit_price ) || (float) $unit_price <= 0 ) {
				// unit_price must be strictly greater than zero.
				$errors[] = __( 'The unit price must be greater than zero.', 'b2b-price-lists' );
			} else {
				$errors = array_merge(
					$errors,
					self::validate_offer_keeps_price_above_zero(
						$unit_price,
						Discount::resolve( null, $rule_discount, $list_discount ),
						$decimals,
						__( 'The unit price', 'b2b-price-lists' )
					)
				);
			}

			if ( $minimum_quantity < 1 ) {
				$errors[] = __( 'The minimum quantity must be at least 1.', 'b2b-price-lists' );
			}
		} else {
			$ranges = isset( $input['ranges'] ) ? (array) $input['ranges'] : array();
			$errors = array_merge( $errors, self::validate_ranges( $ranges, $rule_discount, $list_discount, $decimals ) );
		}

		return $errors;
	}

	/**
	 * applied to the price the buyer actually pays, not just to the
	 * price as written: a percentage under 100 can still round a small base
	 * price down to zero (0.40 at -99% is 0.00 at two decimals), and a free
	 * item would then flow untouched into the cart, the order and the
	 * invoice. Rejected at save time, with the arithmetic spelled out, so the
	 * administrator can fix the percentage instead of discovering it in an
	 * order.
	 *
	 * @param float|string $base_price Price as written (already > 0).
	 * @param array        $offer      Result of `Discount::resolve()`.
	 * @param int          $decimals   Store price decimals.
	 * @param string       $label      Human-readable name of the price being checked.
	 * @return string[]
	 */
	private static function validate_offer_keeps_price_above_zero( $base_price, array $offer, $decimals, $label ) {
		if ( null === $offer['percent'] ) {
			return array();
		}

		if ( Discount::apply( $base_price, $offer['percent'], $decimals ) > 0 ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: 1: name of the price field, 2: offer percentage. */
				__( '%1$s is too low for a %2$s%% offer: it would leave the final price at zero. Lower the percentage or raise the price.', 'b2b-price-lists' ),
				$label,
				Discount::format_percent( $offer['percent'] )
			),
		);
	}

	/**
	 * Validates a full set of tiered price ranges: at least one range
	 * (RI2-M3), `min_qty` >= 1, `unit_price` > 0, no overlaps, no
	 * gaps, `max_qty` never less than its own `min_qty`, and only the last
	 * range (by `min_qty`) may be open (`max_qty = null`).
	 *
	 * @param array $ranges        Each item: min_qty, max_qty (nullable),
	 *                              unit_price, discount_percent (nullable).
	 * @param mixed $rule_discount  Offer set on the rule group, if any.
	 * @param mixed $list_discount  Offer set on the list, if any.
	 * @param int   $decimals       Store price decimals.
	 * @return string[]
	 */
	public static function validate_ranges( array $ranges, $rule_discount = null, $list_discount = null, $decimals = 2 ) {
		$errors = array();

		if ( empty( $ranges ) ) {
			$errors[] = __( 'A tiered pricing group requires at least one price range.', 'b2b-price-lists' );

			return $errors;
		}

		foreach ( $ranges as $index => $range ) {
			$position   = $index + 1;
			$min_qty    = isset( $range['min_qty'] ) ? $range['min_qty'] : null;
			$max_qty    = ( isset( $range['max_qty'] ) && '' !== $range['max_qty'] ) ? $range['max_qty'] : null;
			$unit_price = isset( $range['unit_price'] ) ? $range['unit_price'] : null;

			if ( ! is_numeric( $min_qty ) || (int) $min_qty < 1 ) {
				/* translators: %d: range position (1-based). */
				$errors[] = sprintf( __( 'Range #%d: the minimum quantity must be at least 1.', 'b2b-price-lists' ), $position );
			}

			if ( null !== $max_qty && ( ! is_numeric( $max_qty ) || (int) $max_qty < (int) $min_qty ) ) {
				/* translators: %d: range position (1-based). */
				$errors[] = sprintf( __( 'Range #%d: the maximum quantity cannot be lower than its minimum quantity.', 'b2b-price-lists' ), $position );
			}

			if ( null === $unit_price || '' === $unit_price || ! is_numeric( $unit_price ) || (float) $unit_price <= 0 ) {
				/* translators: %d: range position (1-based). */
				$errors[] = sprintf( __( 'Range #%d: the unit price must be greater than zero.', 'b2b-price-lists' ), $position );
			} else {
				$errors = array_merge(
					$errors,
					self::validate_offer_keeps_price_above_zero(
						$unit_price,
						Discount::resolve( $range['discount_percent'] ?? null, $rule_discount, $list_discount ),
						$decimals,
						/* translators: %d: range position (1-based). */
						sprintf( __( 'The unit price of range #%d', 'b2b-price-lists' ), $position )
					)
				);
			}

			$errors = array_merge(
				$errors,
				Discount::validate(
					$range['discount_percent'] ?? null,
					/* translators: %d: range position (1-based). */
					sprintf( __( 'Range #%d offer', 'b2b-price-lists' ), $position )
				)
			);
		}

		if ( ! empty( $errors ) ) {
			// Do not attempt overlap/gap analysis on structurally invalid ranges.
			return $errors;
		}

		$sorted = $ranges;
		usort(
			$sorted,
			static function ( $a, $b ) {
				return (int) $a['min_qty'] <=> (int) $b['min_qty'];
			}
		);

		$count = count( $sorted );

		for ( $i = 0; $i < $count; $i++ ) {
			$current     = $sorted[ $i ];
			$current_max = ( isset( $current['max_qty'] ) && '' !== $current['max_qty'] ) ? (int) $current['max_qty'] : null;
			$is_last     = ( $count - 1 ) === $i;

			if ( null === $current_max && ! $is_last ) {
				$errors[] = __( 'Only the last price range (by minimum quantity) may be left open-ended.', 'b2b-price-lists' );
				break;
			}

			if ( ! $is_last ) {
				$next          = $sorted[ $i + 1 ];
				$next_min      = (int) $next['min_qty'];
				$expected_next = $current_max + 1;

				if ( $next_min === (int) $current['min_qty'] ) {
					$errors[] = __( 'Two price ranges cannot share the same minimum quantity.', 'b2b-price-lists' );
					break;
				}

				if ( $next_min < $expected_next ) {
					$errors[] = __( 'Price ranges cannot overlap.', 'b2b-price-lists' );
					break;
				}

				if ( $next_min > $expected_next ) {
					$errors[] = __( 'Price ranges cannot leave a gap in quantity coverage.', 'b2b-price-lists' );
					break;
				}
			}
		}

		return $errors;
	}

	/**
	 * Pure predicate for within an already-fetched set of rule
	 * group rows, finds every OTHER row that shares the same `list_id` +
	 * `product_id` + `scope`. Uniqueness of `simple`/`all_variations` is
	 * evaluated **only** by this combination -- never by `product_id`
	 * alone, so the same product can have its own independent `simple`/
	 * `all_variations` rule in several different lists at once without
	 * conflict.
	 *
	 * Used two ways: (1) `Domain\RuleGroupService::save()` calls the
	 * equivalent targeted SQL query (`RuleGroupsRepository::find_duplicate_scope()`)
	 * for efficiency at write time; this pure version exists so the exact
	 * same invariant is directly unit-testable without a database, and is
	 * reused by the price resolution engine (`PriceResolutionEngine`) to
	 * detect corrupt data (more than one `simple`/`all_variations` for the
	 * same `list_id`+`product_id`) at read/resolution time.
	 *
	 * @param array<int,array<string,mixed>> $groups           Candidate rows,
	 *                                                          each with at
	 *                                                          least id,
	 *                                                          list_id,
	 *                                                          product_id,
	 *                                                          scope.
	 * @param int                            $list_id          List id.
	 * @param int                            $product_id       Parent product id.
	 * @param string                         $scope            'simple' or 'all_variations'.
	 * @param int                            $exclude_group_id Rule group id to
	 *                                                         exclude (the one
	 *                                                         currently being
	 *                                                         saved), 0 when
	 *                                                         creating.
	 * @return array<int,array<string,mixed>> Conflicting rows, if any.
	 */
	public static function find_scope_conflicts( array $groups, $list_id, $product_id, $scope, $exclude_group_id = 0 ) {
		$conflicts = array();

		foreach ( $groups as $group ) {
			if ( (int) ( $group['list_id'] ?? 0 ) !== (int) $list_id ) {
				continue;
			}

			if ( (int) ( $group['product_id'] ?? 0 ) !== (int) $product_id ) {
				continue;
			}

			if ( (string) ( $group['scope'] ?? '' ) !== (string) $scope ) {
				continue;
			}

			if ( (int) ( $group['id'] ?? 0 ) === (int) $exclude_group_id ) {
				continue;
			}

			$conflicts[] = $group;
		}

		return $conflicts;
	}
}
