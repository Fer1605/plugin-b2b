<?php
/**
 * Pure detection of whether the current HTTP request targets this plugin's
 * REST namespace (`b2bpl/v1`), independent of whether pretty permalinks are
 * enabled and of a site's custom REST URL prefix.
 *
 * `Webhook\RequestGuard` uses this to decide whether its
 * `rest_authentication_errors`/`rest_post_dispatch` callbacks should look at
 * a request at all -- every other REST request (WordPress core, WooCommerce,
 * the Novamira integration, anything else) must see this plugin do nothing.
 * Kept free of any WordPress function so both request shapes below can be
 * unit-tested exhaustively with plain strings, matching the pattern already
 * used by `Support\RoleInstaller::sanitize_roles()`/`Support\Masker`.
 *
 * WordPress exposes a REST request two different ways, and either can be in
 * play on any given request:
 *
 * 1. Pretty permalinks: `REQUEST_URI` contains `/{prefix}/b2bpl/v1/...`,
 *    where `{prefix}` defaults to `wp-json` (`rest_get_url_prefix()`) but a
 *    site can filter it to something else entirely.
 * 2. Plain permalinks (or any request using the fallback form): a
 *    `rest_route` query parameter carries the route directly, e.g.
 *    `?rest_route=/b2bpl/v1/webhook`, with or without a leading slash.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RouteMatcher
 */
class RouteMatcher {

	/**
	 * This plugin's REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_SLUG = 'b2bpl/v1';

	/**
	 * The REST URL prefix used when none is given (WordPress core's own
	 * default, before any `rest_url_prefix` filter).
	 *
	 * @var string
	 */
	const DEFAULT_REST_PREFIX = 'wp-json';

	/**
	 * Whether a request targets `b2bpl/v1`, given the raw values a caller
	 * would read from `$_SERVER['REQUEST_URI']`, `$_GET['rest_route']` and
	 * `rest_get_url_prefix()`.
	 *
	 * @param string|null $request_uri      Typically `$_SERVER['REQUEST_URI']`.
	 * @param string|null $rest_route_param Typically `$_GET['rest_route']`
	 *                                      (only set on the query-param
	 *                                      fallback form), or `null`/`''`
	 *                                      when absent.
	 * @param string|null $rest_prefix      Typically `rest_get_url_prefix()`;
	 *                                      defaults to `wp-json` when not
	 *                                      given or empty, exactly like
	 *                                      WordPress core itself.
	 * @return bool
	 */
	public static function matches( $request_uri, $rest_route_param = null, $rest_prefix = null ) {
		if ( is_string( $rest_route_param ) && '' !== trim( $rest_route_param, " \t\n\r\0\x0B/" ) ) {
			return self::path_targets_namespace( $rest_route_param );
		}

		$path = self::extract_path( $request_uri );

		if ( '' === $path ) {
			return false;
		}

		$prefix = self::normalize_prefix( $rest_prefix );
		$marker = '/' . $prefix . '/';
		$offset = strpos( $path, $marker );

		if ( false === $offset ) {
			return false;
		}

		$remainder = substr( $path, $offset + strlen( $marker ) );

		return self::path_targets_namespace( $remainder );
	}

	/**
	 * Whether a route/path (with or without leading/trailing slashes)
	 * either is exactly `b2bpl/v1`, or starts with `b2bpl/v1/`.
	 *
	 * @param string $path Route or path segment, already stripped of any
	 *                      REST prefix and query string.
	 * @return bool
	 */
	private static function path_targets_namespace( $path ) {
		$path = trim( (string) $path, '/' );

		return self::NAMESPACE_SLUG === $path || 0 === strpos( $path, self::NAMESPACE_SLUG . '/' );
	}

	/**
	 * Extracts the path component from a request URI, dropping any query
	 * string, using plain PHP (`parse_url()`) rather than a WordPress
	 * function, so this stays testable in the pure unit bootstrap.
	 *
	 * @param mixed $request_uri Raw request URI (may lack a scheme/host,
	 *                            which `parse_url()` handles for a
	 *                            path-only string like `REQUEST_URI`).
	 * @return string
	 */
	private static function extract_path( $request_uri ) {
		if ( ! is_string( $request_uri ) || '' === $request_uri ) {
			return '';
		}

		// Plain `parse_url()`, not `wp_parse_url()`: this class is
		// deliberately WordPress-independent so it can be unit-tested in
		// the pure bootstrap (see the class docblock).
		$path = parse_url( $request_uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		return is_string( $path ) ? $path : '';
	}

	/**
	 * Normalizes a candidate REST prefix: trims slashes, falls back to
	 * `wp-json` when empty/invalid.
	 *
	 * @param mixed $prefix Candidate prefix.
	 * @return string
	 */
	private static function normalize_prefix( $prefix ) {
		if ( ! is_string( $prefix ) ) {
			return self::DEFAULT_REST_PREFIX;
		}

		$prefix = trim( $prefix, '/' );

		return '' === $prefix ? self::DEFAULT_REST_PREFIX : $prefix;
	}
}
