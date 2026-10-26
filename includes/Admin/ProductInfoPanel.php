<?php
/**
 * Read-only "B2B Lists" tab in the product editor (Fase 8, part F,
 *, architecture-analysis.md section 17.1): shows every
 * list that includes the product being edited (active or inactive), one
 * independent entry per list even when the product appears in several,
 * with a deep-link to edit the rule from the central panel. No editing
 * happens from this screen.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Domain\ProductLookup;
use B2BPriceLists\Domain\RuleGroupValidator;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\ListUsersRepository;
use B2BPriceLists\Repository\PriceRangesRepository;
use B2BPriceLists\Repository\RuleGroupsRepository;
use B2BPriceLists\Repository\RuleGroupVariationsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductInfoPanel
 */
class ProductInfoPanel {

	/**
	 * Lists per page (pagination for a heavily-shared product, section 17.1).
	 *
	 * @var int
	 */
	const PER_PAGE = 10;

	/**
	 * Registers the WooCommerce product editor hooks. Called once from
	 * `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
	}

	/**
	 * Adds the "B2B Lists" tab. Reuses WooCommerce's own native
	 * `edit_post`/`edit_product` gate for the screen itself -- no
	 * additional capability is required just to see this read-only panel
	 * (architecture-analysis.md section 17.1).
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs['b2bpl_lists'] = array(
			'label'    => __( 'B2B Lists', 'b2b-price-lists' ),
			'target'   => 'b2bpl_product_panel',
			'class'    => array(),
			'priority' => 90,
		);

		return $tabs;
	}

	/**
	 * Renders the panel content.
	 *
	 * @return void
	 */
	public function render_panel() {
		global $post;

		$product_id = $post ? (int) $post->ID : 0;

		echo '<div id="b2bpl_product_panel" class="panel woocommerce_options_panel b2bpl-admin">';

		if ( ! $product_id ) {
			echo '</div>';

			return;
		}

		$rule_groups_repo = new RuleGroupsRepository();
		$lists_repo       = new ListsRepository();
		$list_users_repo  = new ListUsersRepository();
		$ranges_repo      = new PriceRangesRepository();
		$variations_repo  = new RuleGroupVariationsRepository();
		$products         = new ProductLookup();

		$total_lists = $rule_groups_repo->count_distinct_lists_for_product( $product_id );

		if ( 0 === $total_lists ) {
			echo '<p>' . esc_html__( 'This product is not included in any B2B price list yet.', 'b2b-price-lists' ) . '</p></div>';

			return;
		}

		$page        = isset( $_GET['b2bpl_panel_page'] ) ? max( 1, absint( $_GET['b2bpl_panel_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change.
		$list_ids    = $rule_groups_repo->get_distinct_list_ids_for_product_paginated( $product_id, $page, self::PER_PAGE );
		$lists_by_id = $lists_repo->find_by_ids( $list_ids );
		$groups      = $rule_groups_repo->get_by_product_and_list_ids( $product_id, $list_ids );

		$groups_by_list = array();
		$group_ids      = array();

		foreach ( $groups as $group ) {
			$groups_by_list[ (int) $group['list_id'] ][] = $group;
			$group_ids[]                                 = (int) $group['id'];
		}

		// Preloaded once for every rule group on this page, never once per
		// group inside the render loop below (no N+1 in this panel).
		$ranges_by_group        = $ranges_repo->get_by_rule_group_ids( $group_ids );
		$variation_ids_by_group = $variations_repo->get_variation_ids_by_rule_group_ids( $group_ids );

		echo '<div class="b2bpl-product-panel-inner" style="padding: 12px;">';

		foreach ( $list_ids as $list_id ) {
			$list = $lists_by_id[ $list_id ] ?? null;

			if ( ! $list ) {
				continue; // Defensive: a list row disappeared between the two queries.
			}

			$this->render_list_entry( $list, $groups_by_list[ $list_id ] ?? array(), $list_users_repo, $ranges_by_group, $variation_ids_by_group, $products, $product_id );
		}

		$this->render_pagination( $page, $total_lists );

		echo '</div></div>';
	}

	/**
	 * Renders one list's entry.
	 *
	 * @param array               $list_row               List row.
	 * @param array               $groups                 This product's rule groups within this list.
	 * @param ListUsersRepository $list_users_repo        Repository.
	 * @param array<int,array>    $ranges_by_group        Preloaded ranges, keyed by rule_group_id.
	 * @param array<int,int[]>    $variation_ids_by_group Preloaded variation ids, keyed by rule_group_id.
	 * @param ProductLookup       $products               Repository.
	 * @param int                 $product_id             Product id.
	 * @return void
	 */
	private function render_list_entry( array $list_row, array $groups, ListUsersRepository $list_users_repo, array $ranges_by_group, array $variation_ids_by_group, ProductLookup $products, $product_id ) {
		$user_count = $list_users_repo->count_for_list( $list_row['id'] );
		$edit_url   = admin_url(
			add_query_arg(
				array(
					'page'              => Menu::PAGE_SLUG,
					'action'            => 'edit',
					'id'                => (int) $list_row['id'],
					'tab'               => 'groups',
					'highlight_product' => $product_id,
				),
				'admin.php'
			)
		);

		echo '<div class="b2bpl-panel-list-entry" style="border:1px solid #dcdcde; padding:10px; margin-bottom:12px;">';
		echo '<p><strong>' . esc_html( $list_row['name'] ) . '</strong> &mdash; <span class="b2bpl-status b2bpl-status--' . esc_attr( $list_row['status'] ) . '">'
			. esc_html( 'active' === $list_row['status'] ? __( 'Active', 'b2b-price-lists' ) : __( 'Inactive', 'b2b-price-lists' ) ) . '</span></p>';

		/* translators: %d: number of users assigned to this list. */
		echo '<p>' . esc_html( sprintf( _n( '%d user assigned', '%d users assigned', $user_count, 'b2b-price-lists' ), $user_count ) ) . '</p>';

		foreach ( $groups as $group ) {
			$this->render_group_summary(
				$group,
				$ranges_by_group[ (int) $group['id'] ] ?? array(),
				$variation_ids_by_group[ (int) $group['id'] ] ?? array(),
				$products
			);
		}

		echo '<p><a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Edit in list', 'b2b-price-lists' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Renders a single rule group's compact summary (scope, pricing mode,
	 * fixed price/minimum quantity, or a compact range table, and variation
	 * labels for a `variation_group`).
	 *
	 * @param array         $group         Rule group row.
	 * @param array         $ranges        Preloaded ranges for this group, ordered by min_qty.
	 * @param int[]         $variation_ids Preloaded variation ids for this group.
	 * @param ProductLookup $products      Repository.
	 * @return void
	 */
	private function render_group_summary( array $group, array $ranges, array $variation_ids, ProductLookup $products ) {
		$scope_labels = array(
			RuleGroupValidator::SCOPE_SIMPLE          => __( 'Simple product', 'b2b-price-lists' ),
			RuleGroupValidator::SCOPE_ALL_VARIATIONS  => __( 'All variations', 'b2b-price-lists' ),
			RuleGroupValidator::SCOPE_VARIATION_GROUP => __( 'Specific variations', 'b2b-price-lists' ),
		);

		echo '<div class="b2bpl-panel-group-summary" style="margin:8px 0 8px 12px; padding-left:8px; border-left:3px solid #c3c4c7;">';
		echo '<p>' . esc_html( $scope_labels[ $group['scope'] ] ?? $group['scope'] );

		if ( ! empty( $group['name'] ) ) {
			echo ' &mdash; <em>' . esc_html( $group['name'] ) . '</em>';
		}

		echo '</p>';

		if ( RuleGroupValidator::PRICING_MODE_FIXED === $group['pricing_mode'] ) {
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: 1: formatted unit price, 2: minimum quantity. */
					__( '%1$s from %2$d unit(s)', 'b2b-price-lists' ),
					wc_price( (float) $group['unit_price'] ),
					(int) $group['minimum_quantity']
				)
			) . '</p>';
		} else {
			echo '<ul style="margin-left:1em; list-style:disc;">';

			foreach ( $ranges as $range ) {
				$max_label = ( null === $range['max_qty'] || '' === $range['max_qty'] )
					/* translators: %d: minimum quantity of an open-ended range. */
					? sprintf( __( '%d+', 'b2b-price-lists' ), (int) $range['min_qty'] )
					/* translators: 1: minimum quantity, 2: maximum quantity. */
					: sprintf( __( '%1$d-%2$d', 'b2b-price-lists' ), (int) $range['min_qty'], (int) $range['max_qty'] );

				echo '<li>' . esc_html( $max_label ) . ': ' . wp_kses_post( wc_price( (float) $range['unit_price'] ) ) . '</li>';
			}

			echo '</ul>';
		}

		if ( RuleGroupValidator::SCOPE_VARIATION_GROUP === $group['scope'] ) {
			$labels = array();

			foreach ( $variation_ids as $variation_id ) {
				$labels[] = $products->get_variation_label( $variation_id );
			}

			if ( ! empty( $labels ) ) {
				echo '<p>' . esc_html__( 'Variations:', 'b2b-price-lists' ) . ' ' . esc_html( implode( ', ', $labels ) ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * Renders simple pagination links across the product editor's own URL.
	 *
	 * @param int $page        Current page.
	 * @param int $total_lists Total distinct lists for this product.
	 * @return void
	 */
	private function render_pagination( $page, $total_lists ) {
		$total_pages = (int) ceil( $total_lists / self::PER_PAGE );

		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<p class="b2bpl-panel-pagination">';

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			if ( $i === $page ) {
				echo '<strong style="margin-right:6px;">' . esc_html( (string) $i ) . '</strong>';
				continue;
			}

			echo '<a href="' . esc_url( add_query_arg( 'b2bpl_panel_page', $i ) ) . '#b2bpl_product_panel" style="margin-right:6px;">' . esc_html( (string) $i ) . '</a>';
		}

		echo '</p>';
	}
}
