<?php
/**
 * Pretty-printing and syntax highlighting for everything the webhook log
 * viewer shows an administrator (T044): the JSON received,
 * the response sent, per-item results/errors, and event context. All of
 * those are stored as plain JSON text (already secret-masked by
 * `Support\Masker` before they were ever written to the database), so this
 * class only has to turn already-decoded PHP values -- or the stored JSON
 * text itself -- into something a non-expert can actually read: 2-space
 * indentation and one `<span class="b2bpl-json-{type}">` per token
 * (`key`/`string`/`number`/`bool`/`null`), never per punctuation character.
 *
 * Deliberately free of every WordPress function (no `esc_html()`,
 * `wp_json_encode()`, `__()`): `render()` is the single layout algorithm
 * shared by the plain-text pretty-print (used for the `.json` "formatted"
 * download) and the HTML-highlighted viewer, so the two can never disagree
 * about indentation or line breaks, and both halves stay unit-testable
 * without WordPress loaded -- exactly the same reasoning already documented
 * on `Support\Masker`. HTML escaping uses `htmlspecialchars()` directly for
 * the same reason; every caller only ever drops this class's output inside
 * an already-escaped admin template (a `<pre>`/`<code>` block), never
 * concatenates it with unescaped user input.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JsonFormatter
 */
class JsonFormatter {

	/**
	 * One indentation level ("sangría de 2 espacios").
	 *
	 * @var string
	 */
	const INDENT_UNIT = '  ';

	/**
	 * Pretty-prints an already-decoded value as plain JSON text, 2-space
	 * indented, no HTML.
	 *
	 * @param mixed $value Decoded JSON (arrays/scalars/`null`).
	 * @return string
	 */
	public static function pretty_print( $value ) {
		return self::render( $value, 0, false );
	}

	/**
	 * Renders an already-decoded value as syntax-highlighted HTML, 2-space
	 * indented: one `<span class="b2bpl-json-{key|string|number|bool|null}">`
	 * per token. The caller wraps the result in its own container (a
	 * `<pre>`/`<code>` element); this method never adds one itself.
	 *
	 * @param mixed $value Decoded JSON (arrays/scalars/`null`).
	 * @return string HTML.
	 */
	public static function highlight( $value ) {
		return self::render( $value, 0, true );
	}

	/**
	 * Decodes a stored JSON string and pretty-prints it as plain text. A
	 * string that is not valid JSON (an empty value, or a raw body that
	 * failed to decode -- "guarda el cuerpo tal cual llegó")
	 * is returned unchanged: there is nothing to re-indent.
	 *
	 * @param string $json_string Stored JSON text.
	 * @return string
	 */
	public static function pretty_print_json_string( $json_string ) {
		$json_string = (string) $json_string;

		if ( '' === trim( $json_string ) ) {
			return $json_string;
		}

		$decoded = json_decode( $json_string, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return $json_string;
		}

		return self::pretty_print( $decoded );
	}

	/**
	 * Decodes a stored JSON string and renders it as syntax-highlighted
	 * HTML wrapped in `<pre class="b2bpl-json-viewer">`. A string that is
	 * not valid JSON falls back to `<pre class="b2bpl-json-raw">` with the
	 * text escaped, but never highlighted or reformatted -- reformatting
	 * text a caller sent malformed would make it harder, not easier, to see
	 * exactly what was wrong with it.
	 *
	 * @param string $json_string Stored JSON text.
	 * @return string HTML.
	 */
	public static function highlight_json_string( $json_string ) {
		$json_string = (string) $json_string;

		if ( '' === trim( $json_string ) ) {
			return '';
		}

		$decoded = json_decode( $json_string, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return '<pre class="b2bpl-json-raw">' . self::escape( $json_string ) . '</pre>';
		}

		return '<pre class="b2bpl-json-viewer">' . self::highlight( $decoded ) . '</pre>';
	}

	/**
	 * The single layout algorithm shared by `pretty_print()`/`highlight()`.
	 * Pure and recursive: every array is classified as a JSON "list" (a
	 * numeric, contiguous, zero-based sequence, `is_list()`) or "object"
	 * (everything else, including an empty array -- which PHP cannot tell
	 * apart from an empty list after a `json_decode( ..., true )` round
	 * trip; documented, not solved, since nothing this class receives can
	 * disambiguate the two).
	 *
	 * @param mixed $value Decoded JSON.
	 * @param int   $depth Current indentation depth.
	 * @param bool  $html  Whether to wrap tokens in `<span>`s (and escape them).
	 * @return string
	 */
	private static function render( $value, $depth, $html ) {
		if ( is_array( $value ) ) {
			return self::render_array( $value, $depth, $html );
		}

		return self::render_scalar( $value, $html );
	}

