<?php
/**
 * Recursive secret masking for anything the webhook persists (
 *): the raw body, the decoded payload, event context and
 * anything else that might carry a value a site would not want sitting in
 * plain text in the database or the log viewer.
 *
 * Deliberately free of any WordPress function call in its core logic (only
 * `masked_key_fragments()` touches `apply_filters()`), so every masking
 * decision can be unit-tested directly, the same way `Support\RoleInstaller`
 * separates its pure `sanitize_roles()` from the WordPress-touching
 * `install()`.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Masker
 */
class Masker {

	/**
	 * The value written in place of a masked secret.
	 *
	 * @var string
	 */
	const MASK = '***';

	/**
	 * Default key fragments that mark a value as a secret: matched
	 * case-insensitively as a substring of the key name, so `app_password`,
	 * `Password`, `x-api-key` and `apiKey` are all caught by a short list.
	 *
	 * @return string[]
	 */
	public static function default_masked_key_fragments() {
		return array(
			'password',
			'pass',
			'secret',
			'token',
			'authorization',
			'api_key',
			'api-key',
			'apikey',
		);
	}

	/**
	 * Pure sanitization of a candidate fragment list coming from the
	 * `b2bpl_webhook_masked_keys` filter: keeps only non-empty strings,
	 * lower-cases and trims them, drops duplicates, and falls back to
	 * `$fallback` whenever the input is not a usable array. Unit-testable
	 * independent of WordPress, exactly like
	 * `RoleInstaller::sanitize_roles()`.
	 *
	 * @param mixed    $fragments Candidate fragments, typically a filtered value.
	 * @param string[] $fallback  Fallback list, already assumed valid.
	 * @return string[]
	 */
	public static function sanitize_fragments( $fragments, array $fallback ) {
		if ( ! is_array( $fragments ) ) {
			return $fallback;
		}

		$clean = array();

		foreach ( $fragments as $fragment ) {
			if ( ! is_string( $fragment ) ) {
				continue;
			}

			$fragment = strtolower( trim( $fragment ) );

			if ( '' === $fragment ) {
				continue;
			}

			$clean[] = $fragment;
		}

		$clean = array_values( array_unique( $clean ) );

		return empty( $clean ) ? $fallback : $clean;
	}

	/**
	 * The key fragments actually used to decide what gets masked, after
	 * applying the `b2bpl_webhook_masked_keys` filter and sanitizing
	 * whatever it returns.
	 *
	 * @return string[]
	 */
	public static function masked_key_fragments() {
		$default   = self::default_masked_key_fragments();
		$fragments = $default;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the key-name fragments that mark a value as a secret
			 * to be masked before it is persisted.
			 *
			 * @param string[] $fragments Lower-cased key fragments.
			 */
			$fragments = apply_filters( 'b2bpl_webhook_masked_keys', $default );
		}

