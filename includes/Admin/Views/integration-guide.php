<?php
/**
 * View: "Integration -> Usage guide" -- a plain-language explanation of the
 * webhook for the store administrator and for whoever runs the external
 * system (an ERP, for instance), written for someone who is not a
 * developer.
 *
 * Deliberately modeled on `Views/help.php` (same section cards, Dashicons,
 * tones and CSS classes from `assets/css/admin.css`) so the two guides read
 * as one family, without adding any CSS of its own.
 *
 * Expected variables: $logs_url (callable), $webhook_url (string, from
 * `Webhook\RestController::url()`), $limits (array, from
 * `Webhook\Settings::fixed_limits()` -- these are fixed, not configurable),
 * $can_configure_integration (bool).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\Menu;
use B2BPriceLists\Admin\UI;
use B2BPriceLists\Webhook\Operations\CatalogOperations;
use B2BPriceLists\Webhook\Settings as WebhookSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Same reasoning as `help.php`: a Dashicon and an accent tone per section,
// Dashicons already ship with wp-admin, so this costs no extra request.
$b2bpl_sections = array(
	'connect'       => array(
		'title' => __( 'One address, three uses', 'b2b-price-lists' ),
		'icon'  => 'dashicons-admin-links',
		'tone'  => 'blue',
	),
	'sending'       => array(
		'title' => __( 'The shape of a request', 'b2b-price-lists' ),
		'icon'  => 'dashicons-upload',
		'tone'  => 'teal',
	),
	'pricing'       => array(
		'title' => __( 'Prices: fixed or by quantity, and where they apply', 'b2b-price-lists' ),
		'icon'  => 'dashicons-tag',
		'tone'  => 'amber',
	),
	'catalog'       => array(
		'title' => __( 'Creating and updating products', 'b2b-price-lists' ),
		'icon'  => 'dashicons-products',
		'tone'  => 'green',
	),
	'examples'      => array(
		'title' => __( 'Two complete examples', 'b2b-price-lists' ),
		'icon'  => 'dashicons-media-code',
		'tone'  => 'purple',
	),
	'processing'    => array(
		'title' => __( 'What happens after you send a batch', 'b2b-price-lists' ),
		'icon'  => 'dashicons-update',
		'tone'  => 'green',
	),
	'limits'        => array(
		'title' => __( 'Fixed limits', 'b2b-price-lists' ),
		'icon'  => 'dashicons-editor-table',
		'tone'  => 'blue',
	),
	'responses'     => array(
		'title' => __( 'Common responses, and what to do about each one', 'b2b-price-lists' ),
		'icon'  => 'dashicons-warning',
		'tone'  => 'amber',
	),
	'logs'          => array(
		'title' => __( 'Where to see what arrived', 'b2b-price-lists' ),
		'icon'  => 'dashicons-backup',
		'tone'  => 'green',
	),
	'notifications' => array(
		'title' => __( 'Notification emails', 'b2b-price-lists' ),
		'icon'  => 'dashicons-email-alt',
		'tone'  => 'blue',
	),
	'customers'     => array(
		'title' => __( 'Customers created by the webhook', 'b2b-price-lists' ),
		'icon'  => 'dashicons-groups',
		'tone'  => 'purple',
	),
	'checklist'     => array(
		'title' => __( 'Before connecting the external system', 'b2b-price-lists' ),
		'icon'  => 'dashicons-yes-alt',
		'tone'  => 'teal',
	),
	'faq'           => array(
		'title' => __( 'Frequently asked questions', 'b2b-price-lists' ),
		'icon'  => 'dashicons-editor-help',
		'tone'  => 'teal',
	),
);

/**
 * Prints a section heading with its icon and tone (same helper as `help.php`).
 *
 * @param array $section Section definition.
 * @return void
 */
$b2bpl_section_number = 0;

$b2bpl_heading = static function ( array $section ) use ( &$b2bpl_section_number ) {
	++$b2bpl_section_number;

	printf(
		'<h2 class="b2bpl-help__title"><span class="b2bpl-card__num">%1$s</span><span class="b2bpl-help__icon b2bpl-help__icon--%2$s dashicons %3$s" aria-hidden="true"></span>%4$s</h2>',
		esc_html( (string) $b2bpl_section_number ),
		esc_attr( $section['tone'] ),
		esc_attr( $section['icon'] ),
		esc_html( $section['title'] )
	);
};

$b2bpl_settings_tab_url = call_user_func( $logs_url, array( 'tab' => 'settings' ) );

