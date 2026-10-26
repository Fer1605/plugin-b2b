<?php
/**
 * View: create/edit rule group form (rule name, product/variation selector,
 * scope, pricing mode, fixed price fields, tiered price ranges, and the
 * offer that can ride on top of either).
 *
 * Expected variables: $list_id (int), $list (array|null), $group
 * (array|null), $pending (array|null), $variation_ids (int[]), $ranges
 * (array), $product_label (string), $all_variations (int[]), $products
 * (ProductLookup), $tab_url (callable), $nonce_field (string), $nonce_name
 * (string), $ajax_nonce (string).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\ListsController;
use B2BPriceLists\Admin\Menu;
use B2BPriceLists\Admin\UI;
use B2BPriceLists\Admin\View;
use B2BPriceLists\Domain\Discount;
use B2BPriceLists\Domain\RuleGroupValidator;
use B2BPriceLists\Support\MoneyFormat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit = null !== $group;

// Resolve current field values with this priority: pending (failed
// resubmission) > existing group (edit) > sane defaults (create).
$values = array(
	'product_id'       => $is_edit ? (int) $group['product_id'] : 0,
	'scope'            => $is_edit ? $group['scope'] : RuleGroupValidator::SCOPE_SIMPLE,
	'pricing_mode'     => $is_edit ? $group['pricing_mode'] : RuleGroupValidator::PRICING_MODE_FIXED,
	'unit_price'       => $is_edit ? $group['unit_price'] : '',
	'minimum_quantity' => $is_edit ? (int) $group['minimum_quantity'] : 1,
	'discount_percent' => $is_edit ? Discount::format_percent( Discount::normalize( $group['discount_percent'] ?? null ) ) : '',
	'name'             => $is_edit ? (string) $group['name'] : '',
	'variation_ids'    => $variation_ids,
);

// Money display config: WooCommerce's own configured decimals/decimal
// separator are the only source of truth for the human-friendly input
// values below -- this plugin never invents its own monetary convention.
$money_decimals          = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
$money_decimal_separator = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';

$unit_price_is_pending = is_array( $pending ) && isset( $pending['unit_price'] );

if ( is_array( $pending ) ) {
	$values['scope']            = isset( $pending['scope'] ) ? sanitize_key( $pending['scope'] ) : $values['scope'];
	$values['pricing_mode']     = isset( $pending['pricing_mode'] ) ? sanitize_key( $pending['pricing_mode'] ) : $values['pricing_mode'];
	$values['unit_price']       = isset( $pending['unit_price'] ) ? sanitize_text_field( $pending['unit_price'] ) : $values['unit_price'];
	$values['minimum_quantity'] = isset( $pending['minimum_quantity'] ) ? absint( $pending['minimum_quantity'] ) : $values['minimum_quantity'];
	$values['discount_percent'] = isset( $pending['discount_percent'] ) ? sanitize_text_field( $pending['discount_percent'] ) : $values['discount_percent'];
	$values['name']             = isset( $pending['name'] ) ? sanitize_text_field( $pending['name'] ) : $values['name'];
	$values['variation_ids']    = isset( $pending['variation_ids'] ) ? array_map( 'absint', (array) $pending['variation_ids'] ) : $values['variation_ids'];

	if ( isset( $pending['product_id'] ) ) {
		$values['product_id'] = absint( $pending['product_id'] );
	}
}

// A pending (failed-resubmission) value is shown exactly as the admin typed
// it, so they can see and fix what was rejected. A value coming from the
// database is reformatted into a clean, human-friendly representation
// (never the raw DECIMAL string) via MoneyFormat.
$unit_price_display = $unit_price_is_pending
	? $values['unit_price']
	: MoneyFormat::format_for_input( $values['unit_price'], $money_decimals, $money_decimal_separator );

$pending_ranges = array();

if ( is_array( $pending ) && isset( $pending['range_min'] ) && is_array( $pending['range_min'] ) ) {
	// Same `is_scalar` guard the scalar fields above already get: these values
	// come from a transient built out of a raw $_POST, so a malformed
	// submission nesting an array inside a range field must not reach
	// `esc_attr()` and raise "Array to string conversion" while re-rendering.
	$pending_scalar = static function ( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	};

	foreach ( $pending['range_min'] as $index => $min ) {
		$pending_ranges[] = array(
			'min_qty'          => $pending_scalar( $min ),
			'max_qty'          => $pending_scalar( $pending['range_max'][ $index ] ?? '' ),
			'unit_price'       => $pending_scalar( $pending['range_price'][ $index ] ?? '' ),
			'discount_percent' => $pending_scalar( $pending['range_discount'][ $index ] ?? '' ),
		);
	}
}

$ranges_are_pending = ! empty( $pending_ranges );
$display_ranges     = $ranges_are_pending ? $pending_ranges : $ranges;

$is_variable = $is_edit ? in_array( $values['scope'], array( RuleGroupValidator::SCOPE_ALL_VARIATIONS, RuleGroupValidator::SCOPE_VARIATION_GROUP ), true ) || ! empty( $all_variations ) : null;

$rule_notice = isset( $_GET['b2bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$list_offer = is_array( $list ) ? Discount::normalize( $list['discount_percent'] ?? null ) : null;
$help_url   = add_query_arg(
	array(
		'page'   => Menu::PAGE_SLUG,
		'action' => 'help',
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap b2bpl-admin">
	<?php
	View::render(
		'list-context',
		array(
			'list_id' => $list_id,
			'list'    => $list,
		)
	);
	?>

	<?php
	UI::screen_header(
		array(
			'title'    => $is_edit ? __( 'Edit price rule', 'b2b-price-lists' ) : __( 'Add price rule', 'b2b-price-lists' ),
			'subtitle' => __( 'Pick a product and say what this list pays for it', 'b2b-price-lists' ),
			'icon'     => 'dashicons-tag',
			'actions'  => array(
				array(
					'label' => __( 'User guide', 'b2b-price-lists' ),
					'url'   => $help_url,
					'icon'  => 'dashicons-book',
				),
			),
		)
	);
	?>
	<hr class="wp-header-end">

	<p class="b2bpl-breadcrumb"><a href="<?php echo esc_url( call_user_func( $tab_url, $list_id ) ); ?>">&larr; <?php esc_html_e( 'Back to Products & prices', 'b2b-price-lists' ); ?></a></p>

	<?php if ( 'saved' === $rule_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule saved.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'error' === $rule_notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( ListsController::consume_error_notice() ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( call_user_func( $tab_url, $list_id ) ); ?>" id="b2bpl-rule-group-form"
		data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-ajax-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
		<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
		<input type="hidden" name="b2bpl_action" value="save-group">
		<input type="hidden" name="list_id" value="<?php echo esc_attr( (string) $list_id ); ?>">
		<input type="hidden" name="rule_group_id" value="<?php echo esc_attr( (string) ( $is_edit ? $group['id'] : 0 ) ); ?>">

		<!-- ==================== 1. Identification ==================== -->
		<?php UI::card_open( __( '1. Name this rule', 'b2b-price-lists' ), 'dashicons-edit', 'blue' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="b2bpl-rule-name"><?php esc_html_e( 'Rule name', 'b2b-price-lists' ); ?> <span class="b2bpl-optional"><?php esc_html_e( '(optional)', 'b2b-price-lists' ); ?></span></label></th>
					<td>
						<input type="text" id="b2bpl-rule-name" name="name" class="regular-text" value="<?php echo esc_attr( $values['name'] ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. 1 L bottles', 'b2b-price-lists' ); ?>">
						<p class="description">
							<?php esc_html_e( 'An internal label so you can tell this rule apart from the others in this list. The customer never sees it: it only appears in the rules table of this list and in the order record.', 'b2b-price-lists' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'It earns its keep when one product has several rules — for example a shampoo with one rule for the "1 L bottles" and another for the "250 ml bottles". Without a name, both rows read just "Shampoo" and you have to open them to tell which is which. With a single rule per product you can safely leave it empty.', 'b2b-price-lists' ); ?>
						</p>
					</td>
				</tr>
			</table>
		<?php UI::card_close(); ?>

		<!-- ==================== 2. Product ==================== -->
		<?php UI::card_open( __( '2. Choose the product', 'b2b-price-lists' ), 'dashicons-products', 'blue' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="b2bpl-product-search"><?php esc_html_e( 'Product', 'b2b-price-lists' ); ?></label></th>
					<td>
						<input type="hidden" id="b2bpl-product-id" name="product_id" value="<?php echo esc_attr( (string) $values['product_id'] ); ?>">
						<input type="hidden" id="b2bpl-product-type" value="<?php echo esc_attr( $is_edit ? ( $is_variable ? 'variable' : 'simple' ) : '' ); ?>">
						<input type="text" id="b2bpl-product-search" class="regular-text"
							value="<?php echo esc_attr( $is_edit ? $product_label : '' ); ?>"
							placeholder="<?php esc_attr_e( 'Search by name, SKU or ID…', 'b2b-price-lists' ); ?>" autocomplete="off">
						<div id="b2bpl-product-results" class="b2bpl-search-results" hidden></div>

						<p id="b2bpl-product-selected" class="description">
							<?php if ( $is_edit ) : ?>
								<?php
								printf(
									/* translators: %s: product name, id and SKU (already formatted by ProductLookup::get_product_label(), e.g. "Blue mug (#123, SKU: MUG-BL)"). */
									esc_html__( 'Selected: %s', 'b2b-price-lists' ),
									esc_html( $product_label )
								);
								?>
							<?php endif; ?>
						</p>

						<p class="description">
							<?php esc_html_e( 'You can type a part of the SKU: it is matched as well as the product name. The SKU is shown next to every result, and next to every variation, whenever the product has one.', 'b2b-price-lists' ); ?>
						</p>

						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Changing the product re-validates the scope and clears any selected variations that do not belong to the new product — price and ranges are kept.', 'b2b-price-lists' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr id="b2bpl-scope-row">
					<th scope="row"><?php esc_html_e( 'Scope', 'b2b-price-lists' ); ?></th>
					<td>
						<div class="b2bpl-options">
							<?php // `b2bpl-scope-option` and `data-scope` stay: admin.js shows or hides each option by them, depending on the product type. ?>
							<label class="b2bpl-option b2bpl-scope-option" data-scope="simple">
								<input type="radio" name="scope" value="simple" <?php checked( $values['scope'], RuleGroupValidator::SCOPE_SIMPLE ); ?>>
								<span class="dashicons dashicons-archive" aria-hidden="true"></span>
								<span class="b2bpl-option__text">
									<strong><?php esc_html_e( 'Simple product', 'b2b-price-lists' ); ?></strong>
									<span><?php esc_html_e( 'The product is sold as one item, with no variations.', 'b2b-price-lists' ); ?></span>
								</span>
							</label>
							<label class="b2bpl-option b2bpl-scope-option" data-scope="all_variations">
								<input type="radio" name="scope" value="all_variations" <?php checked( $values['scope'], RuleGroupValidator::SCOPE_ALL_VARIATIONS ); ?>>
								<span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
								<span class="b2bpl-option__text">
									<strong><?php esc_html_e( 'All variations (fallback rule)', 'b2b-price-lists' ); ?></strong>
									<span><?php esc_html_e( 'The same price for every variation that no other rule covers.', 'b2b-price-lists' ); ?></span>
								</span>
							</label>
							<label class="b2bpl-option b2bpl-scope-option" data-scope="variation_group">
								<input type="radio" name="scope" value="variation_group" <?php checked( $values['scope'], RuleGroupValidator::SCOPE_VARIATION_GROUP ); ?>>
								<span class="dashicons dashicons-yes" aria-hidden="true"></span>
								<span class="b2bpl-option__text">
									<strong><?php esc_html_e( 'Specific variations', 'b2b-price-lists' ); ?></strong>
									<span><?php esc_html_e( 'Only the variations you tick below get this price.', 'b2b-price-lists' ); ?></span>
								</span>
							</label>
						</div>
						<p class="description"><?php esc_html_e( 'A specific-variations rule and an all-variations fallback rule can coexist for the same product and list.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr id="b2bpl-variations-row">
					<th scope="row"><?php esc_html_e( 'Variations', 'b2b-price-lists' ); ?></th>
					<td>
						<div id="b2bpl-variations-list" class="b2bpl-variations-list">
							<?php foreach ( $all_variations as $variation_id ) : ?>
								<label class="b2bpl-variation-option">
									<input type="checkbox" name="variation_ids[]" value="<?php echo esc_attr( (string) $variation_id ); ?>"
										<?php checked( in_array( (int) $variation_id, $values['variation_ids'], true ) ); ?>>
									<?php echo esc_html( $products->get_variation_label( $variation_id ) ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
			</table>
		<?php UI::card_close(); ?>

		<!-- ==================== 3. Price ==================== -->
		<?php UI::card_open( __( '3. Set the price', 'b2b-price-lists' ), 'dashicons-money-alt', 'teal' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Pricing mode', 'b2b-price-lists' ); ?></th>
					<td>
						<div class="b2bpl-options">
							<?php // The `name`/`value` pairs are the hook: admin.js reads `input[name="pricing_mode"]` to show the matching fields. ?>
							<label class="b2bpl-option">
								<input type="radio" name="pricing_mode" value="fixed" <?php checked( $values['pricing_mode'], RuleGroupValidator::PRICING_MODE_FIXED ); ?>>
								<span class="dashicons dashicons-tag" aria-hidden="true"></span>
								<span class="b2bpl-option__text">
									<strong><?php esc_html_e( 'Fixed unit price', 'b2b-price-lists' ); ?></strong>
									<span><?php esc_html_e( 'One price, however many units they buy.', 'b2b-price-lists' ); ?></span>
								</span>
							</label>
							<label class="b2bpl-option">
								<input type="radio" name="pricing_mode" value="tiered" <?php checked( $values['pricing_mode'], RuleGroupValidator::PRICING_MODE_TIERED ); ?>>
								<span class="dashicons dashicons-editor-ol" aria-hidden="true"></span>
								<span class="b2bpl-option__text">
									<strong><?php esc_html_e( 'Tiered price ranges', 'b2b-price-lists' ); ?></strong>
									<span><?php esc_html_e( 'The unit price drops as the quantity goes up.', 'b2b-price-lists' ); ?></span>
								</span>
							</label>
						</div>
						<p class="description"><?php esc_html_e( 'A fixed price is the same however many units they buy. Tiered ranges make the unit price drop as the quantity goes up.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr id="b2bpl-fixed-row">
					<th scope="row"><label for="b2bpl-unit-price"><?php esc_html_e( 'Unit price', 'b2b-price-lists' ); ?></label></th>
					<td>
						<span class="b2bpl-field-pair">
							<input type="text" inputmode="decimal" id="b2bpl-unit-price" name="unit_price"
								value="<?php echo esc_attr( (string) $unit_price_display ); ?>">
							<label for="b2bpl-min-qty"><?php esc_html_e( 'Minimum quantity', 'b2b-price-lists' ); ?></label>
							<input type="number" id="b2bpl-min-qty" name="minimum_quantity" step="1" min="1"
								value="<?php echo esc_attr( (string) $values['minimum_quantity'] ); ?>">
						</span>
						<p class="description"><?php esc_html_e( 'This is the final unit price the buyer pays — never a discount off the shop price. Only an offer (below) changes it.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr id="b2bpl-tiered-row">
					<th scope="row"><?php esc_html_e( 'Price ranges', 'b2b-price-lists' ); ?></th>
					<td>
						<table id="b2bpl-ranges-table" class="b2bpl-ranges-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Min. quantity', 'b2b-price-lists' ); ?></th>
									<th><?php esc_html_e( 'Max. quantity', 'b2b-price-lists' ); ?><br><span class="b2bpl-th-hint"><?php esc_html_e( 'empty = open range', 'b2b-price-lists' ); ?></span></th>
									<th><?php esc_html_e( 'Unit price', 'b2b-price-lists' ); ?></th>
									<th><?php esc_html_e( 'Offer %', 'b2b-price-lists' ); ?><br><span class="b2bpl-th-hint"><?php esc_html_e( 'only this range', 'b2b-price-lists' ); ?></span></th>
									<th></th>
								</tr>
							</thead>
							<tbody id="b2bpl-ranges-body">
								<?php foreach ( $display_ranges as $range ) : ?>
									<?php
									$range_price_display = $ranges_are_pending
										? $range['unit_price']
										: MoneyFormat::format_for_input( $range['unit_price'], $money_decimals, $money_decimal_separator );

									$range_discount_display = $ranges_are_pending
										? ( $range['discount_percent'] ?? '' )
										: Discount::format_percent( Discount::normalize( $range['discount_percent'] ?? null ) );
									?>
									<tr class="b2bpl-range-row">
										<td><input type="number" name="range_min[]" step="1" min="1" value="<?php echo esc_attr( (string) $range['min_qty'] ); ?>"></td>
										<td><input type="number" name="range_max[]" step="1" min="1" value="<?php echo esc_attr( null === $range['max_qty'] || '' === $range['max_qty'] ? '' : (string) $range['max_qty'] ); ?>"></td>
										<td><input type="text" inputmode="decimal" name="range_price[]" value="<?php echo esc_attr( (string) $range_price_display ); ?>"></td>
										<td class="b2bpl-range-offer"><input type="text" inputmode="decimal" name="range_discount[]" placeholder="—" value="<?php echo esc_attr( (string) $range_discount_display ); ?>"></td>
										<td><button type="button" class="button-link b2bpl-remove-range b2bpl-link-danger"><?php esc_html_e( 'Remove', 'b2b-price-lists' ); ?></button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" id="b2bpl-add-range" class="button"><?php esc_html_e( 'Add range', 'b2b-price-lists' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'Ranges must not overlap or leave gaps: if one ends at 30, the next starts at 31. Only the last range (by minimum quantity) may be left open.', 'b2b-price-lists' ); ?></p>
						<p class="description"><?php esc_html_e( 'The "Offer %" column discounts that one range only. Leave it empty and the range keeps exactly the price you typed.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
			</table>
		<?php UI::card_close(); ?>

		<!-- ==================== 4. Offer ==================== -->
		<?php UI::card_open( __( '4. Offer for this rule (optional)', 'b2b-price-lists' ), 'dashicons-megaphone', 'amber' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="b2bpl-discount-percent"><?php esc_html_e( 'Discount', 'b2b-price-lists' ); ?></label></th>
					<td>
						<span class="b2bpl-percent-field">
							<input type="text" inputmode="decimal" id="b2bpl-discount-percent" name="discount_percent" class="small-text"
								value="<?php echo esc_attr( (string) $values['discount_percent'] ); ?>" placeholder="0">
							<span class="b2bpl-percent-field__sign">%</span>
						</span>
						<p class="description"><?php esc_html_e( 'Takes this percentage off the price above — off the fixed price, or off every range that has no offer of its own. Leave it empty for no offer.', 'b2b-price-lists' ); ?></p>
						<p class="description"><?php esc_html_e( 'The customer sees the price before the offer struck through, the offer price in full size, and a "-%" badge. The final price is what goes into the cart and the order.', 'b2b-price-lists' ); ?></p>

						<div class="b2bpl-precedence">
							<strong><?php esc_html_e( 'When more than one offer could apply, the most specific one wins — they are never added up:', 'b2b-price-lists' ); ?></strong>
							<ol>
								<li><?php esc_html_e( 'the offer on a price range', 'b2b-price-lists' ); ?></li>
								<li><?php esc_html_e( 'the offer on this rule', 'b2b-price-lists' ); ?></li>
								<li><?php esc_html_e( 'the offer on the whole list', 'b2b-price-lists' ); ?></li>
							</ol>
							<?php if ( null !== $list_offer ) : ?>
								<p class="b2bpl-precedence__current">
									<?php
									printf(
										/* translators: %s: offer percentage configured on the list. */
										esc_html__( 'This list currently carries a -%s%% offer. It applies to everything here that does not define its own.', 'b2b-price-lists' ),
										esc_html( Discount::format_percent( $list_offer ) )
									);
									?>
								</p>
							<?php endif; ?>
						</div>
					</td>
				</tr>
			</table>
		<?php UI::card_close(); ?>

		<?php submit_button( $is_edit ? __( 'Save changes', 'b2b-price-lists' ) : __( 'Create rule', 'b2b-price-lists' ) ); ?>
	</form>
</div>

<template id="b2bpl-range-row-template">
	<tr class="b2bpl-range-row">
		<td><input type="number" name="range_min[]" step="1" min="1"></td>
		<td><input type="number" name="range_max[]" step="1" min="1"></td>
		<td><input type="text" inputmode="decimal" name="range_price[]"></td>
		<td class="b2bpl-range-offer"><input type="text" inputmode="decimal" name="range_discount[]" placeholder="—"></td>
		<td><button type="button" class="button-link b2bpl-remove-range b2bpl-link-danger"><?php esc_html_e( 'Remove', 'b2b-price-lists' ); ?></button></td>
	</tr>
</template>
