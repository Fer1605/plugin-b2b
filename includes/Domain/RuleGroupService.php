<?php
/**
 * Orchestrates saving/deleting rule groups: combines the pure business
 * validation from `RuleGroupValidator` with the WooCommerce/database facts
 * only this layer is allowed to query, and wraps every multi-table write
 * in a `b2bpl_*`-only transaction.
 *
 * @package B2BPriceLists\Domain
 */

namespace B2BPriceLists\Domain;

use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Activity\ChangeSet;
use B2BPriceLists\Database\Transaction;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\PriceRangesRepository;
use B2BPriceLists\Repository\RuleGroupsRepository;
use B2BPriceLists\Repository\RuleGroupVariationsRepository;
use B2BPriceLists\Support\Cache;
use RuntimeException;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RuleGroupService
 */
class RuleGroupService {

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
	 * Lists repository -- read only, to know the offer already set on the
	 * list this rule belongs to (a rule's own price has to be validated
	 * against the percentage that will actually reach it).
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * The side effects (activity log entry + cache invalidation) of the most
	 * recent `save()`/`delete()` call made with `$emit_side_effects = false`,
	 * held here instead of emitted immediately until
	 * the caller -- `Webhook\Operations\{List,Product}Operations` -- has
	 * finished every write for the *whole* webhook item and its enclosing
	 * transaction has actually committed. `null` whenever nothing is pending
	 * (the default, and immediately after `take_pending_side_effects()` reads
	 * it back). Callers making several deferred `save()`/`delete()` calls in
	 * a row must read this back after *each* one (as
	 * `Webhook\Operations\ProductOperations::replace()` does) -- it is a
	 * single slot, not a queue.
	 *
	 * @var array{log: array{action:string,context:array}|null, cache_list_ids:int[]}|null
	 */
	private $pending_side_effects = null;

	/**
	 * Constructor.
	 *
	 * @param RuleGroupsRepository|null          $rule_groups Optional, for tests.
	 * @param RuleGroupVariationsRepository|null $variations  Optional, for tests.
	 * @param PriceRangesRepository|null         $ranges      Optional, for tests.
	 * @param ProductLookup|null                 $products    Optional, for tests.
	 * @param ListsRepository|null               $lists       Optional, for tests.
	 */
	public function __construct(
		?RuleGroupsRepository $rule_groups = null,
		?RuleGroupVariationsRepository $variations = null,
		?PriceRangesRepository $ranges = null,
		?ProductLookup $products = null,
		?ListsRepository $lists = null
	) {
		$this->rule_groups = $rule_groups ?? new RuleGroupsRepository();
		$this->variations  = $variations ?? new RuleGroupVariationsRepository();
		$this->ranges      = $ranges ?? new PriceRangesRepository();
		$this->products    = $products ?? new ProductLookup();
		$this->lists       = $lists ?? new ListsRepository();
	}

