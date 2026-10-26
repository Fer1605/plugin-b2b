<?php
/**
 * Maps `list.create|update|upsert|delete|duplicate` (contracts/webhook-api.md,
 *) onto `Domain\ListService`/`Domain\RuleGroupService`, applying
 * exactly the panel's own validations -- this class
 * adds no business rule of its own.
 *
 * Correction A.1: `list.create`/`list.upsert`'s
 * optional embedded `products[]` distinguish two, deliberately different,
 * kinds of per-entry failure -- see `Webhook\Operations\ProductOperations`'s
 * class docblock for the full rationale, shared verbatim here via
 * `ProductOperations::resolve_rule_identity()`/`build_rule_input()`.
 *
 * - A *resolution* failure only fails that one entry (`errors[]`), the list
 *   mutation and every other entry are unaffected.
 * - A *business-rule* failure (`Domain\RuleGroupService::validate()`) aborts
 *   the *whole* item: nothing is written, not even the list itself for
 * `list.create`. `resolve_and_validate_embedded_products`
 *   therefore resolves and validates -- but never writes -- every entry
 *   first, throwing (uncaught) on the first business failure; only once
 *   every entry has cleared validation does `write_validated_products()`
 *   actually persist them, with side effects deferred until its own
 *   transaction commits.
 *
 * `create()` wraps the list creation itself, the validation pass and the
 * write pass in one transaction (a business failure rolls back the list
 * creation too, since `Domain\ListService::create()`'s only side effect --
 * an activity-log row -- is itself a `b2bpl_*` table write and therefore
 * rolls back with everything else). `upsert()` on an *existing* list instead
 * validates first and only then calls `Domain\ListService::update_partial()`,
 * because that method (unlike `RuleGroupService::save()`) invalidates the
 * cache immediately and unconditionally, with no deferred-effects mode of its
 * own -- wrapping it in a transaction that might still roll back afterward
 * would not undo that invalidation.
 *
 * Every public method either returns the item's `result`/`external_ref`
 * payload on success, or throws `ItemFailure` (caught by
 * `Webhook\BatchProcessor`, which is also where each item's outermost
 * `Database\Transaction::run()` wrapper lives).
 *
 * @package B2BPriceLists\Webhook\Operations
 */

namespace B2BPriceLists\Webhook\Operations;

use B2BPriceLists\Database\Transaction;
use B2BPriceLists\Domain\ListService;
use B2BPriceLists\Domain\ProductLookup;
use B2BPriceLists\Domain\RuleGroupService;
use B2BPriceLists\Domain\RuleGroupValidator;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\PriceRangesRepository;
use B2BPriceLists\Repository\RuleGroupsRepository;
use B2BPriceLists\Webhook\Resolver\SkuResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ListOperations
 */
class ListOperations {

	/**
	 * List CRUD/duplication/cascading-deletion service.
	 *
	 * @var ListService
	 */
	private $lists_service;

	/**
	 * Rule group persistence service, reused for `list.create`/`list.upsert`'s
	 * optional embedded `products[]`.
	 *
	 * @var RuleGroupService
	 */
	private $rule_group_service;

	/**
	 * Lists repository (read: `external_code` lookups).
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * Rule groups repository (read: existing-rule lookups for embedded
	 * `products[]`, shared with `ProductOperations`' own instance).
	 *
	 * @var RuleGroupsRepository
	 */
	private $rule_groups;

	/**
	 * SKU resolver, shared with `ProductOperations` via the same signature.
	 *
	 * @var SkuResolver
	 */
	private $sku_resolver;

	/**
	 * Price ranges repository (read: `list.delete`'s deletion snapshot,
	 *).
	 *
	 * @var PriceRangesRepository
	 */
	private $ranges;

