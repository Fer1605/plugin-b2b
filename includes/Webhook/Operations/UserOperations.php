<?php
/**
 * Maps `user.upsert|assign_list|unassign_list` (contracts/webhook-api.md,
 *, US3) onto WordPress/WooCommerce's own user
 * APIs (`wc_create_new_customer()`, `wp_update_user()`, `WC_Customer`) and
 * `Domain\UserAssignmentService` -- the exact same service the profile
 * screen and the list editor use (`Admin\UserAssignmentController`), so a
 * webhook assignment behaves identically to one made from the panel.
 *
 * No user is ever deleted here. Every action's identity resolution
 * follows the contract: `external_code` (meta `_b2bpl_external_code`) first,
 * then `email`. The decision logic itself (`resolve_locator()`,
 * `resolve_upsert_identity()`, `should_send_welcome_email()`) is factored out
 * as pure, static methods taking already-fetched facts as plain scalars --
 * never a `WP_User`/database call -- so it is directly unit-testable without
 * WordPress, exactly like `Webhook\Resolver\SkuResolver`'s pure helpers. The
 * instance methods that orchestrate the actual WordPress/WooCommerce calls
 * are reviewed by inspection and covered by the manual staging verification
 * plan in the delivery report, the same documented split already used
 * throughout W1-W3 for this plugin's other WordPress-touching webhook glue.
 *
 * A protected account (`Webhook\RolePolicy::user_is_protected()`) is always
 * checked *before* any write: its email, role, password and list assignment
 * are never touched by any of these three actions.
 *
 * @package B2BPriceLists\Webhook\Operations
 */

namespace B2BPriceLists\Webhook\Operations;

use B2BPriceLists\Domain\UserAssignmentService;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Support\Logger;
use B2BPriceLists\Webhook\BatchProcessor;
use B2BPriceLists\Webhook\Context;
use B2BPriceLists\Webhook\RolePolicy;
use B2BPriceLists\Webhook\Settings;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UserOperations
 */
class UserOperations {

	/**
	 * Meta key holding the external system's own customer code.
	 *
	 * @var string
	 */
	const META_EXTERNAL_CODE = '_b2bpl_external_code';

	/**
	 * Meta key recording whether the account is retail or wholesale
	 *. The role says the same thing, but a shop may change its
	 * roles; this stays.
	 *
	 * @var string
	 */
	const META_CUSTOMER_TYPE = '_b2bpl_customer_type';

	/**
	 * Meta key recording whether an account was created from the panel or
	 * the webhook. Only ever set at creation time -- updating an
	 * admin-created account via the webhook never rewrites its origin.
	 *
	 * @var string
	 */
	const META_CREATED_VIA = '_b2bpl_created_via';

	/**
	 * Meta key holding the UTC timestamp of the last successful `user.*`
	 * write for this account.
	 *
	 * @var string
	 */
	const META_LAST_SYNCED_AT = '_b2bpl_last_synced_at';

	/**
	 * Meta key holding the UUID of the last batch that modified this account.
	 *
	 * @var string
	 */
	const META_LAST_BATCH_ID = '_b2bpl_last_batch_id';

