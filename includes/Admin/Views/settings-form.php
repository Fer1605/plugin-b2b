<?php
/**
 * View: price table settings screen.
 *
 * Expected variables: $settings (array), $positions (string[]),
 * $settings_url (string), $nonce_field (string), $nonce_name (string),
 * $purge_on_uninstall (bool), $retention_months (int).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Admin\UI;
use B2BPriceLists\Support\TableSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = isset( $_GET['b2bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

/*
 * Where the wholesale table is injected on the product page. The label in the
 * <select> is deliberately SHORT: a browser sizes a select to its widest
 * option, so a sentence-long label pushed the whole admin page sideways and
 * made it scroll horizontally. The sentence now lives underneath, in
 * `$position_notes`, where it can wrap.
 */
$position_labels = array(
	TableSettings::POSITION_BEFORE_ADD_TO_CART     => __( 'Before the add-to-cart button (recommended)', 'b2b-price-lists' ),
	TableSettings::POSITION_AFTER_ADD_TO_CART      => __( 'After the add-to-cart button', 'b2b-price-lists' ),
	TableSettings::POSITION_BEFORE_VARIATIONS      => __( 'Before the variations form', 'b2b-price-lists' ),
	TableSettings::POSITION_AFTER_VARIATIONS       => __( 'After the variations form', 'b2b-price-lists' ),
	TableSettings::POSITION_AFTER_ADD_TO_CART_FORM => __( 'After the whole add-to-cart form', 'b2b-price-lists' ),
	TableSettings::POSITION_MANUAL_SHORTCODE       => __( 'Manual only, with the shortcode', 'b2b-price-lists' ),
);

$position_notes = array(
	TableSettings::POSITION_BEFORE_ADD_TO_CART     => __( 'On a variable product the table lands right after the variation selectors and right before the quantity field. On a simple product, right before the quantity field.', 'b2b-price-lists' ),
	TableSettings::POSITION_AFTER_ADD_TO_CART      => __( 'Right under the button, before anything the theme adds below it.', 'b2b-price-lists' ),
	TableSettings::POSITION_BEFORE_VARIATIONS      => __( 'Variable products only. On a simple product, which has no variations form, the table falls back to just before the add-to-cart button.', 'b2b-price-lists' ),
	TableSettings::POSITION_AFTER_VARIATIONS       => __( 'Variable products only. On a simple product, which has no variations form, the table falls back to just after the add-to-cart button.', 'b2b-price-lists' ),
	TableSettings::POSITION_AFTER_ADD_TO_CART_FORM => __( 'Under the whole block: variation selectors, quantity and button.', 'b2b-price-lists' ),
	TableSettings::POSITION_MANUAL_SHORTCODE       => __( 'The table never appears on its own. Place it wherever you want with the shortcode.', 'b2b-price-lists' ),
);

/**
 * Formats an example amount in the shop's own currency.
 *
 * @param float $amount Amount.
 * @return string HTML.
 */
$b2bpl_money = static function ( $amount ) {
	if ( function_exists( 'wc_price' ) ) {
		return wc_price( $amount );
	}

	return esc_html( number_format_i18n( (float) $amount, 2 ) );
};

/**
 * A miniature of how a catalogue card reads in each layout, built with the
 * same pieces the storefront prints (price paid, reference price, offer
 * badge, minimum quantity) so the choice can be seen instead of imagined.
 *
 * @param string $layout One of TableSettings::CARD_LAYOUT_*.
 * @return string HTML.
 */
$b2bpl_preview = static function ( $layout ) use ( $b2bpl_money ) {
	$final = $b2bpl_money( 80.75 );
	$base  = $b2bpl_money( 95 );
	$badge = '<span class="b2bpl-cardpreview__badge">-15%</span>';
	$min   = '<em class="b2bpl-cardpreview__min">' . esc_html(
		sprintf(
			/* translators: %d: minimum number of units. */
			_n( 'from %d u.', 'from %d u.', 6, 'b2b-price-lists' ),
			6
		)
	) . '</em>';

	if ( TableSettings::CARD_LAYOUT_STACKED === $layout ) {
		return '<span class="b2bpl-cardpreview__price b2bpl-cardpreview__price--stacked">'
			. '<span class="b2bpl-cardpreview__line">'
			. '<ins class="b2bpl-cardpreview__final">' . $final . '</ins> ' . $badge
			. '</span>'
			. '<span class="b2bpl-cardpreview__reference">'
			. $min . ' '
			. '<span class="b2bpl-cardpreview__label">' . esc_html__( 'Before', 'b2b-price-lists' ) . '</span> '
			. $base
			. '</span>'
			. '</span>';
	}

	return '<span class="b2bpl-cardpreview__price">'
		. '<del class="b2bpl-cardpreview__base">' . $base . '</del> '
		. '<ins class="b2bpl-cardpreview__final">' . $final . '</ins> ' . $badge . ' ' . $min
		. '</span>';
};

