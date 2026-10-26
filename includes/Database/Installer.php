<?php
/**
 * Creates/upgrades the plugin's own `b2bpl_*` tables via `dbDelta()`, and
 * keeps the stored schema version actively compared against
 * `B2BPL_DB_SCHEMA_VERSION` (unlike the reference plugin, which wrote a
 * schema version option but never read it back).
 *
 * @package B2BPriceLists\Database
 */

namespace B2BPriceLists\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Installer
 */
class Installer {

	/**
	 * Option name storing the currently installed schema version.
	 *
	 * @var string
	 */
	const OPTION_SCHEMA_VERSION = 'b2bpl_db_schema_version';

	/**
	 * Compares the stored schema version against `B2BPL_DB_SCHEMA_VERSION`
	 * and (re)installs the tables when they differ. `dbDelta()` is
	 * idempotent by design: running it again with an unchanged schema
	 * never duplicates tables/columns/indexes and never drops data.
	 *
	 * Safe to call on every `plugins_loaded` (already gated by
	 * `EnvironmentCheck::is_compatible()` upstream) and on plugin
	 * activation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed_version = (int) get_option( self::OPTION_SCHEMA_VERSION, 0 );
		$target_version    = defined( 'B2BPL_DB_SCHEMA_VERSION' ) ? (int) B2BPL_DB_SCHEMA_VERSION : 0;

		if ( $installed_version === $target_version ) {
			return;
		}

		self::install();

		update_option( self::OPTION_SCHEMA_VERSION, $target_version, false );
	}

	/**
	 * Creates or updates every `b2bpl_*` table via `dbDelta()`.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::schema_statements( $charset_collate ) as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * The `CREATE TABLE` statements for every table, formatted the way
	 * `dbDelta()` requires (one column per line, two spaces before
	 * `PRIMARY KEY`, no backticks around index names).
	 *
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string[]
	 */
	private static function schema_statements( $charset_collate ) {
		$activity              = Schema::activity_table();
		$activity_archives     = Schema::activity_archives_table();
		$lists                 = Schema::lists_table();
		$list_users            = Schema::list_users_table();
		$rule_groups           = Schema::rule_groups_table();
		$rule_group_variations = Schema::rule_group_variations_table();
		$price_ranges          = Schema::price_ranges_table();
		$webhook_requests      = Schema::webhook_requests_table();
		$webhook_items         = Schema::webhook_items_table();
		$webhook_events        = Schema::webhook_events_table();
		$idempotency_index     = Schema::WEBHOOK_IDEMPOTENCY_INDEX;

		$statements = array();
		// `external_code` (schema v5): the webhook's own
		// identifier for a list, distinct from the auto-generated `slug`.
		// Nullable so every list created from the panel before 1.5.0 (and
		// any created there afterwards without an external system in mind)
		// is left alone; MySQL's `UNIQUE` allows any number of `NULL`s.
		$statements[] = "CREATE TABLE {$lists} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			description text NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			discount_percent decimal(7,4) NULL,
			external_code varchar(100) NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			UNIQUE KEY external_code (external_code),
			KEY status (status)
		) ENGINE=InnoDB {$charset_collate};";

