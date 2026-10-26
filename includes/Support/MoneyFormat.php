<?php
/**
 * Pure, WordPress-independent parsing/formatting of decimal money amounts
 * for admin price inputs (`unit_price`, `range_price`). Deliberately free
 * of any WordPress/WooCommerce call so it can be covered by fast unit
 * tests -- the caller is responsible for supplying WooCommerce's own
 * configured decimals/decimal separator/thousand separator (never a
 * convention invented by this plugin).
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MoneyFormat
 */
class MoneyFormat {

	/**
	 * Formats a stored decimal value into a human-friendly string for an
	 * editable input: no thousand separators, decimals trimmed away entirely
	 * when the value is a whole number, otherwise fixed at `$decimals` using
	 * the given decimal separator (never more, never fewer).
	 *
	 * @param float|int|string|null $value             Stored value (e.g. from a DECIMAL column).
	 * @param int                   $decimals          Number of decimals to show for a non-whole value.
	 * @param string                $decimal_separator Decimal separator to use in the output.
	 * @return string Empty string for null/non-numeric input.
	 */
	public static function format_for_input( $value, $decimals, $decimal_separator ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		$decimals = max( 0, (int) $decimals );
		$rounded  = round( (float) $value, $decimals );
		$is_whole = abs( $rounded - round( $rounded ) ) < 0.0000001;

		$formatted = $is_whole
			? (string) (int) round( $rounded )
			: number_format( $rounded, $decimals, '.', '' );

		return '.' === $decimal_separator ? $formatted : str_replace( '.', $decimal_separator, $formatted );
	}

	/**
	 * Parses a raw admin-submitted price string into a float, using
	 * WooCommerce's own configured decimal/thousand separators as the only
	 * source of truth -- never assumes "." is the decimal separator, never
	 * confuses "1,000" with "1.000" without considering that configuration.
	 *
	 * @param string $raw                Raw submitted value.
	 * @param string $decimal_separator  WooCommerce's configured decimal separator.
	 * @param string $thousand_separator WooCommerce's configured thousand separator.
	 * @return float|null Null when the input has no parseable numeric content.
	 */
	public static function parse( $raw, $decimal_separator, $thousand_separator ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return null;
		}

		if ( '' !== $thousand_separator && $thousand_separator !== $decimal_separator ) {
			$raw = str_replace( $thousand_separator, '', $raw );
		}

		if ( '.' !== $decimal_separator ) {
			$raw = str_replace( $decimal_separator, '.', $raw );
		}

		// Defensive cleanup: drop anything that still isn't part of a plain
		// float literal (e.g. a currency symbol, or the "wrong" grouping
		// character pasted by the admin) once the configured separators have
		// already been normalized above.
		$raw = preg_replace( '/[^0-9.\-]/', '', (string) $raw );

		// Strict format check -- reject anything that isn't a single,
		// optionally-negative float literal (at most one "." and one
		// leading "-") instead of letting PHP's (float) cast silently
		// truncate a malformed value (e.g. "12-34" or "1.2.3", including
		// the case where the configured decimal and thousand separators
		// happen to be the same character) at its first invalid character.
		if ( ! preg_match( '/^-?\d*\.?\d*$/', $raw ) || ! preg_match( '/\d/', $raw ) ) {
			return null;
		}

		return (float) $raw;
	}
}
