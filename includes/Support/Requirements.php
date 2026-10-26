<?php
/**
 * Centralized minimum version requirements.
 *
 * Single source of truth for the minimum PHP, WordPress and WooCommerce
 * versions supported by this release. `EnvironmentCheck` reads these
 * constants instead of hard-coding versions of its own, so the plugin
 * header, `readme.txt` and the runtime check can be kept in sync manually
 * against one authoritative place in code.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Requirements
 */
class Requirements {

	/**
	 * Minimum PHP version required by this release.
	 *
	 * @var string
	 */
	const MIN_PHP_VERSION = '8.0.30';

	/**
	 * Minimum WordPress version required by this release.
	 *
	 * @var string
	 */
	const MIN_WP_VERSION = '7.0.2';

	/**
	 * Minimum WooCommerce version required by this release.
	 *
	 * @var string
	 */
	const MIN_WC_VERSION = '10.9.4';
}
