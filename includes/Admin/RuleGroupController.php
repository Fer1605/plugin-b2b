<?php
/**
 * Admin controller for the "Products & prices" tab of a list: lists,
 * creates, edits and deletes rule groups (with fixed/tiered pricing and
 * simple/all_variations/variation_group scopes). Delegates every business
 * validation and persistence decision to `Domain\RuleGroupService`.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Domain\ProductLookup;
use B2BPriceLists\Domain\RuleGroupService;
use B2BPriceLists\Domain\RuleGroupValidator;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\PriceRangesRepository;
use B2BPriceLists\Repository\RuleGroupsRepository;
use B2BPriceLists\Repository\RuleGroupVariationsRepository;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\MoneyFormat;
use B2BPriceLists\Support\Nonces;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RuleGroupController
 */
class RuleGroupController {

	/**
	 * Shared instance.
	 *
	 * @var RuleGroupController|null
	 */
	private static $instance = null;

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
	 * Rule group validation/persistence service.
	 *
	 * @var RuleGroupService
	 */
	private $service;

	/**
	 * WooCommerce product/variation lookup helper.
	 *
	 * @var ProductLookup
	 */
	private $products;

	/**
	 * Lists repository -- only used to put the list's own name on every
	 * screen below it, so an admin editing rules always knows which list
	 * they are inside.
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * Returns the shared instance.
	 *
	 * @return RuleGroupController
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->rule_groups = new RuleGroupsRepository();
		$this->variations  = new RuleGroupVariationsRepository();
		$this->ranges      = new PriceRangesRepository();
		$this->service     = new RuleGroupService();
		$this->products    = new ProductLookup();
		$this->lists       = new ListsRepository();
	}

	/**
	 * The list row every screen in this tab belongs to, or null when it no
	 * longer exists (the screens degrade to showing just the id rather than
	 * failing).
	 *
	 * @param int $list_id List id.
	 * @return array<string,mixed>|null
	 */
	private function get_list( $list_id ) {
		return $this->lists->find( (int) $list_id );
	}

