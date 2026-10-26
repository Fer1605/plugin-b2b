<?php
/**
 * Runtime environment compatibility check.
 *
 * Verifies the current PHP, WordPress and WooCommerce versions against the
 * minimum requirements declared in `B2BPriceLists\Support\Requirements`,
 * without ever triggering a fatal error, so it can safely run as early as
 * `plugins_loaded` even when the environment does not meet the
 * requirements.
 *
 * @package B2BPriceLists\Compatibility
 */

namespace B2BPriceLists\Compatibility;

use B2BPriceLists\Support\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EnvironmentCheck
 */
class EnvironmentCheck {

	/**
	 * Whether the running PHP version satisfies the minimum requirement.
	 *
	 * @return bool
	 */
	public function is_php_compatible() {
		return version_compare( PHP_VERSION, Requirements::MIN_PHP_VERSION, '>=' );
	}

	/**
	 * Whether the running WordPress version satisfies the minimum requirement.
	 *
	 * @return bool
	 */
	public function is_wp_compatible() {
		$wp_version = $this->get_wp_version();

		if ( '' === $wp_version ) {
			// Version could not be determined defensively: do not claim compatibility.
			return false;
		}

		return version_compare( $wp_version, Requirements::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Whether WooCommerce is active in the current request.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether the active WooCommerce version satisfies the minimum requirement.
	 *
	 * Returns false when WooCommerce is not active or its version constant
	 * is unavailable, so callers must check `is_woocommerce_active()`
	 * separately to report the precise cause of an incompatibility.
	 *
	 * @return bool
	 */
	public function is_woocommerce_compatible() {
		if ( ! $this->is_woocommerce_active() ) {
			return false;
		}

		if ( ! defined( 'WC_VERSION' ) || '' === WC_VERSION ) {
			return false;
		}

		return version_compare( WC_VERSION, Requirements::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Whether every requirement (PHP, WordPress, WooCommerce presence and
	 * version) is satisfied.
	 *
	 * @return bool
	 */
	public function is_compatible() {
		return $this->is_php_compatible()
			&& $this->is_wp_compatible()
			&& $this->is_woocommerce_active()
			&& $this->is_woocommerce_compatible();
	}

	/**
	 * List of requirement identifiers that currently fail, in evaluation
	 * order, so admin notices can report precise, non-generic causes.
	 *
	 * Possible values: 'php', 'wordpress', 'woocommerce_missing',
	 * 'woocommerce_version'.
	 *
	 * @return string[]
	 */
	public function get_failures() {
		$failures = array();

		if ( ! $this->is_php_compatible() ) {
			$failures[] = 'php';
		}

		if ( ! $this->is_wp_compatible() ) {
			$failures[] = 'wordpress';
		}

		if ( ! $this->is_woocommerce_active() ) {
			$failures[] = 'woocommerce_missing';
		} elseif ( ! $this->is_woocommerce_compatible() ) {
			$failures[] = 'woocommerce_version';
		}

		return $failures;
	}

	/**
	 * Current PHP version, for reporting purposes.
	 *
	 * @return string
	 */
	public function get_current_php_version() {
		return PHP_VERSION;
	}

	/**
	 * Current WordPress version, for reporting purposes.
	 *
	 * @return string Empty string when the version cannot be determined.
	 */
	public function get_current_wp_version() {
		return $this->get_wp_version();
	}

	/**
	 * Current WooCommerce version, for reporting purposes.
	 *
	 * @return string Empty string when WooCommerce is inactive or the
	 *                version constant is unavailable.
	 */
	public function get_current_wc_version() {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
	}

	/**
	 * Reads the current WordPress version defensively.
	 *
	 * @return string Empty string when the version cannot be determined.
	 */
	private function get_wp_version() {
		global $wp_version;

		if ( ! empty( $wp_version ) && is_string( $wp_version ) ) {
			return $wp_version;
		}

		if ( function_exists( 'get_bloginfo' ) ) {
			$version = get_bloginfo( 'version' );

			if ( is_string( $version ) && '' !== $version ) {
				return $version;
			}
		}

		return '';
	}
}
