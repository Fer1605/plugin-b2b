<?php
/**
 * Pure slug generation with safe disambiguation.
 *
 * Deliberately free of any WordPress database access so it can be covered
 * by fast unit tests: callers inject an "exists" checker instead.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlugGenerator
 */
class SlugGenerator {

	/**
	 * Builds a unique slug from a human-readable name.
	 *
	 * If the base slug (or an explicitly provided candidate) already exists
	 * according to `$exists_checker`, numeric suffixes are appended
	 * (`-2`, `-3`, ...) until a free slug is found. The check always
	 * excludes the id being edited (if any), via `$exists_checker` itself.
	 *
	 * @param string   $name           Human-readable name to slugify.
	 * @param callable $exists_checker function(string $candidate): bool.
	 * @param string   $preferred      Optional explicit slug candidate
	 *                                 (e.g. one the admin typed manually).
	 * @return string Never empty; falls back to "lista" as a base when the
	 *                sanitized name/slug is empty.
	 */
	public static function generate( $name, callable $exists_checker, $preferred = '' ) {
		$base = '' !== trim( (string) $preferred ) ? $preferred : $name;
		$base = sanitize_title( (string) $base );

		if ( '' === $base ) {
			$base = 'lista';
		}

		$candidate = $base;
		$suffix    = 2;

		while ( $exists_checker( $candidate ) ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;

			// Defensive circuit breaker: never loop forever on a checker
			// that always returns true (e.g. a bug in the caller).
			if ( $suffix > 1000 ) {
				$candidate = $base . '-' . uniqid();
				break;
			}
		}

		return $candidate;
	}
}
