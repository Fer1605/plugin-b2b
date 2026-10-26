<?php
/**
 * Pure offer/discount arithmetic and precedence.
 *
 * An offer is never a pricing mode of its own: it is a percentage that
 * modifies an already-defined price (a `fixed` unit price, or one tier of a
 * `tiered` rule). Three levels can carry one, and the MOST SPECIFIC one
 * wins -- they are never compounded, because a buyer reading "-5% on this
 * tier" and "-20% on this list" would have no way to predict a stacked
 * -24% result:
 *
 *   1. the price range (one tier of a tiered rule)   -- most specific
 *   2. the rule group (a whole product rule)
 *   3. the price list (every rule in the list)       -- least specific
 *
 * Deliberately free of any WordPress/WooCommerce call so it can be covered
 * by fast unit tests -- the same split already established by
 * `RuleGroupValidator`/`PriceResolutionEngine` (pure) vs.
 * `RuleGroupService`/`PriceResolver` (WordPress-aware orchestration). The
 * caller supplies WooCommerce's own configured number of price decimals;
 * this class never invents a rounding convention of its own.
 *
 * @package B2BPriceLists\Domain
 */

namespace B2BPriceLists\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Discount
 */
class Discount {

	/**
	 * The offer was defined on the price range (one tier).
	 *
	 * @var string
	 */
	const SOURCE_RANGE = 'range';

	/**
	 * The offer was defined on the rule group (the whole product rule).
	 *
	 * @var string
	 */
	const SOURCE_RULE = 'rule';

	/**
	 * The offer was defined on the price list (every rule in it).
	 *
	 * @var string
	 */
	const SOURCE_LIST = 'list';

	/**
	 * Number of decimals kept for a stored percentage. Matches the
	 * `decimal(7,4)` columns, so 12.5 and 33.3333 are both expressible.
	 *
	 * @var int
	 */
	const PERCENT_DECIMALS = 4;

	/**
	 * Normalizes a raw percentage into a float, or null when there is no
	 * offer at all.
	 *
	 * "No offer" and "0%" are deliberately the same thing: an empty input,
	 * a zero and a non-numeric value all mean the price stands as written.
	 * A value outside the valid range is NOT silently clamped here -- it
	 * also returns null, and `validate()` is what rejects it with a visible
	 * error message before it can ever reach persistence.
	 *
	 * @param mixed $raw Raw value (string from a form, float from the DB, null).
	 * @return float|null
	 */
	public static function normalize( $raw ) {
		if ( null === $raw || '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}

		$percent = round( (float) $raw, self::PERCENT_DECIMALS );

		if ( $percent <= 0 || $percent >= 100 ) {
			return null;
		}

		return $percent;
	}

	/**
	 * Validates a raw percentage submitted from the admin. An empty value
	 * is always valid (it simply means "no offer"); anything else must be a
	 * number strictly greater than 0 and strictly lower than 100 -- a 100%
	 * offer would produce a zero final price, which every price field in
	 * this plugin already forbids.
	 *
	 * @param mixed  $raw     Raw submitted value.
	 * @param string $context Human-readable field name, used in the message.
	 * @return string[] Translated error messages; empty array means valid.
	 */
	public static function validate( $raw, $context = '' ) {
		if ( null === $raw || '' === trim( (string) $raw ) ) {
			return array();
		}

		// Validation is expressed in terms of `normalize()` itself, never as
		// an independent range check: anything the engine would silently
		// read as "no offer" must be rejected here with a visible message
		// instead. That closes the gap where a value too small to survive
		// rounding (e.g. 0.00001) passed a plain `> 0 && < 100` test, was
		// then normalized to null, and left the admin looking at an offer
		// field they had filled in that quietly did nothing.
		if ( null !== self::normalize( $raw ) ) {
			return array();
		}

		if ( '' !== $context ) {
			return array(
				sprintf(
					/* translators: %s: the name of the offer field being validated. */
					__( '%s: the offer must be a percentage greater than 0 and lower than 100. Leave it empty for no offer.', 'b2b-price-lists' ),
					$context
				),
			);
		}

		return array( __( 'The offer must be a percentage greater than 0 and lower than 100. Leave it empty for no offer.', 'b2b-price-lists' ) );
	}

	/**
	 * Applies the precedence rule: the first level that actually carries an
	 * offer wins, from most specific to least specific. Never adds two
	 * percentages together.
	 *
	 * @param mixed $range_percent Offer defined on the price range, if any.
	 * @param mixed $rule_percent  Offer defined on the rule group, if any.
	 * @param mixed $list_percent  Offer defined on the price list, if any.
	 * @return array{percent:float|null, source:string|null}
	 */
	public static function resolve( $range_percent, $rule_percent, $list_percent ) {
		$candidates = array(
			self::SOURCE_RANGE => self::normalize( $range_percent ),
			self::SOURCE_RULE  => self::normalize( $rule_percent ),
			self::SOURCE_LIST  => self::normalize( $list_percent ),
		);

		foreach ( $candidates as $source => $percent ) {
			if ( null !== $percent ) {
				return array(
					'percent' => $percent,
					'source'  => $source,
				);
			}
		}

		return array(
			'percent' => null,
			'source'  => null,
		);
	}

	/**
	 * Applies a percentage to a base price and rounds the result to the
	 * store's own configured number of price decimals.
	 *
	 * The rounded value is what the buyer is charged, what the cart totals
	 * are built from and what the order snapshot records -- one single
	 * number, computed once here, never recomputed differently downstream.
	 *
	 * @param float|int|string $base_price Price before the offer.
	 * @param float|null       $percent    Already-normalized percentage, or null.
	 * @param int              $decimals   Store price decimals (WooCommerce's own).
	 * @return float The final price. Identical to the base price when there
	 *               is no offer.
	 */
	public static function apply( $base_price, $percent, $decimals = 2 ) {
		$base_price = (float) $base_price;

		if ( null === $percent ) {
			return $base_price;
		}

		$decimals = max( 0, (int) $decimals );

		return round( $base_price * ( 1 - ( (float) $percent / 100 ) ), $decimals );
	}

	/**
	 * Formats a percentage for display, trimming a trailing ".0000" so a
	 * plain "20%" never renders as "20.0000%". Deliberately locale-agnostic
	 * (always a dot): the caller decides whether to localize further.
	 *
	 * @param float|null $percent Already-normalized percentage.
	 * @return string Empty string when there is no offer.
	 */
	public static function format_percent( $percent ) {
		if ( null === $percent ) {
			return '';
		}

		$formatted = rtrim( rtrim( number_format( (float) $percent, self::PERCENT_DECIMALS, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}
}
