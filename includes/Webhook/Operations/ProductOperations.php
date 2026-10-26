<?php
/**
 * Maps `products.upsert|remove|replace`
 * onto `Domain\RuleGroupService`, resolving every `sku`/`variation_skus`
 * through `Webhook\Resolver\SkuResolver` first and applying exactly the
 * panel's own validations via
 * `Domain\RuleGroupValidator` -- this class adds no business rule of its own.
 *
 * Correction A.1: every `products[]` entry
 * can fail for one of two, deliberately different, reasons.
 *
 * - A *resolution* failure (`resolve_rule_identity()`: `sku_not_found`,
 *   `sku_ambiguous`, `product_type_mismatch`, `variation_mismatch`,
 *   `variation_sku_missing`, `rule_code_required`, or a structurally invalid
 *   `scope`/`variation_skus`) only affects *that* entry -- every caller in
 *   this class catches it locally, records it in `errors[]`, and moves on to
 *   the next entry.
 * - A *business-rule* failure (`Domain\RuleGroupValidator`'s own checks --
 *   price/range validation, a duplicate `simple`/`all_variations`, a
 *   conflicting variation -- surfaced by `write_rule()` as an `invalid_rule`
 *   `ItemFailure`) is deliberately left uncaught by every caller here: it
 *   propagates out of this class, out of `Webhook\Operations\ListOperations`
 *   when reused for embedded `products[]`, and out of
 *   `Webhook\BatchProcessor::process_item()`'s own item-level
 *   `Database\Transaction::run()`, rolling back *everything* already written
 *   for that item -- including a list just created by `list.create` in the
 *   same item.
 *
 * This is why `upsert()`/`replace()` (and `ListOperations::save_embedded_products()`,
 * which shares `resolve_rule_identity()`/`write_rule()`) always resolve every
 * entry first and only ever write inside their own `Database\Transaction::run()`
 * call with side effects deferred (`RuleGroupService::save()`/`delete()`'s
 * `$emit_side_effects = false` mode) -- a later entry's business failure must
 * be able to undo an earlier entry's already-written, but not yet
 * side-effect-flushed, rule.
 *
 * @package B2BPriceLists\Webhook\Operations
 */

namespace B2BPriceLists\Webhook\Operations;

use B2BPriceLists\Domain\RuleGroupService;
use B2BPriceLists\Domain\RuleGroupValidator;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\RuleGroupsRepository;
use B2BPriceLists\Support\Cache;
use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Database\Transaction;
use B2BPriceLists\Webhook\Resolver\SkuResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductOperations
 */
class ProductOperations {

	/**
	 * Rule group persistence service.
	 *
	 * @var RuleGroupService
	 */
	private $rule_group_service;

	/**
	 * SKU resolver.
	 *
	 * @var SkuResolver
	 */
	private $sku_resolver;

	/**
	 * Lists repository (read: `external_code` lookups).
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * Rule groups repository (read: existing rule lookups for upsert/remove/replace).
	 *
	 * @var RuleGroupsRepository
	 */
	private $rule_groups;

	/**
	 * Constructor.
	 *
	 * @param RuleGroupService|null     $rule_group_service Optional, for tests.
	 * @param SkuResolver|null          $sku_resolver       Optional, for tests.
	 * @param ListsRepository|null      $lists              Optional, for tests.
	 * @param RuleGroupsRepository|null $rule_groups        Optional, for tests.
	 */
	public function __construct(
		?RuleGroupService $rule_group_service = null,
		?SkuResolver $sku_resolver = null,
		?ListsRepository $lists = null,
		?RuleGroupsRepository $rule_groups = null
	) {
		$this->rule_group_service = $rule_group_service ?? new RuleGroupService();
		$this->sku_resolver       = $sku_resolver ?? new SkuResolver();
		$this->lists              = $lists ?? new ListsRepository();
		$this->rule_groups        = $rule_groups ?? new RuleGroupsRepository();
	}

