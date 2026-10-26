<?php
/**
 * Turns a stored activity row into the sentence and the detail lines the
 * screen shows.
 *
 * Formatting happens here, at render time, and never at write time: the log
 * stores machine-readable facts, so an entry recorded months ago still reads
 * correctly in whatever language the site uses today, and a currency or date
 * format change applies retroactively to the whole history.
 *
 * @package B2BPriceLists\Activity
 */

namespace B2BPriceLists\Activity;

use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Domain\RuleGroupValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActivityFormatter
 */
class ActivityFormatter {

	/**
	 * The one-line sentence describing what happened.
	 *
	 * @param array $row Activity row.
	 * @return string Plain text (the caller escapes).
	 */
	public static function summary( array $row ) {
		$object = (string) $row['object_label'];
		$list   = (string) $row['list_name'];

		switch ( (string) $row['action'] ) {
			case ActivityLog::LIST_CREATED:
				/* translators: %s: list name. */
				return sprintf( __( 'Created the price list “%s”', 'b2b-price-lists' ), $list );

			case ActivityLog::LIST_UPDATED:
				/* translators: %s: list name. */
				return sprintf( __( 'Edited the price list “%s”', 'b2b-price-lists' ), $list );

			case ActivityLog::LIST_DUPLICATED:
				/* translators: %s: list name. */
				return sprintf( __( 'Duplicated a price list into “%s”', 'b2b-price-lists' ), $list );

			case ActivityLog::LIST_DELETED:
				/* translators: %s: list name. */
				return sprintf( __( 'Permanently deleted the price list “%s”', 'b2b-price-lists' ), $list );

			case ActivityLog::RULE_CREATED:
				return sprintf(
					/* translators: 1: product label, 2: list name. */
					__( 'Added the product “%1$s” to the list “%2$s”', 'b2b-price-lists' ),
					$object,
					$list
				);

			case ActivityLog::RULE_UPDATED:
				return sprintf(
					/* translators: 1: product label, 2: list name. */
					__( 'Edited the price rule for “%1$s” in the list “%2$s”', 'b2b-price-lists' ),
					$object,
					$list
				);

			case ActivityLog::RULE_DELETED:
				return sprintf(
					/* translators: 1: product label, 2: list name. */
					__( 'Removed the product “%1$s” from the list “%2$s”', 'b2b-price-lists' ),
					$object,
					$list
				);

			case ActivityLog::USER_ASSIGNED:
				return sprintf(
					/* translators: 1: customer name, 2: list name. */
					__( 'Assigned the customer “%1$s” to the list “%2$s”', 'b2b-price-lists' ),
					$object,
					$list
				);

			case ActivityLog::USER_UNASSIGNED:
				return sprintf(
					/* translators: 1: customer name, 2: list name. */
					__( 'Removed the customer “%1$s” from the list “%2$s”', 'b2b-price-lists' ),
					$object,
					$list
				);

			case ActivityLog::PRODUCT_CREATED:
				/* translators: %s: product name and SKU. */
				return sprintf( __( 'Created the product “%s” in the shop', 'b2b-price-lists' ), $object );

			case ActivityLog::PRODUCT_UPDATED:
				/* translators: %s: product name and SKU. */
				return sprintf( __( 'Updated the product “%s” in the shop', 'b2b-price-lists' ), $object );

			case ActivityLog::PRODUCT_DISCONTINUED:
				/* translators: %s: product name and SKU. */
				return sprintf( __( 'Took the product “%s” out of sale', 'b2b-price-lists' ), $object );

			case ActivityLog::SETTINGS_UPDATED:
				return __( 'Changed the plugin settings', 'b2b-price-lists' );
		}

		return (string) $row['action'];
	}

