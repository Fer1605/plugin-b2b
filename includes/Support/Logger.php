<?php
/**
 * Minimal internal logging helper.
 *
 * Used exclusively for conditions that must never be shown to the buyer
 * (data-integrity errors detected by the price resolution engine;
 * unexpected exceptions caught at a WooCommerce integration point,
 * TC-067) -- the storefront always keeps rendering the safe B2C fallback
 * regardless of what happens here.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 */
class Logger {

	/**
	 * Logs an internal error. Uses the `b2bpl_log_error` action when
	 * something is hooked to it (so a site can route this to a proper
	 * logging plugin/service), and always falls back to PHP's own
	 * `error_log()` so nothing is silently lost when no listener is
	 * registered.
	 *
	 * @param string              $message Short, developer-facing message
	 *                                      (never shown to the buyer).
	 * @param array<string,mixed> $context Extra structured context
	 *                                      (product_id, list_id, etc.).
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires whenever the plugin logs an internal error.
			 *
			 * @param string               $message Log message.
			 * @param array<string,mixed>  $context Structured context.
			 */
			do_action( 'b2bpl_log_error', $message, $context );
		}

		$line = '[B2B Price Lists] ' . $message;

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate, minimal internal logging sink; never exposes content to the storefront.
		error_log( $line );
	}
}
