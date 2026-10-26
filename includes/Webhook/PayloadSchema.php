<?php
/**
 * Pure structural validation of a webhook request body (contracts/
 * webhook-api.md): does it have an `idempotency_key`, is
 * `operations[]` a non-empty, plain (0-indexed) array within the
 * configured limits, does every operation declare a known `action` valid
 * for the endpoint it was sent to and an object `data`, and does the total
 * number of product rules across the batch stay within its own limit.
 *
 * Deliberately **not** responsible for business-rule validation of a
 * single operation's fields (a rule's `sku`/`scope`/`pricing_mode`/
 * `ranges`, a user's `email`/`role`, etc.) -- that reuses the panel's
 * existing validators (`Domain\RuleGroupValidator` and friends) once an
 * operation actually runs, in W3/W4. This class only decides whether the
 * request is well-formed enough to be queued at all (422
 * `b2bpl_invalid_payload`), which is why every error it returns uses the
 * `{path, code}` shape from the contract's 422 example, not the `{code,
 * message, path}` shape used for a *processed* item's result.
 *
 * Entirely free of any WordPress function call, so it can be unit-tested
 * directly against arbitrary decoded-JSON shapes.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PayloadSchema
 */
class PayloadSchema {

	const ENDPOINT_LISTS = 'lists';
	const ENDPOINT_USERS = 'users';

	/**
	 * Catalogue batches: creating and updating the WooCommerce
	 * products themselves, as opposed to the B2B price rules that point at
	 * products which already exist.
	 *
	 * @var string
	 */
	const ENDPOINT_CATALOG = 'catalog';

	/**
	 * The single `/webhook` endpoint: the batch's required `type`
	 * (`lists` or `users`) decides which actions it accepts.
	 *
	 * @var string
	 */
	const ENDPOINT_WEBHOOK = 'webhook';

	/**
	 * Maximum length accepted for an idempotency key, from either source
	 * (contract: "Máximo 100 caracteres").
	 *
	 * @var int
	 */
	const IDEMPOTENCY_KEY_MAX_LENGTH = 100;

	/**
	 * Valid values of a `/webhook` batch's `type`.
	 *
	 * @return string[]
	 */
	public static function types() {
		return array( self::ENDPOINT_LISTS, self::ENDPOINT_USERS, self::ENDPOINT_CATALOG );
	}

	/**
	 * List and product actions.
	 *
	 * @return string[]
	 */
	public static function list_actions() {
		return array(
			'list.create',
			'list.update',
			'list.upsert',
			'list.delete',
			'list.duplicate',
			'products.upsert',
			'products.remove',
			'products.replace',
		);
	}

	/**
	 * User actions.
	 *
	 * @return string[]
	 */
	public static function user_actions() {
		return array(
			'user.upsert',
			'user.assign_list',
			'user.unassign_list',
		);
	}

	/**
	 * Catalogue actions. Deliberately named `product.*`, apart
	 * from the existing `products.*`: those place a price rule on a product
	 * inside a list, these create or update the product itself.
	 *
	 * There is no `product.delete` on purpose -- an automated feed that
	 * deletes products takes order history and links with it, so the only
	 * way out of the catalogue is `product.discontinue`, which is
	 * reversible.
	 *
	 * @return string[]
	 */
	public static function catalog_actions() {
		return array(
			'product.upsert',
			'product.discontinue',
		);
	}

	/**
	 * The actions valid for a given endpoint. An unrecognized endpoint
	 * accepts nothing, so every operation in it fails with `unknown_action`
	 * rather than this method fataling or silently allowing everything.
	 *
	 * @param string $endpoint `ENDPOINT_WEBHOOK`, `ENDPOINT_LISTS` or `ENDPOINT_USERS`.
	 * @return string[]
	 */
	public static function actions_for_endpoint( $endpoint ) {
		if ( self::ENDPOINT_WEBHOOK === $endpoint ) {
			return self::all_actions();
		}

		if ( self::ENDPOINT_USERS === $endpoint ) {
			return self::user_actions();
		}

		if ( self::ENDPOINT_LISTS === $endpoint ) {
			return self::list_actions();
		}

		if ( self::ENDPOINT_CATALOG === $endpoint ) {
			return self::catalog_actions();
		}

		return array();
	}

