<?php
/**
 * Table name registry for the plugin's own `b2bpl_*` tables.
 *
 * The single source of truth for table names, so repositories never
 * hard-code `$wpdb->prefix . 'b2bpl_...'` themselves.
 *
 * @package B2BPriceLists\Database
 */

namespace B2BPriceLists\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema
 */
class Schema {

	/**
	 * Name of `b2bpl_webhook_requests`' `UNIQUE KEY (auth_user_id,
	 * idempotency_key)` index: shared between `Database\Installer`
	 * (which defines it) and `Webhook\IngestLogger` (which recognizes a
	 * duplicate-key `INSERT` failure by it, as a fallback to MySQL's own
	 * error code 1062), so the two can never drift apart.
	 *
	 * @var string
	 */
	const WEBHOOK_IDEMPOTENCY_INDEX = 'auth_user_idempotency';

	/**
	 * Returns the fully prefixed name of the lists table.
	 *
	 * @return string
	 */
	public static function lists_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_lists';
	}

	/**
	 * Returns the fully prefixed name of the list/user assignment table.
	 *
	 * @return string
	 */
	public static function list_users_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_list_users';
	}

	/**
	 * Returns the fully prefixed name of the rule groups table.
	 *
	 * @return string
	 */
	public static function rule_groups_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_rule_groups';
	}

	/**
	 * Returns the fully prefixed name of the rule group/variation
	 * relationship table.
	 *
	 * @return string
	 */
	public static function rule_group_variations_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_rule_group_variations';
	}

	/**
	 * Returns the fully prefixed name of the price ranges table.
	 *
	 * @return string
	 */
	public static function price_ranges_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_price_ranges';
	}

	/**
	 * Returns the fully prefixed name of the activity log table.
	 *
	 * @return string
	 */
	public static function activity_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_activity';
	}

	/**
	 * Returns the fully prefixed name of the activity archives table: one
	 * row per calendar month, holding the entries the retention window
	 * removed from the live log, already formatted as CSV.
	 *
	 * @return string
	 */
	public static function activity_archives_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_activity_archives';
	}

	/**
	 * Returns the fully prefixed name of the webhook requests table (one row
	 * per received request, schema v5).
	 *
	 * @return string
	 */
	public static function webhook_requests_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_webhook_requests';
	}

	/**
	 * Returns the fully prefixed name of the webhook items table (one row
	 * per operation inside a request, schema v5).
	 *
	 * @return string
	 */
	public static function webhook_items_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_webhook_items';
	}

	/**
	 * Returns the fully prefixed name of the webhook events table (granular
	 * timeline entries per request/item, schema v5).
	 *
	 * @return string
	 */
	public static function webhook_events_table() {
		global $wpdb;

		return $wpdb->prefix . 'b2bpl_webhook_events';
	}

	/**
	 * All table names owned by this plugin, in an order safe for
	 * unconditional DROP TABLE during an explicit purge (children first).
	 *
	 * The webhook tables have no foreign keys either (same rationale as the
	 * activity log: rows are a durable record even after their subjects
	 * disappear), but events/items still logically depend on their parent
	 * request row, so they are dropped first.
	 *
	 * @return string[]
	 */
	public static function all_tables_for_purge() {
		return array(
			self::webhook_events_table(),
			self::webhook_items_table(),
			self::webhook_requests_table(),
			self::activity_archives_table(),
			self::activity_table(),
			self::price_ranges_table(),
			self::rule_group_variations_table(),
			self::rule_groups_table(),
			self::list_users_table(),
			self::lists_table(),
		);
	}
}