		return self::sanitize_fragments( $fragments, $default );
	}

	/**
	 * Whether a key name should be treated as a secret, given a set of
	 * lower-cased fragments. Pure and unit-testable.
	 *
	 * @param string   $key       Key name, as it appeared in the payload/headers.
	 * @param string[] $fragments Lower-cased fragments (see `masked_key_fragments()`).
	 * @return bool
	 */
	public static function is_masked_key( $key, array $fragments ) {
		$key = strtolower( (string) $key );

		if ( '' === $key ) {
			return false;
		}

		foreach ( $fragments as $fragment ) {
			if ( '' !== $fragment && false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively masks every array value whose key matches
	 * `$fragments` (defaulting to `masked_key_fragments()`), replacing it
	 * with `MASK`. Scalars, `null`, and non-array/non-string values are
	 * left as-is; nested arrays (objects decoded with `json_decode(...,
	 * true)`) are walked all the way down, which is what makes this safe
	 * against a secret nested anywhere inside `operations[].data`.
	 *
	 * A masked value is never partially masked (e.g. keeping the first
	 * characters): requires the value to read as `***`, full stop.
	 *
	 * @param mixed         $data      Decoded JSON (arrays/scalars), or any
	 *                                 other array structure to mask.
	 * @param string[]|null $fragments Key fragments; `null` uses
	 *                                 `masked_key_fragments()`.
	 * @return mixed Same shape as `$data`, with secrets replaced.
	 */
	public static function mask( $data, ?array $fragments = null ) {
		if ( null === $fragments ) {
			$fragments = self::masked_key_fragments();
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$masked = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_masked_key( $key, $fragments ) ) {
				$masked[ $key ] = self::MASK;
				continue;
			}

			$masked[ $key ] = is_array( $value ) ? self::mask( $value, $fragments ) : $value;
		}

		return $masked;
	}

	/**
	 * Best-effort masking of a body that failed to decode as JSON
	 *: a malformed or non-JSON payload is still stored
	 * verbatim ( "guarda el cuerpo tal cual llegó"), but a key that
	 * looks like a secret must never survive in it unmasked just because the
	 * body could not be parsed. Recognizes two shapes, both matched
	 * case-insensitively against `$fragments`:
	 *
	 * - `"key": "value"` / `"key":value` -- a JSON-*like* body broken
	 *   elsewhere (a trailing comma, an unescaped quote further down) still
	 *   has individually recognizable key/value pairs.
	 * - `key=value` -- form-encoded or query-string-style bodies.
	 *
	 * A regex pass over unstructured text is inherently heuristic (unlike
	 * `mask()`, which walks an already-decoded, unambiguous array): it can
	 * miss an unusual shape, but it never has to guess at parsing the rest
	 * of a broken body to find what it does recognize, so it never makes an
	 * already-invalid body harder to diagnose.
	 *
	 * @param mixed         $text      Raw text to mask; anything other than a
	 *                                 non-empty string is returned unchanged.
	 * @param string[]|null $fragments Key fragments; `null` uses
	 *                                 `masked_key_fragments()`.
	 * @return mixed Same value, secrets replaced when `$text` was a string.
	 */
	public static function mask_raw_text( $text, ?array $fragments = null ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}

		if ( null === $fragments ) {
			$fragments = self::masked_key_fragments();
		}

		$fragments = array_values(
			array_filter(
				$fragments,
				static function ( $fragment ) {
					return is_string( $fragment ) && '' !== $fragment;
				}
			)
		);

		if ( empty( $fragments ) ) {
			return $text;
		}

		$key_pattern = implode(
			'|',
			array_map(
				static function ( $fragment ) {
					return preg_quote( $fragment, '/' );
				},
				$fragments
			)
		);

		// `"key": "value"` (including escaped characters inside the value).
		$text = (string) preg_replace_callback(
			'/"([^"\\\\]*(?:' . $key_pattern . ')[^"\\\\]*)"(\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
			static function ( $matches ) {
				return '"' . $matches[1] . '"' . $matches[2] . '"' . self::MASK . '"';
			},
			$text
		);

		// `"key": 123` / `true` / `false` / `null` (unquoted JSON scalar).
		$text = (string) preg_replace_callback(
			'/"([^"\\\\]*(?:' . $key_pattern . ')[^"\\\\]*)"(\s*:\s*)(-?\d+(?:\.\d+)?|true|false|null)\b/i',
			static function ( $matches ) {
				return '"' . $matches[1] . '"' . $matches[2] . '"' . self::MASK . '"';
			},
			$text
		);

		// `key=value` (form-encoded / query-string style).
		$text = (string) preg_replace_callback(
			'/(^|[?&;\s])([^&;=\s"]*(?:' . $key_pattern . ')[^&;=\s"]*)=([^&;\s]*)/i',
			static function ( $matches ) {
				return $matches[1] . $matches[2] . '=' . self::MASK;
			},
			$text
		);

		return $text;
	}

	/**
	 * The only HTTP request headers ever persisted (R8): every other header,
	 * `Authorization` foremost, is dropped entirely rather than masked, so
	 * there is no representation of it anywhere to leak, not even `***`.
	 *
	 * @return string[] Lower-case header names.
	 */
	public static function allowed_header_names() {
		return array(
			'content-type',
			'content-length',
			'user-agent',
			'idempotency-key',
			'x-request-id',
		);
	}

	/**
	 * Filters a raw header map (`name => value`) down to
	 * `allowed_header_names()` only, additionally masking any allowed
	 * header whose value still happens to look like a secret (defensive:
	 * none of the allowed names should ever carry one, but a value is
	 * masked instead of trusted blindly).
	 *
	 * @param array<string,string> $headers   Raw headers, any case.
	 * @param string[]|null        $fragments Key fragments; `null` uses
	 *                                         `masked_key_fragments()`.
	 * @return array<string,string> Lower-cased header names.
	 */
	public static function filter_headers( array $headers, ?array $fragments = null ) {
		if ( null === $fragments ) {
			$fragments = self::masked_key_fragments();
		}

		$allowed  = self::allowed_header_names();
		$filtered = array();

		foreach ( $headers as $name => $value ) {
			$lower_name = strtolower( (string) $name );

			if ( ! in_array( $lower_name, $allowed, true ) ) {
				continue;
			}

			$filtered[ $lower_name ] = self::is_masked_key( $lower_name, $fragments )
				? self::MASK
				: $value;
		}

		return $filtered;
	}
}