	/**
	 * Builds the base URL used within the "groups" tab.
	 *
	 * Public (not private): views render it via `call_user_func( $tab_url, ... )`
	 * from outside this class's scope, which PHP only allows for
	 * public/protected visibility regardless of the bound `$this`.
	 *
	 * @param int   $list_id List id.
	 * @param array $args    Extra query args.
	 * @return string
	 */
	public function tab_url( $list_id, array $args = array() ) {
		$base = array(
			'page'   => Menu::PAGE_SLUG,
			'action' => 'edit',
			'id'     => $list_id,
			'tab'    => 'groups',
		);

		return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Entry point called by `ListsController` when the "groups" tab is
	 * active. Not registered directly with WordPress.
	 *
	 * @param int $list_id List id.
	 * @return void
	 */
	public function render_tab( $list_id ) {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		$group_action = isset( $_GET['group_action'] ) ? sanitize_key( wp_unslash( $_GET['group_action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $group_action ) {
			case 'new':
				$this->render_form( $list_id, null );
				break;

			case 'edit':
				$this->render_form( $list_id, isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				break;

			case 'delete':
				$this->render_delete_confirmation( $list_id, isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				break;

			case 'list':
			default:
				$this->render_list( $list_id );
				break;
		}
	}

	/**
	 * Renders the rule groups table for a list.
	 *
	 * @param int $list_id List id.
	 * @return void
	 */
	private function render_list( $list_id ) {
		$groups = $this->rule_groups->get_by_list( $list_id );

		$rows = array();

		foreach ( $groups as $group ) {
			$group['product_label'] = $this->products->get_product_label( $group['product_id'] );
			$group['product_sku']   = $this->products->get_sku( $group['product_id'] );
			$group['price_summary'] = $this->price_summary( $group );
			$group['offer_summary'] = $this->offer_summary( $group );

			if ( RuleGroupValidator::SCOPE_VARIATION_GROUP === $group['scope'] ) {
				$labels = array();

				foreach ( $this->variations->get_variation_ids( $group['id'] ) as $variation_id ) {
					$labels[] = $this->products->get_variation_label( $variation_id );
				}

				$group['variation_labels'] = $labels;
			} else {
				$group['variation_labels'] = array();
			}

			$rows[] = $group;
		}

		$notice = isset( $_GET['b2bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		View::render(
			'rule-groups-table',
			array(
				'list_id' => $list_id,
				'list'    => $this->get_list( $list_id ),
				'groups'  => $rows,
				'tab_url' => array( $this, 'tab_url' ),
				'notice'  => $notice,
			)
		);
	}

	/**
	 * A short, translator-friendly one-line summary of a group's price
	 * ("Q80.00 from 3 units", or "3 tiers, from 2 units").
	 *
	 * @param array $group Rule group row.
	 * @return string Escaped HTML (uses `wc_price()`).
	 */
	private function price_summary( array $group ) {
		if ( RuleGroupValidator::PRICING_MODE_FIXED === $group['pricing_mode'] ) {
			return sprintf(
				/* translators: 1: formatted unit price, 2: minimum quantity. */
				__( '%1$s from %2$d unit(s)', 'b2b-price-lists' ),
				wc_price( (float) $group['unit_price'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price() already returns safe HTML.
				(int) $group['minimum_quantity']
			);
		}

		$ranges = $this->ranges->get_by_rule_group( $group['id'] );

		if ( empty( $ranges ) ) {
			return __( '(no price ranges yet)', 'b2b-price-lists' );
		}

		$first = $ranges[0];

		return sprintf(
			/* translators: 1: number of ranges, 2: minimum quantity of the first range. */
			_n( '%1$d range, from %2$d unit(s)', '%1$d ranges, from %2$d unit(s)', count( $ranges ), 'b2b-price-lists' ),
			count( $ranges ),
			(int) $first['min_qty']
		);
	}

	/**
	 * A short description of the offer(s) attached to a rule, for the rules
	 * table: the rule's own percentage, or how many of its ranges carry one
	 * -- so an admin can see at a glance which rows are discounted without
	 * opening each of them.
	 *
	 * @param array $group Rule group row.
	 * @return array{percent:float|null, ranges_on_offer:int}
	 */
	private function offer_summary( array $group ) {
		$ranges_on_offer = 0;

		if ( RuleGroupValidator::PRICING_MODE_TIERED === $group['pricing_mode'] ) {
			foreach ( $this->ranges->get_by_rule_group( $group['id'] ) as $range ) {
				if ( null !== Discount::normalize( $range['discount_percent'] ?? null ) ) {
					++$ranges_on_offer;
				}
			}
		}

		return array(
			'percent'         => Discount::normalize( $group['discount_percent'] ?? null ),
			'ranges_on_offer' => $ranges_on_offer,
		);
	}

	/**
	 * Renders the create/edit rule group form.
	 *
	 * @param int      $list_id       List id.
	 * @param int|null $rule_group_id Rule group id, or null when creating.
	 * @return void
	 */
	private function render_form( $list_id, $rule_group_id ) {
		$group = null;

		if ( $rule_group_id ) {
			$group = $this->rule_groups->find( $rule_group_id );

			if ( ! $group || (int) $group['list_id'] !== (int) $list_id ) {
				wp_die( esc_html__( 'The rule could not be found.', 'b2b-price-lists' ) );
			}
		}

		$pending = $this->consume_pending_input();

		$variation_ids  = array();
		$ranges         = array();
		$product_label  = '';
		$all_variations = array();

		if ( $group ) {
			$variation_ids = $this->variations->get_variation_ids( $group['id'] );
			$ranges        = $this->ranges->get_by_rule_group( $group['id'] );
		}

		// the product is now editable, so a resubmission
		// being replayed after a validation error may name a DIFFERENT
		// product than the rule's own in the database -- the label and
		// variation checklist shown must follow that pending product, never
		// silently fall back to the original one (which would make the
		// admin's product change look discarded).
		$pending_product_id = is_array( $pending ) && isset( $pending['product_id'] ) ? absint( $pending['product_id'] ) : 0;
		$display_product_id = $pending_product_id ? $pending_product_id : ( $group ? (int) $group['product_id'] : 0 );

		if ( $display_product_id ) {
			$product_label  = $this->products->get_product_label( $display_product_id );
			$all_variations = $this->products->get_variation_ids( $display_product_id );
		}

		View::render(
			'rule-group-form',
			array(
				'list_id'        => $list_id,
				'list'           => $this->get_list( $list_id ),
				'group'          => $group,
				'pending'        => $pending,
				'variation_ids'  => $variation_ids,
				'ranges'         => $ranges,
				'product_label'  => $product_label,
				'all_variations' => $all_variations,
				'products'       => $this->products,
				'tab_url'        => array( $this, 'tab_url' ),
				'nonce_field'    => Nonces::ADMIN_ACTION,
				'nonce_name'     => Nonces::FIELD_NAME,
				'ajax_nonce'     => wp_create_nonce( Nonces::AJAX_ACTION ),
			)
		);
	}

	/**
	 * Handles the create/edit form submission.
	 *
	 * @return void
	 */
	public function handle_save() {
		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$list_id       = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
		$rule_group_id = isset( $_POST['rule_group_id'] ) ? absint( $_POST['rule_group_id'] ) : 0;

		// the product of an existing rule group is now
		// editable through the same AJAX product selector used when
		// creating one -- `RuleGroupService::save()` re-validates scope,
		// duplicate-scope and variation-membership against whichever
		// product ends up submitted here, and replaces (never leaves
		// orphaned) any variation relationship that no longer matches.
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		$scope        = isset( $_POST['scope'] ) && is_scalar( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$pricing_mode = isset( $_POST['pricing_mode'] ) && is_scalar( $_POST['pricing_mode'] ) ? sanitize_key( wp_unslash( $_POST['pricing_mode'] ) ) : '';

		// WooCommerce's own configured decimal/thousand separators are the
		// only source of truth for interpreting a submitted price string --
		// never a convention invented by this plugin (e.g. "1,000" and
		// "1.000" resolve to different values depending on this config).
		$money_decimal_separator  = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		$money_thousand_separator = function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',';

		$unit_price       = isset( $_POST['unit_price'] ) && is_scalar( $_POST['unit_price'] ) && '' !== $_POST['unit_price']
			? MoneyFormat::parse( sanitize_text_field( wp_unslash( $_POST['unit_price'] ) ), $money_decimal_separator, $money_thousand_separator )
			: null;
		$minimum_quantity = isset( $_POST['minimum_quantity'] ) && is_scalar( $_POST['minimum_quantity'] ) ? absint( $_POST['minimum_quantity'] ) : 1;
		// Rule-wide offer: kept as the raw submitted string so an
		// out-of-range percentage is rejected with a visible message by
		// `Domain\RuleGroupValidator`, never silently dropped.
		$discount_percent = isset( $_POST['discount_percent'] ) && is_scalar( $_POST['discount_percent'] )
			? sanitize_text_field( wp_unslash( $_POST['discount_percent'] ) )
			: '';
		$name             = isset( $_POST['name'] ) && is_scalar( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$variation_ids    = isset( $_POST['variation_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['variation_ids'] ) ) : array();

		$ranges = array();

		if ( isset( $_POST['range_min'] ) && is_array( $_POST['range_min'] ) ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized by sanitize_text_field()/MoneyFormat::parse() below; array_filter( ..., 'is_scalar' ) only guards against a malformed request nesting arrays inside the range fields before that sanitization runs.
			$mins   = array_map( 'sanitize_text_field', array_filter( wp_unslash( $_POST['range_min'] ), 'is_scalar' ) );
			$maxes  = isset( $_POST['range_max'] ) && is_array( $_POST['range_max'] ) ? array_map( 'sanitize_text_field', array_filter( wp_unslash( $_POST['range_max'] ), 'is_scalar' ) ) : array();
			$prices = isset( $_POST['range_price'] ) && is_array( $_POST['range_price'] )
				? array_map(
					static function ( $raw ) use ( $money_decimal_separator, $money_thousand_separator ) {
						return MoneyFormat::parse( sanitize_text_field( $raw ), $money_decimal_separator, $money_thousand_separator );
					},
					array_filter( wp_unslash( $_POST['range_price'] ), 'is_scalar' )
				)
				: array();
			// A percentage is never money: it is not parsed through
			// MoneyFormat (which follows the store's currency separators)
			// but read as a plain number, so "12.5" always means twelve and
			// a half per cent whatever the currency is configured to use.
			$range_discounts = isset( $_POST['range_discount'] ) && is_array( $_POST['range_discount'] )
				? array_map( 'sanitize_text_field', array_filter( wp_unslash( $_POST['range_discount'] ), 'is_scalar' ) )
				: array();
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			foreach ( $mins as $index => $min ) {
				if ( '' === trim( (string) $min ) ) {
					continue;
				}

				$ranges[] = array(
					'min_qty'          => $min,
					'max_qty'          => isset( $maxes[ $index ] ) ? $maxes[ $index ] : null,
					'unit_price'       => isset( $prices[ $index ] ) ? $prices[ $index ] : null,
					'discount_percent' => isset( $range_discounts[ $index ] ) ? $range_discounts[ $index ] : null,
				);
			}
		}

		$result = $this->service->save(
			array(
				'rule_group_id'    => $rule_group_id,
				'list_id'          => $list_id,
				'product_id'       => $product_id,
				'scope'            => $scope,
				'pricing_mode'     => $pricing_mode,
				'unit_price'       => $unit_price,
				'minimum_quantity' => $minimum_quantity,
				'discount_percent' => $discount_percent,
				'name'             => $name,
				'variation_ids'    => $variation_ids,
				'ranges'           => $ranges,
			)
		);

		$return_url = $rule_group_id
			? $this->tab_url(
				$list_id,
				array(
					'group_action' => 'edit',
					'group_id'     => $rule_group_id,
				)
			)
			: $this->tab_url( $list_id, array( 'group_action' => 'new' ) );

		if ( is_wp_error( $result ) ) {
			$this->store_pending_input( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via check_admin_referer().
			$this->redirect_with_error( $return_url, $result );

			return;
		}

		wp_safe_redirect(
			$this->tab_url(
				$list_id,
				array(
					'group_action' => 'edit',
					'group_id'     => $result,
					'b2bpl_notice' => 'saved',
				)
			)
		);
	}

	/**
	 * Renders the rule group deletion confirmation screen.
	 *
	 * @param int $list_id       List id.
	 * @param int $rule_group_id Rule group id.
	 * @return void
	 */
	private function render_delete_confirmation( $list_id, $rule_group_id ) {
		$group = $this->rule_groups->find( $rule_group_id );

		if ( ! $group || (int) $group['list_id'] !== (int) $list_id ) {
			wp_die( esc_html__( 'The rule could not be found.', 'b2b-price-lists' ) );
		}

		$group['product_label'] = $this->products->get_product_label( $group['product_id'] );

		View::render(
			'rule-group-delete-confirm',
			array(
				'list_id'     => $list_id,
				'list'        => $this->get_list( $list_id ),
				'group'       => $group,
				'tab_url'     => array( $this, 'tab_url' ),
				'nonce_field' => Nonces::ADMIN_ACTION,
				'nonce_name'  => Nonces::FIELD_NAME,
			)
		);
	}

	/**
	 * Handles the confirmed rule group deletion POST.
	 *
	 * @return void
	 */
	public function handle_delete() {
		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$list_id       = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
		$rule_group_id = isset( $_POST['rule_group_id'] ) ? absint( $_POST['rule_group_id'] ) : 0;

		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect(
				$this->tab_url(
					$list_id,
					array(
						'group_action' => 'delete',
						'group_id'     => $rule_group_id,
					)
				)
			);

			return;
		}

		$this->service->delete( $rule_group_id );

		wp_safe_redirect( $this->tab_url( $list_id, array( 'b2bpl_notice' => 'deleted' ) ) );
	}

	/**
	 * Persists the raw POST data of a failed submission for one request,
	 * so the form can be re-rendered without losing the admin's input.
	 *
	 * @param array $post_data Raw $_POST.
	 * @return void
	 */
	private function store_pending_input( array $post_data ) {
		set_transient( 'b2bpl_pending_group_' . get_current_user_id(), $post_data, 60 );
	}

	/**
	 * Reads and clears the pending form input set by a previous failed
	 * submission, if any.
	 *
	 * @return array|null
	 */
	private function consume_pending_input() {
		$key   = 'b2bpl_pending_group_' . get_current_user_id();
		$value = get_transient( $key );

		if ( false !== $value ) {
			delete_transient( $key );

			return $value;
		}

		return null;
	}

	/**
	 * Redirects with a one-time error message (mirrors
	 * `ListsController::redirect_with_error()`).
	 *
	 * @param string   $url   Target URL.
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function redirect_with_error( $url, WP_Error $error ) {
		set_transient( 'b2bpl_admin_error_' . get_current_user_id(), $error->get_error_message(), 60 );
		wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'error', $url ) );
	}
}
