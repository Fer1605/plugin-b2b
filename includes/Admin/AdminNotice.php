<?php
/**
 * Safe admin notices for bootstrap-time failures.
 *
 * Renders the notices needed when the plugin cannot start: missing
 * Composer autoloader, and the four environment failures reported by
 * `B2BPriceLists\Compatibility\EnvironmentCheck` (PHP too old, WordPress
 * too old, WooCommerce missing, WooCommerce too old). Every method here is
 * defensive by design, because it may run precisely when the environment
 * is not the one expected.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Compatibility\EnvironmentCheck;
use B2BPriceLists\Support\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminNotice
 */
class AdminNotice {

	/**
	 * Messages already queued in the current request, keyed by a hash of
	 * their content, to avoid rendering duplicates if a notice is
	 * triggered more than once.
	 *
	 * @var array<string, bool>
	 */
	private static $queued = array();

	/**
	 * Queues the notice shown when `vendor/autoload.php` is missing.
	 *
	 * @return void
	 */
	public static function missing_autoloader() {
		self::add(
			__( 'B2B Price Lists could not start: the Composer autoloader is missing. Run "composer install" (or "composer dump-autoload") inside the plugin folder.', 'b2b-price-lists' )
		);
	}

	/**
	 * Queues one notice per unmet requirement reported by EnvironmentCheck.
	 *
	 * @param string[]         $failures Failure identifiers from EnvironmentCheck::get_failures().
	 * @param EnvironmentCheck $check    The check instance, used to read current versions.
	 * @return void
	 */
	public static function for_environment_failures( array $failures, EnvironmentCheck $check ) {
		foreach ( $failures as $failure ) {
			switch ( $failure ) {
				case 'php':
					self::add(
						sprintf(
							/* translators: 1: required PHP version, 2: current PHP version. */
							__( 'B2B Price Lists requires PHP %1$s or higher. The current PHP version is %2$s.', 'b2b-price-lists' ),
							Requirements::MIN_PHP_VERSION,
							$check->get_current_php_version()
						)
					);
					break;

				case 'wordpress':
					self::add(
						sprintf(
							/* translators: 1: required WordPress version, 2: current WordPress version. */
							__( 'B2B Price Lists requires WordPress %1$s or higher. The current WordPress version is %2$s.', 'b2b-price-lists' ),
							Requirements::MIN_WP_VERSION,
							$check->get_current_wp_version()
						)
					);
					break;

				case 'woocommerce_missing':
					self::add(
						__( 'B2B Price Lists requires WooCommerce to be installed and active.', 'b2b-price-lists' )
					);
					break;

				case 'woocommerce_version':
					self::add(
						sprintf(
							/* translators: 1: required WooCommerce version, 2: current WooCommerce version. */
							__( 'B2B Price Lists requires WooCommerce %1$s or higher. The current WooCommerce version is %2$s.', 'b2b-price-lists' ),
							Requirements::MIN_WC_VERSION,
							$check->get_current_wc_version()
						)
					);
					break;
			}
		}
	}

	/**
	 * Queues a single notice for rendering on `admin_notices`.
	 *
	 * @param string $message Already translated, plain-text message.
	 * @return void
	 */
	private static function add( $message ) {
		$message = (string) $message;

		if ( '' === $message ) {
			return;
		}

		$hash = md5( $message );

		if ( isset( self::$queued[ $hash ] ) ) {
			return;
		}

		self::$queued[ $hash ] = true;

		add_action(
			'admin_notices',
			static function () use ( $message ) {
				self::render( $message );
			}
		);
	}

	/**
	 * Outputs a single notice box, defensively.
	 *
	 * @param string $message Already translated, plain-text message.
	 * @return void
	 */
	private static function render( $message ) {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