	/**
	 * Every action this plugin recognizes, across both endpoints -- used to
	 * tell "this action does not exist" (`unknown_action`) apart from
	 * "this action exists, just not here" (`action_not_allowed_for_endpoint`).
	 *
	 * @return string[]
	 */
	public static function all_actions() {
		return array_values( array_unique( array_merge( self::list_actions(), self::user_actions(), self::catalog_actions() ) ) );
	}

	/**
	 * The `limits` key (`Webhook\Settings::limit_bounds()`) that bounds the
	 * number of top-level operations for a given endpoint ("20
	 * operaciones de lista" vs "50 usuarios").
	 *
	 * @param string $endpoint `ENDPOINT_LISTS`, `ENDPOINT_USERS` or `ENDPOINT_CATALOG`.
	 * @return string
	 */
	public static function operations_limit_key_for_endpoint( $endpoint ) {
		$keys = array(
			self::ENDPOINT_USERS   => 'users',
			self::ENDPOINT_CATALOG => 'catalog_operations',
		);

		return isset( $keys[ $endpoint ] ) ? $keys[ $endpoint ] : 'list_operations';
	}

	/**
	 * The limit key a single operation counts against, by its action. Used
	 * on the mixed `/webhook` endpoint, where a batch is counted per kind
	 * rather than as one total.
	 *
	 * @param string $action Operation action.
	 * @return string
	 */
	private static function limit_key_for_action( $action ) {
		if ( 0 === strpos( $action, 'user.' ) ) {
			return 'users';
		}

		if ( 0 === strpos( $action, 'product.' ) ) {
			return 'catalog_operations';
		}

		return 'list_operations';
	}

	/**
	 * Actions whose `data.products[]` counts toward the total "reglas de
	 * producto" limit. `list.update`/`list.delete`/`list.duplicate`/
	 * `user.*` never carry a `products[]` array in the first place, so they
	 * are simply never asked; listed explicitly anyway so a future action
	 * added here without updating this list fails safe (counts as 0 rather
	 * than throwing).
	 *
	 * @return string[]
	 */
	private static function actions_with_product_rules() {
		return array( 'list.create', 'list.upsert', 'products.upsert', 'products.remove', 'products.replace' );
	}

	/**
	 * Resolves the idempotency key from whichever of its two valid sources
	 * (contract: `Idempotency-Key` header, or `idempotency_key` in the
	 * body) actually carries a non-empty string, the header taking
	 * precedence when both are present. Returns `null` when neither does.
	 *
	 * @param array<string,mixed> $payload      Decoded request body.
	 * @param string|null         $header_value Raw `Idempotency-Key` header value, if any.
	 * @return string|null
	 */
	public static function resolve_idempotency_key( array $payload, $header_value ) {
		if ( is_string( $header_value ) && '' !== trim( $header_value ) ) {
			return trim( $header_value );
		}

		if ( isset( $payload['idempotency_key'] ) && is_string( $payload['idempotency_key'] ) && '' !== trim( $payload['idempotency_key'] ) ) {
			return trim( $payload['idempotency_key'] );
		}

		return null;
	}

	/**
	 * Whether an array is a plain, 0-indexed list (what `operations[]` and
	 * `data.products[]` must be), as opposed to a JSON object decoded as an
	 * associative array.
	 *
	 * @param array<mixed> $candidate Candidate array.
	 * @return bool
	 */
	private static function is_list( array $candidate ) {
		return array_values( $candidate ) === $candidate;
	}

	/**
	 * How many product-rule entries a single operation's `data.products[]`
	 * contributes to the batch total. `products.replace` counts
	 * only what is actually sent in this operation, never anything it will
	 * implicitly delete -- which is automatically true here, since a
	 * `replace` operation's `products[]` *is* everything it sends.
	 *
	 * @param string              $action Operation action.
	 * @param array<string,mixed> $data   Operation `data`.
	 * @return int
	 */
	private static function count_product_rules( $action, array $data ) {
		if ( ! in_array( $action, self::actions_with_product_rules(), true ) ) {
			return 0;
		}

		if ( ! isset( $data['products'] ) || ! is_array( $data['products'] ) ) {
			return 0;
		}

		return count( $data['products'] );
	}

