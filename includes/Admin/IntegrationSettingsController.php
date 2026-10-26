<?php
/**
 * Admin controller for "Integration -> Settings" (T040, `manage_options`
 * only): turning the webhook on/off, the retention window,
 * the new-account welcome email, whether granular debug-level events are
 * stored, the notification-email recipients, and -- since -- the
 * catalogue module (whether the webhook may write products at all, the status
 * a product is created with, whether missing categories/tags are created, and
 * which taxonomy a brand goes to), and -- since -- the two customer
 * fields (which role a B2B account is given, and which meta key the
 * customer's tax id is written to). Every field is persisted
 * through `Webhook\Settings::save()`, which already whitelists and clamps
 * every value (`Settings::sanitize()`) -- this controller only shapes raw
 * `$_POST` values into the array `Settings::save()` expects.
 *
 * The role whitelist, IP allowlist, trusted proxy header and per-request
 * limits this screen used to manage have all been removed or made fixed,
 * non-configurable values (site owner request, 2026-09) -- see
 * `Webhook\Settings`' own class docblock.
 *
 * Since this screen also manages the webhook's own username/
 * password (`Webhook\Credentials`): saving the username, generating a
 * random password (shown exactly once, from a short-lived per-admin
 * transient this controller manages, never persisted or logged in plain
 * text), or setting one manually. The webhook can never be enabled without
 * both configured (`Credentials::resolve_enabled_flag()`); this screen no
 * longer creates or explains a WordPress user/role/Application Password at
 * all.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\Nonces;
use B2BPriceLists\Repository\Webhook\RequestsRepository;
use B2BPriceLists\Webhook\Credentials;
use B2BPriceLists\Webhook\RestController;
use B2BPriceLists\Webhook\RetentionPurger;
use B2BPriceLists\Webhook\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IntegrationSettingsController
 */
class IntegrationSettingsController {

	/**
	 * Transient key prefix holding a freshly generated password, in plain
	 * text, for exactly one admin screen load ("se muestra una
	 * sola vez"). Keyed by the current administrator's user id so two
	 * administrators generating a password around the same time never see
	 * each other's.
	 *
	 * @var string
	 */
	const NEW_PASSWORD_TRANSIENT_PREFIX = 'b2bpl_webhook_new_password_';

	/**
	 * How long the freshly generated password stays available to be shown,
	 * in seconds -- long enough for the redirect this same request ends
	 * with to render, short enough that it can never be fetched again
	 * after the page has loaded once.
	 *
	 * @var int
	 */
	const NEW_PASSWORD_TRANSIENT_TTL = 60;

	/**
	 * Shared instance.
	 *
	 * @var IntegrationSettingsController|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return IntegrationSettingsController
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
	private function __construct() {}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Capabilities::can_configure_integration() ) {
			wp_die( esc_html__( 'Only site administrators can configure the webhook.', 'b2b-price-lists' ) );
		}

		View::render(
			'integration-settings',
			array(
				'settings'                 => Settings::get(),
				'notification_logo_url'    => self::logo_url( (int) Settings::get()['notification_logo_id'] ),
				'retention_choices'        => Settings::valid_retention_months(),
				'endpoints'                => $this->endpoint_urls(),
				'credentials'              => Credentials::get(),
				'credentials_configured'   => Credentials::is_configured(),
				'revealed_password'        => $this->consume_revealed_password(),
				'last_purge'               => RetentionPurger::last_purge(),
				// the taxonomy a brand would actually be written to
				// right now -- the configured one when it exists, and
				// otherwise whichever known brand taxonomy this site
				// registers. '' when the shop has none at all.
				'catalog_brand_taxonomy'   => Settings::catalog_brand_taxonomy(),
				'settings_url'             => IntegrationLogController::instance()->settings_url(),
				'logs_url'                 => IntegrationLogController::instance()->logs_url(),
				'guide_url'                => IntegrationLogController::instance()->guide_url(),
				'stuck_count'              => count( ( new RequestsRepository() )->find_stuck_queued( IntegrationLogController::STUCK_QUEUE_MINUTES ) ),
				'retry_queue_nonce_action' => IntegrationLogController::NONCE_RETRY_QUEUE,
				'retry_queue_action_url'   => IntegrationLogController::instance()->logs_url(),
				'nonce_field'              => Nonces::ADMIN_ACTION,
				'nonce_name'               => Nonces::FIELD_NAME,
			)
		);
	}

	/**
	 * Reads and immediately deletes the current administrator's one-time
	 * password-reveal transient, if any ("se muestra una sola
	 * vez"). A second load of this screen (a refresh, a second tab) never
	 * sees it again.
	 *
	 * @return string|null
	 */
	private function consume_revealed_password() {
		$key      = self::NEW_PASSWORD_TRANSIENT_PREFIX . get_current_user_id();
		$password = get_transient( $key );

		if ( false === $password || ! is_string( $password ) || '' === $password ) {
			return null;
		}

		delete_transient( $key );

		return $password;
	}

