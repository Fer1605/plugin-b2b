<?php
/**
 * Orchestrates list CRUD, cascading deletion and duplication.
 *
 * @package B2BPriceLists\Domain
 */

namespace B2BPriceLists\Domain;

use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Activity\ChangeSet;
use B2BPriceLists\Database\Transaction;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\ListUsersRepository;
use B2BPriceLists\Repository\PriceRangesRepository;
use B2BPriceLists\Repository\RuleGroupsRepository;
use B2BPriceLists\Repository\RuleGroupVariationsRepository;
use B2BPriceLists\Support\Cache;
use B2BPriceLists\Support\SlugGenerator;
use RuntimeException;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ListService
 */
class ListService {

	const STATUS_ACTIVE   = 'active';
	const STATUS_INACTIVE = 'inactive';

	/**
	 * Lists repository.
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * List/user assignment repository.
	 *
	 * @var ListUsersRepository
	 */
	private $list_users;

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
	 * Constructor.
	 *
	 * @param ListsRepository|null               $lists       Optional, for tests.
	 * @param ListUsersRepository|null           $list_users  Optional, for tests.
	 * @param RuleGroupsRepository|null          $rule_groups Optional, for tests.
	 * @param RuleGroupVariationsRepository|null $variations  Optional, for tests.
	 * @param PriceRangesRepository|null         $ranges      Optional, for tests.
	 */
	public function __construct(
		?ListsRepository $lists = null,
		?ListUsersRepository $list_users = null,
		?RuleGroupsRepository $rule_groups = null,
		?RuleGroupVariationsRepository $variations = null,
		?PriceRangesRepository $ranges = null
	) {
		$this->lists       = $lists ?? new ListsRepository();
		$this->list_users  = $list_users ?? new ListUsersRepository();
		$this->rule_groups = $rule_groups ?? new RuleGroupsRepository();
		$this->variations  = $variations ?? new RuleGroupVariationsRepository();
		$this->ranges      = $ranges ?? new PriceRangesRepository();
	}

	/**
	 * Valid list statuses (no "archived", no date-based states).
	 *
	 * @return string[]
	 */
	public static function valid_statuses() {
		return array( self::STATUS_ACTIVE, self::STATUS_INACTIVE );
	}

	/**
	 * Validates the fields common to create/update.
	 *
	 * @param array    $input      name, status, external_code (optional).
	 * @param int|null $exclude_id List id to exclude from the `external_code`
	 *                              uniqueness check (the list currently being
	 *                              edited), `null` when creating.
	 * @return string[]
	 */
	private function validate_fields( array $input, $exclude_id = null ) {
		$errors = array();

		if ( '' === trim( (string) ( $input['name'] ?? '' ) ) ) {
			$errors[] = __( 'The list name is required.', 'b2b-price-lists' );
		}

		if ( ! in_array( (string) ( $input['status'] ?? '' ), self::valid_statuses(), true ) ) {
			$errors[] = __( 'Invalid list status.', 'b2b-price-lists' );
		}

		$errors = array_merge(
			$errors,
			Discount::validate(
				$input['discount_percent'] ?? null,
				__( 'Offer for the whole list', 'b2b-price-lists' )
			)
		);

		$errors = array_merge( $errors, $this->validate_external_code( $input['external_code'] ?? '', $exclude_id ) );

		return $errors;
	}

	/**
	 * Validates the optional `external_code` field (T026/): format
	 * (non-empty candidates capped at the column's own length) and
	 * uniqueness across every other list. An empty candidate is always valid
	 * -- `external_code` is optional for a list created from the panel.
	 *
	 * @param mixed    $external_code Candidate external code (empty string/`null` = none).
	 * @param int|null $exclude_id    List id to exclude, `null` when creating.
	 * @return string[]
	 */
	private function validate_external_code( $external_code, $exclude_id = null ) {
		$external_code = trim( (string) $external_code );

		if ( '' === $external_code ) {
			return array();
		}

		if ( mb_strlen( $external_code ) > 100 ) {
			return array( __( 'The external code cannot be longer than 100 characters.', 'b2b-price-lists' ) );
		}

		if ( $this->lists->external_code_exists( $external_code, $exclude_id ) ) {
			return array( __( 'This external code is already used by another list.', 'b2b-price-lists' ) );
		}

		return array();
	}