	/**
	 * WooCommerce product/variation lookup helper (read: a rule's own SKU,
	 * for `list.delete`'s deletion snapshot).
	 *
	 * @var ProductLookup
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param ListService|null           $lists_service      Optional, for tests.
	 * @param RuleGroupService|null      $rule_group_service Optional, for tests.
	 * @param ListsRepository|null       $lists              Optional, for tests.
	 * @param SkuResolver|null           $sku_resolver       Optional, for tests.
	 * @param RuleGroupsRepository|null  $rule_groups        Optional, for tests.
	 * @param PriceRangesRepository|null $ranges             Optional, for tests.
	 * @param ProductLookup|null         $products           Optional, for tests.
	 */
	public function __construct(
		?ListService $lists_service = null,
		?RuleGroupService $rule_group_service = null,
		?ListsRepository $lists = null,
		?SkuResolver $sku_resolver = null,
		?RuleGroupsRepository $rule_groups = null,
		?PriceRangesRepository $ranges = null,
		?ProductLookup $products = null
	) {
		$this->lists_service      = $lists_service ?? new ListService();
		$this->rule_group_service = $rule_group_service ?? new RuleGroupService();
		$this->lists              = $lists ?? new ListsRepository();
		$this->sku_resolver       = $sku_resolver ?? new SkuResolver();
		$this->rule_groups        = $rule_groups ?? new RuleGroupsRepository();
		$this->ranges             = $ranges ?? new PriceRangesRepository();
		$this->products           = $products ?? new ProductLookup();
	}

	/**
	 * `list.create` (contract: `external_code`* required and unique, `name`*,
	 * `description`, `status`, `discount_percent`, optional `products[]`).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string}
	 * @throws ItemFailure On `external_code_required` (missing/blank).
	 * @throws ItemFailure On `list_exists` (the `external_code` already
	 *                      belongs to another list -- unchanged for `list.create`,
	 *).
	 * @throws ItemFailure On `invalid_list` (the panel's own validation rejected it).
	 * @throws ItemFailure On `invalid_rule` (a business-rule failure in an
	 *                      embedded product, uncaught here on purpose --
	 *                      escenario 3, rolls back the list creation too).
	 */
	public function create( array $data ) {
		$external_code = self::require_string( $data, 'external_code', 'external_code_required' );

		if ( null !== $this->lists->find_by_external_code( $external_code ) ) {
			throw new ItemFailure(
				'list_exists',
				sprintf(
					/* translators: %s: external code submitted by the caller. */
					__( 'A list with the external code "%s" already exists.', 'b2b-price-lists' ),
					$external_code
				),
				'data.external_code'
			);
		}

		$products = isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();

		$outcome = Transaction::run(
			function () use ( $data, $external_code, $products ) {
				$result = $this->lists_service->create(
					array(
						'name'             => $data['name'] ?? '',
						'description'      => $data['description'] ?? '',
						'status'           => $data['status'] ?? ListService::STATUS_INACTIVE,
						'discount_percent' => $data['discount_percent'] ?? null,
						'external_code'    => $external_code,
					)
				);

				if ( is_wp_error( $result ) ) {
					throw new ItemFailure( 'invalid_list', $result->get_error_message() );
				}

				$list_id = (int) $result;

				// Throws (uncaught) on the first business-rule failure,
				// rolling back the list creation above too -- see the class
				// docblock.
				$prepared = $this->resolve_and_validate_embedded_products( $list_id, $products );

				$write_result = $this->write_validated_products( $prepared['to_write'] );

				return array(
					'list_id'         => $list_id,
					'errors'          => $prepared['errors'],
					'saved'           => $write_result['saved'],
					'pending_effects' => $write_result['pending_effects'],
				);
			}
		);

		// M2 (independent review of W4/W5): never flushed here -- this
		// method's own `Transaction::run()` above is only ever a *nested*
		// `SAVEPOINT` when called from `Webhook\BatchProcessor::process_item()`
		// (the real, outermost transaction for this whole item). Flushing
		// immediately after a `SAVEPOINT` is released, rather than after the
		// real `COMMIT`, would emit an activity-log entry/cache invalidation
		// for a write that a *later* failure in the very same item could
		// still roll back. `pending_effects` is instead returned to
		// `Webhook\BatchProcessor::process_item()`, which flushes it only
		// once its own, truly outermost `Transaction::run()` call has
		// returned successfully.
		return array(
			'result'          => array(
				'list_id'       => $outcome['list_id'],
				'external_code' => $external_code,
				'rules_created' => $outcome['saved'],
			),
			'external_ref'    => $external_code,
			'status'          => self::embedded_products_status( $outcome['saved'], $outcome['errors'] ),
			'errors'          => $outcome['errors'],
			'pending_effects' => $outcome['pending_effects'],
		);
	}