/**
 * One card miniature, frame included.
 *
 * @param string $layout One of TableSettings::CARD_LAYOUT_*.
 * @return string HTML.
 */
$b2bpl_preview_card = static function ( $layout ) use ( $b2bpl_preview ) {
	return '<span class="b2bpl-cardpreview" aria-hidden="true">'
		. '<span class="b2bpl-cardpreview__image"></span>'
		. '<span class="b2bpl-cardpreview__name">' . esc_html__( 'Sample product', 'b2b-price-lists' ) . '</span>'
		. $b2bpl_preview( $layout )
		. '</span>';
};
?>
<div class="wrap b2bpl-admin">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'Settings', 'b2b-price-lists' ),
			'subtitle' => __( 'How wholesale prices and the price table are shown in the shop', 'b2b-price-lists' ),
			'icon'     => 'dashicons-admin-generic',
		)
	);
	?>
	<hr class="wp-header-end">

	<?php UI::main_nav( 'settings' ); ?>

	<?php if ( 'saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'b2b-price-lists' ); ?></p></div>
	<?php endif; ?>

	<?php
	UI::hero(
		esc_html__( 'Nothing on this screen changes a price: it only decides how the wholesale price and the quantity table are shown to a customer who already has a price list.', 'b2b-price-lists' ),
		'dashicons-lightbulb'
	);
	?>

	<form method="post" action="<?php echo esc_url( $settings_url ); ?>">
		<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
		<input type="hidden" name="b2bpl_action" value="save-settings">

		<?php UI::card_open( __( 'Price layout', 'b2b-price-lists' ), 'dashicons-grid-view', 'purple' ); ?>
		<p class="description b2bpl-field-intro">
			<?php esc_html_e( 'How the wholesale price is arranged. It changes no price and hides no data: the same pieces are always shown -- what the customer pays, the price it is compared against, and the minimum quantity -- only laid out differently. Catalogue cards and the product page are set separately, because a narrow card and a roomy product page rarely want the same arrangement.', 'b2b-price-lists' ); ?>
		</p>
		<?php
		// Deliberately not a `form-table`: with a two-option chooser that
		// carries its own preview, the label column left the cards squeezed
		// against the right edge with a dead gutter beside them.
		$b2bpl_layout_rows = array(
			'card_layout'   => array(
				'label' => __( 'On catalogue cards', 'b2b-price-lists' ),
				'note'  => __( 'Cards in the shop, category, brand and search pages, and the product carousels.', 'b2b-price-lists' ),
			),
			'single_layout' => array(
				'label' => __( 'On the product page', 'b2b-price-lists' ),
				'note'  => __( 'The main price next to the product title, including the price that updates when a variation or a quantity is chosen.', 'b2b-price-lists' ),
			),
		);

		$b2bpl_layout_choices = array(
			TableSettings::CARD_LAYOUT_INLINE  => array(
				'icon'  => 'dashicons-editor-alignleft',
				'title' => __( 'One line', 'b2b-price-lists' ),
				'note'  => __( 'Everything on a single line, with the compared price struck through. Best where there is width to spare.', 'b2b-price-lists' ),
			),
			TableSettings::CARD_LAYOUT_STACKED => array(
				'icon'  => 'dashicons-editor-justify',
				'title' => __( 'Two lines', 'b2b-price-lists' ),
				'note'  => __( 'The price paid on its own line, big and first, with its offer badge; underneath, in small type, the minimum quantity and the compared price, named instead of struck through ("Unit", or "Before" when an offer applies). On catalogue cards both lines are centred. Best on narrow cards, and it is what most wholesale catalogues use.', 'b2b-price-lists' ),
			),
		);
		?>
		<?php foreach ( $b2bpl_layout_rows as $b2bpl_field => $b2bpl_row ) : ?>
			<div class="b2bpl-setting">
				<h3 class="b2bpl-setting__label"><?php echo esc_html( $b2bpl_row['label'] ); ?></h3>
				<p class="b2bpl-setting__note"><?php echo esc_html( $b2bpl_row['note'] ); ?></p>
				<fieldset class="b2bpl-options b2bpl-options--cards">
					<legend class="screen-reader-text"><?php echo esc_html( $b2bpl_row['label'] ); ?></legend>
					<?php foreach ( $b2bpl_layout_choices as $b2bpl_value => $b2bpl_choice ) : ?>
						<label class="b2bpl-option b2bpl-option--stacked">
							<span class="b2bpl-option__head">
								<input type="radio" name="<?php echo esc_attr( $b2bpl_field ); ?>" value="<?php echo esc_attr( $b2bpl_value ); ?>"
									<?php checked( $settings[ $b2bpl_field ], $b2bpl_value ); ?>>
								<span class="dashicons <?php echo esc_attr( $b2bpl_choice['icon'] ); ?>" aria-hidden="true"></span>
								<strong><?php echo esc_html( $b2bpl_choice['title'] ); ?></strong>
							</span>
							<span class="b2bpl-option__desc"><?php echo esc_html( $b2bpl_choice['note'] ); ?></span>
							<?php echo wp_kses_post( $b2bpl_preview_card( $b2bpl_value ) ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</div>
		<?php endforeach; ?>
		<?php UI::card_close(); ?>

		<?php UI::card_open( __( 'Wholesale price table', 'b2b-price-lists' ), 'dashicons-editor-table', 'teal' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show table', 'b2b-price-lists' ); ?></th>
				<td>
					<div class="b2bpl-switch-row">
						<label class="b2bpl-switch">
							<input type="checkbox" id="b2bpl-show-table" name="show_table" value="1" <?php checked( $settings['show_table'] ); ?>>
							<span class="b2bpl-switch__track" aria-hidden="true"></span>
						</label>
						<label for="b2bpl-show-table"><?php esc_html_e( 'Show the wholesale price table to customers with an applicable price list', 'b2b-price-lists' ); ?></label>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show title', 'b2b-price-lists' ); ?></th>
				<td>
					<div class="b2bpl-switch-row">
						<label class="b2bpl-switch">
							<input type="checkbox" id="b2bpl-show-title" name="show_title" value="1" <?php checked( $settings['show_title'] ); ?>>
							<span class="b2bpl-switch__track" aria-hidden="true"></span>
						</label>
						<label for="b2bpl-show-title"><?php esc_html_e( 'Show the table title', 'b2b-price-lists' ); ?></label>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b2bpl-title"><?php esc_html_e( 'Title', 'b2b-price-lists' ); ?></label></th>
				<td><input type="text" id="b2bpl-title" name="title" class="regular-text" value="<?php echo esc_attr( $settings['title'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show column headers', 'b2b-price-lists' ); ?></th>
				<td>
					<div class="b2bpl-switch-row">
						<label class="b2bpl-switch">
							<input type="checkbox" id="b2bpl-show-headers" name="show_headers" value="1" <?php checked( $settings['show_headers'] ); ?>>
							<span class="b2bpl-switch__track" aria-hidden="true"></span>
						</label>
						<label for="b2bpl-show-headers"><?php esc_html_e( 'Show the quantity/price column headers', 'b2b-price-lists' ); ?></label>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b2bpl-qty-header"><?php esc_html_e( 'Quantity column header', 'b2b-price-lists' ); ?></label></th>
				<td><input type="text" id="b2bpl-qty-header" name="qty_header" class="regular-text" value="<?php echo esc_attr( $settings['qty_header'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="b2bpl-price-header"><?php esc_html_e( 'Price column header', 'b2b-price-lists' ); ?></label></th>
				<td><input type="text" id="b2bpl-price-header" name="price_header" class="regular-text" value="<?php echo esc_attr( $settings['price_header'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Inline variation price', 'b2b-price-lists' ); ?></th>
				<td>
					<div class="b2bpl-switch-row">
						<label class="b2bpl-switch">
							<input type="checkbox" id="b2bpl-show-inline-variation-price" name="show_inline_variation_price" value="1" <?php checked( $settings['show_inline_variation_price'] ); ?>>
							<span class="b2bpl-switch__track" aria-hidden="true"></span>
						</label>
						<label for="b2bpl-show-inline-variation-price"><?php esc_html_e( 'Show B2B price next to the product/variation selection', 'b2b-price-lists' ); ?></label>
					</div>
					<p class="description">
						<?php esc_html_e( 'Controls only the struck-through regular price + B2B price + minimum quantity note shown right after selecting a variation. Disabling it does not affect the wholesale price table, catalog prices or the cart -- those keep working normally.', 'b2b-price-lists' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b2bpl-position"><?php esc_html_e( 'Position', 'b2b-price-lists' ); ?></label></th>
				<td>
					<select id="b2bpl-position" name="position" class="b2bpl-select">
						<?php foreach ( $position_labels as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['position'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php foreach ( $position_notes as $value => $note ) : ?>
						<p class="description b2bpl-position-note" data-position="<?php echo esc_attr( $value ); ?>"
							<?php echo $settings['position'] === $value ? '' : 'hidden'; ?>>
							<?php echo esc_html( $note ); ?>
						</p>
					<?php endforeach; ?>
					<p class="description">
						<?php esc_html_e( 'The [b2b_price_list_table] shortcode is always available regardless of this setting.', 'b2b-price-lists' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php UI::card_close(); ?>

		<?php UI::card_open( __( 'Activity log', 'b2b-price-lists' ), 'dashicons-backup', 'blue' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="b2bpl-retention"><?php esc_html_e( 'Keep history for', 'b2b-price-lists' ); ?></label></th>
				<td>
					<select id="b2bpl-retention" name="activity_retention_months" class="b2bpl-select">
						<?php foreach ( ActivityLog::retention_choices() as $b2bpl_months ) : ?>
							<option value="<?php echo esc_attr( (string) $b2bpl_months ); ?>" <?php selected( (int) $retention_months, $b2bpl_months ); ?>>
								<?php
								printf(
									/* translators: %d: number of months of history kept. */
									esc_html( _n( '%d month', '%d months', $b2bpl_months, 'b2b-price-lists' ) ),
									(int) $b2bpl_months
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Once a day, entries older than this are saved into the archive of their month and only then removed from the history. Nothing is deleted without being archived first, and archives are kept. Six months is the default.', 'b2b-price-lists' ); ?></p>
					<p class="description">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \B2BPriceLists\Admin\ActivityController::PAGE_SLUG ) ); ?>">
							<?php esc_html_e( 'Open the activity log', 'b2b-price-lists' ); ?>
						</a>
					</p>
				</td>
			</tr>
		</table>
		<?php UI::card_close(); ?>

		<?php
		/*
		 * The uninstall card keeps its own danger styling instead of a section
		 * tone: it is the one place on this screen where a choice is not
		 * reversible, and it should not read like the settings above it.
		 */
		?>
		<section class="b2bpl-card b2bpl-card--danger">
		<h2 class="b2bpl-card__title"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( 'Uninstallation', 'b2b-price-lists' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Permanently delete all data on uninstall', 'b2b-price-lists' ); ?></th>
				<td>
					<div class="b2bpl-switch-row">
						<label class="b2bpl-switch">
							<input type="checkbox" id="b2bpl-purge-on-uninstall" name="purge_on_uninstall" value="1" <?php checked( $purge_on_uninstall ); ?>>
							<span class="b2bpl-switch__track" aria-hidden="true"></span>
						</label>
						<label for="b2bpl-purge-on-uninstall"><?php esc_html_e( 'When this plugin is uninstalled, permanently delete every B2B Price Lists table and setting', 'b2b-price-lists' ); ?></label>
					</div>
					<p class="description">
						<?php esc_html_e( 'Disabled by default: uninstalling the plugin normally keeps every list, rule and setting untouched. Enable this only if you want a future uninstall to permanently erase this plugin\'s own data. This never deletes WooCommerce products, users, orders, or any order line item data -- those always belong to WooCommerce, never to this option.', 'b2b-price-lists' ); ?>
					</p>
				</td>
			</tr>
		</table>
		</section>

		<?php submit_button( __( 'Save settings', 'b2b-price-lists' ) ); ?>
	</form>
</div>