	/**
	 * Billing/shipping fields accepted from the contract's `billing`/
	 * `shipping` objects, mapped onto `WC_Customer::set_{type}_{field}()`.
	 * `email`/`phone` are billing-only -- WooCommerce has no
	 * `set_shipping_email()`, and a shipping phone is deliberately left out
	 * too (out of scope for this release; nothing in the contract requires
	 * it).
	 *
	 * @return array{billing:string[],shipping:string[]}
	 */
	private static function address_fields() {
		return array(
			'billing'  => array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ),
			'shipping' => array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ),
		);
	}

	/**
	 * `user.upsert` (contract: creates when neither identifier matches an
	 * existing user, otherwise updates only the submitted fields).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string,status?:string,errors?:array}
	 * @throws ItemFailure On `email_invalid`, `identity_conflict`,
	 *                      `protected_user`, `email_in_use`, `role_not_allowed`.
	 */
	public function upsert( array $data ) {
		$external_code = self::normalize_optional_string( $data['external_code'] ?? null );
		$email         = self::normalize_optional_string( $data['email'] ?? null );

		if ( null === $external_code && null === $email ) {
			throw new ItemFailure( 'email_invalid', __( 'Either "external_code" or "email" is required to identify the user.', 'b2b-price-lists' ), 'data.email' );
		}

		if ( null !== $email && ! is_email( $email ) ) {
			throw new ItemFailure( 'email_invalid', __( 'The "email" field is not a valid email address.', 'b2b-price-lists' ), 'data.email' );
		}

		$by_code_user  = null !== $external_code ? self::find_user_by_external_code( $external_code ) : null;
		$by_email_user = null !== $email ? get_user_by( 'email', $email ) : null;

		$email_owner_current_external_code = null;

		if ( null === $by_code_user && $by_email_user ) {
			$existing_code                     = get_user_meta( $by_email_user->ID, self::META_EXTERNAL_CODE, true );
			$email_owner_current_external_code = is_string( $existing_code ) ? $existing_code : null;
		}

		$identity = self::resolve_upsert_identity(
			$by_code_user ? (int) $by_code_user->ID : null,
			$by_email_user ? (int) $by_email_user->ID : null,
			$email_owner_current_external_code,
			$external_code
		);

		if ( $identity['conflict'] ) {
			throw new ItemFailure( 'identity_conflict', __( 'The "external_code" and "email" fields identify two different existing users.', 'b2b-price-lists' ) );
		}

		// M1 (independent review of W4/W5 literal): a *new*
		// `external_code` matching an existing account found only by
		// `email` -- one that has no `external_code` of its own yet -- is
		// deliberately still linked to that account (a customer already
		// registered in the store, now becoming B2B), never treated as a
		// conflict or as "create a second account". Made traceable per that
		// review: reported in the item's own `result` and logged as its own
		// `warning` event, and the account's email is never touched on this
		// first link (checked again below, defensively, even though it
		// cannot actually differ -- `$by_email_user` was found by this exact
		// `$email`).
		$linked_existing_account = self::is_linked_existing_account(
			$by_code_user ? (int) $by_code_user->ID : null,
			$by_email_user ? (int) $by_email_user->ID : null,
			$external_code
		);

		$user_id = $identity['user_id'];
		$is_new  = null === $user_id;
		$user    = $is_new ? null : get_userdata( $user_id );

		if ( ! $is_new && ! $user ) {
			// The meta/lookup pointed at an id that no longer exists;
			// treat exactly like "not found for that identifier" -- a
			// fresh account is created instead, never an error.
			$is_new  = true;
			$user_id = null;
		}

		$allowed_roles = Settings::get()['allowed_roles'];

		if ( $user instanceof WP_User && RolePolicy::user_is_protected( $user, $allowed_roles ) ) {
			throw new ItemFailure( 'protected_user', __( 'This account has administrative permissions and cannot be modified by the webhook.', 'b2b-price-lists' ) );
		}

		if ( null !== $email ) {
			$email_owner = get_user_by( 'email', $email );

			if ( $email_owner && ( null === $user_id || (int) $email_owner->ID !== $user_id ) ) {
				throw new ItemFailure( 'email_in_use', __( 'This email address already belongs to a different user.', 'b2b-price-lists' ), 'data.email' );
			}
		}

		// `customer_type` says what kind of account this is, and
		// the role follows from it. An explicit `role` still wins, as long
		// as it passes the same whitelist, so a shop with its own role can
		// keep sending it.
		$customer_type = self::read_customer_type( $data );

		$requested_role = self::normalize_optional_string( array_key_exists( 'role', $data ) ? $data['role'] : null );

		if ( null === $requested_role && null !== $customer_type ) {
			$requested_role = 'b2b' === $customer_type ? Settings::b2b_role() : Settings::b2c_role();
		}

		if ( null !== $requested_role ) {
			$role_error = RolePolicy::role_error( $requested_role, $allowed_roles );

			if ( null !== $role_error ) {
				throw new ItemFailure( $role_error, __( 'This role cannot be assigned by the webhook.', 'b2b-price-lists' ), 'data.role' );
			}
		}

		// New user, no role submitted -> `customer`. Existing user,
		// no role submitted -> `null` here means "leave the current role
		// untouched" (never downgraded/upgraded implicitly).
		$role_to_apply = $is_new ? ( $requested_role ?? Settings::b2c_role() ) : $requested_role;

		// A retail account never belongs to a price list: the list is what
		// makes an account wholesale, so the two would contradict each
		// other. Reported instead of silently picking one.
		if ( 'b2c' === $customer_type ) {
			$list_code = self::normalize_optional_string( array_key_exists( 'list_external_code', $data ) ? $data['list_external_code'] : null );

			if ( null !== $list_code ) {
				throw new ItemFailure(
					'list_not_allowed_for_b2c',
					__( 'A retail (b2c) customer cannot be assigned to a price list. Send customer_type b2b, or leave the list out.', 'b2b-price-lists' ),
					'data.list_external_code'
				);
			}
		}

		if ( $is_new ) {
			if ( null === $email ) {
				throw new ItemFailure( 'email_invalid', __( 'A valid "email" is required to create a new user.', 'b2b-price-lists' ), 'data.email' );
			}

			$created = self::create_customer( $email, $role_to_apply, $data );

			if ( is_wp_error( $created ) ) {
				$error_code = self::classify_customer_creation_error( $created->get_error_code() );

				if ( 'user_create_failed' === $error_code ) {
					// a generic, non-leaking message reaches the
					// caller; the real WooCommerce error is only ever
					// available in the panel's own log.
					Logger::error(
						'wc_create_new_customer() failed for a webhook user.upsert for a reason other than an invalid or already-registered email.',
						array(
							'wc_error_code'    => $created->get_error_code(),
							'wc_error_message' => $created->get_error_message(),
						)
					);

					throw new ItemFailure( 'user_create_failed', __( 'The account could not be created.', 'b2b-price-lists' ), 'data.email' );
				}

				throw new ItemFailure( $error_code, $created->get_error_message(), 'data.email' );
			}

			$user_id = (int) $created;
		} else {
			// M1: never touched on a first link-by-email (see above) --
			// defensive in practice, since `$user` was found by this exact
			// `$email` in the first place, but never left implicit.
			if ( ! $linked_existing_account && null !== $email && strtolower( $user->user_email ) !== strtolower( $email ) ) {
				wp_update_user(
					array(
						'ID'         => $user_id,
						'user_email' => $email,
					)
				);
			}

			if ( null !== $role_to_apply ) {
				$user->set_role( $role_to_apply );
			}
		}

		self::apply_profile_fields( $user_id, $data );

		if ( null !== $external_code ) {
			update_user_meta( $user_id, self::META_EXTERNAL_CODE, $external_code );
		}

		// Kept alongside the role so the account type survives a shop that
		// later renames or swaps its wholesale role, and so customers can
		// be filtered by it without reading roles.
		if ( null !== $customer_type ) {
			update_user_meta( $user_id, self::META_CUSTOMER_TYPE, $customer_type );
		}

		if ( $is_new ) {
			update_user_meta( $user_id, self::META_CREATED_VIA, 'webhook' );
		}

		self::stamp_sync_metadata( $user_id );

		$result = array(
			'user_id' => $user_id,
			'created' => $is_new,
		);

		if ( null !== $customer_type ) {
			$result['customer_type'] = $customer_type;
		}

		if ( null !== $role_to_apply ) {
			$result['role'] = $role_to_apply;
		}

		if ( null !== $external_code ) {
			$result['external_code'] = $external_code;
		}

		if ( $linked_existing_account ) {
			$result['linked_existing_account'] = true;
		}

		$list_error      = null;
		$pending_effects = array();

		if ( array_key_exists( 'list_external_code', $data ) ) {
			$list_code = $data['list_external_code'];

			if ( null === $list_code ) {
				( new UserAssignmentService() )->unassign( $user_id, false );
				$pending_effects[]         = ( new UserAssignmentService() )->take_pending_side_effects();
				$result['list_unassigned'] = true;
			} else {
				$list = ( new ListsRepository() )->find_by_external_code( (string) $list_code );

				if ( null === $list ) {
					$list_error = array(
						'code'    => 'list_not_found',
						'message' => sprintf(
							/* translators: %s: external code submitted by the caller. */
							__( 'No list with the external code "%s" was found.', 'b2b-price-lists' ),
							$list_code
						),
						'path'    => 'data.list_external_code',
					);
				} else {
					$assignment_service = new UserAssignmentService();
					$assign_result      = $assignment_service->assign( $user_id, (int) $list['id'], false );

					if ( is_wp_error( $assign_result ) ) {
						$list_error = array(
							'code'    => 'invalid_list',
							'message' => $assign_result->get_error_message(),
							'path'    => 'data.list_external_code',
						);
					} else {
						$pending_effects[]            = $assignment_service->take_pending_side_effects();
						$result['list_id']            = (int) $list['id'];
						$result['list_external_code'] = (string) $list_code;
					}
				}
			}
		}

		$outcome = array(
			'result'          => $result,
			'external_ref'    => BatchProcessor::external_ref_for_data( $data ) ?? (string) $user_id,
			// M2 (independent review of W4/W5): not flushed here -- see
			// `Webhook\Operations\ListOperations::create()`'s own comment.
			// `Webhook\BatchProcessor::process_item()` flushes it only once
			// its own, truly outermost transaction for this item has
			// actually committed.
			'pending_effects' => $pending_effects,
		);

		if ( null !== $list_error ) {
			// R5: the user is created/updated regardless; only the
			// assignment itself is reported as a partial failure.
			$outcome['status'] = 'partial';
			$outcome['errors'] = array( $list_error );
		}

		if ( $linked_existing_account ) {
			$outcome['events'][] = array(
				'level'   => 'warning',
				'code'    => 'account_linked_by_email',
				'message' => sprintf(
					/* translators: 1: external code submitted by the caller, 2: existing WordPress user id. */
					__( 'Linked the new external code "%1$s" to the existing account found by email (user #%2$d); the email was not modified.', 'b2b-price-lists' ),
					$external_code,
					$user_id
				),
			);
		}

		return $outcome;
	}

	/**
	 * `user.assign_list` (contract: replaces any previous assignment --
	 * 1 user = 1 list).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string}
	 * @throws ItemFailure On `user_not_found`, `identity_conflict`,
	 *                      `protected_user`, `list_not_found`, `invalid_list`.
	 */
	public function assign_list( array $data ) {
		$user          = $this->resolve_existing_user_or_fail( $data );
		$allowed_roles = Settings::get()['allowed_roles'];

		if ( RolePolicy::user_is_protected( $user, $allowed_roles ) ) {
			throw new ItemFailure( 'protected_user', __( 'This account has administrative permissions and cannot be modified by the webhook.', 'b2b-price-lists' ) );
		}

		$list_code = ListOperations::require_string( $data, 'list_external_code', 'list_not_found' );
		$list      = ( new ListsRepository() )->find_by_external_code( $list_code );

		if ( null === $list ) {
			throw new ItemFailure(
				'list_not_found',
				sprintf(
					/* translators: %s: external code submitted by the caller. */
					__( 'No list with the external code "%s" was found.', 'b2b-price-lists' ),
					$list_code
				),
				'data.list_external_code'
			);
		}

		$assignment_service = new UserAssignmentService();
		$result             = $assignment_service->assign( (int) $user->ID, (int) $list['id'], false );

		if ( is_wp_error( $result ) ) {
			throw new ItemFailure( 'invalid_list', $result->get_error_message(), 'data.list_external_code' );
		}

		self::stamp_sync_metadata( (int) $user->ID );

		return array(
			'result'          => array(
				'user_id'            => (int) $user->ID,
				'list_id'            => (int) $list['id'],
				'list_external_code' => $list_code,
			),
			'external_ref'    => BatchProcessor::external_ref_for_data( $data ) ?? (string) $user->ID,
			// M2 (independent review of W4/W5): flushed only once
			// `Webhook\BatchProcessor::process_item()`'s own, truly
			// outermost transaction for this item has actually committed.
			'pending_effects' => array( $assignment_service->take_pending_side_effects() ),
		);
	}

	/**
	 * `user.unassign_list` (contract: the user returns to standard B2C
	 * pricing).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return array{result:array,external_ref:string}
	 * @throws ItemFailure On `user_not_found`, `identity_conflict`, `protected_user`.
	 */
	public function unassign_list( array $data ) {
		$user          = $this->resolve_existing_user_or_fail( $data );
		$allowed_roles = Settings::get()['allowed_roles'];

		if ( RolePolicy::user_is_protected( $user, $allowed_roles ) ) {
			throw new ItemFailure( 'protected_user', __( 'This account has administrative permissions and cannot be modified by the webhook.', 'b2b-price-lists' ) );
		}

		$assignment_service = new UserAssignmentService();
		$assignment_service->unassign( (int) $user->ID, false );

		self::stamp_sync_metadata( (int) $user->ID );

		return array(
			'result'          => array( 'user_id' => (int) $user->ID ),
			'external_ref'    => BatchProcessor::external_ref_for_data( $data ) ?? (string) $user->ID,
			// M2 (independent review of W4/W5): flushed only once
			// `Webhook\BatchProcessor::process_item()`'s own, truly
			// outermost transaction for this item has actually committed.
			'pending_effects' => array( $assignment_service->take_pending_side_effects() ),
		);
	}

	/**
	 * Locates the user an `assign_list`/`unassign_list` operation targets
	 * (contract: `external_code` first, then `email`).
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return WP_User
	 * @throws ItemFailure On `user_not_found`/`identity_conflict`.
	 */
	private function resolve_existing_user_or_fail( array $data ) {
		$external_code = self::normalize_optional_string( $data['external_code'] ?? null );
		$email         = self::normalize_optional_string( $data['email'] ?? null );

		if ( null === $external_code && null === $email ) {
			throw new ItemFailure( 'user_not_found', __( 'Either "external_code" or "email" is required to identify the user.', 'b2b-price-lists' ), 'data.email' );
		}

		$by_code_user  = null !== $external_code ? self::find_user_by_external_code( $external_code ) : null;
		$by_email_user = ( null !== $email && is_email( $email ) ) ? get_user_by( 'email', $email ) : null;

		$locator = self::resolve_locator(
			$by_code_user ? (int) $by_code_user->ID : null,
			$by_email_user ? (int) $by_email_user->ID : null
		);

		if ( $locator['conflict'] ) {
			throw new ItemFailure( 'identity_conflict', __( 'The "external_code" and "email" fields identify two different existing users.', 'b2b-price-lists' ) );
		}

		$user = null !== $locator['user_id'] ? get_userdata( $locator['user_id'] ) : null;

		if ( ! $user ) {
			throw new ItemFailure( 'user_not_found', __( 'No user matches the given "external_code"/"email".', 'b2b-price-lists' ), 'data.email' );
		}

		return $user;
	}

	/**
	 * Finds the (single) WordPress user carrying a given `_b2bpl_external_code`
	 * meta value. Uniqueness across users is enforced at write time (`upsert()`'s
	 * `identity_conflict`/normal update path); a lookup here simply takes the
	 * first match, since WordPress metadata itself has no unique index
	 *.
	 *
	 * @param string $external_code External code.
	 * @return WP_User|null
	 */
	private static function find_user_by_external_code( $external_code ) {
		$users = get_users(
			array(
				'meta_key'   => self::META_EXTERNAL_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $external_code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
			)
		);

		return ! empty( $users ) ? $users[0] : null;
	}

	/**
	 * Creates a new WooCommerce customer (contract: random password, never
	 * returned or logged -- `wc_create_new_customer()` generates and applies
	 * it internally when `$password` is left empty, exactly like a normal
	 * WooCommerce registration with no password field). The native "New
	 * account" email is enabled or suppressed via a filter added and removed
	 * around this single call only, per `should_send_welcome_email`.
	 *
	 * @param string              $email Validated email.
	 * @param string              $role  Role to create the account with.
	 * @param array<string,mixed> $data  Operation `data` (`first_name`/
	 *                                   `last_name`/`display_name`/
	 *                                   `send_welcome_email` are read).
	 * @return int|\WP_Error
	 */
	private static function create_customer( $email, $role, array $data ) {
		$args = array( 'role' => $role );

		foreach ( array( 'first_name', 'last_name', 'display_name' ) as $field ) {
			$value = self::normalize_optional_string( $data[ $field ] ?? null );

			if ( null !== $value ) {
				$args[ $field ] = sanitize_text_field( $value );
			}
		}

		$send_email = self::should_send_welcome_email( ! empty( Settings::get()['send_new_user_email'] ), $data['send_welcome_email'] ?? null );
		$suppress   = ! $send_email;

		$filter = static function ( $enabled ) use ( $suppress ) {
			return $suppress ? false : $enabled;
		};

		add_filter( 'woocommerce_email_enabled_customer_new_account', $filter, 20 );

		try {
			return wc_create_new_customer( $email, '', '', $args );
		} finally {
			remove_filter( 'woocommerce_email_enabled_customer_new_account', $filter, 20 );
		}
	}

	/**
	 * Applies name/billing/shipping fields the caller actually sent, never
	 * touching a field left out of `$data` (contract: "actualiza campos
	 * enviados").
	 *
	 * @param int                 $user_id User id.
	 * @param array<string,mixed> $data    Operation `data`.
	 * @return void
	 */
	private static function apply_profile_fields( $user_id, array $data ) {
		$update = array( 'ID' => $user_id );

		foreach ( array( 'first_name', 'last_name', 'display_name' ) as $field ) {
			$value = self::normalize_optional_string( $data[ $field ] ?? null );

			if ( null !== $value ) {
				$update[ $field ] = sanitize_text_field( $value );
			}
		}

		if ( count( $update ) > 1 ) {
			wp_update_user( $update );
		}

		foreach ( self::address_fields() as $type => $allowed_fields ) {
			if ( isset( $data[ $type ] ) && is_array( $data[ $type ] ) ) {
				self::apply_address( $user_id, $type, $data[ $type ], $allowed_fields );
			}
		}

		self::apply_nit( $user_id, $data );
	}

	/**
	 * Writes the customer's tax id (NIT) to the field the shop's own
	 * checkout already uses (`Settings::nit_meta_key()`), never to a key of
	 * this plugin's invention: otherwise the number would exist in the
	 * database and nowhere a human can see it.
	 *
	 * Sent as an empty string or `null`, it clears the field; left out, it
	 * is not touched, like every other field here.
	 *
	 * @param int                 $user_id User id.
	 * @param array<string,mixed> $data    Operation `data`.
	 * @return void
	 */
	private static function apply_nit( $user_id, array $data ) {
		if ( ! isset( $data['billing'] ) || ! is_array( $data['billing'] ) || ! array_key_exists( 'nit', $data['billing'] ) ) {
			return;
		}

		$nit = $data['billing']['nit'];
		$nit = null === $nit ? '' : sanitize_text_field( (string) $nit );
		$key = Settings::nit_meta_key();

		if ( '' === $key ) {
			return;
		}

		if ( '' === $nit ) {
			delete_user_meta( $user_id, $key );

			return;
		}

		update_user_meta( $user_id, $key, $nit );
	}

	/**
	 * Reads `customer_type`, the account kind this operation declares.
	 *
	 * @param array<string,mixed> $data Operation `data`.
	 * @return string|null `b2c`, `b2b`, or null when it was not sent.
	 * @throws ItemFailure On any other value.
	 */
	public static function read_customer_type( array $data ) {
		if ( ! array_key_exists( 'customer_type', $data ) || null === $data['customer_type'] ) {
			return null;
		}

		$type = strtolower( trim( (string) $data['customer_type'] ) );

		if ( ! in_array( $type, array( 'b2c', 'b2b' ), true ) ) {
			throw new ItemFailure(
				'invalid_customer_type',
				__( 'The customer type must be b2c or b2b.', 'b2b-price-lists' ),
				'data.customer_type'
			);
		}

		return $type;
	}

	/**
	 * Writes one `billing`/`shipping` object onto `WC_Customer`, field by
	 * field, only for the keys actually present.
	 *
	 * @param int      $user_id        User id.
	 * @param string   $type           `billing` or `shipping`.
	 * @param array    $fields         Submitted fields for this address type.
	 * @param string[] $allowed_fields `address_fields()`'s whitelist for this type.
	 * @return void
	 */
	private static function apply_address( $user_id, $type, array $fields, array $allowed_fields ) {
		if ( ! class_exists( 'WC_Customer' ) ) {
			return;
		}

		$customer = new \WC_Customer( $user_id );
		$changed  = false;

		foreach ( $allowed_fields as $field ) {
			if ( ! array_key_exists( $field, $fields ) || ! is_scalar( $fields[ $field ] ) ) {
				continue;
			}

			$setter = "set_{$type}_{$field}";

			if ( method_exists( $customer, $setter ) ) {
				$customer->{$setter}( sanitize_text_field( (string) $fields[ $field ] ) );
				$changed = true;
			}
		}

		if ( $changed ) {
			$customer->save();
		}
	}

	/**
	 * Stamps `_b2bpl_last_synced_at`/`_b2bpl_last_batch_id` after any
	 * successful `user.*` write.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private static function stamp_sync_metadata( $user_id ) {
		update_user_meta( $user_id, self::META_LAST_SYNCED_AT, gmdate( 'Y-m-d H:i:s' ) );

		$context = Context::get();

		update_user_meta( $user_id, self::META_LAST_BATCH_ID, isset( $context['batch_id'] ) ? (string) $context['batch_id'] : '' );
	}

	/**
	 * Pure classification of a `wc_create_new_customer()` failure
	 *: WooCommerce's own two email-related codes keep their
	 * specific, existing item error code; anything else -- a disabled
	 * registration setting, a database failure, a third-party plugin
	 * rejecting the registration for its own reasons -- becomes the generic
	 * `user_create_failed`, never the misleading `email_invalid` this
	 * correction replaces.
	 *
	 * @param string $wc_error_code `WP_Error::get_error_code()`'s value.
	 * @return string `email_invalid`|`email_in_use`|`user_create_failed`.
	 */
	public static function classify_customer_creation_error( $wc_error_code ) {
		$map = array(
			'registration-error-invalid-email' => 'email_invalid',
			'registration-error-email-exists'  => 'email_in_use',
		);

		return $map[ (string) $wc_error_code ] ?? 'user_create_failed';
	}

	/**
	 * Trims a candidate field to `null`-or-non-empty-string. Pure.
	 *
	 * @param mixed $value Candidate value.
	 * @return string|null
	 */
	public static function normalize_optional_string( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		return '' === $value ? null : $value;
	}

	/**
	 * Whether the native "New account" WooCommerce email should be sent for
	 * a newly created customer: the site-wide setting, or
	 * an explicit `send_welcome_email: true` on this one item, either one is
	 * enough. An explicit `false` on the item never overrides a site-wide
	 * `true` -- the contract gives the item flag no power to opt an account
	 * *out* of a setting the site turned on for everyone, only to opt one in
	 * when the site default is off. Pure.
	 *
	 * @param bool  $global_enabled `Webhook\Settings::get()['send_new_user_email']`.
	 * @param mixed $item_flag      The operation's own `send_welcome_email`, if any.
	 * @return bool
	 */
	public static function should_send_welcome_email( $global_enabled, $item_flag ) {
		return (bool) $global_enabled || true === $item_flag;
	}

	/**
	 * General identity-locator resolution, shared by `assign_list()`/
	 * `unassign_list()`: when both an `external_code`-matched and an
	 * `email`-matched user id are known and they differ, the two identifiers
	 * are ambiguous (`identity_conflict`, contract). Otherwise, whichever one
	 * resolved (external_code first) is the target; neither resolving means
	 * "not found". Pure -- both arguments are already-fetched lookups, never
	 * a database call.
	 *
	 * @param int|null $by_code_user_id  User id found by `external_code`, if any.
	 * @param int|null $by_email_user_id User id found by `email`, if any.
	 * @return array{conflict:bool,user_id:int|null}
	 */
	public static function resolve_locator( $by_code_user_id, $by_email_user_id ) {
		if ( null !== $by_code_user_id && null !== $by_email_user_id && $by_code_user_id !== $by_email_user_id ) {
			return array(
				'conflict' => true,
				'user_id'  => null,
			);
		}

		return array(
			'conflict' => false,
			'user_id'  => $by_code_user_id ?? $by_email_user_id,
		);
	}

	/**
	 * `user.upsert`'s own identity resolution (contract: "external_code
	 * primero y, si no se envía, email"; escenario 2: an `external_code`
	 * match whose email differs from an unrelated account is `email_in_use`,
	 * decided separately by the caller -- never `identity_conflict` here).
	 *
	 * `external_code` always wins when it matches an existing user. Only
	 * when it does *not* match anyone (a brand-new code, or none submitted at
	 * all) does the `email` match take over -- and even then, only as a
	 * genuine conflict when that account already carries a *different*,
	 * non-empty external code than the one just submitted: two different
	 * external identities disagreeing about which local account they mean.
	 * Pure -- every argument is an already-fetched fact, never a database
	 * call.
	 *
	 * @param int|null    $by_code_user_id                    User id found by `external_code`, if any.
	 * @param int|null    $by_email_user_id                   User id found by `email`, if any.
	 * @param string|null $email_owner_current_external_code  The `email`-matched
	 *                                                          user's own,
	 *                                                          already-stored
	 *                                                          `external_code`
	 *                                                          meta, if any
	 *                                                          (irrelevant,
	 *                                                          and safe to pass
	 *                                                          as `null`, when
	 *                                                          `$by_code_user_id`
	 *                                                          is not `null`).
	 * @param string|null $submitted_external_code            The `external_code`
	 *                                                          submitted in this
	 *                                                          operation, if any.
	 * @return array{conflict:bool,user_id:int|null}
	 */
	public static function resolve_upsert_identity( $by_code_user_id, $by_email_user_id, $email_owner_current_external_code, $submitted_external_code ) {
		if ( null !== $by_code_user_id ) {
			return array(
				'conflict' => false,
				'user_id'  => $by_code_user_id,
			);
		}

		if ( null !== $by_email_user_id ) {
			$current   = is_string( $email_owner_current_external_code ) ? trim( $email_owner_current_external_code ) : '';
			$submitted = is_string( $submitted_external_code ) ? trim( $submitted_external_code ) : '';

			if ( '' !== $current && '' !== $submitted && $current !== $submitted ) {
				return array(
					'conflict' => true,
					'user_id'  => null,
				);
			}

			return array(
				'conflict' => false,
				'user_id'  => $by_email_user_id,
			);
		}

		return array(
			'conflict' => false,
			'user_id'  => null,
		);
	}

	/**
	 * Pure decision behind the `linked_existing_account` result flag/log
	 * event (M1, independent review of W4/W5 literal): `true` only
	 * when the identity resolution found *no* account by `external_code`
	 * (a brand new code, never `resolve_upsert_identity()`'s conflict
	 * outcome, which is checked and thrown before this is ever computed),
	 * *did* find one by `email`, and the caller actually submitted an
	 * `external_code` to attach to it -- an `email`-only update (no
	 * `external_code` in the payload at all) is a plain update, not a
	 * "link", and never reported this way.
	 *
	 * @param int|null    $by_code_user_id  User id found by `external_code`, if any.
	 * @param int|null    $by_email_user_id User id found by `email`, if any.
	 * @param string|null $external_code    The `external_code` submitted in this operation, if any.
	 * @return bool
	 */
	public static function is_linked_existing_account( $by_code_user_id, $by_email_user_id, $external_code ) {
		return null === $by_code_user_id && null !== $by_email_user_id && null !== $external_code;
	}
}