	/**
	 * Handles the settings form submission. Called by
	 * `IntegrationLogController::handle_early_actions()` for
	 * `b2bpl_action=save-integration-settings`.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! Capabilities::can_configure_integration() ) {
			wp_die( esc_html__( 'Only site administrators can configure the webhook.', 'b2b-price-lists' ) );
		}

		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$before            = Settings::get();
		$requested_enabled = ! empty( $_POST['enabled'] );

		$input = array(
			// the webhook can never end up enabled without a
			// configured username and password, no matter what was
			// submitted here.
			'enabled'              => Credentials::resolve_enabled_flag( $requested_enabled, Credentials::is_configured() ),
			'retention_months'     => isset( $_POST['retention_months'] ) ? absint( wp_unslash( $_POST['retention_months'] ) ) : null,
			'send_new_user_email'  => ! empty( $_POST['send_new_user_email'] ),
			'store_debug_events'   => ! empty( $_POST['store_debug_events'] ),
			'notification_emails'  => self::textarea_to_list( isset( $_POST['notification_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notification_emails'] ) ) : '' ),
			'notification_logo_id' => isset( $_POST['notification_logo_id'] ) ? absint( wp_unslash( $_POST['notification_logo_id'] ) ) : 0,
			// Catalogue. `Settings::sanitize` still decides what
			// each of these may end up as -- `catalog_status` falls back to
			// `publish` for anything other than `draft` -- so this only
			// shapes the raw submission.
			'catalog_enabled'      => ! empty( $_POST['catalog_enabled'] ),
			'catalog_status'       => isset( $_POST['catalog_status'] ) ? sanitize_key( wp_unslash( $_POST['catalog_status'] ) ) : 'publish',
			'catalog_create_terms' => ! empty( $_POST['catalog_create_terms'] ),
		);

		$after = Settings::save( $input );

		$changes = array();

		foreach ( $after as $field => $value ) {
			if ( ( $before[ $field ] ?? null ) === $value ) {
				continue;
			}

			$changes[] = array(
				'field' => $field,
				'from'  => $before[ $field ] ?? null,
				'to'    => $value,
			);
		}

		if ( ! empty( $changes ) ) {
			// Reuses `SETTINGS_UPDATED`, the same action already used by the
			// price-table settings screen (`Admin\SettingsController`):
			// `object_type` is what tells the two apart on the Activity
			// screen, so no new action constant is needed.
			ActivityLog::record(
				ActivityLog::SETTINGS_UPDATED,
				array(
					'object_type' => 'webhook_settings',
					'changes'     => $changes,
				)
			);
		}

		$notice = ( $requested_enabled && ! $after['enabled'] ) ? 'enabled-requires-credentials' : 'saved';

		wp_safe_redirect( add_query_arg( 'b2bpl_notice', $notice, IntegrationLogController::instance()->settings_url() ) );
		exit;
	}

	/**
	 * Handles `b2bpl_action=save-webhook-username`: saves the
	 * webhook's own username, independent of the rest of the settings form.
	 * An invalid submission (wrong length/characters) changes nothing.
	 *
	 * @return void
	 */
	public function handle_save_username() {
		if ( ! Capabilities::can_configure_integration() ) {
			wp_die( esc_html__( 'Only site administrators can configure the webhook.', 'b2b-price-lists' ) );
		}

		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$username = isset( $_POST['webhook_username'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_username'] ) ) : '';
		$notice   = 'invalid-username';

		if ( Credentials::is_valid_username( $username ) ) {
			$current = Credentials::get()['username'];

			if ( $username !== $current ) {
				Credentials::save_username( $username );
				$this->log_credentials_updated();
			}

			$notice = 'username-saved';
		}

		wp_safe_redirect( add_query_arg( 'b2bpl_notice', $notice, IntegrationLogController::instance()->settings_url() ) );
		exit;
	}

	/**
	 * Handles `b2bpl_action=generate-webhook-password`: a random
	 * 32-character password, hashed and saved immediately (invalidating any
	 * previous one), shown exactly once via `consume_revealed_password()`.
	 *
	 * @return void
	 */
	public function handle_generate_password() {
		if ( ! Capabilities::can_configure_integration() ) {
			wp_die( esc_html__( 'Only site administrators can configure the webhook.', 'b2b-price-lists' ) );
		}

		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$plaintext = Credentials::generate_password();

		Credentials::save_password_hash( Credentials::hash_password( $plaintext ) );
		set_transient( self::NEW_PASSWORD_TRANSIENT_PREFIX . get_current_user_id(), $plaintext, self::NEW_PASSWORD_TRANSIENT_TTL );
		$this->log_credentials_updated();

		wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'password-generated', IntegrationLogController::instance()->settings_url() ) );
		exit;
	}

