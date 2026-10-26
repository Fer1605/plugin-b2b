<?php
/**
 * Cache-busting version string for the plugin's own CSS/JS.
 *
 * Enqueuing an asset with `B2BPL_VERSION` alone looks right and quietly is
 * not: the plugin version only changes on a release, so every stylesheet edit
 * between releases is served under a URL the browser -- and any CDN in front
 * of the site -- already has cached. The fix is not "remember to bump the
 * version": it is to make the version follow the file.
 *
 * The file's modification time is appended, so any deploy that actually
 * changes a file changes its URL, and a deploy that does not leaves the URL
 * (and the cache) alone.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assets
 */
class Assets {

	/**
	 * Version string for one of this plugin's assets.
	 *
	 * @param string $relative_path Path under the plugin root, e.g. `assets/css/admin.css`.
	 * @return string
	 */
	public static function version( $relative_path ) {
		$version = defined( 'B2BPL_VERSION' ) ? B2BPL_VERSION : '0';

		if ( ! defined( 'B2BPL_PATH' ) ) {
			return $version;
		}

		$path = B2BPL_PATH . ltrim( (string) $relative_path, '/' );

		if ( ! is_readable( $path ) ) {
			return $version;
		}

		$modified = filemtime( $path );

		return $modified ? $version . '.' . $modified : $version;
	}
}
