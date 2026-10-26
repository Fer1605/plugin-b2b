<?php
/**
 * Pure "what actually changed" comparison for the activity log.
 *
 * The log is only worth keeping if it says precisely what somebody altered:
 * "edited the rule" is nearly useless, "changed the 21-50 range from Q85.00
 * to Q90.00 and added a 30% offer to it" is what an audit actually needs. So
 * every write in this plugin hands its before/after state here, and gets back
 * a list of individual, machine-readable changes.
 *
 * Deliberately free of any WordPress/database access, so it can be covered by
 * fast unit tests -- the same split already used by `Domain\Discount` and
 * `Domain\PriceResolutionEngine`. It also never produces human sentences:
 * those are built at render time by `Activity\ActivityFormatter`, so a log
 * recorded on a Spanish site still reads correctly if the site later switches
 * language.
 *
 * @package B2BPriceLists\Activity
 */

namespace B2BPriceLists\Activity;

use B2BPriceLists\Domain\Discount;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ChangeSet
 */
class ChangeSet {

	/**
	 * Compares two list rows and returns only the fields that differ.
	 *
	 * @param array $before List row before the edit (empty when creating).
	 * @param array $after  List row after the edit.
	 * @return array<int,array{field:string, from:mixed, to:mixed}>
	 */
	public static function for_list( array $before, array $after ) {
		$fields  = array( 'name', 'slug', 'description', 'status' );
		$changes = array();

		foreach ( $fields as $field ) {
			$from = isset( $before[ $field ] ) ? (string) $before[ $field ] : '';
			$to   = isset( $after[ $field ] ) ? (string) $after[ $field ] : '';

			if ( $from !== $to ) {
				$changes[] = array(
					'field' => $field,
					'from'  => $from,
					'to'    => $to,
				);
			}
		}

		$offer_from = Discount::normalize( $before['discount_percent'] ?? null );
		$offer_to   = Discount::normalize( $after['discount_percent'] ?? null );

		if ( $offer_from !== $offer_to ) {
			$changes[] = array(
				'field' => 'discount_percent',
				'from'  => $offer_from,
				'to'    => $offer_to,
			);
		}

		return $changes;
	}

	/**
	 * Compares two rule groups, including their price ranges and their
	 * variation membership.
	 *
	 * Ranges are matched by their quantity span (`min_qty`-`max_qty`) rather
	 * than by row id, because saving a rule replaces every range row: matching
	 * by id would report "3 ranges removed, 3 ranges added" on an edit that
	 * only moved one price. Matching by span reports what a human actually
	 * did -- one range changed, or one added, or one removed.
	 *
	 * @param array $before {
	 *     State before the edit. Empty arrays when creating.
	 *
	 *     @type array $group      Rule group row.
	 *     @type array $ranges     Price range rows.
	 *     @type int[] $variations Variation ids.
	 * }
	 * @param array $after Same shape, after the edit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_rule( array $before, array $after ) {
		$group_before = isset( $before['group'] ) ? (array) $before['group'] : array();
		$group_after  = isset( $after['group'] ) ? (array) $after['group'] : array();

		$changes = array();

		foreach ( array( 'product_id', 'scope', 'pricing_mode', 'name' ) as $field ) {
			$from = isset( $group_before[ $field ] ) ? (string) $group_before[ $field ] : '';
			$to   = isset( $group_after[ $field ] ) ? (string) $group_after[ $field ] : '';

			if ( $from !== $to ) {
				$changes[] = array(
					'field' => $field,
					'from'  => $from,
					'to'    => $to,
				);
			}
		}

		$changes = array_merge( $changes, self::compare_money( 'unit_price', $group_before, $group_after ) );
		$changes = array_merge( $changes, self::compare_int( 'minimum_quantity', $group_before, $group_after ) );

		$offer_from = Discount::normalize( $group_before['discount_percent'] ?? null );
		$offer_to   = Discount::normalize( $group_after['discount_percent'] ?? null );

		if ( $offer_from !== $offer_to ) {
			$changes[] = array(
				'field' => 'discount_percent',
				'from'  => $offer_from,
				'to'    => $offer_to,
			);
		}

		$changes = array_merge(
			$changes,
			self::compare_ranges(
				isset( $before['ranges'] ) ? (array) $before['ranges'] : array(),
				isset( $after['ranges'] ) ? (array) $after['ranges'] : array()
			)
		);

		$changes = array_merge(
			$changes,
			self::compare_variations(
				isset( $before['variations'] ) ? (array) $before['variations'] : array(),
				isset( $after['variations'] ) ? (array) $after['variations'] : array()
			)
		);

		return $changes;
	}

	/**
	 * Compares two sets of price ranges, keyed by their quantity span.
	 *
	 * @param array $before Range rows before.
	 * @param array $after  Range rows after.
	 * @return array<int,array<string,mixed>>
	 */
	private static function compare_ranges( array $before, array $after ) {
		$by_span_before = self::index_ranges( $before );
		$by_span_after  = self::index_ranges( $after );

		$changes = array();

		foreach ( $by_span_after as $span => $range ) {
			if ( ! isset( $by_span_before[ $span ] ) ) {
				$changes[] = array(
					'field' => 'range_added',
					'range' => $range,
				);

				continue;
			}

			$was = $by_span_before[ $span ];

			$price_changed = self::money( $was['unit_price'] ) !== self::money( $range['unit_price'] );
			$offer_changed = Discount::normalize( $was['discount_percent'] ?? null ) !== Discount::normalize( $range['discount_percent'] ?? null );

			if ( $price_changed || $offer_changed ) {
				$changes[] = array(
					'field' => 'range_updated',
					'range' => $range,
					'from'  => array(
						'unit_price'       => self::money( $was['unit_price'] ),
						'discount_percent' => Discount::normalize( $was['discount_percent'] ?? null ),
					),
					'to'    => array(
						'unit_price'       => self::money( $range['unit_price'] ),
						'discount_percent' => Discount::normalize( $range['discount_percent'] ?? null ),
					),
				);
			}
		}

		foreach ( $by_span_before as $span => $range ) {
			if ( ! isset( $by_span_after[ $span ] ) ) {
				$changes[] = array(
					'field' => 'range_removed',
					'range' => $range,
				);
			}
		}

		return $changes;
	}