	/**
	 * Creates a new list.
	 *
	 * @param array $input name, description, status, slug (optional
	 *                     preferred candidate), created_by.
	 * @return int|WP_Error New list id.
	 */
	public function create( array $input ) {
		$errors = $this->validate_fields( $input );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'b2bpl_invalid_list', implode( ' ', $errors ) );
		}

		$slug = SlugGenerator::generate(
			(string) $input['name'],
			function ( $candidate ) {
				return $this->lists->slug_exists( $candidate );
			},
			(string) ( $input['slug'] ?? '' )
		);

		// Falls back to whoever is signed in: the admin screens always pass
		// this, but a programmatic caller has no reason to know it exists,
		// and an audit trail must never lose its author to a missing key.
		$user_id = (int) ( $input['created_by'] ?? get_current_user_id() );

		$id = $this->lists->insert(
			array(
				'name'             => (string) $input['name'],
				'slug'             => $slug,
				'description'      => (string) ( $input['description'] ?? '' ),
				'status'           => (string) $input['status'],
				'discount_percent' => Discount::normalize( $input['discount_percent'] ?? null ),
				'external_code'    => isset( $input['external_code'] ) ? trim( (string) $input['external_code'] ) : '',
				'created_by'       => $user_id,
				'updated_by'       => $user_id,
			)
		);

		if ( ! $id ) {
			return new WP_Error( 'b2bpl_save_failed', __( 'The list could not be created.', 'b2b-price-lists' ) );
		}

		ActivityLog::record(
			ActivityLog::LIST_CREATED,
			array(
				'object_type' => 'list',
				'object_id'   => $id,
				'list_id'     => $id,
				'list_name'   => (string) $input['name'],
				// A creation is logged with the values it started from, so
				// the very first entry of a list already says what it was.
				'changes'     => self::as_initial( ChangeSet::for_list( array(), $input ) ),
			)
		);

		return $id;
	}

	/**
	 * Updates an existing list.
	 *
	 * @param int   $id    List id.
	 * @param array $input name, description, status, slug (optional
	 *                     preferred candidate), updated_by.
	 * @return true|WP_Error
	 */
	public function update( $id, array $input ) {
		$id = (int) $id;

		$existing = $this->lists->find( $id );

		if ( ! $existing ) {
			return new WP_Error( 'b2bpl_not_found', __( 'The list could not be found.', 'b2b-price-lists' ) );
		}

		$errors = $this->validate_fields( $input, $id );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'b2bpl_invalid_list', implode( ' ', $errors ) );
		}

		$offer  = Discount::normalize( $input['discount_percent'] ?? null );
		$zeroed = $this->find_prices_zeroed_by_list_offer( $id, $offer );

		if ( ! empty( $zeroed ) ) {
			return new WP_Error(
				'b2bpl_offer_zeroes_prices',
				sprintf(
					/* translators: 1: offer percentage, 2: how many prices it would zero, 3: comma-separated product ids. */
					_n(
						'A %1$s%% offer would leave %2$d price in this list at zero (product %3$s). Lower the percentage, or give that product its own offer.',
						'A %1$s%% offer would leave %2$d prices in this list at zero (products %3$s). Lower the percentage, or give those products their own offer.',
						count( $zeroed ),
						'b2b-price-lists'
					),
					Discount::format_percent( $offer ),
					count( $zeroed ),
					implode(
						', ',
						array_map(
							static function ( $product_id ) {
								return '#' . (int) $product_id;
							},
							array_unique( $zeroed )
						)
					)
				)
			);
		}

		$preferred = (string) ( $input['slug'] ?? $existing['slug'] );

		$slug = SlugGenerator::generate(
			(string) $input['name'],
			function ( $candidate ) use ( $id ) {
				return $this->lists->slug_exists( $candidate, $id );
			},
			$preferred
		);

		$update_data = array(
			'name'             => (string) $input['name'],
			'slug'             => $slug,
			'description'      => (string) ( $input['description'] ?? '' ),
			'status'           => (string) $input['status'],
			'discount_percent' => Discount::normalize( $input['discount_percent'] ?? null ),
			'updated_by'       => (int) ( $input['updated_by'] ?? get_current_user_id() ),
		);

		// `external_code` is only ever written when the caller explicitly
		// provided the key -- see `ListsRepository::update()`'s docblock.
		if ( array_key_exists( 'external_code', $input ) ) {
			$update_data['external_code'] = trim( (string) $input['external_code'] );
		}

		$updated = $this->lists->update( $id, $update_data );

		if ( ! $updated ) {
			return new WP_Error( 'b2bpl_save_failed', __( 'The list could not be updated.', 'b2b-price-lists' ) );
		}

		$changes = ChangeSet::for_list( $existing, $input );

		// Diffed manually, never via `ChangeSet::for_list()`'s generic field
		// loop: `$input` only carries `external_code` when the caller
		// explicitly set the key (see above), so treating its *absence* the
		// same as an explicit empty string there would wrongly log "cleared"
		// on every call site that never touches this field at all (e.g.
		// `ListsController::handle_toggle_status()`).
		if ( array_key_exists( 'external_code', $update_data ) ) {
			$existing_code = isset( $existing['external_code'] ) ? (string) $existing['external_code'] : '';

			if ( $existing_code !== $update_data['external_code'] ) {
				$changes[] = array(
					'field' => 'external_code',
					'from'  => $existing_code,
					'to'    => $update_data['external_code'],
				);
			}
		}

		// A save that changed nothing is not an event worth a line in the
		// log: it would only bury the edits that did change something.
		if ( ! empty( $changes ) ) {
			ActivityLog::record(
				ActivityLog::LIST_UPDATED,
				array(
					'object_type' => 'list',
					'object_id'   => $id,
					'list_id'     => $id,
					'list_name'   => (string) $input['name'],
					'changes'     => $changes,
				)
			);
		}

		Cache::invalidate_list( $id );
		Cache::invalidate_users( $this->list_users->get_user_ids_for_list( $id ) );

		return true;
	}

	/**
	 * Partial update for `list.update` (contract: "actualiza solo los campos
	 * enviados"), without changing `update` itself (T023/): merges
	 * only the keys present in `$fields` onto the existing row, then
	 * delegates the actual write, validation and side effects entirely to
	 * `update()` -- so a webhook `list.update` behaves exactly like an admin
	 * re-submitting the full panel form with every other field left as-is.
	 *
	 * @param int                 $id     List id.
	 * @param array<string,mixed> $fields Any subset of `name`, `slug`,
	 *                                    `description`, `status`,
	 *                                    `discount_percent`, `external_code`.
	 *                                    Keys not present are left untouched.
	 * @param int|null            $actor_user_id `updated_by`; defaults to the
	 *                                    current user, exactly like `update()`.
	 * @return true|WP_Error
	 */
	public function update_partial( $id, array $fields, $actor_user_id = null ) {
		$id       = (int) $id;
		$existing = $this->lists->find( $id );

		if ( ! $existing ) {
			return new WP_Error( 'b2bpl_not_found', __( 'The list could not be found.', 'b2b-price-lists' ) );
		}

		$merged = array(
			'name'             => array_key_exists( 'name', $fields ) ? $fields['name'] : $existing['name'],
			'slug'             => array_key_exists( 'slug', $fields ) ? $fields['slug'] : $existing['slug'],
			'description'      => array_key_exists( 'description', $fields ) ? $fields['description'] : $existing['description'],
			'status'           => array_key_exists( 'status', $fields ) ? $fields['status'] : $existing['status'],
			'discount_percent' => array_key_exists( 'discount_percent', $fields ) ? $fields['discount_percent'] : $existing['discount_percent'],
			'external_code'    => array_key_exists( 'external_code', $fields ) ? $fields['external_code'] : $existing['external_code'],
			'updated_by'       => null !== $actor_user_id ? (int) $actor_user_id : get_current_user_id(),
		);

		return $this->update( $id, $merged );
	}

	/**
	 * Marks a change set as the initial state of something just created, so
	 * the log reads "Status: Active" instead of "(empty) → Active".
	 *
	 * @param array $changes Change set.
	 * @return array
	 */
	private static function as_initial( array $changes ) {
		foreach ( $changes as $index => $change ) {
			$changes[ $index ]['initial'] = true;
		}

		return $changes;
	}

	/**
	 * Product ids in this list whose price would round down to zero under a
	 * list-wide offer.
	 *
	 * A percentage under 100 can still produce a zero once rounded to the
	 * store's decimals (0.40 at -99% is 0.00 at two decimals), and a free
	 * item would then flow untouched into the cart, the order and the
	 * invoice. The rule editor already refuses this one product at a time;
	 * this is the same guard for the one field that can zero many prices at
	 * once. Rules and ranges that carry their own offer are skipped: the
	 * list's percentage never reaches them.
	 *
	 * @param int        $list_id List id.
	 * @param float|null $percent Already-normalized list-wide offer.
	 * @return int[] Offending product ids (may repeat across ranges).
	 */
	private function find_prices_zeroed_by_list_offer( $list_id, $percent ) {
		if ( null === $percent ) {
			return array();
		}

		$decimals  = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$offenders = array();

		foreach ( $this->rule_groups->get_by_list( (int) $list_id ) as $group ) {
			if ( null !== Discount::normalize( $group['discount_percent'] ?? null ) ) {
				continue; // Its own offer wins; the list's never reaches it.
			}

			if ( RuleGroupValidator::PRICING_MODE_FIXED === $group['pricing_mode'] ) {
				if ( isset( $group['unit_price'] ) && null !== $group['unit_price']
					&& Discount::apply( $group['unit_price'], $percent, $decimals ) <= 0 ) {
					$offenders[] = (int) $group['product_id'];
				}

				continue;
			}

			foreach ( $this->ranges->get_by_rule_group( (int) $group['id'] ) as $range ) {
				if ( null !== Discount::normalize( $range['discount_percent'] ?? null ) ) {
					continue;
				}

				if ( Discount::apply( $range['unit_price'], $percent, $decimals ) <= 0 ) {
					$offenders[] = (int) $group['product_id'];
				}
			}
		}

		return $offenders;
	}

	/**
	 * Numbers of users and distinct products affected by deleting a list,
	 * shown on the confirmation screen before an irreversible delete
	 *.
	 *
	 * @param int $id List id.
	 * @return array{users:int, products:int}
	 */
	public function get_affected_counts( $id ) {
		$id = (int) $id;

		return array(
			'users'    => $this->list_users->count_for_list( $id ),
			'products' => $this->rule_groups->count_distinct_products_for_list( $id ),
		);
	}

	/**
	 * Permanently deletes a list and everything that depends on it, inside
	 * a single transaction: assignments, rule groups, variation
	 * relationships, ranges, then the list itself. Never touches historical
	 * orders (their audit snapshot, is independent of these rows).
	 *
	 * @param int $id List id.
	 * @return true|WP_Error
	 */
	public function delete( $id ) {
		$id = (int) $id;

		$existing = $this->lists->find( $id );

		if ( ! $existing ) {
			return new WP_Error( 'b2bpl_not_found', __( 'The list could not be found.', 'b2b-price-lists' ) );
		}

		$affected_user_ids = $this->list_users->get_user_ids_for_list( $id );
		// Counted before the rows are gone, so the log entry can state the
		// real scope of what was destroyed.
		$affected_products = $this->rule_groups->count_distinct_products_for_list( $id );

		try {
			Transaction::run(
				function () use ( $id ) {
					$rule_group_ids = $this->rule_groups->get_ids_by_list( $id );

					$this->ranges->delete_by_rule_group_ids( $rule_group_ids );
					$this->variations->delete_by_rule_group_ids( $rule_group_ids );

					if ( ! $this->rule_groups->delete_by_list_id( $id ) ) {
						throw new RuntimeException( 'Failed to delete rule groups.' );
					}

					if ( ! $this->list_users->delete_by_list_id( $id ) ) {
						throw new RuntimeException( 'Failed to delete user assignments.' );
					}

					if ( ! $this->lists->delete( $id ) ) {
						throw new RuntimeException( 'Failed to delete the list.' );
					}

					return true;
				}
			);
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'b2bpl_delete_failed', __( 'The list could not be deleted. No changes were made.', 'b2b-price-lists' ) );
		}

		ActivityLog::record(
			ActivityLog::LIST_DELETED,
			array(
				'object_type' => 'list',
				'object_id'   => $id,
				'list_id'     => $id,
				'list_name'   => (string) $existing['name'],
				'changes'     => array(
					array(
						'field' => 'affected_users',
						'count' => count( $affected_user_ids ),
					),
					array(
						'field' => 'affected_products',
						'count' => $affected_products,
					),
				),
			)
		);

		// Affected users must see B2C pricing immediately (TC-071): invalidate
		// their per-user cache in addition to the list-wide cache.
		Cache::invalidate_list( $id );
		Cache::invalidate_users( $affected_user_ids );

		return true;
	}

	/**
	 * Duplicates a list's structure (name, description, status, rule
	 * groups, variation relationships and price ranges) under a new,
	 * disambiguated slug.
	 *
	 * User assignments are intentionally NOT duplicated: because a user
	 * can hold at most one list (UNIQUE(user_id)), copying
	 * assignments to the new list would silently reassign those users away
	 * from the original list instead of duplicating anything for them.
	 *
	 * @param int $id      List id to duplicate.
	 * @param int $user_id Current user id, recorded as creator/updater of
	 *                     the new list.
	 * @return int|WP_Error New list id.
	 */
	public function duplicate( $id, $user_id ) {
		$id     = (int) $id;
		$source = $this->lists->find( $id );

		if ( ! $source ) {
			return new WP_Error( 'b2bpl_not_found', __( 'The list could not be found.', 'b2b-price-lists' ) );
		}

		/* translators: %s: original list name. */
		$new_name = sprintf( __( '%s (copy)', 'b2b-price-lists' ), $source['name'] );

		try {
			return Transaction::run(
				function () use ( $source, $new_name, $user_id, $id ) {
					$slug = SlugGenerator::generate(
						$new_name,
						function ( $candidate ) {
							return $this->lists->slug_exists( $candidate );
						}
					);

					$new_id = $this->lists->insert(
						array(
							'name'             => $new_name,
							'slug'             => $slug,
							'description'      => $source['description'],
							'status'           => self::STATUS_INACTIVE, // Safer default: never silently duplicate an active list live.
							'discount_percent' => Discount::normalize( $source['discount_percent'] ?? null ),
							'created_by'       => (int) $user_id,
							'updated_by'       => (int) $user_id,
						)
					);

					if ( ! $new_id ) {
						throw new RuntimeException( 'Failed to duplicate the list.' );
					}

					foreach ( $this->rule_groups->get_by_list( $id ) as $group ) {
						$new_group_id = $this->rule_groups->insert(
							array(
								'list_id'          => $new_id,
								'product_id'       => $group['product_id'],
								'scope'            => $group['scope'],
								'pricing_mode'     => $group['pricing_mode'],
								'unit_price'       => $group['unit_price'],
								'discount_percent' => $group['discount_percent'] ?? null,
								'minimum_quantity' => $group['minimum_quantity'],
								'name'             => $group['name'],
							)
						);

						if ( ! $new_group_id ) {
							throw new RuntimeException( 'Failed to duplicate a rule group.' );
						}

						$variation_ids = $this->variations->get_variation_ids( $group['id'] );

						if ( ! empty( $variation_ids ) ) {
							$this->variations->replace_for_group( $new_group_id, $variation_ids );
						}

						$ranges = $this->ranges->get_by_rule_group( $group['id'] );

						if ( ! empty( $ranges ) ) {
							$this->ranges->replace_for_group( $new_group_id, $ranges );
						}
					}

					ActivityLog::record(
						ActivityLog::LIST_DUPLICATED,
						array(
							'object_type' => 'list',
							'object_id'   => $new_id,
							'list_id'     => $new_id,
							'list_name'   => $new_name,
							'changes'     => array(
								array(
									'field' => 'copied_from',
									'label' => (string) $source['name'],
									'id'    => (int) $id,
								),
							),
						)
					);

					return $new_id;
				}
			);
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'b2bpl_duplicate_failed', __( 'The list could not be duplicated. No changes were made.', 'b2b-price-lists' ) );
		}
	}
}