// printed in the "Customers" section, so the guide names the role
// this store really gives a B2B account and not just the default one.
$b2bpl_b2b_role     = WebhookSettings::b2b_role();
$b2bpl_nit_meta_key = WebhookSettings::nit_meta_key();
?>
<div class="wrap b2bpl-admin b2bpl-integration b2bpl-help">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'B2B Price Lists — Integration', 'b2b-price-lists' ),
			'subtitle' => __( 'How an outside system sends lists, prices and customers here', 'b2b-price-lists' ),
			'icon'     => 'dashicons-rest-api',
		)
	);
	?>
	<hr class="wp-header-end">

	<?php UI::main_nav( 'integration' ); ?>

	<?php
	UI::tabs(
		array(
			array(
				'label' => __( 'Log', 'b2b-price-lists' ),
				'url'   => call_user_func( $logs_url ),
				'icon'  => 'dashicons-list-view',
			),
			array(
				'label' => __( 'Settings', 'b2b-price-lists' ),
				'url'   => $b2bpl_settings_tab_url,
				'icon'  => 'dashicons-admin-generic',
			),
			array(
				'label'  => __( 'Usage guide', 'b2b-price-lists' ),
				'url'    => call_user_func( $logs_url, array( 'tab' => 'guide' ) ),
				'icon'   => 'dashicons-book',
				'active' => true,
			),
		),
		__( 'Integration sections', 'b2b-price-lists' )
	);
	?>

	<?php
	UI::hero(
		esc_html__( 'The webhook lets an outside system -- typically an ERP or another catalogue -- send price lists, products, prices and customers into this store automatically, instead of somebody typing them in by hand. It receives data; it never sends this store\'s data out on its own. It does nothing at all until it is turned on in Settings, and once it is, it only accepts requests carrying the webhook\'s own username and password.', 'b2b-price-lists' ),
		'dashicons-rest-api'
	);
	?>

	<?php // Same plain index as the other guide: a numbered list in columns. ?>
	<?php UI::card_open( __( 'What this guide covers', 'b2b-price-lists' ), 'dashicons-list-view', 'amber' ); ?>
		<nav aria-label="<?php esc_attr_e( 'Guide contents', 'b2b-price-lists' ); ?>">
			<ol class="b2bpl-contents">
				<?php foreach ( $b2bpl_sections as $b2bpl_anchor => $b2bpl_section ) : ?>
					<li>
						<a href="#b2bpl-guide-<?php echo esc_attr( $b2bpl_anchor ); ?>">
							<span class="b2bpl-contents__icon b2bpl-contents__icon--<?php echo esc_attr( $b2bpl_section['tone'] ); ?> dashicons <?php echo esc_attr( $b2bpl_section['icon'] ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $b2bpl_section['title'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ol>
		</nav>
	<?php UI::card_close(); ?>

	<!-- ============================ 1. Connecting ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--blue" id="b2bpl-guide-connect">
		<?php $b2bpl_heading( $b2bpl_sections['connect'] ); ?>

		<p><?php esc_html_e( 'Everything happens through a single address. What it does depends only on how you call it:', 'b2b-price-lists' ); ?></p>

		<table class="b2bpl-help__table">
			<tbody>
				<tr>
					<th><code>GET</code></th>
					<td><?php esc_html_e( 'Tests the connection and the credentials. Send it with no body and it answers whether the webhook is on and who you authenticated as -- the quickest way to check a new integration before sending any real data.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>POST</code></th>
					<td><?php esc_html_e( 'Sends a batch: one or more changes to lists/products, or one or more changes to customers, in a single request.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>GET ?batch_id=…</code></th>
					<td><?php esc_html_e( 'Looks up the result of a batch you sent earlier, by the batch_id a POST returned.', 'b2b-price-lists' ); ?></td>
				</tr>
			</tbody>
		</table>

		<p>
			<strong><?php esc_html_e( 'This site\'s webhook address:', 'b2b-price-lists' ); ?></strong><br>
			<code id="b2bpl-guide-webhook-url"><?php echo esc_html( $webhook_url ); ?></code>
		</p>

		<h3><?php esc_html_e( 'Authentication', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'Every request must carry HTTP Basic authentication with the webhook\'s own username and password -- not a WordPress user, not an email address, not a role. You create that username and password (and can regenerate the password at any time) in Integration → Settings; the password is only ever shown once, right after it is generated or set, because only its hash is stored afterwards. Regenerating it immediately stops the old one from working, so the external system must be updated with the new one at the same time.', 'b2b-price-lists' ); ?></p>
		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'HTTPS is required.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'A plain HTTP request is refused outright, except on a site explicitly running in a local development environment.', 'b2b-price-lists' ); ?>
		</p>
		<?php if ( $can_configure_integration ) : ?>
			<p><a href="<?php echo esc_url( $b2bpl_settings_tab_url ); ?>"><?php esc_html_e( 'Open Settings to set up the webhook username and password', 'b2b-price-lists' ); ?></a></p>
		<?php endif; ?>
	</section>

	<!-- ============================ 2. Shape of a request ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--teal" id="b2bpl-guide-sending">
		<?php $b2bpl_heading( $b2bpl_sections['sending'] ); ?>

		<p><?php esc_html_e( 'A POST always sends a single JSON object with these fields:', 'b2b-price-lists' ); ?></p>

		<table class="b2bpl-help__table">
			<tbody>
				<tr>
					<th><code>type</code></th>
					<td><?php esc_html_e( 'Required. Either "lists" (for list and product changes) or "users" (for customer changes). A batch can never mix the two: sending a customer action inside a "lists" batch, or the other way around, gets the whole batch rejected.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>idempotency_key</code></th>
					<td><?php esc_html_e( 'Required. A text of up to 100 characters, unique for every send. Sending the exact same key with the exact same body again simply returns the original batch (nothing is processed twice); the same key with a different body is refused. It can also be sent as the Idempotency-Key header instead of inside the body -- the header wins if both are present.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>sent_at</code></th>
					<td><?php esc_html_e( 'Optional. The date/time the external system considers the batch sent. Purely informational: it is kept in the log but never used to decide how the batch is processed.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>operations</code></th>
					<td><?php esc_html_e( 'Required. A list with at least one change to make. Each entry has an action (what to do), an optional but recommended ref (a free label you choose, echoed back in the result so you know which change it refers to without counting positions), and data (the details of that one change).', 'b2b-price-lists' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Every action', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'For type "lists":', 'b2b-price-lists' ); ?></p>
		<dl class="b2bpl-help__faq">
			<dt><code>list.create</code></dt>
			<dd><?php esc_html_e( 'Creates a new list. Fails if a list with that same external code already exists.', 'b2b-price-lists' ); ?></dd>
			<dt><code>list.update</code></dt>
			<dd><?php esc_html_e( 'Changes only the fields sent on a list that must already exist. Cannot carry products -- use one of the products.* actions for those.', 'b2b-price-lists' ); ?></dd>
			<dt><code>list.upsert</code></dt>
			<dd><?php esc_html_e( 'Creates the list if it does not exist yet, or updates the fields sent if it does. The safest default when you are not sure whether the list already exists.', 'b2b-price-lists' ); ?></dd>
			<dt><code>list.delete</code></dt>
			<dd><?php esc_html_e( 'Deletes a list exactly like doing it from the panel. Its customers go back to the store\'s normal prices.', 'b2b-price-lists' ); ?></dd>
			<dt><code>list.duplicate</code></dt>
			<dd><?php esc_html_e( 'Copies an existing list (its rules, description and offer) under a new external code.', 'b2b-price-lists' ); ?></dd>
			<dt><code>products.upsert</code></dt>
			<dd><?php esc_html_e( 'Adds a product rule to a list, or replaces it completely if a rule for that same product already exists -- it is never a partial edit, so the whole rule must be sent every time.', 'b2b-price-lists' ); ?></dd>
			<dt><code>products.remove</code></dt>
			<dd><?php esc_html_e( 'Removes one product rule from a list.', 'b2b-price-lists' ); ?></dd>
			<dt><code>products.replace</code></dt>
			<dd><?php esc_html_e( 'Leaves a list with exactly the products sent, deleting any rule not included. Requires confirm_replace: true, precisely so an incomplete list sent by mistake cannot wipe out the rest silently.', 'b2b-price-lists' ); ?></dd>
		</dl>
		<p><?php esc_html_e( 'For type "users":', 'b2b-price-lists' ); ?></p>
		<dl class="b2bpl-help__faq">
			<dt><code>user.upsert</code></dt>
			<dd><?php esc_html_e( 'Creates the customer if none matches yet, or updates the fields sent if one does. Can also assign or remove their price list in the same call.', 'b2b-price-lists' ); ?></dd>
			<dt><code>user.assign_list</code></dt>
			<dd><?php esc_html_e( 'Assigns a price list to an existing customer, replacing whichever list they had before (one customer, one list).', 'b2b-price-lists' ); ?></dd>
			<dt><code>user.unassign_list</code></dt>
			<dd><?php esc_html_e( 'Removes a customer\'s price list; they go back to the store\'s normal prices.', 'b2b-price-lists' ); ?></dd>
		</dl>
	</section>

	<!-- ============================ 3. Pricing ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--amber" id="b2bpl-guide-pricing">
		<?php $b2bpl_heading( $b2bpl_sections['pricing'] ); ?>

		<div class="b2bpl-help__grid">
			<div>
				<h3><?php esc_html_e( 'Fixed price', 'b2b-price-lists' ); ?></h3>
				<p><?php esc_html_e( 'pricing_mode: "fixed" with a single unit_price, however many units the customer buys, and an optional minimum_quantity.', 'b2b-price-lists' ); ?></p>
			</div>
			<div>
				<h3><?php esc_html_e( 'Tiered ranges', 'b2b-price-lists' ); ?></h3>
				<p><?php esc_html_e( 'pricing_mode: "tiered" with a ranges list: each one has min_qty, max_qty (or null for "and above", only allowed on the last range) and its own unit_price. The ranges must cover every quantity with no gap and no overlap -- exactly the same rule the panel already enforces.', 'b2b-price-lists' ); ?></p>
			</div>
		</div>

		<h3><?php esc_html_e( 'Where a rule applies (scope)', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><strong><code>simple</code></strong> — <?php esc_html_e( 'a simple product, identified by its own SKU.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>all_variations</code></strong> — <?php esc_html_e( 'every variation of a variable product, identified by the parent product\'s SKU.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>variation_group</code></strong> — <?php esc_html_e( 'only some variations of a variable product: give the group a rule_code (unique within the list) and list their SKUs in variation_skus.', 'b2b-price-lists' ); ?></li>
		</ul>
		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'A price rule needs the product to exist already.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'A rule prices a product that is already published in this store; one referring to a SKU that does not exist fails on its own, and every other change in the same batch still goes through. The products themselves can be created from the webhook too, but through a separate kind of send -- see "Creating and updating products" -- never from a price rule.', 'b2b-price-lists' ); ?>
		</p>
	</section>

	<!-- ============================ 4. Catalogue ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--green" id="b2bpl-guide-catalog">
		<?php $b2bpl_heading( $b2bpl_sections['catalog'] ); ?>

		<p><?php esc_html_e( 'Everything above prices products that somebody already created in this store. A batch sent with type "catalog" does the other half: it creates the products themselves and keeps them up to date from the outside system, so the shop never has to type an article in twice.', 'b2b-price-lists' ); ?></p>

		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'Off by default.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'This is the only part of the webhook that writes the shop\'s own catalogue, so it has to be turned on deliberately in Settings → Product catalogue. While it is off, a "catalog" batch is still accepted and logged, but every operation in it fails with catalog_disabled and nothing is written.', 'b2b-price-lists' ); ?>
		</p>

		<h3><?php esc_html_e( 'The two actions', 'b2b-price-lists' ); ?></h3>
		<dl class="b2bpl-help__faq">
			<dt><code>product.upsert</code></dt>
			<dd><?php esc_html_e( 'Creates the product when its SKU is unknown here, and updates exactly the fields sent when it already exists. The product is looked up by sku first and, only when that SKU is unknown, by external_id -- so renaming a SKU in the outside system updates the same product instead of creating a second one for the same article. Turning a product that already exists from simple into variable (or the other way round) is refused with product_type_mismatch and nothing is written: that change throws away prices, stock and variations, so it has to be made in the shop by hand.', 'b2b-price-lists' ); ?></dd>
			<dt><code>product.discontinue</code></dt>
			<dd><?php esc_html_e( 'Takes a product out of sale, reversibly. With "mode": "draft" (the default) it stops being published; with "mode": "outofstock" it stays published but out of stock. A SKU this store never had is reported as skipped, not as an error -- an outside system that has dropped an article should not have to know whether this shop ever carried it.', 'b2b-price-lists' ); ?></dd>
		</dl>

		<h3><?php esc_html_e( 'The golden rule', 'b2b-price-lists' ); ?></h3>
		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'What you do not send is not touched.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'Unlike a product rule, a product is never replaced whole: leaving a field out of the batch leaves whatever the shop has in it alone -- a description written by marketing, a retouched photo, a tag added by hand. Sending a field as null does empty it, on purpose; omitting it does not.', 'b2b-price-lists' ); ?>
		</p>
		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'Nothing is ever deleted.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'There is no action that removes a product: product.discontinue takes it out of sale and can be undone. The single exception is one variation, which disappears only when it is sent with "delete": true.', 'b2b-price-lists' ); ?>
		</p>

		<h3><?php esc_html_e( 'The fields of a product', 'b2b-price-lists' ); ?></h3>
		<table class="b2bpl-help__table">
			<tbody>
				<tr>
					<th><code>sku</code></th>
					<td><?php esc_html_e( 'Required, always. It is the product\'s identity here, and it is what a B2B price rule later points at.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>external_id</code></th>
					<td><?php esc_html_e( 'Optional but recommended. The outside system\'s own id for the article, kept on the product so it can still be found after its SKU changes.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>type</code></th>
					<td><?php esc_html_e( 'simple or variable, nothing else. Defaults to simple when it is left out. Do not confuse it with the batch\'s own type, which is always "catalog".', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>name</code></th>
					<td><?php esc_html_e( 'Required when the product is being created; optional afterwards, and only changes the name when it is sent.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>status</code></th>
					<td><?php esc_html_e( 'publish or draft, for this one product, overriding the default chosen in Settings. Remember a draft is not found by the B2B rules, which only resolve published products.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>regular_price</code></th>
					<td><?php esc_html_e( 'The normal price, as a plain number with no currency symbol. Required to create a new simple product; on a variable product the prices live on its variations instead.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>sale_price</code></th>
					<td><?php esc_html_e( 'The offer price. It can never be higher than regular_price, and sending it as null removes the offer.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>sale_from</code></th>
					<td><?php esc_html_e( 'Optional date the offer starts, as YYYY-MM-DD. Left out, the offer starts straight away.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>sale_to</code></th>
					<td><?php esc_html_e( 'Optional date the offer ends, as YYYY-MM-DD. Left out, it runs until the sale price is removed.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>description</code></th>
					<td><?php esc_html_e( 'The long description shown on the product page.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>short_description</code></th>
					<td><?php esc_html_e( 'The short summary shown next to the price.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>categories</code></th>
					<td><?php esc_html_e( 'A list of categories, each one written as its full path from parent to child -- ["Tools", "Hand tools"] -- so this store ends up with the same tree the outside system has. A single name is also accepted, and means a top-level category.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>brand</code></th>
					<td><?php esc_html_e( 'One brand name. It is written to the brand taxonomy chosen (or detected) in Settings; when this store has no brand taxonomy at all, the brand is skipped and reported, never invented.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>tags</code></th>
					<td><?php esc_html_e( 'A list of tag names, with no hierarchy.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>images</code></th>
					<td><?php esc_html_e( 'A list of http or https addresses this site can download, in the order they should appear: the first one becomes the main image and the rest the gallery. Each entry can also be an object with src and alt. An address that is already in the media library is not downloaded twice.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>manage_stock</code></th>
					<td><?php esc_html_e( 'true to have WooCommerce count units for this product, false to leave stock alone.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>stock_quantity</code></th>
					<td><?php esc_html_e( 'The number of units in stock, only meaningful while manage_stock is true.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>stock_status</code></th>
					<td><?php esc_html_e( 'instock, outofstock or onbackorder, for a product that does not count units.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>weight</code></th>
					<td><?php esc_html_e( 'The weight, in whichever unit the store is configured to use.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>dimensions</code></th>
					<td><?php esc_html_e( 'An object with length, width and height, in the store\'s own unit.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>attributes[]</code></th>
					<td><?php esc_html_e( 'The product\'s attributes. Each one has a name, a list of options, visible (whether it is shown on the product page) and variation. An attribute marked "variation": true is one of the axes the combinations of a variable product are built from; the rest are plain information.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th><code>variations[]</code></th>
					<td><?php esc_html_e( 'Only for a variable product. Each variation needs its own sku and the attributes that identify it ({"Size": "M"}), and accepts its own prices, sale window, stock, weight, dimensions and a single image. Sending "delete": true instead removes that one variation -- the only thing in a catalogue batch that deletes anything.', 'b2b-price-lists' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Limits of a catalogue batch', 'b2b-price-lists' ); ?></h3>
		<table class="b2bpl-help__table">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Products per send', 'b2b-price-lists' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $limits['catalog_operations'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Variations per send (all products combined)', 'b2b-price-lists' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $limits['catalog_variations'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Variations per product', 'b2b-price-lists' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( CatalogOperations::MAX_VARIATIONS ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Images per product', 'b2b-price-lists' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( CatalogOperations::MAX_IMAGES ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<p class="b2bpl-note"><?php esc_html_e( 'Products are far heavier than price rules -- each one can write categories, meta and downloaded images -- which is why a catalogue send carries fewer operations than a "lists" send. Going over the per-send limits refuses the whole batch; going over the per-product ones fails only that product, and every other product in the batch still goes through.', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'A simple product', 'b2b-price-lists' ); ?></h3>
		<pre><code><?php echo esc_html( "{\n  \"type\": \"catalog\",\n  \"idempotency_key\": \"erp-catalog-2026-10-20-0001\",\n  \"operations\": [\n    {\n      \"action\": \"product.upsert\",\n      \"ref\": \"p-1\",\n      \"data\": {\n        \"sku\": \"PROD-001\",\n        \"external_id\": \"ERP-77301\",\n        \"type\": \"simple\",\n        \"name\": \"Martillo de carpintero 16 oz\",\n        \"regular_price\": 89.00,\n        \"sale_price\": 79.00,\n        \"sale_from\": \"2026-11-01\",\n        \"sale_to\": \"2026-11-30\",\n        \"short_description\": \"Mango de fibra de vidrio.\",\n        \"categories\": [ [ \"Herramientas\", \"Herramienta manual\" ] ],\n        \"brand\": \"Truper\",\n        \"tags\": [ \"carpinteria\" ],\n        \"images\": [ \"https://erp.ejemplo.com/img/prod-001.jpg\" ],\n        \"manage_stock\": true,\n        \"stock_quantity\": 48,\n        \"weight\": \"0.65\",\n        \"dimensions\": { \"length\": \"33\", \"width\": \"12\", \"height\": \"4\" }\n      }\n    }\n  ]\n}" ); ?></code></pre>

		<h3><?php esc_html_e( 'A variable product, with one variation removed', 'b2b-price-lists' ); ?></h3>
		<pre><code><?php echo esc_html( "{\n  \"type\": \"catalog\",\n  \"idempotency_key\": \"erp-catalog-2026-10-20-0002\",\n  \"operations\": [\n    {\n      \"action\": \"product.upsert\",\n      \"ref\": \"p-2\",\n      \"data\": {\n        \"sku\": \"CAMISA-001\",\n        \"type\": \"variable\",\n        \"name\": \"Camisa de trabajo\",\n        \"status\": \"publish\",\n        \"categories\": [ [ \"Ropa\", \"Ropa de trabajo\" ] ],\n        \"attributes\": [\n          { \"name\": \"Talla\", \"options\": [ \"S\", \"M\", \"L\" ], \"visible\": true, \"variation\": true },\n          { \"name\": \"Material\", \"options\": [ \"Algodon\" ], \"visible\": true, \"variation\": false }\n        ],\n        \"variations\": [\n          {\n            \"sku\": \"CAMISA-001-S\",\n            \"attributes\": { \"Talla\": \"S\" },\n            \"regular_price\": 150.00,\n            \"manage_stock\": true,\n            \"stock_quantity\": 12\n          },\n          {\n            \"sku\": \"CAMISA-001-M\",\n            \"attributes\": { \"Talla\": \"M\" },\n            \"regular_price\": 150.00,\n            \"stock_status\": \"instock\"\n          },\n          { \"sku\": \"CAMISA-001-XL\", \"delete\": true }\n        ]\n      }\n    }\n  ]\n}" ); ?></code></pre>
	</section>

	<!-- ============================ 5. Examples ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--purple" id="b2bpl-guide-examples">
		<?php $b2bpl_heading( $b2bpl_sections['examples'] ); ?>

		<h3><?php esc_html_e( 'A "lists" batch: one wholesale list with one product', 'b2b-price-lists' ); ?></h3>
		<pre><code><?php echo esc_html( "{\n  \"type\": \"lists\",\n  \"idempotency_key\": \"erp-lists-2026-09-16-0001\",\n  \"operations\": [\n    {\n      \"action\": \"list.upsert\",\n      \"ref\": \"op-1\",\n      \"data\": {\n        \"external_code\": \"MAYORISTA-A\",\n        \"name\": \"Mayorista A\",\n        \"status\": \"active\",\n        \"products\": [\n          {\n            \"sku\": \"PROD-001\",\n            \"scope\": \"simple\",\n            \"pricing_mode\": \"fixed\",\n            \"unit_price\": 45.50,\n            \"minimum_quantity\": 6\n          }\n        ]\n      }\n    }\n  ]\n}" ); ?></code></pre>

		<h3><?php esc_html_e( 'A "users" batch: one customer, with a list assigned', 'b2b-price-lists' ); ?></h3>
		<pre><code><?php echo esc_html( "{\n  \"type\": \"users\",\n  \"idempotency_key\": \"erp-users-2026-09-16-0001\",\n  \"operations\": [\n    {\n      \"action\": \"user.upsert\",\n      \"ref\": \"u-1\",\n      \"data\": {\n        \"external_code\": \"CLI-0001\",\n        \"email\": \"compras@cliente-ejemplo.com\",\n        \"first_name\": \"Ana\",\n        \"last_name\": \"Lopez\",\n        \"list_external_code\": \"MAYORISTA-A\"\n      }\n    }\n  ]\n}" ); ?></code></pre>
	</section>

	<!-- ============================ 6. Processing ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--green" id="b2bpl-guide-processing">
		<?php $b2bpl_heading( $b2bpl_sections['processing'] ); ?>

		<p><?php esc_html_e( 'A valid POST never applies anything instantly. It answers 202 straight away with a batch_id and a status_url, and the batch is processed afterwards, in the background, in the exact order its operations were listed. Each operation is independent: if one fails, every other operation in the same batch still runs.', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'The batch\'s own status', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><strong><code>queued</code></strong> — <?php esc_html_e( 'accepted, waiting its turn.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>processing</code></strong> — <?php esc_html_e( 'being worked on right now.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>completed</code></strong> — <?php esc_html_e( 'every operation succeeded.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>completed_with_errors</code></strong> — <?php esc_html_e( 'finished, but at least one operation failed or partially applied.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>failed</code></strong> — <?php esc_html_e( 'processing stopped unexpectedly; it can be reprocessed once the cause is fixed.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>paused</code></strong> — <?php esc_html_e( 'the webhook was turned off while this batch was pending; it resumes automatically once turned back on.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>rejected</code></strong> — <?php esc_html_e( 'refused before anything was processed (bad credentials, malformed data, or over a limit); nothing in it was applied.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>duplicate</code></strong> — <?php esc_html_e( 'reused an idempotency_key already used by an earlier request; it was not processed again.', 'b2b-price-lists' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Each item\'s own status', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><strong><code>success</code></strong> — <?php esc_html_e( 'applied completely.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>partial</code></strong> — <?php esc_html_e( 'part of it applied (for example, a customer was saved but the list it named does not exist).', 'b2b-price-lists' ); ?></li>
			<li><strong><code>error</code></strong> — <?php esc_html_e( 'it did not apply at all.', 'b2b-price-lists' ); ?></li>
			<li><strong><code>skipped</code></strong> — <?php esc_html_e( 'there was nothing to do (for example, removing a rule that was already gone).', 'b2b-price-lists' ); ?></li>
		</ul>
	</section>

	<!-- ============================ 7. Limits ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--blue" id="b2bpl-guide-limits">
		<?php $b2bpl_heading( $b2bpl_sections['limits'] ); ?>

		<p><?php esc_html_e( 'A single send has to stay within these fixed limits:', 'b2b-price-lists' ); ?></p>

		<table class="b2bpl-help__table">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'List/product operations per send', 'b2b-price-lists' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $limits['list_operations'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Product rules per send (all lists combined)', 'b2b-price-lists' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $limits['product_rules'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'User operations per send', 'b2b-price-lists' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $limits['users'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Maximum request body size', 'b2b-price-lists' ); ?></th>
					<td><?php echo esc_html( size_format( (int) $limits['max_body_bytes'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Requests per minute', 'b2b-price-lists' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: %s: maximum number of requests allowed per minute. */
							esc_html__( '%s (going over this answers 429, see below)', 'b2b-price-lists' ),
							esc_html( number_format_i18n( (int) $limits['requests_per_minute'] ) )
						);
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="b2bpl-note">
			<?php esc_html_e( 'These limits are fixed: they cannot be changed from Settings, and nothing in a request can raise them. A send that goes over any of them is refused whole, and nothing in it is processed.', 'b2b-price-lists' ); ?>
		</p>
	</section>

	<!-- ============================ 8. Responses ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--amber" id="b2bpl-guide-responses">
		<?php $b2bpl_heading( $b2bpl_sections['responses'] ); ?>

		<table class="b2bpl-help__table">
			<tbody>
				<tr>
					<th>401</th>
					<td><?php esc_html_e( 'No credentials were sent, or the username/password is wrong. Check them in Settings; they are case-sensitive.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th>404</th>
					<td><?php esc_html_e( 'Either the webhook is turned off in Settings, or the URL is wrong. WordPress answers exactly the same "route not found" either way, on purpose.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th>413</th>
					<td><?php esc_html_e( 'The request body is bigger than the maximum allowed size. Split it into smaller batches.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th>415</th>
					<td><?php esc_html_e( 'The Content-Type header was not application/json.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th>422</th>
					<td>
						<?php esc_html_e( 'The batch is not well-formed; nothing in it was processed. The response lists every problem found. The most common ones:', 'b2b-price-lists' ); ?>
						<ul class="b2bpl-help__list">
							<li><code>type_required</code> / <code>invalid_type</code> — <?php esc_html_e( 'type is missing, or is something other than "lists"/"users".', 'b2b-price-lists' ); ?></li>
							<li><code>action_not_allowed_for_type</code> — <?php esc_html_e( 'an operation does not belong to the declared type (for example, a user.* action inside a "lists" batch).', 'b2b-price-lists' ); ?></li>
							<li><code>idempotency_key_required</code> — <?php esc_html_e( 'idempotency_key is missing from both the body and the header.', 'b2b-price-lists' ); ?></li>
							<li><code>too_many_operations</code> — <?php esc_html_e( 'the batch has more operations than this site\'s limit allows.', 'b2b-price-lists' ); ?></li>
						</ul>
					</td>
				</tr>
				<tr>
					<th>409</th>
					<td><?php esc_html_e( 'The idempotency_key was already used for a batch with different content. Use a new key for a genuinely new send.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th>429</th>
					<td><?php esc_html_e( 'Too many requests too quickly. Wait the number of seconds given in the Retry-After header before trying again.', 'b2b-price-lists' ); ?></td>
				</tr>
				<tr>
					<th>503</th>
					<td><?php esc_html_e( 'The request could not be recorded, so it was not processed. Try again shortly; nothing was applied.', 'b2b-price-lists' ); ?></td>
				</tr>
			</tbody>
		</table>

		<p class="b2bpl-note"><?php esc_html_e( 'After any of the responses above, the idempotency_key used stays free: fix the request and resend it with the very same key.', 'b2b-price-lists' ); ?></p>
	</section>

	<!-- ============================ 9. Logs ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--green" id="b2bpl-guide-logs">
		<?php $b2bpl_heading( $b2bpl_sections['logs'] ); ?>

		<p>
			<?php
			printf(
				/* translators: 1: opening link tag to the Log tab, 2: closing link tag. */
				esc_html__( 'Every request the webhook receives is recorded in %1$sIntegration → Log%2$s -- including requests that were rejected outright. The one exception is a failed username/password attempt: that is kept only as a short security line, without the data that was sent, so a stolen or mistyped password is never itself stored.', 'b2b-price-lists' ),
				'<a href="' . esc_url( call_user_func( $logs_url ) ) . '">',
				'</a>'
			);
			?>
		</p>

		<p><?php esc_html_e( 'Opening a batch shows: the JSON exactly as it was received and a formatted, highlighted copy of it; the response that was sent back; the result of every item, with its errors when it has any; and a timeline of events. Everything can be downloaded, and a site administrator can also download the whole batch as a single .zip file.', 'b2b-price-lists' ); ?></p>

		<p><?php esc_html_e( 'A batch with failed or partial items can be reprocessed, either item by item or all of them at once, without resending anything from the external system.', 'b2b-price-lists' ); ?></p>

		<p class="b2bpl-note"><?php esc_html_e( 'How long this history is kept (6, 12 or 24 months, 12 by default) is chosen in Settings; older requests are removed automatically once a day.', 'b2b-price-lists' ); ?></p>

		<p class="b2bpl-note">
			<strong><?php esc_html_e( '"Debug events", in Settings:', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'a checkbox, on by default, that keeps a detailed, step-by-step note of everything that happened while a batch was processed, not only its final result. It is recommended to leave it on: it is what makes diagnosing a problem from the timeline of a batch possible in the first place.', 'b2b-price-lists' ); ?>
		</p>
	</section>

	<!-- ============================ 10. Notification emails ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--blue" id="b2bpl-guide-notifications">
		<?php $b2bpl_heading( $b2bpl_sections['notifications'] ); ?>

		<p><?php esc_html_e( 'In Settings you can list one or more email addresses (up to 10) to be told automatically when something needs attention, instead of having to keep checking Integration → Log yourself.', 'b2b-price-lists' ); ?></p>

		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'An email goes out for absolutely every error: any request that is rejected outright -- wrong or missing credentials, the webhook turned off or the wrong URL, too many requests too quickly, a malformed batch, and every other case covered above -- as well as any batch that finishes with errors or fails. Nothing is left out.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'To avoid flooding the inbox during a spike of errors, up to 30 individual emails are sent within any given hour; anything past that in the same hour is not dropped, it is gathered into a single summary email instead, so nothing goes unnoticed.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'These emails never contain the webhook\'s password, its credentials, or the full JSON that was sent or received -- only enough to know where to look, such as the batch id, the date and what went wrong.', 'b2b-price-lists' ); ?></li>
		</ul>
	</section>

	<!-- ============================ 11. Customers ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--purple" id="b2bpl-guide-customers">
		<?php $b2bpl_heading( $b2bpl_sections['customers'] ); ?>

		<h3><?php esc_html_e( 'Retail or wholesale: customer_type', 'b2b-price-lists' ); ?></h3>
		<p><?php esc_html_e( 'A user.upsert operation says what kind of account it is with customer_type, and the role follows from that value on its own -- there is nothing else to send:', 'b2b-price-lists' ); ?></p>
		<ul class="b2bpl-help__list">
			<li>
				<strong><code>"customer_type": "b2c"</code></strong> —
				<?php
				printf(
					/* translators: %s: role slug given to a retail customer, wrapped in a <code> tag. */
					esc_html__( 'a retail account. It is given WooCommerce\'s own Customer role (%s) and keeps the store\'s normal prices.', 'b2b-price-lists' ),
					'<code>customer</code>'
				);
				?>
			</li>
			<li>
				<strong><code>"customer_type": "b2b"</code></strong> —
				<?php
				printf(
					/* translators: %s: role slug currently given to a B2B customer, wrapped in a <code> tag. */
					esc_html__( 'a wholesale account. It is given this store\'s B2B role, %s, which the plugin creates by itself -- there is nothing to configure.', 'b2b-price-lists' ),
					'<code>' . esc_html( $b2bpl_b2b_role ) . '</code>'
				);
				?>
			</li>
		</ul>
		<p><?php esc_html_e( 'The field is optional: left out, the role of an existing customer is not touched, and a brand-new customer is created as retail. Any value other than "b2c" or "b2b" is refused with invalid_customer_type, and only that one operation fails -- the rest of the batch still goes through.', 'b2b-price-lists' ); ?></p>
		<p><?php esc_html_e( 'You can still send role yourself instead, and it wins over customer_type, as long as it is one of those two roles; any other role is refused with role_not_allowed.', 'b2b-price-lists' ); ?></p>
		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'The role only says what kind of account it is.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'It never decides a single price: the B2B price always comes from the price list assigned to that customer. The B2B role is created with exactly the permissions of a normal customer and nothing more, and it exists so the store can tell its wholesale accounts apart -- to filter them, to greet them differently, to report on them.', 'b2b-price-lists' ); ?>
		</p>
		<p class="b2bpl-note">
			<strong><?php esc_html_e( 'A retail customer cannot carry a price list.', 'b2b-price-lists' ); ?></strong>
			<?php esc_html_e( 'Sending "customer_type": "b2c" together with a list_external_code is refused with list_not_allowed_for_b2c, rather than silently picking one of the two: the list is precisely what makes an account wholesale, so the two contradict each other. Send customer_type "b2b", or leave the list out.', 'b2b-price-lists' ); ?>
		</p>

		<h3><?php esc_html_e( 'The tax id (NIT)', 'b2b-price-lists' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: 1: the billing.nit field name, wrapped in a <code> tag, 2: meta key the tax id is currently written to, wrapped in a <code> tag. */
				esc_html__( 'A customer\'s tax id travels inside the billing object, as %1$s. It is written to the very field this store already keeps a tax id in -- %2$s right now -- so it shows up on the customer\'s billing address and on their orders like any other field, never in a field only this plugin knows about. Which field that is, the plugin works out from the store itself: there is nothing to configure, and while no customer or order carries a tax id yet it keeps looking, so the first one that does settles it.', 'b2b-price-lists' ),
				'<code>"billing": { "nit": "..." }</code>',
				'<code>' . esc_html( $b2bpl_nit_meta_key ) . '</code>'
			);
			?>
		</p>
		<p><?php esc_html_e( 'Sent as an empty string or null it clears the field; left out of the billing object, it is not touched -- exactly like every other billing and shipping field.', 'b2b-price-lists' ); ?></p>

		<h3><?php esc_html_e( 'And in every case', 'b2b-price-lists' ); ?></h3>
		<ul class="b2bpl-help__list">
			<li><?php esc_html_e( 'The WooCommerce "New account" welcome email is off by default for accounts the webhook creates. A site can turn it on for every new account in Settings, or a single user.upsert operation can ask for it with "send_welcome_email": true regardless of that setting.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'A protected account (an administrator or anyone else with an administrative role) is never modified or matched by the webhook, whatever external code or email is sent.', 'b2b-price-lists' ); ?></li>
			<li><?php esc_html_e( 'A customer is looked up first by external_code and, only if that is not sent, by email.', 'b2b-price-lists' ); ?></li>
		</ul>
	</section>

	<!-- ============================ 12. Checklist ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--teal" id="b2bpl-guide-checklist">
		<?php $b2bpl_heading( $b2bpl_sections['checklist'] ); ?>

		<ol class="b2bpl-steps">
			<li><h3><?php esc_html_e( 'Turn the webhook on', 'b2b-price-lists' ); ?></h3><p><?php esc_html_e( 'In Settings, and set (or generate) its username and password.', 'b2b-price-lists' ); ?></p></li>
			<li><h3><?php esc_html_e( 'Give the external system the address and the credentials', 'b2b-price-lists' ); ?></h3><p><?php esc_html_e( 'The webhook URL shown above, and the username/password -- never a WordPress account.', 'b2b-price-lists' ); ?></p></li>
			<li><h3><?php esc_html_e( 'Send a GET request first', 'b2b-price-lists' ); ?></h3><p><?php esc_html_e( 'With no body, to confirm the connection and the credentials work before sending anything real.', 'b2b-price-lists' ); ?></p></li>
			<li><h3><?php esc_html_e( 'Confirm every SKU you plan to price already exists', 'b2b-price-lists' ); ?></h3><p><?php esc_html_e( 'And is published in this store\'s catalogue. If the products are not here yet, send them first with a catalogue batch, or load them by hand, and only then send their B2B prices.', 'b2b-price-lists' ); ?></p></li>
			<li><h3><?php esc_html_e( 'Send one small batch first', 'b2b-price-lists' ); ?></h3><p><?php esc_html_e( 'With a real idempotency_key, and check its result in Integration → Log before sending everything.', 'b2b-price-lists' ); ?></p></li>
			<li><h3><?php esc_html_e( 'Decide the welcome-email behaviour', 'b2b-price-lists' ); ?></h3><p><?php esc_html_e( 'Either the general Settings switch, or send_welcome_email on individual customers.', 'b2b-price-lists' ); ?></p></li>
		</ol>
	</section>

	<!-- ============================ 13. FAQ ============================ -->
	<section class="b2bpl-card b2bpl-help__section b2bpl-help__section--teal" id="b2bpl-guide-faq">
		<?php $b2bpl_heading( $b2bpl_sections['faq'] ); ?>

		<dl class="b2bpl-help__faq">
			<dt><?php esc_html_e( 'Do I need to create a WordPress user for the external system?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'No. The webhook has its own username and password, unrelated to any WordPress account, email or role.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'Can one send include both lists and customers?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'No. Every send declares one type, "lists" or "users", and only actions matching that type are accepted.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'What happens if I accidentally send the same batch twice?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'If the body is identical and the idempotency_key is the same, nothing is processed again: you get back the original batch\'s own current status. If the body is different, the second send is refused instead of silently overwriting the first.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'Can I edit part of a product rule with products.upsert?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'No -- it always replaces the whole rule. Send every field the rule should end up with, not only the ones that changed.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'One product in my batch failed. Does that stop the rest?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'No. Every operation in a batch is independent: the others still run, and the failed one is reported on its own in the batch\'s result and in the log.', 'b2b-price-lists' ); ?></dd>

			<dt><?php esc_html_e( 'Why does the same URL sometimes answer 404?', 'b2b-price-lists' ); ?></dt>
			<dd><?php esc_html_e( 'Either the webhook is currently turned off in Settings, or the address was typed incorrectly. Both look identical on purpose, to avoid revealing to an outsider whether the endpoint exists at all.', 'b2b-price-lists' ); ?></dd>
		</dl>
	</section>
</div>