	/**
	 * `list.update` (contract: partial -- only the submitted fields change;
	 * `products` is not accepted here, per contract, use `products.*`).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string}
	 * @throws ItemFailure On `list_not_found`/`invalid_list`.
	 * @throws ItemFailure On `products_not_allowed` (`products` is
	 *                      only accepted by `list.create`/`list.upsert`; use
	 *                      `products.*` instead) -- checked before anything
	 *                      is read or written, so a caller cannot smuggle a
	 *                      products change through `list.update` by mistake.
	 */
	public function update( array $data ) {
		if ( array_key_exists( 'products', $data ) ) {
			throw new ItemFailure(
				'products_not_allowed',
				__( '"products" is not accepted by "list.update"; use "products.upsert"/"products.remove"/"products.replace" instead.', 'b2b-price-lists' ),
				'data.products'
			);
		}

		$external_code = self::require_string( $data, 'external_code', 'list_not_found' );
		$list          = $this->find_list_or_fail( $external_code );

		$fields = array();

		foreach ( array( 'name', 'description', 'status', 'discount_percent' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = $data[ $field ];
			}
		}

		$result = $this->lists_service->update_partial( (int) $list['id'], $fields );

		if ( is_wp_error( $result ) ) {
			throw new ItemFailure( 'invalid_list', $result->get_error_message() );
		}

		return array(
			'result'       => array(
				'list_id'       => (int) $list['id'],
				'external_code' => $external_code,
			),
			'external_ref' => $external_code,
		);
	}

	/**
	 * `list.upsert` (contract: creates when the `external_code` does not
	 * exist yet, otherwise updates the submitted fields -- `products[]` is
	 * accepted either way, exactly like `list.create`).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string}
	 * @throws ItemFailure On `invalid_list`, or on the first business-rule
	 *                      failure of an embedded product (uncaught here on
	 *                      purpose -- escenario 3; see the class docblock for
	 *                      why an existing list validates its embedded
	 *                      products *before* touching any of its own fields).
	 */
	public function upsert( array $data ) {
		$external_code = self::require_string( $data, 'external_code', 'external_code_required' );
		$existing      = $this->lists->find_by_external_code( $external_code );

		if ( null === $existing ) {
			return $this->create( $data );
		}

		$list_id  = (int) $existing['id'];
		$products = isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();

		// Validate everything first -- see the class docblock: unlike
		// `create()`, this method cannot rely on a transaction rollback to
		// undo `update_partial()`'s side effects.
		$prepared = $this->resolve_and_validate_embedded_products( $list_id, $products );

		$fields = array();

		foreach ( array( 'name', 'description', 'status', 'discount_percent' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = $data[ $field ];
			}
		}

		$result = $this->lists_service->update_partial( $list_id, $fields );

		if ( is_wp_error( $result ) ) {
			throw new ItemFailure( 'invalid_list', $result->get_error_message() );
		}

		$write_result = $this->write_validated_products( $prepared['to_write'] );

		// M2: not flushed here -- see `create()`'s own comment. Returned as
		// `pending_effects` for `Webhook\BatchProcessor::process_item()` to
		// flush once its own, truly outermost transaction has committed.
		return array(
			'result'          => array(
				'list_id'       => $list_id,
				'external_code' => $external_code,
				'rules_created' => $write_result['saved'],
			),
			'external_ref'    => $external_code,
			'status'          => self::embedded_products_status( $write_result['saved'], $prepared['errors'] ),
			'errors'          => $prepared['errors'],
			'pending_effects' => $write_result['pending_effects'],
		);
	}

	/**
	 * `list.delete` (contract: "elimina como el panel"; `result` carries a
	 * snapshot of what was removed, spec US2 escenario 5).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string}
	 * @throws ItemFailure On `list_not_found`/`invalid_list`.
	 */
	public function delete( array $data ) {
		$external_code = self::require_string( $data, 'external_code', 'list_not_found' );
		$list          = $this->find_list_or_fail( $external_code );

		$counts = $this->lists_service->get_affected_counts( (int) $list['id'] );
		// Read before the write: once `delete()` below returns, every rule
		// group/range row is gone -- the snapshot exists only because it was
		// captured first.
		$rules_snapshot = $this->snapshot_rules_for_list( (int) $list['id'] );

		$result = $this->lists_service->delete( (int) $list['id'] );

		if ( is_wp_error( $result ) ) {
			throw new ItemFailure( 'invalid_list', $result->get_error_message() );
		}

		return array(
			'result'       => self::build_deletion_snapshot( $list, $rules_snapshot, $counts['users'] ),
			'external_ref' => $external_code,
		);
	}