	/**
	 * Renders a PHP array as a JSON array or object, depending on
	 * `is_list()`.
	 *
	 * @param array<int|string,mixed> $value Decoded JSON array.
	 * @param int                     $depth Current indentation depth.
	 * @param bool                    $html  See `render()`.
	 * @return string
	 */
	private static function render_array( array $value, $depth, $html ) {
		$is_list = self::is_list( $value );

		if ( empty( $value ) ) {
			return $is_list ? '[]' : '{}';
		}

		$inner_indent = str_repeat( self::INDENT_UNIT, $depth + 1 );
		$outer_indent = str_repeat( self::INDENT_UNIT, $depth );
		$lines        = array();

		foreach ( $value as $key => $item ) {
			$line = $inner_indent;

			if ( ! $is_list ) {
				$line .= self::render_key( (string) $key, $html ) . ': ';
			}

			$line   .= self::render( $item, $depth + 1, $html );
			$lines[] = $line;
		}

		$open  = $is_list ? '[' : '{';
		$close = $is_list ? ']' : '}';

		return $open . "\n" . implode( ",\n", $lines ) . "\n" . $outer_indent . $close;
	}

	/**
	 * Renders an object key: the quoted string, wrapped as a whole
	 * (quotes included) in `b2bpl-json-key` -- matching the contract's
	 * `<span class="b2bpl-json-key">` shape, distinct from an ordinary
	 * string value's `b2bpl-json-string`.
	 *
	 * @param string $key  Raw key name.
	 * @param bool   $html See `render()`.
	 * @return string
	 */
	private static function render_key( $key, $html ) {
		$quoted = '"' . ( $html ? self::escape( $key ) : self::escape_for_text( $key ) ) . '"';

		return $html ? self::wrap( 'key', $quoted ) : $quoted;
	}

	/**
	 * Renders a scalar value (string/int/float/bool/`null`).
	 *
	 * @param mixed $value Decoded JSON scalar.
	 * @param bool  $html  See `render()`.
	 * @return string
	 */
	private static function render_scalar( $value, $html ) {
		if ( is_string( $value ) ) {
			$quoted = '"' . ( $html ? self::escape( $value ) : self::escape_for_text( $value ) ) . '"';

			return $html ? self::wrap( 'string', $quoted ) : $quoted;
		}

		if ( is_bool( $value ) ) {
			$text = $value ? 'true' : 'false';

			return $html ? self::wrap( 'bool', $text ) : $text;
		}

		if ( null === $value ) {
			return $html ? self::wrap( 'null', 'null' ) : 'null';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			// `json_encode()` on a single scalar never fails for a finite
			// number, and always produces the same numeric text a decoder
			// would have read back from the original JSON.
			$text = (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure class, deliberately independent of wp_json_encode()/WordPress (see class docblock).

			return $html ? self::wrap( 'number', $text ) : $text;
		}

		// Defensive: `json_decode( ..., true )` never produces anything
		// else, but a caller could still hand this class an arbitrary PHP
		// value directly. Treated as a string, quotes included either way.
		$value = (string) $value;

		return $html
			? self::wrap( 'string', '"' . self::escape( $value ) . '"' )
			: '"' . self::escape_for_text( $value ) . '"';
	}

	/**
	 * Wraps already-escaped HTML in one token span.
	 *
	 * @param string $type          `key`|`string`|`number`|`bool`|`null`.
	 * @param string $escaped_inner Already-escaped inner HTML.
	 * @return string
	 */
	private static function wrap( $type, $escaped_inner ) {
		return '<span class="b2bpl-json-' . $type . '">' . $escaped_inner . '</span>';
	}

	/**
	 * HTML-escapes a string for inclusion inside the highlighted viewer.
	 * See the class docblock for why this is `htmlspecialchars()` and not
	 * `esc_html()`.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function escape( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * "Escapes" a string for the plain-text pretty-print output: JSON
	 * string content only ever needs its own quotes/backslashes escaped
	 * the way `json_encode()` would, never HTML entities.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function escape_for_text( $text ) {
		$encoded = json_encode( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure class, deliberately independent of wp_json_encode()/WordPress (see class docblock).

		if ( ! is_string( $encoded ) || strlen( $encoded ) < 2 ) {
			return $text;
		}

		// `json_encode()` of a string always returns a quoted JSON string;
		// strip the surrounding quotes this method's own callers already add.
		return substr( $encoded, 1, -1 );
	}

	/**
	 * Whether a PHP array is a JSON "list": numeric keys, contiguous,
	 * starting at zero. A manual equivalent of PHP 8.1's `array_is_list()`,
	 * since this plugin's declared minimum is PHP 8.0.30.
	 *
	 * @param array<int|string,mixed> $value Candidate array.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		$expected = 0;

		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}

			++$expected;
		}

		return true;
	}
}