	/**
	 * Indexes range rows by their quantity span, normalizing every value so
	 * a DECIMAL string from the database and a float from a form submission
	 * compare equal.
	 *
	 * @param array $ranges Range rows.
	 * @return array<string,array<string,mixed>>
	 */
	private static function index_ranges( array $ranges ) {
		$indexed = array();

		foreach ( $ranges as $range ) {
			$min = (int) ( $range['min_qty'] ?? 0 );
			$max = ( isset( $range['max_qty'] ) && null !== $range['max_qty'] && '' !== $range['max_qty'] )
				? (int) $range['max_qty']
				: null;

			$indexed[ $min . '-' . ( null === $max ? '' : $max ) ] = array(
				'min_qty'          => $min,
				'max_qty'          => $max,
				'unit_price'       => self::money( $range['unit_price'] ?? null ),
				'discount_percent' => Discount::normalize( $range['discount_percent'] ?? null ),
			);
		}

		return $indexed;
	}

	/**
	 * Compares two variation-membership sets.
	 *
	 * @param array $before Variation ids before.
	 * @param array $after  Variation ids after.
	 * @return array<int,array<string,mixed>>
	 */
	private static function compare_variations( array $before, array $after ) {
		$before = array_map( 'intval', $before );
		$after  = array_map( 'intval', $after );

		$added   = array_values( array_diff( $after, $before ) );
		$removed = array_values( array_diff( $before, $after ) );

		$changes = array();

		if ( ! empty( $added ) ) {
			$changes[] = array(
				'field' => 'variations_added',
				'ids'   => $added,
			);
		}

		if ( ! empty( $removed ) ) {
			$changes[] = array(
				'field' => 'variations_removed',
				'ids'   => $removed,
			);
		}

		return $changes;
	}

	/**
	 * Compares one monetary field.
	 *
	 * @param string $field  Field name.
	 * @param array  $before Row before.
	 * @param array  $after  Row after.
	 * @return array<int,array<string,mixed>>
	 */
	private static function compare_money( $field, array $before, array $after ) {
		$from = self::money( $before[ $field ] ?? null );
		$to   = self::money( $after[ $field ] ?? null );

		if ( $from === $to ) {
			return array();
		}

		return array(
			array(
				'field' => $field,
				'from'  => $from,
				'to'    => $to,
			),
		);
	}

	/**
	 * Compares one integer field.
	 *
	 * @param string $field  Field name.
	 * @param array  $before Row before.
	 * @param array  $after  Row after.
	 * @return array<int,array<string,mixed>>
	 */
	private static function compare_int( $field, array $before, array $after ) {
		$from = isset( $before[ $field ] ) ? (int) $before[ $field ] : null;
		$to   = isset( $after[ $field ] ) ? (int) $after[ $field ] : null;

		if ( $from === $to ) {
			return array();
		}

		return array(
			array(
				'field' => $field,
				'from'  => $from,
				'to'    => $to,
			),
		);
	}

	/**
	 * Normalizes a monetary value to a float, or null.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	private static function money( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return round( (float) $value, 4 );
	}
}