	/**
	 * Business validation for a rule group submission (the exact same checks
	 * `save()` always ran before writing anything), extracted on its own
	 * (correction A.1, spec US2 escenario 3/) so a caller that needs to
	 * know *whether several rules would all validate* before writing *any* of
	 * them -- `Webhook\Operations\{ListOperations,ProductOperations}`'s
	 * embedded/`products.upsert` entries -- can call this first, without a
	 * database write, and abort the whole webhook item on the first business
	 * failure instead of discovering it mid-write.
	 *
	 * Still touches the database (product existence/type, the list's own
	 * offer, duplicate-scope and conflicting-variation lookups are all reads
	 * only this layer is allowed to make -- see the class docblock), but
	 * never writes anything, so calling it any number of times has no side
	 * effect at all.
	 *
	 * @param array $input Same shape documented on `save()`.
	 * @return WP_Error|null `null` when the submission is valid.
	 */
	public function validate( array $input ) {
		$rule_group_id = isset( $input['rule_group_id'] ) ? (int) $input['rule_group_id'] : 0;
		$list_id       = (int) $input['list_id'];
		$product_id    = (int) $input['product_id'];
		$scope         = (string) $input['scope'];
		$variation_ids = array_map( 'intval', (array) ( $input['variation_ids'] ?? array() ) );

		if ( ! $this->products->exists( $product_id ) ) {
			return new WP_Error( 'b2bpl_invalid_product', __( 'The selected product could not be found.', 'b2b-price-lists' ) );
		}

		$is_variable = $this->products->is_variable( $product_id );

		// The list's own offer is part of the validation input: a price is
		// only valid if it survives the percentage that will actually reach
		// it, and that may be the list's when neither the rule nor the range
		// defines one.
		$parent_list   = $this->lists->find( $list_id );
		$list_discount = is_array( $parent_list ) ? ( $parent_list['discount_percent'] ?? null ) : null;

		$errors = RuleGroupValidator::validate(
			array(
				'scope'                 => $scope,
				'is_variable_product'   => $is_variable,
				'variation_ids'         => $variation_ids,
				'pricing_mode'          => (string) $input['pricing_mode'],
				'unit_price'            => $input['unit_price'] ?? null,
				'minimum_quantity'      => $input['minimum_quantity'] ?? 1,
				'discount_percent'      => $input['discount_percent'] ?? null,
				'list_discount_percent' => $list_discount,
				'price_decimals'        => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
				'ranges'                => $input['ranges'] ?? array(),
			)
		);

		if ( RuleGroupValidator::SCOPE_VARIATION_GROUP === $scope && ! empty( $variation_ids ) ) {
			// now that a rule's product is editable,
			// this guards against variation ids left over from whatever
			// product was selected before the change (a stale client-side
			// checklist, or a tampered request) ever being attached to the
			// wrong product -- never an orphaned/mismatched relationship.
			$valid_variation_ids   = $this->products->get_variation_ids( $product_id );
			$foreign_variation_ids = array_diff( $variation_ids, $valid_variation_ids );

			if ( ! empty( $foreign_variation_ids ) ) {
				$errors[] = __( 'One or more selected variations do not belong to the selected product. Reselect the variations for this product and try again.', 'b2b-price-lists' );
			}

			$conflicts = $this->variations->find_conflicting_variations( $list_id, $product_id, $variation_ids, $rule_group_id );

			foreach ( $conflicts as $conflict ) {
				$errors[] = sprintf(
					/* translators: 1: variation label/id, 2: conflicting rule group name or id. */
					__( 'Variation #%1$d already belongs to another variation group ("%2$s") in this list. A variation cannot be assigned to more than one variation group per list.', 'b2b-price-lists' ),
					(int) $conflict['variation_id'],
					'' !== $conflict['rule_group_name'] && null !== $conflict['rule_group_name']
						? $conflict['rule_group_name']
						: sprintf( '#%d', (int) $conflict['rule_group_id'] )
				);
			}
		}

		// uniqueness of `simple`/`all_variations` is always evaluated
		// by list_id + product_id together, never by product_id alone -- the
		// same product may legitimately have its own `simple`/`all_variations`
		// rule in several different lists at once. Only one of each scope is
		// allowed within a single list_id+product_id combination.
		if ( in_array( $scope, array( RuleGroupValidator::SCOPE_SIMPLE, RuleGroupValidator::SCOPE_ALL_VARIATIONS ), true ) ) {
			$duplicates = $this->rule_groups->find_duplicate_scope( $list_id, $product_id, $scope, $rule_group_id );

			if ( ! empty( $duplicates ) ) {
				$errors[] = RuleGroupValidator::SCOPE_SIMPLE === $scope
					? __( 'This list already has a "simple product" rule for this product. Only one is allowed per list and product -- edit the existing rule instead of creating another one.', 'b2b-price-lists' )
					: __( 'This list already has an "all variations" rule for this product. Only one is allowed per list and product -- edit the existing rule instead of creating another one.', 'b2b-price-lists' );
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'b2bpl_invalid_rule_group', implode( ' ', array_unique( $errors ) ) );
		}

		return null;
	}

