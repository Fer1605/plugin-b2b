<?php
/**
 * Minimal view renderer: keeps presentation (plain PHP templates under
 * `includes/Admin/Views/`) out of the controller classes.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class View
 */
class View {

	/**
	 * Renders a view template, immediately echoing its output.
	 *
	 * @param string              $template Template file name (without .php), under Views/.
	 * @param array<string,mixed> $vars     Variables extracted into the template's scope.
	 * @return void
	 */
	public static function render( $template, array $vars = array() ) {
		$path = __DIR__ . '/Views/' . basename( $template ) . '.php';

		if ( ! file_exists( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Deliberate, minimal templating helper; $vars is always built by trusted controller code, never from raw request input.
		extract( $vars );

		include $path;
	}
}