	/**
	 * Pure assembly of `list.delete`'s `result` snapshot from
	 * already-fetched facts -- never a database/WooCommerce call itself,
	 * directly unit-testable.
	 *
	 * @param array<string,mixed>            $list_row         Full list row, read before deletion.
	 * @param array<int,array<string,mixed>> $rules            `snapshot_rules_for_list()`'s result.
	 * @param int $unassigned_users Users this deletion unassigned.
	 * @return array<string,mixed>
	 */
	public static function build_deletion_snapshot( array $list_row, array $rules, $unassigned_users ) {
		return array(
			'list'              => array(
				'id'               => (int) $list_row['id'],
				'external_code'    => isset( $list_row['external_code'] ) ? (string) $list_row['external_code'] : '',
				'name'             => (string) $list_row['name'],
				'status'           => (string) $list_row['status'],
				'discount_percent' => array_key_exists( 'discount_percent', $list_row ) ? $list_row['discount_percent'] : null,
			),
			'rules'             => $rules,
			'unassigned_users'  => (int) $unassigned_users,
			// Kept alongside the richer snapshot above for any existing
			// consumer of the pre- shape (the panel's own
			// confirmation screen reports the same two numbers separately).
			'affected_users'    => (int) $unassigned_users,
			'affected_products' => count( $rules ),
		);
	}

	/**
	 * Reads every rule group of a list (and its ranges, when `tiered`) into
	 * the plain shape `build_deletion_snapshot()` embeds -- the one place
	 * this class resolves a rule's SKU from its `product_id` (`ProductLookup::get_sku()`),
	 * since a deleted rule group's own row keeps no SKU of its own (only
	 * `product_id`).
	 *
	 * @param int $list_id List id.
	 * @return array<int,array<string,mixed>>
	 */
	private function snapshot_rules_for_list( $list_id ) {
		$rules = array();

		foreach ( $this->rule_groups->get_by_list( (int) $list_id ) as $group ) {
			$is_fixed = RuleGroupValidator::PRICING_MODE_FIXED === (string) $group['pricing_mode'];

			$rules[] = array(
				'sku'              => (string) $this->products->get_sku( (int) $group['product_id'] ),
				'scope'            => (string) $group['scope'],
				'pricing_mode'     => (string) $group['pricing_mode'],
				'unit_price'       => $is_fixed && isset( $group['unit_price'] ) ? (float) $group['unit_price'] : null,
				'minimum_quantity' => isset( $group['minimum_quantity'] ) ? (int) $group['minimum_quantity'] : null,
				'discount_percent' => array_key_exists( 'discount_percent', $group ) ? $group['discount_percent'] : null,
				'ranges'           => $is_fixed ? array() : self::format_ranges_for_snapshot( $this->ranges->get_by_rule_group( (int) $group['id'] ) ),
			);
		}

		return $rules;
	}

	/**
	 * Pure formatting of raw `b2bpl_price_ranges` rows for the deletion
	 * snapshot. Unit-testable independent of the database.
	 *
	 * @param array<int,array<string,mixed>> $ranges Raw range rows.
	 * @return array<int,array{min_qty:int,max_qty:?int,unit_price:float,discount_percent:mixed}>
	 */
	public static function format_ranges_for_snapshot( array $ranges ) {
		return array_map(
			static function ( $range ) {
				return array(
					'min_qty'          => (int) $range['min_qty'],
					'max_qty'          => isset( $range['max_qty'] ) && null !== $range['max_qty'] ? (int) $range['max_qty'] : null,
					'unit_price'       => (float) $range['unit_price'],
					'discount_percent' => array_key_exists( 'discount_percent', $range ) ? $range['discount_percent'] : null,
				);
			},
			$ranges
		);
	}