	/**
	 * Validates and persists a rule group (create when `rule_group_id` is
	 * 0/empty, update otherwise).
	 *
	 * @param array $input {
	 *     Rule group submission.
	 *
	 *     @type int      $rule_group_id    0 for create.
	 *     @type int      $list_id
	 *     @type int      $product_id
	 *     @type string   $scope
	 *     @type string   $pricing_mode
	 *     @type float|null $unit_price
	 *     @type int      $minimum_quantity
	 *     @type string   $name
	 *     @type int[]    $variation_ids
	 *     @type array    $ranges
	 * }
	 * @param bool  $emit_side_effects When `true` (default, the panel's
	 *                                  unchanged behavior), the activity log
	 *                                  entry and cache invalidation happen
	 *                                  here, immediately after this rule's
	 *                                  own transaction commits, exactly as
	 *                                  before this parameter existed. When
	 * `false` (used by
	 *                                  `Webhook\Operations\{List,Product}Operations`
	 *                                  to keep a multi-rule webhook item
	 *                                  atomic), both are instead stashed for
	 *                                  the caller to read back with
	 *                                  `take_pending_side_effects()` and emit
	 *                                  itself only once the whole item's own,
	 *                                  wider transaction has committed --
	 *                                  otherwise, an unrelated later product
	 *                                  in the same item failing and rolling
	 *                                  back this rule's DB write would still
	 *                                  leave behind an activity-log entry and
	 *                                  a cache invalidation for a change that
	 *                                  never actually happened.
	 * @return int|WP_Error Rule group id on success.
	 */
	public function save( array $input, $emit_side_effects = true ) {
		$rule_group_id = isset( $input['rule_group_id'] ) ? (int) $input['rule_group_id'] : 0;
		$list_id       = (int) $input['list_id'];
		$product_id    = (int) $input['product_id'];
		$scope         = (string) $input['scope'];
		$variation_ids = array_map( 'intval', (array) ( $input['variation_ids'] ?? array() ) );

		$validation_error = $this->validate( $input );

		if ( null !== $validation_error ) {
			return $validation_error;
		}

		// Re-read: `validate()` only reads it to check the offer against the
		// price, but `build_log_event()` below needs the list row itself.
		$parent_list = $this->lists->find( $list_id );

		$data = array(
			'list_id'          => $list_id,
			'product_id'       => $product_id,
			'scope'            => $scope,
			'pricing_mode'     => (string) $input['pricing_mode'],
			'unit_price'       => RuleGroupValidator::PRICING_MODE_FIXED === $input['pricing_mode']
				? (float) $input['unit_price']
				: null,
			'minimum_quantity' => RuleGroupValidator::PRICING_MODE_FIXED === $input['pricing_mode']
				? max( 1, (int) ( $input['minimum_quantity'] ?? 1 ) )
				: 1,
			// A rule-wide offer applies to both pricing modes: to the fixed
			// unit price, or to every tier of a tiered rule that does not
			// define its own (see `Domain\Discount` for the precedence).
			'discount_percent' => Discount::normalize( $input['discount_percent'] ?? null ),
			'name'             => isset( $input['name'] ) ? (string) $input['name'] : '',
		);

		// `rule_code` (schema v5, webhook-only): only ever written when the
		// caller explicitly provides the key -- the panel's own form never
		// does, so `RuleGroupsRepository::update()`'s `array_key_exists()`
		// gate leaves an existing (always-`NULL`-for-panel-created) value
		// untouched, exactly like `ListsRepository::update()`'s `external_code`.
		if ( array_key_exists( 'rule_code', $input ) ) {
			$data['rule_code'] = $input['rule_code'];
		}

		$ranges = $this->normalize_ranges( (array) ( $input['ranges'] ?? array() ) );

		// Captured before the write, so the log can say what actually moved
		// rather than just "the rule was edited". Saving a rule replaces all
		// of its range rows, so without this snapshot every edit would look
		// like a full rewrite.
		$before = $rule_group_id > 0
			? array(
				'group'      => (array) $this->rule_groups->find( $rule_group_id ),
				'ranges'     => $this->ranges->get_by_rule_group( $rule_group_id ),
				'variations' => $this->variations->get_variation_ids( $rule_group_id ),
			)
			: array(
				'group'      => array(),
				'ranges'     => array(),
				'variations' => array(),
			);

		try {
			// Assigned, not returned: the log entry is written (or stashed)
			// after the transaction commits, so an action that rolled back
			// is never recorded as if it had happened.
			$result = Transaction::run(
				function () use ( $rule_group_id, $data, $scope, $variation_ids, $input, $ranges, $list_id, $emit_side_effects ) {
					if ( $rule_group_id > 0 ) {
						if ( ! $this->rule_groups->update( $rule_group_id, $data ) ) {
							throw new RuntimeException( 'Failed to update rule group.' );
						}
						$id = $rule_group_id;
					} else {
						$id = $this->rule_groups->insert( $data );

						if ( ! $id ) {
							throw new RuntimeException( 'Failed to insert rule group.' );
						}
					}

					if ( RuleGroupValidator::SCOPE_VARIATION_GROUP === $scope ) {
						$this->variations->replace_for_group( $id, $variation_ids );
					} else {
						$this->variations->delete_by_rule_group_id( $id );
					}

					if ( RuleGroupValidator::PRICING_MODE_TIERED === $input['pricing_mode'] ) {
						$this->ranges->replace_for_group( $id, $ranges );
					} else {
						// Never leave stray ranges under a fixed-price group (TC-063).
						$this->ranges->delete_by_rule_group_id( $id );
					}

					if ( $emit_side_effects ) {
						Cache::invalidate_list( $list_id );
					}

					return $id;
				}
			);
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'b2bpl_save_failed', __( 'The rule group could not be saved. No changes were made.', 'b2b-price-lists' ) );
		}

