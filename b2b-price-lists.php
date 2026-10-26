<?php
/**
 * Plugin Name:       B2B Price Lists
 * Plugin URI:        https://github.com/Fer1605/plugin-b2b
 * Description:       Manage centralized B2B price lists for WooCommerce and assign them directly to individual users, independent of user roles.
 * Version:           3.7.0
 * Requires at least: 7.0.2
 * Requires PHP:      8.0.30
 * Author:            Fer Santizo
 * Author URI:        https://github.com/Fer1605
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       b2b-price-lists
 * Domain Path:       /languages
 *
 * @package B2BPriceLists
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ---------------------------------------------------------------------
 * Core constants.
 * ---------------------------------------------------------------------
 */

define( 'B2BPL_VERSION', '3.7.0' );
define( 'B2BPL_FILE', __FILE__ );
define( 'B2BPL_PATH', plugin_dir_path( __FILE__ ) );
define( 'B2BPL_URL', plugin_dir_url( __FILE__ ) );
define( 'B2BPL_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Schema version for the plugin's own `b2bpl_*` database tables.
 *
 * Bumped from 0 (no tables, 0.1.0) to 1 in 0.5.0, which introduces the
 * five `b2bpl_*` tables via `Database\Installer`, and to 2 in 1.1.0, which
 * adds the nullable `discount_percent` column to `b2bpl_lists`,
 * `b2bpl_rule_groups` and `b2bpl_price_ranges` (the offer feature), and to 3
 * in 1.2.0, which adds the `b2bpl_activity` table (the activity log), to 4
 * in 1.3.0, which adds the `b2bpl_activity_archives` table (the monthly
 * archives written before the retention window removes old entries), and to
 * 5 in 1.5.0, which adds the `b2bpl_webhook_requests`, `b2bpl_webhook_items`
 * and `b2bpl_webhook_events` tables, the nullable
 * `external_code` column (+ unique key) on `b2bpl_lists`, the nullable
 * `rule_code` column (+ unique key scoped to the list) on
 * `b2bpl_rule_groups`, and the `source`/`batch_id` columns on
 * `b2bpl_activity`.
 * `Installer::maybe_upgrade()` compares this constant against the
 * `b2bpl_db_schema_version` option on every compatible `plugins_loaded`
 * (not only on activation) and re-runs the idempotent `dbDelta()` routine
 * whenever they differ -- adding a nullable column, or a whole new table, to
 * an existing install is exactly what `dbDelta()` handles natively, with no
 * data loss.
 */
define( 'B2BPL_DB_SCHEMA_VERSION', 5 );

/**
 * Version of the set of WordPress role capabilities this plugin grants
 *: `manage_b2b_price_lists` on `administrator`
 * and `shop_manager` (filterable via `b2bpl_default_capability_roles`).
 * Bumped to 3 in 1.5.0: the `b2bpl_integration` role briefly
 * created earlier in the same release's development is removed
 * again -- the webhook now authenticates with its own username/password
 * (`Webhook\Credentials`), never a WordPress role or Application Password.
 * Bumped whenever the set of roles or capabilities this plugin grants by
 * default changes, exactly like `B2BPL_DB_SCHEMA_VERSION` but for roles
 * instead of tables. `Support\RoleInstaller::maybe_upgrade()` compares this
 * constant against the `b2bpl_caps_version` option on activation and on
 * every compatible `plugins_loaded`, and is idempotent: granting an
 * already-granted capability, or removing an already-absent role, is a
 * harmless no-op.
 */
define( 'B2BPL_CAPS_VERSION', 4 );

/**
 * ---------------------------------------------------------------------
 * HPOS (custom order tables) compatibility declaration.
 * ---------------------------------------------------------------------
 *
 * Registered unconditionally and defensively: it does not depend on the
 * Composer autoloader or on the environment check below, and it must
 * never fatal even if WooCommerce is absent or outdated.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			B2BPL_FILE,
			true
		);
	}
);

/**
 * ---------------------------------------------------------------------
 * Bootstrap-critical classes, loaded directly (not via Composer autoload).
 * ---------------------------------------------------------------------
 *
 * `Requirements` and `AdminNotice` have no dependency on any package
 * installed by Composer, so they are required directly here. This lets
 * the plugin report a missing `vendor/autoload.php` through the same
 * `AdminNotice` class used for every other compatibility notice, instead
 * of a bare `wp_die()` or a raw fatal error.
 */
require_once B2BPL_PATH . 'includes/Support/Requirements.php';
require_once B2BPL_PATH . 'includes/Admin/AdminNotice.php';

/**
 * ---------------------------------------------------------------------
 * Composer autoloader.
 * ---------------------------------------------------------------------
 */
if ( ! file_exists( B2BPL_PATH . 'vendor/autoload.php' ) ) {
	\B2BPriceLists\Admin\AdminNotice::missing_autoloader();
	return;
}

require_once B2BPL_PATH . 'vendor/autoload.php';

/**
 * ---------------------------------------------------------------------
 * Environment-gated bootstrap.
 * ---------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function () {
		static $bootstrapped = false;

		// Guards against double initialization if this callback ever
		// runs more than once in the same request.
		if ( $bootstrapped ) {
			return;
		}
		$bootstrapped = true;

		$environment_check = new \B2BPriceLists\Compatibility\EnvironmentCheck();

		if ( ! $environment_check->is_compatible() ) {
			\B2BPriceLists\Admin\AdminNotice::for_environment_failures(
				$environment_check->get_failures(),
				$environment_check
			);
			return;
		}

		\B2BPriceLists\Plugin::instance()->init();
	},
	10
);

/**
 * ---------------------------------------------------------------------
 * Activation: create/upgrade the plugin's own `b2bpl_*` tables.
 * ---------------------------------------------------------------------
 *
 * Reuses the same environment gate as the `plugins_loaded` bootstrap
 * above, so activating this plugin while WooCommerce is absent or
 * incompatible never fatals: it simply skips table creation, which then
 * runs automatically on the first `plugins_loaded` after the environment
 * becomes compatible (see `Plugin::init()`).
 */
register_activation_hook(
	__FILE__,
	static function () {
		$environment_check = new \B2BPriceLists\Compatibility\EnvironmentCheck();

		if ( ! $environment_check->is_compatible() ) {
			return;
		}

		\B2BPriceLists\Database\Installer::maybe_upgrade();
		\B2BPriceLists\Support\RoleInstaller::maybe_upgrade();
	}
);

/**
 * ---------------------------------------------------------------------
 * Deactivation: stop the activity-log retention cron.
 * ---------------------------------------------------------------------
 *
 * The log rows themselves are deliberately left alone -- deactivating a
 * plugin is not a request to destroy its audit trail. Only the recurring
 * event is cleared, so WordPress is not left scheduling work for a plugin
 * that is no longer running.
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		if ( class_exists( '\\B2BPriceLists\\Activity\\ActivityLog' ) ) {
			\B2BPriceLists\Activity\ActivityLog::unschedule();
		}

		// Same rationale as above, for the webhook's own retention purge
		// (W5/T045): the request/item/event rows are left alone, only the
		// recurring cron event is cleared.
		if ( class_exists( '\\B2BPriceLists\\Webhook\\RetentionPurger' ) ) {
			\B2BPriceLists\Webhook\RetentionPurger::unschedule();
		}
	}
);

/**
 * ---------------------------------------------------------------------
 * Translations.
 * ---------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'b2b-price-lists',
			false,
			dirname( B2BPL_BASENAME ) . '/languages'
		);
	},
	10
);