	/**
	 * `products.upsert` (contract: creates a rule when it does not exist,
	 * otherwise *replaces it completely* -- never a partial merge).
	 *
	 * Every entry is resolved first (a resolution failure only skips that
	 * entry, escenario 2); every resolved entry is then written inside one
	 * transaction with deferred side effects -- a business-rule failure on
	 * any of them rolls back every rule already written by this same call
	 *, and is left uncaught so the item ends `error`, never
	 * `partial`.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string,status?:string,errors?:array}
	 * @throws ItemFailure On `list_not_found`, when every entry fails to
	 *                      resolve, or on the first business-rule failure
	 *                      (uncaught, see class docblock).
	 */
	public function upsert( array $data ) {
		$list = $this->find_list_or_fail( $data );

		$products = self::require_products_array( $data );
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
				$identity = self::resolve_rule_identity( $this->sku_resolver, (int) $list['id'], $product_data );
			} catch ( ItemFailure $failure ) {
				$entry         = $failure->to_error_entry();
				$entry['path'] = "data.products[{$index}]." . ( $entry['path'] ?? 'sku' );
				$errors[]      = $entry;
				continue;
			}

			// From this point, a failure is a business-rule one (price,
			// ranges, duplicate scope, conflicting variation): deliberately
			// left uncaught, see the class docblock. The original `$products`
			// index travels with the entry so a save-time failure below can
			// still report the correct `data.products[{index}]` position
			// -- `$to_write` is a sequentially re-indexed array,
			// since earlier entries may have been skipped above.
			$to_write[] = array(
				'index'    => $index,
				'identity' => $identity,
				'data'     => $product_data,
			);
		}

		if ( empty( $to_write ) ) {
			if ( ! empty( $errors ) ) {
				$first_error = $errors[0];

				throw new ItemFailure( $first_error['code'], $first_error['message'], $first_error['path'] ?? null );
			}

			return array(
				'result'       => array( 'rules_saved' => 0 ),
				'external_ref' => (string) ( $data['list_external_code'] ?? '' ),
				'status'       => 'success',
				'errors'       => array(),
			);
		}

		$pending_effects = array();
		$saved           = 0;

		Transaction::run(
			function () use ( $to_write, $list, &$pending_effects, &$saved ) {
				foreach ( $to_write as $entry ) {
					try {
						self::write_rule( $this->rule_group_service, $this->rule_groups, (int) $list['id'], $entry['identity'], $entry['data'], false );
					} catch ( ItemFailure $failure ) {
						// requalified with this entry's own
						// `products[]` position -- `write_rule()` itself has
						// no way to know it.
						throw new ItemFailure( $failure->get_error_code(), $failure->getMessage(), "data.products[{$entry['index']}].sku" );
					}

					$pending_effects[] = $this->rule_group_service->take_pending_side_effects();
					++$saved;
				}

				return true;
			}
		);

		// M2 (independent review of W4/W5): not flushed here -- see
		// `Webhook\Operations\ListOperations::create()`'s own comment for
		// why. Returned as `pending_effects` for
		// `Webhook\BatchProcessor::process_item()` to flush only once its
		// own, truly outermost transaction has committed.
		return array(
			'result'          => array( 'rules_saved' => $saved ),
			'external_ref'    => (string) ( $data['list_external_code'] ?? '' ),
			'status'          => empty( $errors ) ? 'success' : 'partial',
			'errors'          => $errors,
			'pending_effects' => $pending_effects,
		);
	}

	/**
	 * `products.remove` (contract: a rule not found is `skipped`, reported
	 * with a `rule_not_found` entry in `errors[]` for diagnostics
	 * without making the item itself `error`/`partial` on that account alone
	 * -- each entry is independent; no business-rule validation is involved
	 * in a removal, so correction A.1 does not change this method's
	 * already-independent-per-entry semantics). A `variation_group` entry is
	 * identified by `sku` + `scope` + `rule_code` alone:
	 * `variation_skus` is never required to *remove* a rule, only to create
	 * or replace one.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string,status:string,errors:array,pending_effects:array}
	 * @throws ItemFailure On `list_not_found`.
	 */
	public function remove( array $data ) {
		$list = $this->find_list_or_fail( $data );

		$products         = self::require_products_array( $data );
		$removed          = 0;
		$rule_not_found   = 0;
		$hard_error_count = 0;
		$errors           = array();
		$pending_effects  = array();

		foreach ( $products as $index => $product_data ) {
			if ( ! is_array( $product_data ) ) {
				++$hard_error_count;
				$errors[] = array(
					'code'    => 'invalid_rule',
					'message' => __( 'Each entry of "products" must be an object.', 'b2b-price-lists' ),
					'path'    => "data.products[{$index}]",
				);
				continue;
			}

			try {
				$identity = self::resolve_removal_identity( $this->sku_resolver, $product_data );
			} catch ( ItemFailure $failure ) {
				++$hard_error_count;
				$entry         = $failure->to_error_entry();
				$entry['path'] = "data.products[{$index}]." . ( $entry['path'] ?? 'sku' );
				$errors[]      = $entry;
				continue;
			}

			$existing = $this->rule_groups->find_by_list_product_scope( (int) $list['id'], $identity['product_id'], $identity['scope'], $identity['rule_code'] );

			if ( null === $existing ) {
				++$rule_not_found;
				$errors[] = array(
					'code'    => 'rule_not_found',
					'message' => __( 'No rule matches this product/scope in this list.', 'b2b-price-lists' ),
					'path'    => "data.products[{$index}]",
				);
				continue;
			}

			if ( $this->rule_group_service->delete( (int) $existing['id'], false ) ) {
				$pending_effects[] = $this->rule_group_service->take_pending_side_effects();
				++$removed;
			} else {
				++$hard_error_count;
				$errors[] = array(
					'code'    => 'invalid_rule',
					'message' => __( 'The rule could not be removed.', 'b2b-price-lists' ),
					'path'    => "data.products[{$index}]",
				);
			}
		}

		// M2 (independent review of W4/W5): not flushed here -- see
		// `Webhook\Operations\ListOperations::create()`'s own comment.
		// Returned as `pending_effects` for
		// `Webhook\BatchProcessor::process_item()` to flush only once its
		// own, truly outermost transaction has committed.
		return array(
			'result'          => array(
				'rules_removed' => $removed,
				'rules_skipped' => $rule_not_found,
			),
			'external_ref'    => (string) ( $data['list_external_code'] ?? '' ),
			'status'          => self::remove_status( $removed, $rule_not_found, $hard_error_count ),
			'errors'          => $errors,
			'pending_effects' => $pending_effects,
		);
	}

	/**
	 * Pure final-`status` decision for `remove`: a real,
	 * structural/resolution failure (`$hard_error_count`) still makes the
	 * item `error`/`partial` exactly as before this correction; a rule that
	 * simply did not exist (`$rule_not_found`, now reported with its own
	 * `rule_not_found` entry) is never treated as a failure on its own --
	 * an item made up *entirely* of "nothing to remove" entries is `skipped`
	 * (contract's own status, spec glossary), not `error`. Unit-testable
	 * independent of any repository/service call.
	 *
	 * @param int $removed          Rules actually removed.
	 * @param int $rule_not_found   Entries that resolved fine but matched no rule.
	 * @param int $hard_error_count Structural/resolution/delete failures.
	 * @return string `success`|`partial`|`error`|`skipped`.
	 */
	public static function remove_status( $removed, $rule_not_found, $hard_error_count ) {
		if ( $hard_error_count > 0 ) {
			return ( $removed + $rule_not_found ) > 0 ? 'partial' : 'error';
		}

		if ( 0 === $removed && $rule_not_found > 0 ) {
			return 'skipped';
		}

		return 'success';
	}

	/**
	 * Resolves a `products.remove` entry's SKU into `product_id`/`scope`/
	 * `rule_code`, without ever requiring `variation_skus`: a
	 * removal only needs enough to look an existing rule up by
	 * `list_id`+`product_id`+`scope`(+`rule_code`), never the set of
	 * variations a `variation_group` currently covers. A failure here is
	 * always a *resolution* failure (see class docblock): callers catch it
	 * locally and only skip the one offending entry.
	 *
	 * @param SkuResolver         $resolver     SKU resolver.
	 * @param array<string,mixed> $product_data One `products[]` entry.
	 * @return array{product_id:int,scope:string,rule_code:?string}
	 * @throws ItemFailure On any resolution failure.
	 */
	public static function resolve_removal_identity( SkuResolver $resolver, array $product_data ) {
		$scope        = isset( $product_data['scope'] ) ? (string) $product_data['scope'] : '';
		$valid_scopes = array( RuleGroupValidator::SCOPE_SIMPLE, RuleGroupValidator::SCOPE_ALL_VARIATIONS, RuleGroupValidator::SCOPE_VARIATION_GROUP );

		if ( ! in_array( $scope, $valid_scopes, true ) ) {
			throw new ItemFailure( 'invalid_rule', __( 'Invalid rule scope.', 'b2b-price-lists' ), 'scope' );
		}

		$sku = isset( $product_data['sku'] ) ? trim( (string) $product_data['sku'] ) : '';

		if ( '' === $sku ) {
			throw new ItemFailure( 'sku_not_found', __( 'The product SKU is required.', 'b2b-price-lists' ), 'sku' );
		}

		$parent = $resolver->resolve_parent( $sku, $scope );

		if ( isset( $parent['error'] ) ) {
			throw new ItemFailure( $parent['error'], $parent['message'], 'sku' );
		}

		$rule_code = null;

		if ( RuleGroupValidator::SCOPE_VARIATION_GROUP === $scope ) {
			$rule_code = isset( $product_data['rule_code'] ) ? trim( (string) $product_data['rule_code'] ) : '';

			if ( '' === $rule_code ) {
				throw new ItemFailure( 'rule_code_required', __( '"rule_code" is required for a variation_group rule.', 'b2b-price-lists' ), 'rule_code' );
			}
		}

		return array(
			'product_id' => (int) $parent['product_id'],
			'scope'      => $scope,
			'rule_code'  => $rule_code,
		);
	}

	/**
	 * `products.replace` (contract: requires `confirm_replace: true`; deletes
	 * every rule not present in `products[]` and upserts the rest).
	 *
	 * Correction B.2 (spec): every entry's SKU is resolved *before*
	 * anything is deleted or written -- any resolution failure aborts the
	 * whole item immediately (a partial replace could otherwise delete rules
	 * the emitter still wanted kept). The deletion of rules not sent and the
	 * upsert of the rest then both run inside the *same* transaction, with
	 * every side effect deferred until it commits: a business-rule failure on
	 * any upserted rule rolls back every deletion and every upsert already
	 * made by this same call, leaving the list exactly as it was before this
	 * item ran.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string}
	 * @throws ItemFailure On `list_not_found`/`replace_not_confirmed`, the
	 *                      first entry that fails to resolve, or the first
	 *                      business-rule failure (uncaught, see class
	 *                      docblock).
	 */
	public function replace( array $data ) {
		$list = $this->find_list_or_fail( $data );

		if ( empty( $data['confirm_replace'] ) ) {
			throw new ItemFailure(
				'replace_not_confirmed',
				__( '"confirm_replace: true" is required to replace every rule of a list.', 'b2b-price-lists' ),
				'data.confirm_replace'
			);
		}

		$products = self::require_products_array( $data );

		$resolved_entries  = array();
		$resolved_rule_ids = array();

		foreach ( $products as $index => $product_data ) {
			if ( ! is_array( $product_data ) ) {
				throw new ItemFailure( 'invalid_rule', __( 'Each entry of "products" must be an object.', 'b2b-price-lists' ), "data.products[{$index}]" );
			}

			try {
				$identity = self::resolve_rule_identity( $this->sku_resolver, (int) $list['id'], $product_data );
			} catch ( ItemFailure $failure ) {
				$entry         = $failure->to_error_entry();
				$entry['path'] = "data.products[{$index}]." . ( $entry['path'] ?? 'sku' );
				throw new ItemFailure( $entry['code'], $entry['message'], $entry['path'] );
			}

			// the original `products[]` index travels with the
			// entry so a save-time failure below can still report the
			// correct `data.products[{index}]` position.
			$resolved_entries[] = array(
				'index'    => $index,
				'identity' => $identity,
				'data'     => $product_data,
			);

			$existing = $this->rule_groups->find_by_list_product_scope( (int) $list['id'], $identity['product_id'], $identity['scope'], $identity['rule_code'] );

			if ( null !== $existing ) {
				$resolved_rule_ids[] = (int) $existing['id'];
			}
		}

		$to_delete = array();

		foreach ( $this->rule_groups->get_by_list( (int) $list['id'] ) as $existing_group ) {
			if ( ! in_array( (int) $existing_group['id'], $resolved_rule_ids, true ) ) {
				$to_delete[] = (int) $existing_group['id'];
			}
		}

		$pending_effects = array();
		$removed         = 0;
		$saved           = 0;

		Transaction::run(
			function () use ( $to_delete, $resolved_entries, $list, &$pending_effects, &$removed, &$saved ) {
				foreach ( $to_delete as $rule_group_id ) {
					if ( $this->rule_group_service->delete( $rule_group_id, false ) ) {
						$pending_effects[] = $this->rule_group_service->take_pending_side_effects();
						++$removed;
					}
				}

				foreach ( $resolved_entries as $entry ) {
					try {
						self::write_rule( $this->rule_group_service, $this->rule_groups, (int) $list['id'], $entry['identity'], $entry['data'], false );
					} catch ( ItemFailure $failure ) {
						// requalified with this entry's own
						// `products[]` position.
						throw new ItemFailure( $failure->get_error_code(), $failure->getMessage(), "data.products[{$entry['index']}].sku" );
					}

					$pending_effects[] = $this->rule_group_service->take_pending_side_effects();
					++$saved;
				}

				return true;
			}
		);

		// M2 (independent review of W4/W5): not flushed here -- see
		// `Webhook\Operations\ListOperations::create()`'s own comment.
		// Returned as `pending_effects` for
		// `Webhook\BatchProcessor::process_item()` to flush only once its
		// own, truly outermost transaction has committed.
		return array(
			'result'          => array(
				'rules_removed' => $removed,
				'rules_saved'   => $saved,
			),
			'external_ref'    => (string) ( $data['list_external_code'] ?? '' ),
			'pending_effects' => $pending_effects,
		);
	}

	/**
	 * Flushes one deferred side-effects result -- from
	 * `RuleGroupService::take_pending_side_effects()` (`cache_list_ids`) or
	 * `Domain\UserAssignmentService::take_pending_side_effects()`
	 * (`cache_user_ids`), which share this same shape (M2, independent
	 * review of W4/W5): the activity log entry (if any change survived) and
	 * every cache invalidation. The single shared flushing point
	 * `Webhook\BatchProcessor::process_item()` calls once its own, truly
	 * outermost transaction for the whole item has actually committed --
	 * never any earlier, nested `Database\Transaction::run()` call's own
	 * release, which a *later* failure in the same item could still roll
	 * back.
	 *
	 * @param array{log:array|null,cache_list_ids?:int[],cache_user_ids?:int[]}|null $effect Pending side effects.
	 * @return void
	 */
	public static function flush_side_effects( $effect ) {
		if ( null === $effect ) {
			return;
		}

		if ( ! empty( $effect['log'] ) ) {
			ActivityLog::record( $effect['log']['action'], $effect['log']['context'] );
		}

		foreach ( (array) ( $effect['cache_list_ids'] ?? array() ) as $list_id ) {
			Cache::invalidate_list( (int) $list_id );
		}

		foreach ( (array) ( $effect['cache_user_ids'] ?? array() ) as $user_id ) {
			Cache::invalidate_user( (int) $user_id );
		}
	}

	/**
	 * Resolves a `products[]` entry's SKU(s) into `product_id`/`scope`/
	 * `rule_code`, without saving anything -- shared by `replace()`'s
	 * pre-validation pass, `upsert()`'s per-entry resolution and
	 * `Webhook\Operations\ListOperations`' embedded `products[]`. A failure
	 * here is always a *resolution* failure (see class docblock): callers
	 * catch it locally and only skip the one offending entry.
	 *
	 * @param SkuResolver         $resolver     SKU resolver.
	 * @param int                 $list_id      List id.
	 * @param array<string,mixed> $product_data One `products[]` entry.
	 * @return array{product_id:int,scope:string,rule_code:?string,variation_ids:int[]}
	 * @throws ItemFailure On any resolution failure.
	 */
	public static function resolve_rule_identity( SkuResolver $resolver, $list_id, array $product_data ) {
		unset( $list_id );

		$scope        = isset( $product_data['scope'] ) ? (string) $product_data['scope'] : '';
		$valid_scopes = array( RuleGroupValidator::SCOPE_SIMPLE, RuleGroupValidator::SCOPE_ALL_VARIATIONS, RuleGroupValidator::SCOPE_VARIATION_GROUP );

		if ( ! in_array( $scope, $valid_scopes, true ) ) {
			throw new ItemFailure( 'invalid_rule', __( 'Invalid rule scope.', 'b2b-price-lists' ), 'scope' );
		}

		$sku = isset( $product_data['sku'] ) ? trim( (string) $product_data['sku'] ) : '';

		if ( '' === $sku ) {
			throw new ItemFailure( 'sku_not_found', __( 'The product SKU is required.', 'b2b-price-lists' ), 'sku' );
		}

		$parent = $resolver->resolve_parent( $sku, $scope );

		if ( isset( $parent['error'] ) ) {
			throw new ItemFailure( $parent['error'], $parent['message'], 'sku' );
		}

		$product_id    = (int) $parent['product_id'];
		$rule_code     = null;
		$variation_ids = array();

		if ( RuleGroupValidator::SCOPE_VARIATION_GROUP === $scope ) {
			$rule_code = isset( $product_data['rule_code'] ) ? trim( (string) $product_data['rule_code'] ) : '';

			if ( '' === $rule_code ) {
				throw new ItemFailure( 'rule_code_required', __( '"rule_code" is required for a variation_group rule.', 'b2b-price-lists' ), 'rule_code' );
			}

			$variation_skus = isset( $product_data['variation_skus'] ) && is_array( $product_data['variation_skus'] ) ? $product_data['variation_skus'] : array();

			if ( empty( $variation_skus ) ) {
				throw new ItemFailure( 'invalid_rule', __( '"variation_skus" must include at least one SKU for a variation_group rule.', 'b2b-price-lists' ), 'variation_skus' );
			}

			$resolved_variations = $resolver->resolve_variations( $product_id, $variation_skus );

			if ( isset( $resolved_variations['error'] ) ) {
				throw new ItemFailure( $resolved_variations['error'], $resolved_variations['message'], 'variation_skus' );
			}

			$variation_ids = $resolved_variations['variation_ids'];
		}

		return array(
			'product_id'    => $product_id,
			'scope'         => $scope,
			'rule_code'     => $rule_code,
			'variation_ids' => $variation_ids,
			'sku'           => $sku,
		);
	}

	/**
	 * Builds the `RuleGroupService::save()`/`validate()` input array for one
	 * already-resolved `products[]` entry. Pure field mapping, no I/O -- the
	 * single place this shape is assembled, shared by `write_rule()` and by
	 * `Webhook\Operations\ListOperations`' own validation-only pass.
	 *
	 * @param int                 $list_id       List id.
	 * @param int                 $rule_group_id 0 for a new rule, the
	 *                                            existing one's id otherwise.
	 * @param array<string,mixed> $identity      `resolve_rule_identity()`'s result.
	 * @param array<string,mixed> $product_data  One `products[]` entry
	 *                                            (contract's rule fields).
	 * @return array<string,mixed>
	 */
	public static function build_rule_input( $list_id, $rule_group_id, array $identity, array $product_data ) {
		return array(
			'rule_group_id'    => (int) $rule_group_id,
			'list_id'          => (int) $list_id,
			'product_id'       => $identity['product_id'],
			'scope'            => $identity['scope'],
			'pricing_mode'     => isset( $product_data['pricing_mode'] ) ? (string) $product_data['pricing_mode'] : '',
			'unit_price'       => $product_data['unit_price'] ?? null,
			'minimum_quantity' => $product_data['minimum_quantity'] ?? 1,
			'discount_percent' => $product_data['discount_percent'] ?? null,
			'name'             => $product_data['name'] ?? '',
			'variation_ids'    => $identity['variation_ids'],
			'ranges'           => isset( $product_data['ranges'] ) && is_array( $product_data['ranges'] ) ? $product_data['ranges'] : array(),
			'rule_code'        => $identity['rule_code'],
		);
	}

	/**
	 * Turns one already-resolved `products[]` entry into a
	 * `RuleGroupService::save()` call, applying exactly the panel's own
	 * validations. A failure here is always a *business-rule* one (see class
	 * docblock): deliberately left uncaught by every caller in this class and
	 * in `Webhook\Operations\ListOperations`.
	 *
	 * @param RuleGroupService     $service           Rule group service.
	 * @param RuleGroupsRepository $rule_groups       Rule groups repository
	 *                                                 (existing-rule lookup).
	 * @param int                  $list_id           List id.
	 * @param array<string,mixed>  $identity          `resolve_rule_identity()`'s result.
	 * @param array<string,mixed>  $product_data      One `products[]` entry
	 *                                                 (contract's rule fields).
	 * @param bool                 $emit_side_effects Forwarded to
	 *                                                 `RuleGroupService::save()`.
	 * @return array{rule_group_id:int,product_id:int,sku:string,scope:string,created:bool}
	 * @throws ItemFailure On a business-rule (`invalid_rule`) failure.
	 */
	public static function write_rule( RuleGroupService $service, RuleGroupsRepository $rule_groups, $list_id, array $identity, array $product_data, $emit_side_effects = true ) {
		$existing      = $rule_groups->find_by_list_product_scope( (int) $list_id, $identity['product_id'], $identity['scope'], $identity['rule_code'] );
		$rule_group_id = $existing ? (int) $existing['id'] : 0;
		$input         = self::build_rule_input( $list_id, $rule_group_id, $identity, $product_data );

		$result = $service->save( $input, $emit_side_effects );

		if ( is_wp_error( $result ) ) {
			// Contract: "el mismo mensaje que daría el panel" -- the
			// validator's own message is reused verbatim.
			throw new ItemFailure( 'invalid_rule', $result->get_error_message(), 'sku' );
		}

		return array(
			'rule_group_id' => (int) $result,
			'product_id'    => $identity['product_id'],
			'sku'           => $identity['sku'],
			'scope'         => $identity['scope'],
			'created'       => 0 === $rule_group_id,
		);
	}

	/**
	 * Finds the list a `products.*` operation targets by `list_external_code`,
	 * or throws `list_not_found`.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array<string,mixed>
	 * @throws ItemFailure On `list_not_found`.
	 */
	private function find_list_or_fail( array $data ) {
		$external_code = ListOperations::require_string( $data, 'list_external_code', 'list_not_found' );
		$list          = $this->lists->find_by_external_code( $external_code );

		if ( null === $list ) {
			throw new ItemFailure(
				'list_not_found',
				sprintf(
					/* translators: %s: external code submitted by the caller. */
					__( 'No list with the external code "%s" was found.', 'b2b-price-lists' ),
					$external_code
				),
				'data.list_external_code'
			);
		}

		return $list;
	}

	/**
	 * Reads and validates the required `products[]` array.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array<int,mixed>
	 * @throws ItemFailure When `products` is missing, not an array, or empty.
	 */
	private static function require_products_array( array $data ) {
		if ( ! isset( $data['products'] ) || ! is_array( $data['products'] ) || empty( $data['products'] ) ) {
			throw new ItemFailure(
				'invalid_rule',
				__( 'At least one entry is required in "products".', 'b2b-price-lists' ),
				'data.products'
			);
		}

		return $data['products'];
	}
}
