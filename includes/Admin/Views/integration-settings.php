<?php
/**
 * View: "Integration -> Settings" (T040, `manage_options` only).
 *
 * Expected variables: $settings (array), $notification_logo_url (string), $retention_choices (int[]),
 * $endpoints (array), $credentials (array), $credentials_configured (bool),
 * $revealed_password (string|null), $last_purge (array|null),
 * $catalog_brand_taxonomy (string, the brand taxonomy actually resolved for
 * this site, from `Webhook\Settings::catalog_brand_taxonomy()`, '' when the
 * shop has none), $settings_url (string), $logs_url (string), $guide_url
 * (string), $stuck_count (int), $retry_queue_nonce_action (string),
 * $retry_queue_action_url (string), $nonce_field (string), $nonce_name
 * (string).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\Menu;
use B2BPriceLists\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$b2bpl_notice = isset( $_GET['b2bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap b2bpl-admin b2bpl-integration">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'B2B Price Lists — Integration', 'b2b-price-lists' ),
			'subtitle' => __( 'Who may send data into this store, and how long it is kept', 'b2b-price-lists' ),
			'icon'     => 'dashicons-rest-api',
			'badge'    => $settings['enabled']
				? array(
					'label' => __( 'Webhook on', 'b2b-price-lists' ),
					'tone'  => 'ok',
					'icon'  => 'dashicons-yes-alt',
				)
				: array(
					'label' => __( 'Webhook off', 'b2b-price-lists' ),
					'tone'  => 'off',
					'icon'  => 'dashicons-marker',
				),
		)
	);
	?>
	<hr class="wp-header-end">

	<?php UI::main_nav( 'integration' ); ?>

	<?php if ( 'saved' === $b2bpl_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'enabled-requires-credentials' === $b2bpl_notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The webhook was not enabled: configure a username and a password below first.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'username-saved' === $b2bpl_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Webhook username saved.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'invalid-username' === $b2bpl_notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That username is not valid: use 3 to 60 letters, numbers, dots, underscores or hyphens.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'password-generated' === $b2bpl_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'New password generated. Copy it below before leaving this page -- it will not be shown again.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'password-set' === $b2bpl_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Webhook password updated.', 'b2b-price-lists' ); ?></p></div>
	<?php elseif ( 'password-too-short' === $b2bpl_notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That password is too short: use at least 20 characters.', 'b2b-price-lists' ); ?></p></div>
	<?php endif; ?>

	<?php
	UI::tabs(
		array(
			array(
				'label' => __( 'Log', 'b2b-price-lists' ),
				'url'   => $logs_url,
				'icon'  => 'dashicons-list-view',
			),
			array(
				'label'  => __( 'Settings', 'b2b-price-lists' ),
				'url'    => $settings_url,
				'icon'   => 'dashicons-admin-generic',
				'active' => true,
			),
			array(
				'label' => __( 'Usage guide', 'b2b-price-lists' ),
				'url'   => $guide_url,
				'icon'  => 'dashicons-book',
			),
		),
		__( 'Integration sections', 'b2b-price-lists' )
	);
	?>

	<?php
	require __DIR__ . '/integration-stuck-notice.php';
	?>

	<?php UI::card_open( __( 'Webhook credentials', 'b2b-price-lists' ), 'dashicons-lock', 'blue' ); ?>

		<?php if ( is_string( $revealed_password ) ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'New password generated:', 'b2b-price-lists' ); ?></strong></p>
				<p>
					<code id="b2bpl-revealed-password"><?php echo esc_html( $revealed_password ); ?></code>
					<button type="button" class="button button-small" data-b2bpl-copy-target="b2bpl-revealed-password" data-b2bpl-copied-label="<?php esc_attr_e( 'Copied!', 'b2b-price-lists' ); ?>">
						<?php esc_html_e( 'Copy', 'b2b-price-lists' ); ?>
					</button>
				</p>
				<p><?php esc_html_e( 'Save it now; it will not be shown again.', 'b2b-price-lists' ); ?></p>
			</div>
		<?php endif; ?>

		<p>
			<?php if ( $credentials_configured && '' !== (string) $credentials['configured_at'] ) : ?>
				<?php
				printf(
					/* translators: %s: date/time (UTC) the webhook credentials were last configured. */
					esc_html__( 'Credentials configured on %s UTC.', 'b2b-price-lists' ),
					esc_html( (string) $credentials['configured_at'] )
				);
				?>
			<?php else : ?>
				<strong><?php esc_html_e( 'No credentials configured yet. The webhook cannot be enabled until a username and a password are set here.', 'b2b-price-lists' ); ?></strong>
			<?php endif; ?>
		</p>

		<form method="post" action="<?php echo esc_url( $settings_url ); ?>" class="b2bpl-inline-form">
			<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
			<input type="hidden" name="b2bpl_action" value="save-webhook-username">
			<label for="b2bpl-webhook-username"><?php esc_html_e( 'Webhook username', 'b2b-price-lists' ); ?></label>
			<input type="text" id="b2bpl-webhook-username" name="webhook_username" class="regular-text" minlength="3" maxlength="60" pattern="[A-Za-z0-9._\-]+" value="<?php echo esc_attr( $credentials['username'] ); ?>" required>
			<?php submit_button( __( 'Save username', 'b2b-price-lists' ), 'secondary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( '3 to 60 characters: letters, numbers, dots, underscores and hyphens.', 'b2b-price-lists' ); ?></p>
		</form>

		<form method="post" action="<?php echo esc_url( $settings_url ); ?>" class="b2bpl-inline-form">
			<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
			<input type="hidden" name="b2bpl_action" value="generate-webhook-password">
			<?php submit_button( __( 'Generate new password', 'b2b-price-lists' ), 'secondary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( 'Creates a random 32-character password and shows it once, above. Any password already in use stops working immediately.', 'b2b-price-lists' ); ?></p>
		</form>

		<form method="post" action="<?php echo esc_url( $settings_url ); ?>" class="b2bpl-inline-form">
			<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
			<input type="hidden" name="b2bpl_action" value="set-webhook-password">
			<label for="b2bpl-webhook-new-password"><?php esc_html_e( 'Or set a password', 'b2b-price-lists' ); ?></label>
			<input type="password" id="b2bpl-webhook-new-password" name="webhook_new_password" class="regular-text" minlength="20" autocomplete="new-password">
			<?php submit_button( __( 'Set password', 'b2b-price-lists' ), 'secondary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( 'At least 20 characters. Only needed if you want to choose your own password instead of generating one.', 'b2b-price-lists' ); ?></p>
		</form>
	<?php UI::card_close(); ?>

	<form method="post" action="<?php echo esc_url( $settings_url ); ?>">
		<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
		<input type="hidden" name="b2bpl_action" value="save-integration-settings">

		<?php UI::card_open( __( 'Webhook status', 'b2b-price-lists' ), 'dashicons-admin-plugins', 'blue' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'b2b-price-lists' ); ?></th>
					<td>
						<div class="b2bpl-switch-row">
							<label class="b2bpl-switch">
								<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
								<span class="b2bpl-switch__track" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Turn the webhook on', 'b2b-price-lists' ); ?></span>
							</label>
							<span><?php esc_html_e( 'Turn the webhook on', 'b2b-price-lists' ); ?></span>
						</div>
						<p class="description"><?php esc_html_e( 'Off by default. While off, none of the endpoints below exist -- any request to them gets a plain 404, exactly like an address that was never registered.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Endpoint', 'b2b-price-lists' ); ?></th>
					<td>
						<ul class="b2bpl-endpoint-list">
							<?php foreach ( $endpoints as $b2bpl_key => $b2bpl_url ) : ?>
								<li>
									<code id="b2bpl-endpoint-<?php echo esc_attr( $b2bpl_key ); ?>"><?php echo esc_html( $b2bpl_url ); ?></code>
									<button type="button" class="button button-small" data-b2bpl-copy-target="b2bpl-endpoint-<?php echo esc_attr( $b2bpl_key ); ?>" data-b2bpl-copied-label="<?php esc_attr_e( 'Copied!', 'b2b-price-lists' ); ?>">
										<?php esc_html_e( 'Copy', 'b2b-price-lists' ); ?>
									</button>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="description">
							<?php esc_html_e( 'One address for everything. POST sends a batch: "type": "lists" for list and product operations, or "type": "users" for user operations; GET checks the connection; GET with ?batch_id= returns the result of a batch. Authenticate with the webhook username and password configured above, using HTTP Basic authentication over HTTPS -- in the external system\'s own configuration, or in a tool such as Postman.', 'b2b-price-lists' ); ?>
						</p>
					</td>
				</tr>
			</table>
		<?php UI::card_close(); ?>

		<?php
		// the catalogue module. Status choices are presented as
		// cards (the same `b2bpl-options--cards` pattern the price-table
		// settings screen uses) because the choice has a real consequence
		// worth spelling out next to each option, not just a label.
		$b2bpl_catalog_status_choices = array(
			'publish' => array(
				'icon'  => 'dashicons-visibility',
				'title' => __( 'Published (recommended)', 'b2b-price-lists' ),
				'note'  => __( 'A product the webhook creates goes on sale straight away, and the B2B price lists can resolve it immediately.', 'b2b-price-lists' ),
			),
			'draft'   => array(
				'icon'  => 'dashicons-hidden',
				'title' => __( 'Draft', 'b2b-price-lists' ),
				'note'  => __( 'A product the webhook creates waits for somebody in the shop to review and publish it by hand.', 'b2b-price-lists' ),
			),
		);
		?>
		<?php UI::card_open( __( 'Product catalogue', 'b2b-price-lists' ), 'dashicons-products', 'green' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Create and update products', 'b2b-price-lists' ); ?></th>
					<td>
						<div class="b2bpl-switch-row">
							<label class="b2bpl-switch">
								<input type="checkbox" name="catalog_enabled" value="1" <?php checked( $settings['catalog_enabled'] ); ?>>
								<span class="b2bpl-switch__track" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Let the webhook create and update this store\'s products', 'b2b-price-lists' ); ?></span>
							</label>
							<span><?php esc_html_e( 'Let the webhook create and update this store\'s products', 'b2b-price-lists' ); ?></span>
						</div>
						<p class="description"><?php esc_html_e( 'Off by default. While it is off, the webhook neither creates nor modifies a single product: every operation in a "catalog" batch is refused with catalog_disabled, and the rest of the webhook -- price lists, product rules and customers -- keeps working exactly as before.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status for new products', 'b2b-price-lists' ); ?></th>
					<td>
						<fieldset class="b2bpl-options b2bpl-options--cards">
							<legend class="screen-reader-text"><?php esc_html_e( 'Status given to a product the webhook creates', 'b2b-price-lists' ); ?></legend>
							<?php foreach ( $b2bpl_catalog_status_choices as $b2bpl_status_value => $b2bpl_status_choice ) : ?>
								<label class="b2bpl-option b2bpl-option--stacked">
									<span class="b2bpl-option__head">
										<input type="radio" name="catalog_status" value="<?php echo esc_attr( $b2bpl_status_value ); ?>"
											<?php checked( $settings['catalog_status'], $b2bpl_status_value ); ?>>
										<span class="dashicons <?php echo esc_attr( $b2bpl_status_choice['icon'] ); ?>" aria-hidden="true"></span>
										<strong><?php echo esc_html( $b2bpl_status_choice['title'] ); ?></strong>
									</span>
									<span class="b2bpl-option__desc"><?php echo esc_html( $b2bpl_status_choice['note'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'A draft is not found by the B2B rules, which only ever resolve a published product: a rule pointing at a draft SKU keeps failing until somebody publishes it.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Categories and tags', 'b2b-price-lists' ); ?></th>
					<td>
						<div class="b2bpl-switch-row">
							<label class="b2bpl-switch">
								<input type="checkbox" name="catalog_create_terms" value="1" <?php checked( $settings['catalog_create_terms'] ); ?>>
								<span class="b2bpl-switch__track" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Create the categories and tags that do not exist yet', 'b2b-price-lists' ); ?></span>
							</label>
							<span><?php esc_html_e( 'Create the categories and tags that do not exist yet', 'b2b-price-lists' ); ?></span>
						</div>
						<p class="description"><?php esc_html_e( 'On by default. With it off, a product is still saved, but a category or tag this store does not already have is skipped and reported in the batch log instead of being created.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Brands', 'b2b-price-lists' ); ?></th>
					<td>
						<?php if ( '' !== $catalog_brand_taxonomy ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: name of the brand taxonomy currently in use, wrapped in a <code> tag. */
									esc_html__( 'A product\'s brand is written to this store\'s own brands, %s. Worked out from the store itself -- there is nothing to configure.', 'b2b-price-lists' ),
									'<code>' . esc_html( $catalog_brand_taxonomy ) . '</code>'
								);
								?>
							</p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'This store has no brands of its own right now, so a brand sent in a catalogue batch is skipped and reported in the batch log -- never invented.', 'b2b-price-lists' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		<?php UI::card_close(); ?>

		<?php UI::card_open( __( 'Retention, email and logging', 'b2b-price-lists' ), 'dashicons-backup', 'blue' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="b2bpl-int-retention"><?php esc_html_e( 'Keep the received JSON and logs for', 'b2b-price-lists' ); ?></label></th>
					<td>
						<select id="b2bpl-int-retention" name="retention_months" class="b2bpl-select">
							<?php foreach ( $retention_choices as $b2bpl_months ) : ?>
								<option value="<?php echo esc_attr( (string) $b2bpl_months ); ?>" <?php selected( (int) $settings['retention_months'], $b2bpl_months ); ?>>
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
						<p class="description"><?php esc_html_e( 'Once a day, requests and their items/events older than this are permanently removed.', 'b2b-price-lists' ); ?></p>
						<?php if ( is_array( $last_purge ) ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: date/time of the last purge, 2: number of requests removed. */
									esc_html__( 'Last automatic purge: %1$s UTC, %2$d request(s) removed.', 'b2b-price-lists' ),
									esc_html( (string) $last_purge['ran_at'] ),
									(int) $last_purge['requests_removed']
								);
								?>
							</p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'The automatic purge has not run yet.', 'b2b-price-lists' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Welcome email', 'b2b-price-lists' ); ?></th>
					<td>
						<div class="b2bpl-switch-row">
							<label class="b2bpl-switch">
								<input type="checkbox" name="send_new_user_email" value="1" <?php checked( $settings['send_new_user_email'] ); ?>>
								<span class="b2bpl-switch__track" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Send WooCommerce\'s standard "New account" email to users created by the webhook', 'b2b-price-lists' ); ?></span>
							</label>
							<span><?php esc_html_e( 'Send WooCommerce\'s standard "New account" email to users created by the webhook', 'b2b-price-lists' ); ?></span>
						</div>
						<p class="description"><?php esc_html_e( 'Off by default. A single user can still request it for themselves with send_welcome_email: true, regardless of this setting.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Debug events', 'b2b-price-lists' ); ?></th>
					<td>
						<div class="b2bpl-switch-row">
							<label class="b2bpl-switch">
								<input type="checkbox" name="store_debug_events" value="1" <?php checked( $settings['store_debug_events'] ); ?>>
								<span class="b2bpl-switch__track" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Keep debug-level events in the timeline (received, validated, queued...)', 'b2b-price-lists' ); ?></span>
							</label>
							<span><?php esc_html_e( 'Keep debug-level events in the timeline (received, validated, queued...)', 'b2b-price-lists' ); ?></span>
						</div>
						<p class="description"><?php esc_html_e( 'Turn this off once the integration is trusted, to keep only info/warning/error events and reduce how many rows are stored.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="b2bpl-notification-emails"><?php esc_html_e( 'Notification emails', 'b2b-price-lists' ); ?></label></th>
					<td>
						<textarea id="b2bpl-notification-emails" name="notification_emails" rows="4" class="large-text" placeholder="ops@example.com&#10;dev@example.com"><?php echo esc_textarea( implode( "\n", $settings['notification_emails'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One address per line, or comma-separated. Up to 10 addresses. Empty by default -- no email is sent while this is empty. When set, an email is sent for every rejected webhook request and every batch that finishes with errors or fails, up to 30 individual emails per hour; anything beyond that is combined into a single hourly digest instead of being dropped.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email logo', 'b2b-price-lists' ); ?></th>
					<td>
						<div class="b2bpl-email-logo" data-b2bpl-email-logo>
							<input type="hidden" name="notification_logo_id" value="<?php echo esc_attr( (string) $settings['notification_logo_id'] ); ?>" data-b2bpl-email-logo-id>
							<img class="b2bpl-email-logo__preview" src="<?php echo esc_url( $notification_logo_url ); ?>" alt="" data-b2bpl-email-logo-preview <?php echo '' === $notification_logo_url ? 'hidden' : ''; ?>>
							<p>
								<button type="button" class="button" data-b2bpl-email-logo-choose data-b2bpl-title="<?php esc_attr_e( 'Choose the email logo', 'b2b-price-lists' ); ?>" data-b2bpl-button="<?php esc_attr_e( 'Use this image', 'b2b-price-lists' ); ?>"><?php esc_html_e( 'Choose image', 'b2b-price-lists' ); ?></button>
								<button type="button" class="button-link" data-b2bpl-email-logo-remove><?php esc_html_e( 'Remove image', 'b2b-price-lists' ); ?></button>
							</p>
						</div>
						<p class="description"><?php esc_html_e( 'Logo shown at the top of notification emails. Use a PNG or JPG image: most email programs do not display SVG. When empty, the header image from the WooCommerce email settings is used.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
			</table>
		<?php UI::card_close(); ?>

		<?php submit_button( __( 'Save settings', 'b2b-price-lists' ) ); ?>
	</form>
</div>