	/**
	 * `list.duplicate` (contract: `source_external_code`* must already
	 * exist, `external_code`* for the copy must not).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string}
	 * @throws ItemFailure On `list_not_found` (the source), `external_code_required`
	 * (the copy's `external_code` missing),
	 * `external_code_in_use` (the copy's
	 *                      `external_code` already belongs to another list --
	 *                      unlike `list.create`, `list.duplicate` is grouped
	 *                      with `list.update`/`list.upsert` for this code, not
	 *                      with `list.create`'s own `list_exists`) or `invalid_list`.
	 */
	public function duplicate( array $data ) {
		$source_code = self::require_string( $data, 'source_external_code', 'list_not_found' );
		$source      = $this->find_list_or_fail( $source_code );

		$new_code = self::require_string( $data, 'external_code', 'external_code_required' );

		if ( null !== $this->lists->find_by_external_code( $new_code ) ) {
			throw new ItemFailure(
				'external_code_in_use',
				sprintf(
					/* translators: %s: external code submitted by the caller. */
					__( 'The external code "%s" already belongs to another list.', 'b2b-price-lists' ),
					$new_code
				),
				'data.external_code'
			);
		}

		$new_id = $this->lists_service->duplicate( (int) $source['id'], get_current_user_id() );

		if ( is_wp_error( $new_id ) ) {
			throw new ItemFailure( 'invalid_list', $new_id->get_error_message() );
		}

		// `duplicate()` always creates an inactive copy with no
		// `external_code` and the "(copy)" name (panel behavior, unchanged):
		// the webhook's own `external_code`/`name`/`status` overrides are
		// then applied as a partial update, exactly like `list.update`.
		$fields = array( 'external_code' => $new_code );

		foreach ( array( 'name', 'status' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = $data[ $field ];
			}
		}

		$updated = $this->lists_service->update_partial( (int) $new_id, $fields );

		if ( is_wp_error( $updated ) ) {
			throw new ItemFailure( 'invalid_list', $updated->get_error_message() );
		}

		return array(
			'result'       => array(
				'list_id'       => (int) $new_id,
				'external_code' => $new_code,
				'copied_from'   => $source_code,
			),
			'external_ref' => $new_code,
		);
	}

	/**
	 * The item `status` for `create()`/`upsert()`'s embedded `products[]`
	 *: a resolution failure never makes
	 * the whole item `error`, even when *every* embedded product fails to
	 * resolve and `$saved` is therefore `0` -- the list mutation itself
	 * (`list.create`'s creation, `list.upsert`'s update or, when the list
	 * did not exist yet, its own creation) has, by the time this is called,
	 * already succeeded and was never rolled back (a *resolution* failure
	 * alone never throws; only a business-rule failure does, and that is
	 * left uncaught long before reaching this method -- see the class
	 * docblock). `$saved` is accepted for the caller's convenience/future
	 * use but no longer changes the outcome, since a saved-count of `0`
	 * with only resolution errors is exactly the case this correction fixes.
	 *
	 * @param int   $saved  Rules actually written (unused in the decision
	 *                       itself, kept for the caller's own reporting).
	 * @param array $errors Resolution-failure entries collected.
	 * @return string `success`|`partial`.
	 */
	private static function embedded_products_status( $saved, array $errors ) {
		unset( $saved );

		return empty( $errors ) ? 'success' : 'partial';
	}

	/**
	 * Resolves and validates every entry of an optional `products[]` array
	 * (`list.create`/`list.upsert`) without writing anything.
	 *
	 * A resolution failure (`ProductOperations::resolve_rule_identity()`)
	 * only excludes that one entry, recorded in the returned `errors[]`
	 *. A business-rule failure
	 * (`Domain\RuleGroupService::validate()`) instead throws immediately,
	 * uncaught, aborting the whole item -- see the class
	 * docblock for why this method never writes, so the caller decides,
	 * depending on whether the list itself already exists, exactly when it
	 * is safe to actually persist the entries this method hands back.
	 *
	 * @param int              $list_id  List id (already real: either
	 *                                    just-created or pre-existing).
	 * @param array<int,mixed> $products Raw `products[]` entries.
	 * @return array{to_write:array<int,array>,errors:array<int,array>}
	 * @throws ItemFailure On the first business-rule failure.
	 */
	private function resolve_and_validate_embedded_products( $list_id, array $products ) {
		if ( empty( $products ) ) {
			return array(
				'to_write' => array(),
				'errors'   => array(),
			);
		}

		$to_write = array();
		$errors   = array();

		foreach ( $products as $index => $product_data ) {
			if ( ! is_array( $product_data ) ) {
				$errors[] = array(
					'code'    => 'invalid_rule',
					'message' => __( 'Each entry of "products" must be an object.', 'b2b-price-lists' ),
					'path'    => "data.products[{$index}]",
				);
				continue;
			}

			try {
				$identity = ProductOperations::resolve_rule_identity( $this->sku_resolver, $list_id, $product_data );
			} catch ( ItemFailure $failure ) {
				$entry         = $failure->to_error_entry();
				$entry['path'] = "data.products[{$index}]." . ( $entry['path'] ?? 'sku' );
				$errors[]      = $entry;
				continue;
			}

			$existing      = $this->rule_groups->find_by_list_product_scope( (int) $list_id, $identity['product_id'], $identity['scope'], $identity['rule_code'] );
			$rule_group_id = $existing ? (int) $existing['id'] : 0;
			$input         = ProductOperations::build_rule_input( $list_id, $rule_group_id, $identity, $product_data );

			$validation_error = $this->rule_group_service->validate( $input );

			if ( null !== $validation_error ) {
				// Contract: "el mismo mensaje que daría el panel" -- and,
				// per escenario 3, this aborts the whole item. the
				// path carries the full `data.products[{index}]` position,
				// not just the bare field name.
				throw new ItemFailure( 'invalid_rule', $validation_error->get_error_message(), "data.products[{$index}].sku" );
			}

			// The original `$products` index travels with the input so a
			// later save-time failure in `write_validated_products()` (an
			// entry that already passed `validate()` here but is rejected
			// again at write time) can still report the same, correct
			// `data.products[{index}]` position -- `$to_write` is a
			// sequentially re-indexed array, since earlier entries may have
			// been skipped above.
			$to_write[] = array(
				'index' => $index,
				'input' => $input,
			);
		}

		return array(
			'to_write' => $to_write,
			'errors'   => $errors,
		);
	}