	/**
	 * A short label for the action, used by the filter dropdown and the
	 * per-row tag.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	public static function action_label( $action ) {
		$labels = array(
			ActivityLog::LIST_CREATED         => __( 'List created', 'b2b-price-lists' ),
			ActivityLog::LIST_UPDATED         => __( 'List edited', 'b2b-price-lists' ),
			ActivityLog::LIST_DUPLICATED      => __( 'List duplicated', 'b2b-price-lists' ),
			ActivityLog::LIST_DELETED         => __( 'List deleted', 'b2b-price-lists' ),
			ActivityLog::RULE_CREATED         => __( 'Product added', 'b2b-price-lists' ),
			ActivityLog::RULE_UPDATED         => __( 'Rule edited', 'b2b-price-lists' ),
			ActivityLog::RULE_DELETED         => __( 'Product removed', 'b2b-price-lists' ),
			ActivityLog::USER_ASSIGNED        => __( 'Customer assigned', 'b2b-price-lists' ),
			ActivityLog::USER_UNASSIGNED      => __( 'Customer removed', 'b2b-price-lists' ),
			ActivityLog::PRODUCT_CREATED      => __( 'Product created', 'b2b-price-lists' ),
			ActivityLog::PRODUCT_UPDATED      => __( 'Product updated', 'b2b-price-lists' ),
			ActivityLog::PRODUCT_DISCONTINUED => __( 'Product out of sale', 'b2b-price-lists' ),
			ActivityLog::SETTINGS_UPDATED     => __( 'Settings changed', 'b2b-price-lists' ),
		);

		return $labels[ $action ] ?? $action;
	}

	/**
	 * The tone used to colour an action tag: created/assigned reads as an
	 * addition, deleted/removed as a removal, everything else as a change.
	 *
	 * @param string $action Action name.
	 * @return string 'add', 'remove' or 'edit'.
	 */
	public static function action_tone( $action ) {
		if ( in_array( $action, array( ActivityLog::LIST_CREATED, ActivityLog::LIST_DUPLICATED, ActivityLog::RULE_CREATED, ActivityLog::USER_ASSIGNED, ActivityLog::PRODUCT_CREATED ), true ) ) {
			return 'add';
		}

		if ( in_array( $action, array( ActivityLog::LIST_DELETED, ActivityLog::RULE_DELETED, ActivityLog::USER_UNASSIGNED, ActivityLog::PRODUCT_DISCONTINUED ), true ) ) {
			return 'remove';
		}

		return 'edit';
	}