		$log_event = $this->build_log_event( $result, $rule_group_id, $before, $data, $ranges, $variation_ids, $parent_list );

		if ( $emit_side_effects ) {
			if ( null !== $log_event ) {
				ActivityLog::record( $log_event['action'], $log_event['context'] );
			}
		} else {
			$this->pending_side_effects = array(
				'log'            => $log_event,
				'cache_list_ids' => array( $list_id ),
			);
		}

		return $result;
	}

	/**
	 * Reads back and clears the side effects stashed by the most recent
	 * `save()`/`delete()` call made with `$emit_side_effects = false`.
	 *
	 * @return array{log: array{action:string,context:array}|null, cache_list_ids:int[]}|null
	 *               `null` when nothing is pending (either `save()`/`delete()`
	 *               was never called with `$emit_side_effects = false`, or
	 *               this method already consumed the one pending result).
	 */
	public function take_pending_side_effects() {
		$pending                    = $this->pending_side_effects;
		$this->pending_side_effects = null;

		return $pending;
	}

	/**
	 * Builds the activity log event for a saved rule (the exact list of what
	 * changed), without emitting it -- `save()` either records it
	 * immediately or stashes it, depending on `$emit_side_effects`.
	 *
	 * @param int        $id            Saved rule group id.
	 * @param int        $rule_group_id 0 when the rule was just created.
	 * @param array      $before        Pre-save snapshot.
	 * @param array      $data          Rule group row as written.
	 * @param array      $ranges        Ranges as written.
	 * @param array      $variation_ids Variation ids as written.
	 * @param array|null $parent_list   Parent list row.
	 * @return array{action:string,context:array}|null `null` when nothing
	 *               changed (an update that touched nothing worth logging).
	 */
	private function build_log_event( $id, $rule_group_id, array $before, array $data, array $ranges, array $variation_ids, $parent_list ) {
		$after = array(
			'group'      => $data,
			'ranges'     => $ranges,
			'variations' => $variation_ids,
		);

		$changes = ChangeSet::for_rule( $before, $after );

		if ( $rule_group_id > 0 && empty( $changes ) ) {
			return null; // Nothing moved; nothing worth logging.
		}

		if ( 0 === $rule_group_id ) {
			// A creation has no "before": its entries are the values the rule
			// was created with, not a comparison against nothing.
			foreach ( $changes as $index => $change ) {
				$changes[ $index ]['initial'] = true;
			}
		}

		// The product's name is snapshotted into every change that mentions
		// it, so the entry still reads correctly once the product is gone.
		foreach ( $changes as $index => $change ) {
			if ( 'product_id' === ( $change['field'] ?? '' ) ) {
				$changes[ $index ]['from_label'] = $this->products->get_product_label( (int) $change['from'] );
				$changes[ $index ]['to_label']   = $this->products->get_product_label( (int) $change['to'] );
			}

			if ( in_array( $change['field'] ?? '', array( 'variations_added', 'variations_removed' ), true ) ) {
				$changes[ $index ]['labels'] = array_map(
					function ( $variation_id ) {
						return $this->products->get_variation_label( (int) $variation_id );
					},
					(array) $change['ids']
				);
			}
		}

		return array(
			'action'  => $rule_group_id > 0 ? ActivityLog::RULE_UPDATED : ActivityLog::RULE_CREATED,
			'context' => array(
				'object_type'  => 'rule',
				'object_id'    => (int) $id,
				'object_label' => $this->products->get_product_label( (int) $data['product_id'] ),
				'list_id'      => (int) $data['list_id'],
				'list_name'    => is_array( $parent_list ) ? (string) $parent_list['name'] : '',
				'changes'      => $changes,
			),
		);
	}

	/**
	 * Deletes a single rule group and everything that depends on it
	 * (ranges, variation relationships), leaving the rest of the list
	 * intact.
	 *
	 * @param int  $rule_group_id     Rule group id.
	 * @param bool $emit_side_effects Same meaning and default as `save()`'s
	 *                                 own parameter (correction B.2, spec
	 * US2 escenario 3/): `true` (the
	 *                                 panel's unchanged behavior) records the
	 *                                 activity-log entry and invalidates the
	 *                                 cache immediately, inside this call.
	 *                                 `false` stashes both for the caller
	 *                                 (`Webhook\Operations\ProductOperations::replace()`)
	 *                                 to read back with
	 *                                 `take_pending_side_effects()` once its
	 *                                 own wider transaction -- which now also
	 *                                 wraps this deletion, not just the
	 *                                 upsert phase -- has actually committed.
	 * @return bool
	 */
	public function delete( $rule_group_id, $emit_side_effects = true ) {
		$rule_group_id = (int) $rule_group_id;
		$group         = $this->rule_groups->find( $rule_group_id );

		if ( ! $group ) {
			return false;
		}

		$list = $this->lists->find( (int) $group['list_id'] );

		try {
			$deleted = (bool) Transaction::run(
				function () use ( $rule_group_id, $group, $emit_side_effects ) {
					$this->ranges->delete_by_rule_group_id( $rule_group_id );
					$this->variations->delete_by_rule_group_id( $rule_group_id );

					if ( ! $this->rule_groups->delete( $rule_group_id ) ) {
						throw new RuntimeException( 'Failed to delete rule group.' );
					}

					if ( $emit_side_effects ) {
						Cache::invalidate_list( (int) $group['list_id'] );
					}

					return true;
				}
			);
		} catch ( RuntimeException $exception ) {
			return false;
		}

		if ( ! $deleted ) {
			return false;
		}

		$log_event = array(
			'action'  => ActivityLog::RULE_DELETED,
			'context' => array(
				'object_type'  => 'rule',
				'object_id'    => $rule_group_id,
				'object_label' => $this->products->get_product_label( (int) $group['product_id'] ),
				'list_id'      => (int) $group['list_id'],
				'list_name'    => is_array( $list ) ? (string) $list['name'] : '',
			),
		);

		if ( $emit_side_effects ) {
			ActivityLog::record( $log_event['action'], $log_event['context'] );
		} else {
			$this->pending_side_effects = array(
				'log'            => $log_event,
				'cache_list_ids' => array( (int) $group['list_id'] ),
			);
		}

		return $deleted;
	}

	/**
	 * Cleanup hook target: removes every rule group (and its ranges and
	 * variation relationships) tied to a WooCommerce product that has
	 * just been permanently deleted (TC-062).
	 *
	 * @param int $product_id Deleted product id.
	 * @return void
	 */
	public function delete_all_for_product( $product_id ) {
		$product_id     = (int) $product_id;
		$rule_group_ids = $this->rule_groups->get_ids_by_product( $product_id );

		if ( empty( $rule_group_ids ) ) {
			return;
		}

		Transaction::run(
			function () use ( $rule_group_ids, $product_id ) {
				$this->ranges->delete_by_rule_group_ids( $rule_group_ids );
				$this->variations->delete_by_rule_group_ids( $rule_group_ids );
				$this->rule_groups->delete_by_product_id( $product_id );

				return true;
			}
		);
	}

	/**
	 * Normalizes a raw set of range rows into the shape the repository
	 * expects, assigning `sort_order` by ascending `min_qty`.
	 *
	 * @param array $ranges Raw ranges from the request.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_ranges( array $ranges ) {
		usort(
			$ranges,
			static function ( $a, $b ) {
				return (int) ( $a['min_qty'] ?? 0 ) <=> (int) ( $b['min_qty'] ?? 0 );
			}
		);

		$normalized = array();

		foreach ( $ranges as $index => $range ) {
			$normalized[] = array(
				'min_qty'          => (int) $range['min_qty'],
				'max_qty'          => ( isset( $range['max_qty'] ) && '' !== $range['max_qty'] ) ? (int) $range['max_qty'] : null,
				'unit_price'       => (float) $range['unit_price'],
				'discount_percent' => Discount::normalize( $range['discount_percent'] ?? null ),
				'sort_order'       => $index,
			);
		}

		return $normalized;
	}
}