	/**
	 * Handles `b2bpl_action=set-webhook-password`: a manually
	 * chosen password (at least 20 characters), hashed and saved
	 * immediately (invalidating any previous one). Never shown back --
	 * the administrator who submitted it already knows it.
	 *
	 * @return void
	 */
	public function handle_set_password() {
		if ( ! Capabilities::can_configure_integration() ) {
			wp_die( esc_html__( 'Only site administrators can configure the webhook.', 'b2b-price-lists' ) );
		}

		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		// Deliberately not `sanitize_text_field()`: a password is free-form
		// data, hashed immediately below, never displayed or stored raw --
		// mangling its characters would only make it harder for the
		// administrator who chose it to reproduce.
		$password = isset( $_POST['webhook_new_password'] ) ? (string) wp_unslash( $_POST['webhook_new_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deliberately not sanitize_text_field(): see the comment above.

		if ( ! Credentials::is_valid_manual_password( $password ) ) {
			wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'password-too-short', IntegrationLogController::instance()->settings_url() ) );
			exit;
		}

		Credentials::save_password_hash( Credentials::hash_password( $password ) );
		$this->log_credentials_updated();

		wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'password-set', IntegrationLogController::instance()->settings_url() ) );
		exit;
	}

	/**
	 * Records "Credentials del webhook actualizadas" in the activity log
	 *: the actor (the logged-in administrator) is picked up
	 * automatically by `ActivityLog::record()`'s own default -- never the
	 * secret itself.
	 *
	 * @return void
	 */
	private function log_credentials_updated() {
		ActivityLog::record(
			ActivityLog::SETTINGS_UPDATED,
			array(
				'object_type'  => 'webhook_credentials',
				'object_label' => __( 'Webhook credentials', 'b2b-price-lists' ),
			)
		);
	}

	/**
	 * Splits a textarea's raw text into one candidate string per entry, one
	 * per line and/or comma-separated (used for the notification-emails
	 * field). `Webhook\Settings::sanitize_notification_emails()` still
	 * trims/validates/deduplicates/caps the result afterward -- this only
	 * turns the form field's raw text into the plain array `Settings::save()`
	 * expects.
	 *
	 * @param string $text Raw textarea content.
	 * @return string[]
	 */
	public static function textarea_to_list( $text ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return array();
		}

		return array_map( 'trim', (array) preg_split( '/[\r\n,]+/', $text ) );
	}

	/**
	 * URL of the notification email logo, or '' when none is set.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function logo_url( $attachment_id ) {
		$url = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, 'full' ) : '';

		return is_string( $url ) ? $url : '';
	}

	/**
	 * The webhook's single URL, ready to paste into the external
	 * system's own configuration.
	 *
	 * @return array<string,string>
	 */
	private function endpoint_urls() {
		return array(
			'webhook' => RestController::url(),
		);
	}
}