	/**
	 * The individual "this became that" lines under the sentence.
	 *
	 * @param array $row Activity row.
	 * @return string[] Plain text lines (the caller escapes).
	 */
	public static function details( array $row ) {
		$changes = json_decode( (string) $row['changes'], true );

		if ( ! is_array( $changes ) || empty( $changes ) ) {
			return array();
		}

		$lines = array();

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			$line = self::describe( $change );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Describes one change.
	 *
	 * @param array $change Change entry from `ChangeSet`.
	 * @return string
	 */
	private static function describe( array $change ) {
		$field = (string) ( $change['field'] ?? '' );

		switch ( $field ) {
			case 'range_added':
				return sprintf(
					/* translators: 1: quantity range, 2: unit price with its offer. */
					__( 'Range added: %1$s at %2$s', 'b2b-price-lists' ),
					self::range_span( $change['range'] ?? array() ),
					self::price_with_offer( $change['range'] ?? array() )
				);

			case 'range_removed':
				return sprintf(
					/* translators: 1: quantity range, 2: unit price with its offer. */
					__( 'Range removed: %1$s at %2$s', 'b2b-price-lists' ),
					self::range_span( $change['range'] ?? array() ),
					self::price_with_offer( $change['range'] ?? array() )
				);

			case 'range_updated':
				return sprintf(
					/* translators: 1: quantity range, 2: previous price/offer, 3: new price/offer. */
					__( 'Range %1$s: %2$s → %3$s', 'b2b-price-lists' ),
					self::range_span( $change['range'] ?? array() ),
					self::price_with_offer( $change['from'] ?? array() ),
					self::price_with_offer( $change['to'] ?? array() )
				);

			case 'variations_added':
				return sprintf(
					/* translators: %s: comma-separated variation labels or ids. */
					__( 'Variations added: %s', 'b2b-price-lists' ),
					self::id_list( $change )
				);

			case 'variations_removed':
				return sprintf(
					/* translators: %s: comma-separated variation labels or ids. */
					__( 'Variations removed: %s', 'b2b-price-lists' ),
					self::id_list( $change )
				);

			case 'affected_users':
				return sprintf(
					/* translators: %d: how many customers were returned to standard pricing. */
					_n( '%d customer returned to standard pricing', '%d customers returned to standard pricing', (int) ( $change['count'] ?? 0 ), 'b2b-price-lists' ),
					(int) ( $change['count'] ?? 0 )
				);

			case 'affected_products':
				return sprintf(
					/* translators: %d: how many products lost their rules. */
					_n( '%d product lost its price rules', '%d products lost their price rules', (int) ( $change['count'] ?? 0 ), 'b2b-price-lists' ),
					(int) ( $change['count'] ?? 0 )
				);

			case 'copied_from':
				return sprintf(
					/* translators: %s: name of the list that was copied. */
					__( 'Copied from “%s”', 'b2b-price-lists' ),
					(string) ( $change['label'] ?? '' )
				);

			case 'moved_from_list':
				return sprintf(
					/* translators: %s: name of the list the customer previously belonged to. */
					__( 'Moved from the list “%s”', 'b2b-price-lists' ),
					(string) ( $change['label'] ?? '' )
				);

			case 'role_changed_to':
				return sprintf(
					/* translators: %s: role slug the account was moved to. */
					__( 'Marked as a B2B account (role %s)', 'b2b-price-lists' ),
					(string) ( $change['label'] ?? '' )
				);
		}

		// A creation has nothing to compare against, so its entries read as
		// plain statements of the values it started with -- "Fixed price:
		// Q65.00" rather than the meaningless "(none) → Q65.00".
		if ( ! empty( $change['initial'] ) ) {
			return sprintf(
				/* translators: 1: field name, 2: value it was created with. */
				__( '%1$s: %2$s', 'b2b-price-lists' ),
				self::field_label( $field ),
				self::value( $field, $change['to'] ?? null, $change )
			);
		}

		// Everything else is a plain "field: from → to".
		return sprintf(
			/* translators: 1: field name, 2: previous value, 3: new value. */
			__( '%1$s: %2$s → %3$s', 'b2b-price-lists' ),
			self::field_label( $field ),
			self::value( $field, $change['from'] ?? null, $change ),
			self::value( $field, $change['to'] ?? null, $change )
		);
	}

	/**
	 * Human name of a changed field.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	private static function field_label( $field ) {
		$labels = array(
			'name'             => __( 'Name', 'b2b-price-lists' ),
			'slug'             => __( 'Slug', 'b2b-price-lists' ),
			'description'      => __( 'Description', 'b2b-price-lists' ),
			'status'           => __( 'Status', 'b2b-price-lists' ),
			'discount_percent' => __( 'Offer', 'b2b-price-lists' ),
			'product_id'       => __( 'Product', 'b2b-price-lists' ),
			'scope'            => __( 'Scope', 'b2b-price-lists' ),
			'pricing_mode'     => __( 'Pricing mode', 'b2b-price-lists' ),
			'unit_price'       => __( 'Fixed price', 'b2b-price-lists' ),
			'minimum_quantity' => __( 'Minimum quantity', 'b2b-price-lists' ),
			'position'         => __( 'Position', 'b2b-price-lists' ),
			'card_layout'      => __( 'Price layout on catalogue cards', 'b2b-price-lists' ),
			'single_layout'    => __( 'Price layout on the product page', 'b2b-price-lists' ),
			'title'            => __( 'Title', 'b2b-price-lists' ),
			'qty_header'       => __( 'Quantity column header', 'b2b-price-lists' ),
			'price_header'     => __( 'Price column header', 'b2b-price-lists' ),
			'product_type'     => __( 'Product type', 'b2b-price-lists' ),
			'fields_written'   => __( 'Fields written', 'b2b-price-lists' ),
			'variations'       => __( 'Variations', 'b2b-price-lists' ),
			'images'           => __( 'Images', 'b2b-price-lists' ),
			'categories'       => __( 'Categories', 'b2b-price-lists' ),
			'mode'             => __( 'How it was taken out of sale', 'b2b-price-lists' ),
			'sku'              => __( 'SKU', 'b2b-price-lists' ),
		);

		return $labels[ $field ] ?? $field;
	}

	/**
	 * Human rendering of one value, by field.
	 *
	 * @param string $field  Field key.
	 * @param mixed  $value  Raw value.
	 * @param array  $change Whole change entry, for snapshotted labels.
	 * @return string
	 */
	private static function value( $field, $value, array $change ) {
		if ( 'discount_percent' === $field ) {
			$percent = Discount::normalize( $value );

			return null === $percent
				? __( 'no offer', 'b2b-price-lists' )
				: '-' . Discount::format_percent( $percent ) . '%';
		}

		if ( 'unit_price' === $field ) {
			return ( null === $value || '' === $value )
				? __( '(none)', 'b2b-price-lists' )
				: wp_strip_all_tags( wc_price( (float) $value ) );
		}

		if ( 'status' === $field ) {
			return 'active' === $value
				? __( 'Active', 'b2b-price-lists' )
				: __( 'Inactive', 'b2b-price-lists' );
		}

		if ( 'scope' === $field ) {
			if ( null === $value || '' === $value ) {
				return __( '(empty)', 'b2b-price-lists' );
			}

			$labels = array(
				RuleGroupValidator::SCOPE_SIMPLE          => __( 'Simple product', 'b2b-price-lists' ),
				RuleGroupValidator::SCOPE_ALL_VARIATIONS  => __( 'All variations', 'b2b-price-lists' ),
				RuleGroupValidator::SCOPE_VARIATION_GROUP => __( 'Specific variations', 'b2b-price-lists' ),
			);

			return $labels[ (string) $value ] ?? (string) $value;
		}

		if ( 'pricing_mode' === $field ) {
			if ( null === $value || '' === $value ) {
				return __( '(empty)', 'b2b-price-lists' );
			}

			return RuleGroupValidator::PRICING_MODE_TIERED === $value
				? __( 'Tiered price ranges', 'b2b-price-lists' )
				: __( 'Fixed unit price', 'b2b-price-lists' );
		}

		if ( 'product_id' === $field ) {
			// The service snapshots the product's label alongside the id, so
			// the entry still names the product after it is deleted.
			$key = ( ( $change['from'] ?? null ) === $value ) ? 'from_label' : 'to_label';

			if ( ! empty( $change[ $key ] ) ) {
				return (string) $change[ $key ];
			}

			return $value ? '#' . (int) $value : __( '(none)', 'b2b-price-lists' );
		}

		if ( null === $value || '' === $value ) {
			return __( '(empty)', 'b2b-price-lists' );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'b2b-price-lists' ) : __( 'No', 'b2b-price-lists' );
		}

		return (string) $value;
	}

