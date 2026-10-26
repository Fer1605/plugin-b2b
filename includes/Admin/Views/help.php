<?php
/**
 * View: "How this plugin works" -- the built-in user manual.
 *
 * Written for the person who administers the store, not for a developer:
 * every section answers a question someone actually asks the first time
 * they open this screen ("where do I put the price?", "why does my
 * customer still see the normal price?", "what does the rule name do?").
 *
 * Expected variables: $list_url (callable), $settings_url (string),
 * $has_lists (bool).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Each section carries a Dashicon and an accent tone. Dashicons ship with
// WordPress admin and are already loaded on every screen, so this costs no
// extra request -- and a guide somebody is meant to actually read should not
// look like a wall of grey paragraphs.
$b2bpl_sections = array(
	'start'      => array(
		'title' => __( 'Getting started in 4 steps', 'b2b-price-lists' ),
		'icon'  => 'dashicons-flag',
		'tone'  => 'blue',
	),
	'pricing'    => array(
		'title' => __( 'The two ways to set a price', 'b2b-price-lists' ),
		'icon'  => 'dashicons-tag',
		'tone'  => 'teal',
	),
	'offers'     => array(
		'title' => __( 'Offers and discounts', 'b2b-price-lists' ),
		'icon'  => 'dashicons-megaphone',
		'tone'  => 'amber',
	),
	'variations' => array(
		'title' => __( 'Variable products and variations', 'b2b-price-lists' ),
		'icon'  => 'dashicons-screenoptions',
		'tone'  => 'purple',
	),
	'customer'   => array(
		'title' => __( 'What the customer sees', 'b2b-price-lists' ),
		'icon'  => 'dashicons-visibility',
		'tone'  => 'green',
	),
	'orders'     => array(
		'title' => __( 'B2B orders', 'b2b-price-lists' ),
		'icon'  => 'dashicons-cart',
		'tone'  => 'amber',
	),
	'fields'     => array(
		'title' => __( 'Every field, explained', 'b2b-price-lists' ),
		'icon'  => 'dashicons-editor-table',
		'tone'  => 'blue',
	),
	'activity'   => array(
		'title' => __( 'Activity log: history, export and archives', 'b2b-price-lists' ),
		'icon'  => 'dashicons-backup',
		'tone'  => 'green',
	),
	'faq'        => array(
		'title' => __( 'Frequently asked questions', 'b2b-price-lists' ),
		'icon'  => 'dashicons-editor-help',
		'tone'  => 'teal',
	),
	'developer'  => array(
		'title' => __( 'For whoever builds the theme', 'b2b-price-lists' ),
		'icon'  => 'dashicons-editor-code',
		'tone'  => 'purple',
	),
);

/**
 * Opens one guide section as a standard panel card, keeping both the anchor
 * the index above links to and the tone stripe down its left edge.
 *
 * @param array  $section Section definition.
 * @param string $anchor  Section anchor.
 * @return void
 */
$b2bpl_section_number = 0;