	/**
	 * Persists every already-validated rule input from
	 * `resolve_and_validate_embedded_products()`, inside its own transaction
	 * (nested as a `SAVEPOINT` when called from within `create()`'s own,
	 * wider one) with every side effect deferred until it commits.
	 *
	 * @param array<int,array{index:int,input:array}> $to_write Entries carrying
	 *                                                          their original
	 *                                                          `products[]`
	 * index
	 *                                                          alongside the
	 *                                                          `RuleGroupService::save()`-ready
	 *                                                          input.
	 * @return array{saved:int,pending_effects:array}
	 * @throws ItemFailure On a save-time failure (should not normally happen,
	 *                      since every entry was already validated).
	 */
	private function write_validated_products( array $to_write ) {
		if ( empty( $to_write ) ) {
			return array(
				'saved'           => 0,
				'pending_effects' => array(),
			);
		}

		$pending_effects = array();
		$saved           = 0;

		Transaction::run(
			function () use ( $to_write, &$pending_effects, &$saved ) {
				foreach ( $to_write as $entry ) {
					$result = $this->rule_group_service->save( $entry['input'], false );

					if ( is_wp_error( $result ) ) {
						// qualified with the same `products[]`
						// position `resolve_and_validate_embedded_products()`
						// already validated this entry against.
						throw new ItemFailure( 'invalid_rule', $result->get_error_message(), "data.products[{$entry['index']}].sku" );
					}

					$pending_effects[] = $this->rule_group_service->take_pending_side_effects();
					++$saved;
				}

				return true;
			}
		);

		return array(
			'saved'           => $saved,
			'pending_effects' => $pending_effects,
		);
	}

	/**
	 * Finds a list by `external_code`, or throws `list_not_found`.
	 *
	 * @param string $external_code External code.
	 * @return array<string,mixed>
	 * @throws ItemFailure On `list_not_found`.
	 */
	private function find_list_or_fail( $external_code ) {
		$list = $this->lists->find_by_external_code( $external_code );

		if ( null === $list ) {
			throw new ItemFailure(
				'list_not_found',
				sprintf(
					/* translators: %s: external code submitted by the caller. */
					__( 'No list with the external code "%s" was found.', 'b2b-price-lists' ),
					$external_code
				),
				'data.external_code'
			);
		}

		return $list;
	}

	/**
	 * Reads a required, non-empty string field, or throws with the given
	 * error code.
	 *
	 * @param array<string,mixed> $data       Operation `data`.
	 * @param string              $field      Field name.
	 * @param string              $error_code Item error code to throw with.
	 * @return string
	 * @throws ItemFailure When the field is missing, not a string, or blank.
	 */
	public static function require_string( array $data, $field, $error_code ) {
		$value = isset( $data[ $field ] ) ? $data[ $field ] : null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new ItemFailure(
				$error_code,
				sprintf(
					/* translators: %s: missing field name. */
					__( 'The field "%s" is required.', 'b2b-price-lists' ),
					$field
				),
				"data.{$field}"
			);
		}

		return trim( $value );
	}
}