	/**
	 * "6 to 20 units" / "51+ units", reusing the same wording the customer
	 * sees in the price table.
	 *
	 * @param array $range Range data.
	 * @return string
	 */
	private static function range_span( array $range ) {
		$min = (int) ( $range['min_qty'] ?? 0 );
		$max = ( isset( $range['max_qty'] ) && null !== $range['max_qty'] ) ? (int) $range['max_qty'] : null;

		if ( null === $max ) {
			/* translators: %d: minimum quantity of an open-ended range. */
			return sprintf( __( '%d+ units', 'b2b-price-lists' ), $min );
		}

		if ( $min === $max ) {
			/* translators: %d: exact quantity. */
			return sprintf( __( '%d unit(s)', 'b2b-price-lists' ), $min );
		}

		/* translators: 1: minimum quantity, 2: maximum quantity. */
		return sprintf( __( '%1$d to %2$d units', 'b2b-price-lists' ), $min, $max );
	}

	/**
	 * A price, with its own offer appended when it has one.
	 *
	 * @param array $data Range-shaped data with `unit_price`/`discount_percent`.
	 * @return string
	 */
	private static function price_with_offer( array $data ) {
		$price = isset( $data['unit_price'] ) && null !== $data['unit_price']
			? wp_strip_all_tags( wc_price( (float) $data['unit_price'] ) )
			: __( '(none)', 'b2b-price-lists' );

		$percent = Discount::normalize( $data['discount_percent'] ?? null );

		if ( null === $percent ) {
			return $price;
		}

		return sprintf(
			/* translators: 1: formatted price, 2: offer percentage. */
			__( '%1$s with -%2$s%% offer', 'b2b-price-lists' ),
			$price,
			Discount::format_percent( $percent )
		);
	}

	/**
	 * Renders a list of ids, preferring the labels snapshotted with them.
	 *
	 * @param array $change Change entry with `ids` and optionally `labels`.
	 * @return string
	 */
	private static function id_list( array $change ) {
		if ( ! empty( $change['labels'] ) && is_array( $change['labels'] ) ) {
			return implode( ', ', array_map( 'strval', $change['labels'] ) );
		}

		$ids = array_map( 'intval', (array) ( $change['ids'] ?? array() ) );

		return implode(
			', ',
			array_map(
				static function ( $id ) {
					return '#' . $id;
				},
				$ids
			)
		);
	}
}