$b2bpl_open_section = static function ( array $section, $anchor ) use ( &$b2bpl_section_number ) {
	++$b2bpl_section_number;

	UI::card_open(
		$section['title'],
		$section['icon'],
		$section['tone'],
		array(
			'id'     => 'b2bpl-help-' . $anchor,
			'class'  => 'b2bpl-help__section b2bpl-help__section--' . $section['tone'],
			'number' => $b2bpl_section_number,
		)
	);
};
?>
<div class="wrap b2bpl-admin b2bpl-help">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'User guide', 'b2b-price-lists' ),
			'subtitle' => __( 'How price lists work, answered question by question', 'b2b-price-lists' ),
			'icon'     => 'dashicons-book',
		)
	);
	?>
	<hr class="wp-header-end">

	<?php UI::main_nav( 'help' ); ?>

	<div class="b2bpl-help__hero">
		<span class="b2bpl-help__hero-icon dashicons dashicons-book-alt" aria-hidden="true"></span>
		<p class="b2bpl-help__lead">
			<?php esc_html_e( 'This plugin gives specific customers their own prices, without changing the prices the rest of the shop sees. You build a price list, you put products and prices in it, and you assign the customers who should get those prices. Nobody else is affected: a visitor who is not signed in, or a customer with no list, keeps seeing the store\'s normal prices exactly as before.', 'b2b-price-lists' ); ?>
		</p>
	</div>

	<?php
	// A plain numbered index, in columns. The ten coloured pills it replaces
	// read as a wall of buttons and gave no sense of order.
	UI::card_open( __( 'What this guide covers', 'b2b-price-lists' ), 'dashicons-list-view', 'amber' );
	?>
		<nav aria-label="<?php esc_attr_e( 'Guide contents', 'b2b-price-lists' ); ?>">
			<ol class="b2bpl-contents">
				<?php foreach ( $b2bpl_sections as $b2bpl_anchor => $b2bpl_section ) : ?>
					<li>
						<a href="#b2bpl-help-<?php echo esc_attr( $b2bpl_anchor ); ?>">
							<span class="b2bpl-contents__icon b2bpl-contents__icon--<?php echo esc_attr( $b2bpl_section['tone'] ); ?> dashicons <?php echo esc_attr( $b2bpl_section['icon'] ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $b2bpl_section['title'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ol>
		</nav>
	<?php UI::card_close(); ?>

	<!-- ============================ 1. Getting started ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['start'], 'start' ); ?>

		<ol class="b2bpl-steps">
			<li>
				<h3><?php esc_html_e( 'Create the price list', 'b2b-price-lists' ); ?></h3>
				<p><?php esc_html_e( 'Use "Add New". Give it a name you will recognise later ("Distributors", "Salon chain", "2026 wholesale"). Leave it Inactive while you are still filling it in: an inactive list changes nothing for anybody.', 'b2b-price-lists' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Add products and prices', 'b2b-price-lists' ); ?></h3>
				<p><?php esc_html_e( 'Open the list, go to the "Products & prices" tab and add one rule per product. Search the product by name, by SKU or by its ID, choose whether the price is a single fixed price or a table of quantity ranges, and type the prices.', 'b2b-price-lists' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Assign the customers', 'b2b-price-lists' ); ?></h3>
				<p><?php esc_html_e( 'In the "Assigned users" tab, type the customer\'s user ID, username or email and press Assign. Each customer can belong to one list only: if they already belong to another one, you will be asked to confirm the move.', 'b2b-price-lists' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Activate the list', 'b2b-price-lists' ); ?></h3>
				<p><?php esc_html_e( 'Set the status to Active. From that moment its customers see their own prices in the catalogue, on the product page, in the cart and at checkout. Deactivating the list puts them straight back on the normal prices, without deleting anything.', 'b2b-price-lists' ); ?></p>
			</li>
		</ol>

		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'The price table appears on its own.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'You do not have to add anything to the product page: as soon as a rule applies to the customer looking at it, the quantity/price table is printed automatically at the position chosen in Settings. It is only ever shown to a customer whose list actually covers that product — never to anybody else.', 'b2b-price-lists' ); ?>
			<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings', 'b2b-price-lists' ); ?></a>
		</p>
		<?php UI::card_close(); ?>

	<!-- ============================ 2. Pricing modes ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['pricing'], 'pricing' ); ?>

		<div class="b2bpl-help__grid">
			<div>
				<h3><?php esc_html_e( 'Fixed unit price', 'b2b-price-lists' ); ?></h3>
				<p><?php esc_html_e( 'One price per unit, however many the customer buys. You can also require a minimum quantity: if you set 6, that customer cannot order fewer than 6 of that product.', 'b2b-price-lists' ); ?></p>
				<p class="b2bpl-help__example"><?php esc_html_e( 'Example: 45.00 per unit, minimum 6 units.', 'b2b-price-lists' ); ?></p>
			</div>
			<div>
				<h3><?php esc_html_e( 'Tiered price ranges', 'b2b-price-lists' ); ?></h3>
				<p><?php esc_html_e( 'The more they buy, the less each unit costs. You write one row per range: from how many, up to how many, and the price per unit inside that range. The last range can be left open (empty maximum) so it covers any larger quantity.', 'b2b-price-lists' ); ?></p>
				<p class="b2bpl-help__example"><?php esc_html_e( 'Example: 4 to 30 units at 50.00, 31 to 100 at 45.00, 101 or more at 40.00.', 'b2b-price-lists' ); ?></p>
			</div>
		</div>

		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'Ranges must be continuous.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'They cannot overlap and they cannot leave a hole: if one range ends at 30, the next one starts at 31. The plugin checks this when you save and tells you exactly what is wrong, so a customer can never land on a quantity with no price.', 'b2b-price-lists' ); ?>
		</p>
		<p class="b2bpl-note">
			<?php esc_html_e( 'The price you type is always the final price per unit the customer pays — it is never a discount off the normal price. The only thing that changes it afterwards is an offer, and the plugin shows you the result of that on screen.', 'b2b-price-lists' ); ?>
		</p>
		<?php UI::card_close(); ?>

	<!-- ============================ 3. Offers ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['offers'], 'offers' ); ?>

		<p><?php esc_html_e( 'An offer is a percentage taken off a price you already set. It is not a third way of pricing: it rides on top of a fixed price or on top of the ranges, and the plugin works out the final price on its own.', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'You can put an offer at three levels', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li>
				<strong><?php esc_html_e( 'The whole list.', 'b2b-price-lists' ); ?></strong>
				<?php esc_html_e( 'In the list\'s "Details" tab. Everything in that list goes down by that percentage — fixed prices and every range alike. This is the quickest way to run "the whole distributor list is 20% off this month".', 'b2b-price-lists' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'One product rule.', 'b2b-price-lists' ); ?></strong>
				<?php esc_html_e( 'In the rule editor. Only that product goes down, whether it is priced with a fixed price or with ranges.', 'b2b-price-lists' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'One single range.', 'b2b-price-lists' ); ?></strong>
				<?php esc_html_e( 'In the "Offer %" column of the ranges table. Only that row goes down; every other range keeps the price you wrote for it.', 'b2b-price-lists' ); ?>
			</li>
		</ul>

		<h3><?php esc_html_e( 'When there is more than one, the most specific wins', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'Percentages are never added together — that would give a number nobody can predict. The range\'s own offer beats the rule\'s, and the rule\'s beats the list\'s. So a list at 20% with one range at 5% means: that one range is 5% off, and everything else in the list is 20% off.', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'What the customer sees', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'The price before the offer, struck through, next to the offer price in full size — the same way a sale price is shown anywhere else in the shop — plus a small "-20%" badge. The price table marks the rows that are on offer the same way.', 'b2b-price-lists' ); ?></p>

		<p class="b2bpl-note">
			<?php esc_html_e( 'An offer must be greater than 0 and lower than 100. Leave the field empty when there is no offer. The final price is rounded using the store\'s own currency settings, and that rounded price is what goes into the cart, the order and the invoice.', 'b2b-price-lists' ); ?>
		</p>
		<?php UI::card_close(); ?>

	<!-- ============================ 4. Variations ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['variations'], 'variations' ); ?>

		<p><?php esc_html_e( 'A variable product (one with sizes, colours, presentations…) is priced through its variations. When you pick one in the rule editor, you choose how far the rule reaches:', 'b2b-price-lists' ); ?></p>

		<ul class="b2bpl-help__list">
			<li>
				<strong><?php esc_html_e( 'All variations.', 'b2b-price-lists' ); ?></strong>
				<?php esc_html_e( 'One price for every variation of the product. It also works as the fallback for any variation you have not priced individually.', 'b2b-price-lists' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Specific variations.', 'b2b-price-lists' ); ?></strong>
				<?php esc_html_e( 'You tick the variations this rule covers and give them their own price. The 1 litre size can cost one thing and the 250 ml another, in the same list.', 'b2b-price-lists' ); ?>
			</li>
		</ul>

		<p><?php esc_html_e( 'The two can live together for the same product: the specific rule wins for the variations it covers, and the "all variations" rule covers the rest. A variation can only belong to one specific-variations rule per list — the plugin refuses to save a second one instead of guessing which price to use.', 'b2b-price-lists' ); ?></p>

		<p><?php esc_html_e( 'Before the customer picks a variation, the shop shows the starting price ("from…"). Once they pick one, the price, the price table and the minimum quantity all update to that exact variation.', 'b2b-price-lists' ); ?></p>

		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'SKUs.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'Both the product search and the variation list show the SKU whenever there is one, and you can search by it — even by a part of it. A variation with no SKU of its own shows its parent product\'s, clearly marked as the parent\'s. A product with no SKU anywhere simply shows none.', 'b2b-price-lists' ); ?>
		</p>
		<?php UI::card_close(); ?>

	<!-- ============================ 5. What the customer sees ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['customer'], 'customer' ); ?>

		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'Their price in the catalogue, on the product page, in the cart and at checkout — always calculated on the server, so it cannot be tampered with from the browser.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'The quantity/price table on the product page, with the range that applies to the quantity they typed.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'The minimum quantity, already filled into the quantity box; they are stopped from ordering fewer.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Nothing at all about other lists, other customers, or which list they are on.', 'b2b-price-lists' ); ?></li>
		</ul>

		<p><?php esc_html_e( 'Every order keeps a record of the price that was applied: which list, which rule, which range, the offer percentage and the price before it. That record stays readable on the order even if you later change or delete the list.', 'b2b-price-lists' ); ?></p>

		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'Somebody without a list is never affected.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'Guests, and customers who are not on any list, keep the store\'s normal prices. The same happens the moment a list is deactivated or deleted.', 'b2b-price-lists' ); ?>
		</p>
		<?php UI::card_close(); ?>

	<!-- ============================ 5b. B2B orders ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['orders'], 'orders' ); ?>

		<h3><?php esc_html_e( 'What counts as a B2B order', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'An order is B2B when the customer had an ACTIVE price list assigned at the exact moment they bought — that alone is enough. The whole order counts as B2B, even if none of the products they happened to order that time had a special price in that list.', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'What is never a B2B order', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'An order from a guest (nobody signed in).', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'An order from a customer with no list assigned.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'An order from a customer whose list was inactive at that exact moment — even if it is active today, or was active before or after.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'An order created manually from the WooCommerce admin panel ("Add new order"). It never goes through the store, so this plugin never calculates a price for it and never marks it — regardless of who the customer is.', 'b2b-price-lists' ); ?></li>
		</ul>

		<p class="b2bpl-note">
			<?php esc_html_e( 'The list name shown on an order is the one it had at the moment of purchase, saved on the order itself. If the list is later renamed or permanently deleted, every past order bought with it keeps showing the original name — it never changes and never shows an error.', 'b2b-price-lists' ); ?>
		</p>

		<h3><?php esc_html_e( 'Where to see them', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'Go to WooCommerce → Orders. In the table of orders there is a column called "B2B", between "Date" and "Status". A B2B order shows a label with the name of the list; a normal order leaves that column empty.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'At the top of that same screen, next to "All | Processing | On hold…", there is a link "B2B (n)", where n is the number of B2B orders. Click it to see only the B2B orders.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Above the table there are two dropdowns: "Order type" (all, B2B or B2C) and "B2B list". Choose an option and press "Filter". You can combine them with the date, customer and search filters.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'In the left menu, under WooCommerce, the "— B2B Orders" item opens the orders screen already showing only B2B orders.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Inside a list: a "B2B orders" button at the top, next to the list name, with a shopping-cart icon and the number of orders. It appears on every screen of the list (details, assigned users, products & prices) and opens the WooCommerce Orders screen already filtered to that one list.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'In a customer\'s profile (Users → edit the customer), the "View their B2B orders" link shows only that customer\'s B2B orders.', 'b2b-price-lists' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'What the box on the order means', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'Opening a B2B order shows a small box with the list it was bought with and how many of its products had a special price — for example "0 of 3 products had a special price" is completely normal and not an error: it simply means none of the products that customer ordered that time were in their list. If the customer has since been moved to a different list (or lost their list entirely), the box says so, purely as information — it never changes what was actually charged.', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'Orders placed before this feature', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'The first time this version runs, it quietly goes through older orders in the background and marks the ones that already show a B2B price on one of their lines, using the list name recorded on that line at the time. A discreet notice appears on this plugin\'s screens and on the orders screen while it works, and disappears on its own when it finishes. It never re-checks an order that already carries a mark, and it can be safely interrupted and resumed (a server restart, for instance) without marking anything twice or skipping an order.', 'b2b-price-lists' ); ?></p>

		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'One thing cannot be recovered after the fact.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'An old order from a B2B customer where none of the products they bought that time had a special price looks, on its lines, exactly like an ordinary B2C order — there is nothing on it to detect. Only orders with at least one line that did get a special price can be found and marked automatically. This is a limitation of data recorded before this feature existed, not of the feature itself: every order placed from now on is marked correctly either way, special price or not.', 'b2b-price-lists' ); ?>
		</p>

		<h3><?php esc_html_e( 'Who can see and manage this', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'Administrators and Shop Managers, through the "Manage B2B price lists" permission this plugin grants them automatically. Seeing or opening any of the B2B order shortcuts above also requires the ability to view orders at all — someone with one permission but not the other does not see these options. Other roles see none of it.', 'b2b-price-lists' ); ?></p>
		<p class="b2bpl-note">
			<?php esc_html_e( 'A site using its own roles plugin can grant or move the "Manage B2B price lists" permission to a different role without touching any code — this plugin does not require that specific plugin, or any roles plugin at all, to work.', 'b2b-price-lists' ); ?>
		</p>
		<?php UI::card_close(); ?>

	<!-- ============================ 6. Fields ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['fields'], 'fields' ); ?>

		<table class="b2bpl-help__table">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Name (of the list)', 'b2b-price-lists' ); ?></th>
					<td><?php esc_html_e( 'How you recognise the list in this panel. Customers never see it.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Slug', 'b2b-price-lists' ); ?></th>
					<td><?php esc_html_e( 'A unique internal identifier. Leave it empty and it is generated from the name.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Status', 'b2b-price-lists' ); ?></th>
					<td><?php esc_html_e( 'Only an Active list changes prices. Inactive is the safe place to build or pause a list without losing anything.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Rule name', 'b2b-price-lists' ); ?></th>
					<td>
						<?php esc_html_e( 'An internal label for one rule inside the list, so you can tell several rules for the same product apart at a glance. It is optional, it never reaches the customer, and it appears in the rules table and in the order record.', 'b2b-price-lists' ); ?>
						<br>
						<em><?php esc_html_e( 'Example: on one shampoo you might have "1 L bottles" and "250 ml bottles" as two separate rules — without a name, both rows would just read "Shampoo" and you would have to open them to tell which is which.', 'b2b-price-lists' ); ?></em>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Minimum quantity', 'b2b-price-lists' ); ?></th>
					<td><?php esc_html_e( 'The fewest units this customer may buy of this product. With ranges it is the starting quantity of the first range.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Offer %', 'b2b-price-lists' ); ?></th>
					<td><?php esc_html_e( 'The percentage taken off the price written next to it. Empty means no offer.', 'b2b-price-lists' ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php UI::card_close(); ?>

	<!-- ============================ 7. Activity log ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['activity'], 'activity' ); ?>

		<p><?php esc_html_e( 'Every change made in this plugin is recorded in WooCommerce → B2B Price Lists → Activity: who made it, when, and exactly what changed — for example "Range 6 to 20 units: Q92.00 → Q95.00".', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'What is recorded', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'Price lists created, edited, duplicated or deleted, including changes to their offer.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Products added to or removed from a list, and every change to their prices, ranges, minimum quantity and offers.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Customers assigned to a list, removed from it, or moved to another one.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Changes made in Settings.', 'b2b-price-lists' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Finding something', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'Click a month to see only that month. The number next to it is how many entries it has.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Narrow it down with the From and To dates, who made the change, the price list and the type of action. Filters can be combined.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Filters stay in the address bar, so a filtered view can be bookmarked or sent to a colleague.', 'b2b-price-lists' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Exporting the history', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'The Export button, above the list of entries, downloads exactly what the screen is showing.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'With a month or any filter selected, only the matching entries are exported — all of them, not only the page you are looking at. With no filter at all, the whole history the site still keeps is exported. The button always says how many entries the file will contain.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'An export is always a complete set of results: single entries are not exported one by one.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'The file is a CSV that opens directly in Excel, Google Sheets or Numbers, newest entry first. It has one row per action and these columns: Date, Time, User, User ID, Action, Price list, What happened and Details. When one action changed several things, Details lists each change on its own line inside the same cell.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'The text is the same you read on screen, in the site\'s language, with times in the site\'s time zone.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'If Excel shows everything in a single column, open the file from Data → From Text/CSV and choose comma as the separator.', 'b2b-price-lists' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'How long the history is kept', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'In Settings → Activity log you choose 6, 12 or 24 months; 6 is the default. Once a day, entries older than that leave the list of entries and go to the archives described below.', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'Automatic archives', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'Nothing is deleted without being saved first. Before old entries are removed, they are written into the archive of the month they belong to. If saving fails for any reason, nothing is removed, and it is tried again the next day.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'The archives are listed at the bottom of the Activity screen, one per month, with how many entries each one holds and the dates it covers. Each one downloads as a CSV with the same columns as a manual export.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'A month\'s archive grows day by day as its entries reach the limit, so the same month may be downloaded again later with more entries in it.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Archives are not deleted by the retention setting: they stay available for download.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'The first archive appears once the oldest entry reaches the limit; until then the screen shows the date it will be created.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'Archives are stored inside the site\'s database, never as files in a public folder, so nobody can open one by guessing a link.', 'b2b-price-lists' ); ?></li>
		</ul>

		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'Only administrators can download.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'Exporting the history and downloading archives requires the Administrator role. Shop managers can still see the Activity screen, but cannot download it: the file shows who changed what across the whole store and can be forwarded anywhere once it leaves the site.', 'b2b-price-lists' ); ?>
		</p>
		<?php UI::card_close(); ?>

	<!-- ============================ 8. FAQ ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['faq'], 'faq' ); ?>

		<dl class="b2bpl-help__faq">
			<dt><?php esc_html_e( 'My customer still sees the normal price. Why?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'Check, in this order: the list is Active; the customer is assigned to it; the product has a rule in that list; and, for a variable product, the variation they picked is covered by the rule. If any of those is missing, the shop correctly falls back to the normal price.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'Can one customer be on two lists?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'No. One customer, one list. That is what makes the price they get unambiguous. Assigning them somewhere else moves them and asks you to confirm first.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'Can the same product be in several lists?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'Yes, with a different price in each one. Each customer only ever sees the price from their own list.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'What happens to orders already placed if I change a price?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'Nothing. Each order keeps the price that was in force when it was placed, along with the record of where that price came from.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'Do I have to add anything to the product page?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'No. The table appears on its own. If you would rather place it somewhere specific in a page builder, set the position to "Manual only" in Settings and drop the [b2b_price_list_table] shortcode wherever you want it.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'What happens if I delete a list?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'Its rules, ranges and assignments go with it, and its customers go back to the normal prices. Past orders are untouched. You are asked to confirm first, and told how many customers and products are affected. If you only want to pause it, deactivate it instead.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'Can I reuse a list as a starting point?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'Yes — "Duplicate" copies every product, price, range and offer into a new list, always inactive and with nobody assigned, so you can adjust it calmly before turning it on.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'An old order from a B2B customer is not marked B2B. Is that a bug?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'Not necessarily. Old orders are marked automatically from what is recorded on their lines — if none of the products that customer ordered that time had a special price, there is nothing left on the order to detect, and it cannot be recovered after the fact. Every order placed from now on is marked correctly regardless of whether any product had a special price.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'I don\'t see the B2B orders button inside a list. Why?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'It only appears for someone who both manages this plugin ("Manage B2B price lists") and can view WooCommerce orders. If either permission is missing, the button is hidden. It also does not appear while creating a new list, until the list is saved.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'The list name on an old order does not match its current name. Is that a bug?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'No — that is by design. An order always keeps the list name exactly as it was at the moment of purchase, even after the list is later renamed or permanently deleted, so the order stays understandable years later regardless of what happens to the list afterwards.', 'b2b-price-lists' ); ?></dd>
		</dl>
		<?php UI::card_close(); ?>

	<!-- ============================ 9. Developers ============================ -->
		<?php $b2bpl_open_section( $b2bpl_sections['developer'], 'developer' ); ?>

		<p><?php esc_html_e( 'Skip this section if you are not editing the theme. Everything here is optional: the plugin works on its own.', 'b2b-price-lists' ); ?></p>

		<p><?php esc_html_e( 'The two card layouts in Settings cover the two arrangements wholesale shops ask for. If your design needs a different one, compose it from the resolved numbers — never by reading this plugin\'s HTML back and rebuilding it. That used to be the only option and it breaks silently: the struck-through amount means the retail price when there is no offer, and the pre-offer wholesale price when there is one, and no amount of HTML parsing can tell those two apart.', 'b2b-price-lists' ); ?></p>

		<dl class="b2bpl-help__faq">
			<dt><code>b2bpl_price_parts</code></dt>
			<dd><?php esc_html_e( 'Receives the already-resolved numbers before they become HTML: the price paid, the price before the offer, the reference price, the offer percentage and the minimum quantity. Change or add pieces here.', 'b2b-price-lists' ); ?></dd>

			<dt><code>b2bpl_price_html</code></dt>
			<dd><?php esc_html_e( 'Receives the finished markup, with those same numbers alongside it, if you would rather replace the whole thing.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'CSS only', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'Every piece already carries its own class and a data-b2bpl-* attribute with its raw value, so restyling or re-ordering usually needs no PHP at all.', 'b2b-price-lists' ); ?></dd>
		</dl>
		<?php UI::card_close(); ?>
</div>