	/**
	 * Whether a batch goes over per-send operation limits. On the
	 * single `/webhook` endpoint a batch may mix both kinds, so
	 * `user.*` operations count against `users` and every other operation
	 * against `list_operations`, each limit on its own.
	 *
	 * @param array<int,mixed>  $operations Raw `operations[]`.
	 * @param string            $endpoint   Endpoint constant.
	 * @param array<string,int> $limits     `Webhook\Settings::get()['limits']`.
	 * @return bool
	 */
	public static function exceeds_operation_limits( array $operations, $endpoint, array $limits ) {
		if ( self::ENDPOINT_WEBHOOK !== $endpoint ) {
			$key   = self::operations_limit_key_for_endpoint( $endpoint );
			$limit = isset( $limits[ $key ] ) ? (int) $limits[ $key ] : PHP_INT_MAX;

			return count( $operations ) > $limit;
		}

		$counts = array(
			'users'              => 0,
			'catalog_operations' => 0,
			'list_operations'    => 0,
		);

		foreach ( $operations as $operation ) {
			$action = ( is_array( $operation ) && isset( $operation['action'] ) && is_string( $operation['action'] ) ) ? $operation['action'] : '';

			++$counts[ self::limit_key_for_action( $action ) ];
		}

		foreach ( $counts as $key => $count ) {
			$limit = isset( $limits[ $key ] ) ? (int) $limits[ $key ] : PHP_INT_MAX;

			if ( $count > $limit ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validates a decoded request body against the contract's structural
	 * rules and limits, for a given endpoint.
	 *
	 * Stops early (returns immediately) only for errors that make the rest
	 * of the payload impossible to meaningfully inspect (not an object at
	 * all, `operations` missing/not a list/empty); every other error keeps
	 * accumulating so a single 422 response can report everything wrong at
	 * once, instead of a caller having to fix one problem per request.
	 *
	 * @param mixed             $payload         Decoded JSON body (or
	 *                                            anything else, e.g. a
	 *                                            scalar from a
	 *                                            non-object JSON body).
	 * @param string            $endpoint        `ENDPOINT_WEBHOOK` (or the older `ENDPOINT_LISTS`/`ENDPOINT_USERS`).
	 * @param array<string,int> $limits          `Webhook\Settings::get()['limits']`.
	 * @param string|null       $idempotency_key_header Raw `Idempotency-Key`
	 *                                            header value, if any.
	 * @return array<int,array{path:string,code:string}> Empty when valid.
	 */
	public static function validate( $payload, $endpoint, array $limits, $idempotency_key_header = null ) {
		if ( ! is_array( $payload ) || self::is_list( $payload ) ) {
			return array(
				array(
					'path' => 'root',
					'code' => 'invalid_payload',
				),
			);
		}

		$errors        = array();
		$mismatch_code = 'action_not_allowed_for_endpoint';

		// on `/webhook`, `type` says what the batch carries. A valid
		// type narrows the batch to that type's actions and limit; a missing
		// or invalid one is reported, and the rest is still checked so one
		// response lists every problem.
		if ( self::ENDPOINT_WEBHOOK === $endpoint ) {
			$type = isset( $payload['type'] ) ? $payload['type'] : null;

			if ( null === $type || '' === $type ) {
				$errors[] = array(
					'path' => 'type',
					'code' => 'type_required',
				);
			} elseif ( ! is_string( $type ) || ! in_array( $type, self::types(), true ) ) {
				$errors[] = array(
					'path' => 'type',
					'code' => 'invalid_type',
				);
			} else {
				$endpoint      = $type;
				$mismatch_code = 'action_not_allowed_for_type';
			}
		}

		$idempotency_key = self::resolve_idempotency_key( $payload, $idempotency_key_header );

		if ( null === $idempotency_key ) {
			$errors[] = array(
				'path' => 'idempotency_key',
				'code' => 'idempotency_key_required',
			);
		} elseif ( strlen( $idempotency_key ) > self::IDEMPOTENCY_KEY_MAX_LENGTH ) {
			$errors[] = array(
				'path' => 'idempotency_key',
				'code' => 'idempotency_key_too_long',
			);
		}

		if ( ! array_key_exists( 'operations', $payload ) ) {
			$errors[] = array(
				'path' => 'operations',
				'code' => 'operations_required',
			);

			return $errors;
		}

		if ( ! is_array( $payload['operations'] ) || ! self::is_list( $payload['operations'] ) ) {
			$errors[] = array(
				'path' => 'operations',
				'code' => 'operations_not_array',
			);

			return $errors;
		}

		$operations = $payload['operations'];

		if ( empty( $operations ) ) {
			$errors[] = array(
				'path' => 'operations',
				'code' => 'operations_empty',
			);

			return $errors;
		}

		if ( self::exceeds_operation_limits( $operations, $endpoint, $limits ) ) {
			$errors[] = array(
				'path' => 'operations',
				'code' => 'too_many_operations',
			);
		}

		$allowed_actions = self::actions_for_endpoint( $endpoint );
		$all_actions     = self::all_actions();
		$product_rules   = 0;
		$variations      = 0;

		foreach ( $operations as $index => $operation ) {
			$path = "operations[{$index}]";

			if ( ! is_array( $operation ) || self::is_list( $operation ) ) {
				$errors[] = array(
					'path' => $path,
					'code' => 'operation_not_object',
				);
				continue;
			}

			$action = isset( $operation['action'] ) ? $operation['action'] : null;

			if ( ! is_string( $action ) || '' === $action ) {
				$errors[] = array(
					'path' => $path . '.action',
					'code' => 'action_required',
				);
			} elseif ( ! in_array( $action, $allowed_actions, true ) ) {
				$errors[] = array(
					'path' => $path . '.action',
					'code' => in_array( $action, $all_actions, true ) ? $mismatch_code : 'unknown_action',
				);
			}

			// Deliberately not also rejecting a `data` that happens to be a
			// plain list here (unlike `operations`/`products` above): once
			// decoded with `json_decode( ..., true )`, an empty JSON object
			// `{}` and an empty JSON array `[]` are both indistinguishable
			// PHP `array()` values, and an operation with no fields to
			// change (an empty `list.update`, a no-op) is a business-rule
			// question for W3, not a structural one.
			if ( ! isset( $operation['data'] ) || ! is_array( $operation['data'] ) ) {
				$errors[] = array(
					'path' => $path . '.data',
					'code' => 'data_required',
				);
				continue;
			}

			if ( isset( $operation['data']['products'] ) && ( ! is_array( $operation['data']['products'] ) || ! self::is_list( $operation['data']['products'] ) ) ) {
				$errors[] = array(
					'path' => $path . '.data.products',
					'code' => 'products_not_array',
				);
				continue;
			}

			// `user.upsert`'s `billing`/`shipping` (contract, W4): each must
			// be an object, never a plain list -- the same distinction
			// already applied to `products` above. A structural failure
			// here only skips this one field's own rule-count contribution
			// (there is none for `user.*` anyway); it does not `continue`
			// the outer loop, since a caller may still have other,
			// perfectly valid errors to report about the same operation.
			foreach ( array( 'billing', 'shipping' ) as $address_field ) {
				if ( isset( $operation['data'][ $address_field ] )
					&& ( ! is_array( $operation['data'][ $address_field ] ) || self::is_list( $operation['data'][ $address_field ] ) )
				) {
					$errors[] = array(
						'path' => $path . '.data.' . $address_field,
						'code' => $address_field . '_not_object',
					);
				}
			}

			// `product.upsert`'s own collections: each must be a
			// plain list, same distinction as `products` above. Reported
			// without `continue`, so one response still lists every other
			// problem in the same operation.
			foreach ( array( 'variations', 'images', 'attributes', 'categories', 'tags' ) as $catalog_field ) {
				if ( isset( $operation['data'][ $catalog_field ] )
					&& ( ! is_array( $operation['data'][ $catalog_field ] ) || ! self::is_list( $operation['data'][ $catalog_field ] ) )
				) {
					$errors[] = array(
						'path' => $path . '.data.' . $catalog_field,
						'code' => $catalog_field . '_not_array',
					);
				}
			}

			if ( isset( $operation['data']['variations'] ) && is_array( $operation['data']['variations'] ) && self::is_list( $operation['data']['variations'] ) ) {
				$variations += count( $operation['data']['variations'] );
			}

			$product_rules += self::count_product_rules( is_string( $action ) ? $action : '', $operation['data'] );
		}

		$variations_limit = isset( $limits['catalog_variations'] ) ? (int) $limits['catalog_variations'] : PHP_INT_MAX;

		if ( $variations > $variations_limit ) {
			$errors[] = array(
				'path' => 'operations',
				'code' => 'too_many_variations',
			);
		}

		$product_rules_limit = isset( $limits['product_rules'] ) ? (int) $limits['product_rules'] : PHP_INT_MAX;

		if ( $product_rules > $product_rules_limit ) {
			$errors[] = array(
				'path' => 'operations',
				'code' => 'too_many_product_rules',
			);
		}

		return $errors;
	}
}
