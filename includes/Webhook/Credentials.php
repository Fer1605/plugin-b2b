<?php
/**
 * Storage and pure validation for the webhook's own username/password
 *: replaces the WordPress "B2B Integration" role and Application
 * Passwords used earlier in 1.5.0's own development. A site
 * installs this plugin without needing to create a WordPress user, an
 * email address or a role for it -- the webhook authenticates with one
 * username/password pair, configured here, independent of `WP_User`.
 *
 * Persisted as its own `wp_options` row (`autoload => false`), deliberately
 * separate from `Webhook\Settings`: these are secrets, and must never ride
 * along with a wholesale export/debug dump of the webhook's own
 * configuration the way `Settings::get()` is designed to be read/logged
 * freely elsewhere in this plugin.
 *
 * The password itself is never stored, only its hash (`wp_hash_password()`,
 * the same one-way hashing WordPress uses for a user's own password) --
 * `Admin\IntegrationSettingsController` shows a freshly generated password
 * exactly once, from a short-lived transient it manages itself, never from
 * this class or from the database.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Credentials
 */
class Credentials {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'b2bpl_webhook_credentials';

	/**
	 * Username length bounds.
	 *
	 * @var int
	 */
	const USERNAME_MIN_LENGTH = 3;
	const USERNAME_MAX_LENGTH = 60;

	/**
	 * Length of a generated password (`wp_generate_password( 32, false )`).
	 *
	 * @var int
	 */
	const GENERATED_PASSWORD_LENGTH = 32;

	/**
	 * Minimum length accepted for a manually-chosen password.
	 *
	 * @var int
	 */
	const MANUAL_PASSWORD_MIN_LENGTH = 20;

	/**
	 * Default (empty/unconfigured) credentials.
	 *
	 * @return array{username:string,password_hash:string,configured_at:string}
	 */
	public static function defaults() {
		return array(
			'username'      => '',
			'password_hash' => '',
			'configured_at' => '',
		);
	}

	/**
	 * The currently saved credentials, merged over `defaults()`. Never
	 * includes anything but the hash of the password -- the plaintext is
	 * never persisted.
	 *
	 * @return array{username:string,password_hash:string,configured_at:string}
	 */
	public static function get() {
		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Whether both a username and a password have been configured. Pure
	 * given an already-fetched credentials array; reads `get()` itself when
	 * none is supplied.
	 *
	 * @param array{username?:mixed,password_hash?:mixed}|null $credentials Already-fetched credentials, or `null` to read `get()`.
	 * @return bool
	 */
	public static function is_configured( ?array $credentials = null ) {
		if ( null === $credentials ) {
			$credentials = self::get();
		}

		return self::has_both_fields(
			isset( $credentials['username'] ) ? $credentials['username'] : '',
			isset( $credentials['password_hash'] ) ? $credentials['password_hash'] : ''
		);
	}

	/**
	 * Pure decision behind `is_configured()`: both a non-blank username and
	 * a non-blank password hash are required -- a username alone (or a hash
	 * alone, which should never happen in practice) is not "configured".
	 *
	 * @param mixed $username      Candidate username.
	 * @param mixed $password_hash Candidate password hash.
	 * @return bool
	 */
	public static function has_both_fields( $username, $password_hash ) {
		return '' !== trim( (string) $username ) && '' !== (string) $password_hash;
	}

	/**
	 * Whether a candidate username is a valid webhook username: 3 to 60
	 * characters, letters/digits/dot/underscore/hyphen only. Pure.
	 *
	 * @param mixed $username Candidate value.
	 * @return bool
	 */
	public static function is_valid_username( $username ) {
		if ( ! is_string( $username ) ) {
			return false;
		}

		$length = strlen( $username );

		if ( $length < self::USERNAME_MIN_LENGTH || $length > self::USERNAME_MAX_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9._-]+$/', $username );
	}

	/**
	 * Whether a candidate manually-chosen password meets the minimum length
	 * (the "Set password" field is optional; most sites use "Generate a new
	 * password" instead). Pure.
	 *
	 * @param mixed $password Candidate value.
	 * @return bool
	 */
	public static function is_valid_manual_password( $password ) {
		return is_string( $password ) && strlen( $password ) >= self::MANUAL_PASSWORD_MIN_LENGTH;
	}

	/**
	 * Pure decision behind "the webhook cannot be enabled without
	 * credentials": the final `enabled` value is whatever was requested,
	 * unless no credentials are configured, in which case it is always
	 * `false` regardless of the request.
	 *
	 * @param bool $requested_enabled Value submitted on the settings form.
	 * @param bool $has_credentials   `is_configured()`'s result.
	 * @return bool
	 */
	public static function resolve_enabled_flag( $requested_enabled, $has_credentials ) {
		return (bool) $requested_enabled && (bool) $has_credentials;
	}

	/**
	 * Saves a new username, keeping the existing password hash/timestamp
	 * untouched.
	 *
	 * @param string $username New username (already validated by the caller).
	 * @return array{username:string,password_hash:string,configured_at:string} The credentials actually persisted.
	 */
	public static function save_username( $username ) {
		$credentials             = self::get();
		$credentials['username'] = (string) $username;

		update_option( self::OPTION_NAME, $credentials, false );

		return $credentials;
	}

	/**
	 * Saves a new password hash, stamping `configured_at`. Immediately
	 * invalidates any previous password: the old hash is overwritten, never
	 * kept alongside the new one.
	 *
	 * @param string $hash `wp_hash_password()`'s result.
	 * @return array{username:string,password_hash:string,configured_at:string} The credentials actually persisted.
	 */
	public static function save_password_hash( $hash ) {
		$credentials                  = self::get();
		$credentials['password_hash'] = (string) $hash;
		$credentials['configured_at'] = current_time( 'mysql', true );

		update_option( self::OPTION_NAME, $credentials, false );

		return $credentials;
	}

	/**
	 * Generates a random password (never persisted here -- the caller hashes
	 * it via `hash_password()` and shows the plaintext at most once).
	 *
	 * @return string
	 */
	public static function generate_password() {
		return wp_generate_password( self::GENERATED_PASSWORD_LENGTH, false );
	}

	/**
	 * Hashes a plaintext password the same way WordPress hashes a user's own
	 * password.
	 *
	 * @param string $password Plaintext password.
	 * @return string
	 */
	public static function hash_password( $password ) {
		return wp_hash_password( (string) $password );
	}

	/**
	 * Verifies a plaintext password against a stored hash.
	 *
	 * @param string $password Plaintext candidate.
	 * @param string $hash     Stored hash.
	 * @return bool
	 */
	public static function verify_password( $password, $hash ) {
		if ( '' === (string) $hash ) {
			return false;
		}

		return (bool) wp_check_password( (string) $password, (string) $hash );
	}

	/**
	 * Timing-safe comparison of a candidate username against the configured
	 * one.
	 *
	 * @param mixed  $candidate  Username supplied on the request.
	 * @param string $configured Configured username.
	 * @return bool
	 */
	public static function username_matches( $candidate, $configured ) {
		if ( ! is_string( $candidate ) || '' === (string) $configured ) {
			return false;
		}

		return hash_equals( (string) $configured, $candidate );
	}

	/**
	 * Deletes the credentials entirely. Called unconditionally from
	 * `uninstall.php` ("el hash y el usuario ... se borran
	 * siempre"), independent of the data-purge opt-in that protects this
	 * plugin's business data -- a leftover secret is never something a
	 * conservative uninstall should keep.
	 *
	 * @return void
	 */
	public static function delete() {
		delete_option( self::OPTION_NAME );
	}
}
