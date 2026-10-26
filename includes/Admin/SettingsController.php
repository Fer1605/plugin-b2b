<?php
/**
 * Admin controller for the "Settings" screen: the
 * customer-facing price table's show/hide, title, headers and position
 * configuration. The single admin capability gate and nonce action already
 * used by every other screen of this plugin (never a screen-specific
 * exception).
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\Nonces;
use B2BPriceLists\Support\TableSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsController
 */
class SettingsController {

	/**
	 * Option name for the uninstall purge opt-in ("Permanently
	 * delete all data on uninstall"), read by `uninstall.php`.
	 *
	 * @var string
	 */
	const PURGE_OPTION_NAME = 'b2bpl_purge_data_on_uninstall';

	/**
	 * Shared instance.
	 *
	 * @var SettingsController|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return SettingsController
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
	 * Entry point called by `ListsController` when `action=settings` (GET).
	 * Not registered directly with WordPress.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		View::render(
			'settings-form',
			array(
				'settings'           => TableSettings::get(),
				'positions'          => TableSettings::valid_positions(),
				'settings_url'       => $this->settings_url(),
				'nonce_field'        => Nonces::ADMIN_ACTION,
				'nonce_name'         => Nonces::FIELD_NAME,
				'purge_on_uninstall' => (bool) get_option( self::PURGE_OPTION_NAME, false ),
				'retention_months'   => ActivityLog::retention_months(),
			)
		);
	}

	/**
	 * Handles the settings form submission. Called by
	 * `ListsController::handle_post()` for `b2bpl_action=save-settings`.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$input = array(
			'show_table'                  => ! empty( $_POST['show_table'] ),
			'show_title'                  => ! empty( $_POST['show_title'] ),
			'title'                       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'show_headers'                => ! empty( $_POST['show_headers'] ),
			'qty_header'                  => isset( $_POST['qty_header'] ) ? sanitize_text_field( wp_unslash( $_POST['qty_header'] ) ) : '',
			'price_header'                => isset( $_POST['price_header'] ) ? sanitize_text_field( wp_unslash( $_POST['price_header'] ) ) : '',
			// Whitelisting itself happens inside TableSettings::sanitize();
			// this is only the initial string extraction from the request.
			'position'                    => isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : '',
			'show_inline_variation_price' => ! empty( $_POST['show_inline_variation_price'] ),
			// Whitelisted inside TableSettings::sanitize(), like `position`.
			'card_layout'                 => isset( $_POST['card_layout'] ) ? sanitize_key( wp_unslash( $_POST['card_layout'] ) ) : '',
			'single_layout'               => isset( $_POST['single_layout'] ) ? sanitize_key( wp_unslash( $_POST['single_layout'] ) ) : '',
		);

		$before = TableSettings::get();
		$after  = TableSettings::save( $input );

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

		// conservative by default, this option is only ever set to
		// `true` by this explicit, capability-gated, nonce-verified form
		// submission -- never a default, never a silent side effect of
		// saving any other setting. `update_option()` persists a PHP
		// boolean as `'1'`/`''`; `uninstall.php` normalizes it back with
		// `FILTER_VALIDATE_BOOLEAN` ( addendum, contract b), so this
		// call can use a plain boolean like every other checkbox here.
		$purge_before = (bool) get_option( self::PURGE_OPTION_NAME, false );
		$purge_after  = ! empty( $_POST['purge_on_uninstall'] );

		update_option( self::PURGE_OPTION_NAME, $purge_after );

		if ( $purge_before !== $purge_after ) {
			$changes[] = array(
				'field' => 'purge_on_uninstall',
				'from'  => $purge_before,
				'to'    => $purge_after,
			);
		}

		$retention_before = ActivityLog::retention_months();
		$retention_after  = isset( $_POST['activity_retention_months'] ) ? absint( $_POST['activity_retention_months'] ) : $retention_before;

		// Only the offered windows (6, 12 or 24 months) are ever stored.
		if ( in_array( $retention_after, ActivityLog::retention_choices(), true ) && $retention_after !== $retention_before ) {
			update_option( ActivityLog::RETENTION_OPTION, $retention_after );

			$changes[] = array(
				'field' => 'activity_retention_months',
				'from'  => $retention_before,
				'to'    => $retention_after,
			);
		}

		if ( ! empty( $changes ) ) {
			ActivityLog::record(
				ActivityLog::SETTINGS_UPDATED,
				array(
					'object_type' => 'settings',
					'changes'     => $changes,
				)
			);
		}

		unset( $before );

		wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'saved', $this->settings_url() ) );
	}

	/**
	 * Builds the settings screen URL.
	 *
	 * @return string
	 */
	public function settings_url() {
		return add_query_arg(
			array(
				'page'   => Menu::PAGE_SLUG,
				'action' => 'settings',
			),
			admin_url( 'admin.php' )
		);
	}
}