		$statements[] = "CREATE TABLE {$list_users} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			list_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY list_id (list_id)
		) ENGINE=InnoDB {$charset_collate};";

		// `rule_code` (schema v5): identifies a `variation_group` rule from
		// outside, unique per list only (the same code may be reused across
		// different lists). Nullable for the same reason as `external_code`
		// above -- every rule created from the panel, and `simple`/
		// `all_variations` rules created via the webhook, never set it.
		$statements[] = "CREATE TABLE {$rule_groups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			list_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			scope varchar(20) NOT NULL,
			pricing_mode varchar(20) NOT NULL,
			unit_price decimal(19,4) NULL,
			discount_percent decimal(7,4) NULL,
			minimum_quantity bigint(20) unsigned NOT NULL DEFAULT 1,
			name varchar(191) NULL,
			rule_code varchar(100) NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY list_product (list_id,product_id),
			KEY product_id (product_id),
			UNIQUE KEY list_rule_code (list_id,rule_code)
		) ENGINE=InnoDB {$charset_collate};";

		$statements[] = "CREATE TABLE {$rule_group_variations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_group_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rule_group_variation (rule_group_id,variation_id),
			KEY variation_id (variation_id)
		) ENGINE=InnoDB {$charset_collate};";

		$statements[] = "CREATE TABLE {$price_ranges} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_group_id bigint(20) unsigned NOT NULL,
			min_qty bigint(20) unsigned NOT NULL,
			max_qty bigint(20) unsigned NULL,
			unit_price decimal(19,4) NOT NULL,
			discount_percent decimal(7,4) NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY rule_group_id (rule_group_id)
		) ENGINE=InnoDB {$charset_collate};";

		// The activity log (who did what, and when). Deliberately append-only
		// and fully denormalized -- `user_name`, `list_name` and the labels
		// inside `changes` are snapshots taken at the moment of the action.
		// An audit trail whose rows stop making sense once the list or the
		// user they refer to is deleted is not an audit trail, so nothing
		// here is a foreign key: rows survive their subjects on purpose, and
		// are removed only by the retention window.
		// `source`/`batch_id` (schema v5): who triggered
		// the action -- the admin panel (default, unchanged for every entry
		// ever written before 1.5.0) or the webhook, and, for the latter,
		// which lot. Never a foreign key, same rationale as every other
		// column on this deliberately denormalized, append-only table.
		$statements[] = "CREATE TABLE {$activity} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_name varchar(191) NOT NULL DEFAULT '',
			action varchar(50) NOT NULL,
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_label varchar(191) NOT NULL DEFAULT '',
			list_id bigint(20) unsigned NOT NULL DEFAULT 0,
			list_name varchar(191) NOT NULL DEFAULT '',
			changes longtext NULL,
			source varchar(20) NOT NULL DEFAULT 'admin',
			batch_id char(36) NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY list_id (list_id),
			KEY action (action),
			KEY source (source)
		) ENGINE=InnoDB {$charset_collate};";

		// Monthly archives of the activity log (schema 4). Before the
		// retention window removes an entry, it is written here, already
		// formatted as CSV text, into the row of its calendar month. Stored in
		// the database rather than as files under `uploads/`: many hosts run
		// nginx, which ignores `.htaccess`, so a file there would be public to
		// anybody who guessed its URL. From here it can only leave through the
		// capability- and nonce-checked download in `Admin\ActivityController`.
		// Never touched by the retention window itself.
		$statements[] = "CREATE TABLE {$activity_archives} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			period char(7) NOT NULL,
			entries int(10) unsigned NOT NULL DEFAULT 0,
			first_at datetime NOT NULL,
			last_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			content longtext NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY period (period)
		) ENGINE=InnoDB {$charset_collate};";

		// Webhook request log (schema v5): one row per
		// received request, written *before* validation or processing
		// (constitution principle IV). `idempotency_key` is unique per
		// integration user, not globally, because two different integration
		// users legitimately choosing the same key are not a collision.
		// `auth_user_id` defaults to 0 for a request that never
		// authenticated at all (still logged, per), which is why the
		// unique pair tolerates repeated `(0, NULL)`/`(0, <key>)` rows the
		// same way MySQL tolerates repeated `NULL`s in any other `UNIQUE`.
		$statements[] = "CREATE TABLE {$webhook_requests} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id char(36) NOT NULL,
			endpoint varchar(20) NOT NULL DEFAULT '',
			method varchar(10) NOT NULL DEFAULT '',
			received_at datetime(3) NOT NULL,
			started_at datetime(3) NULL,
			finished_at datetime(3) NULL,
			auth_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			auth_user_login varchar(60) NOT NULL DEFAULT '',
			app_password_uuid char(36) NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			idempotency_key varchar(100) NULL,
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(25) NOT NULL DEFAULT '',
			rejection_code varchar(50) NULL,
			body_bytes int(10) unsigned NOT NULL DEFAULT 0,
			body_sha256 char(64) NOT NULL DEFAULT '',
			body_truncated tinyint(1) NOT NULL DEFAULT 0,
			headers text NULL,
			raw_body longtext NULL,
			payload longtext NULL,
			response_body longtext NULL,
			items_total int(10) unsigned NOT NULL DEFAULT 0,
			items_success int(10) unsigned NOT NULL DEFAULT 0,
			items_partial int(10) unsigned NOT NULL DEFAULT 0,
			items_error int(10) unsigned NOT NULL DEFAULT 0,
			items_skipped int(10) unsigned NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			summary longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_id (batch_id),
			UNIQUE KEY {$idempotency_index} (auth_user_id,idempotency_key),
			KEY received_at (received_at),
			KEY status (status),
			KEY endpoint (endpoint),
			KEY auth_user_id (auth_user_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Webhook items (schema v5): one row per operation inside a request
		// (`operations[]`), keyed by its position so a duplicate submission
		// of the exact same request never creates duplicate item rows on
		// reprocessing.
		$statements[] = "CREATE TABLE {$webhook_items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			item_index int(10) unsigned NOT NULL DEFAULT 0,
			ref varchar(100) NULL,
			entity varchar(20) NOT NULL DEFAULT '',
			action varchar(40) NOT NULL DEFAULT '',
			external_ref varchar(191) NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			input longtext NULL,
			result longtext NULL,
			errors longtext NULL,
			created_at datetime(3) NOT NULL,
			updated_at datetime(3) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_item (request_id,item_index),
			KEY status (status),
			KEY external_ref (external_ref)
		) ENGINE=InnoDB {$charset_collate};";

		// Webhook events (schema v5): granular timeline, optionally scoped
		// to a single item (`item_id NULL` = request-level event).
		$statements[] = "CREATE TABLE {$webhook_events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned NULL,
			created_at datetime(3) NOT NULL,
			level varchar(10) NOT NULL DEFAULT 'info',
			code varchar(50) NOT NULL DEFAULT '',
			message varchar(500) NOT NULL DEFAULT '',
			context longtext NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY request_created (request_id,created_at),
			KEY level (level)
		) ENGINE=InnoDB {$charset_collate};";

		return $statements;
	}
}
