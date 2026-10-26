<?php
/**
 * Uninstall routine.
 *
 * Conservative by default: uninstalling the plugin never
 * deletes data unless an administrator explicitly opted in beforehand via
 * the `b2bpl_purge_data_on_uninstall` option, exposed since 0.9.0
 * as an explicit checkbox on `WooCommerce -> B2B Price Lists -> Settings`
 * (`Admin\SettingsController`). Since 0.5.0 the plugin owns five `b2bpl_*`
 * tables (see `includes/Database/Schema.php`) plus, since 0.8.0, the
 * `b2bpl_table_settings` option; this file drops all of them, together with
 * the schema version and purge-opt-in options, but only on that explicit
 * opt-in.
 *
 * @package B2BPriceLists
 */

// Standard WordPress guard: this file must only run through the core
// uninstall process, never by direct request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Retire the plugin's own `manage_b2b_price_lists` capability from every
 * role, unconditionally -- this runs regardless
 * of the data-purge opt-in below, and before it: removing a capability
 * this plugin granted is not a data-destructive action, so it is never
 * gated behind the same opt-in that protects `b2bpl_*` tables and options.
 * A site that had previously removed `manage_woocommerce` from Shop
 * Manager specifically to keep this plugin's older versions away from that
 * role now needs to remove `manage_b2b_price_lists` from Shop Manager
 * instead (see the 1.4.0 CHANGELOG entry) -- uninstalling does that
 * automatically, but a still-active older role setup does not.
 *
 * The dedicated `b2bpl_integration` role (retired again
 * by) and any scheduled webhook batch actions are removed the same
 * unconditional way, for the same reason: retiring a role or an Action
 * Scheduler hook this plugin created is not business-data-destructive, so
 * neither is gated behind the purge opt-in below.
 *
 * The webhook's own username/password (`Webhook\Credentials`) is
 * also deleted unconditionally, before the purge opt-in: unlike this
 * plugin's business data, a leftover secret is never something a
 * conservative uninstall should keep around.
 */
$autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $autoloader ) ) {
	require_once $autoloader;

	\B2BPriceLists\Support\RoleInstaller::uninstall();
	\B2BPriceLists\Support\RoleInstaller::uninstall_integration_role();
	\B2BPriceLists\Webhook\Credentials::delete();
	\B2BPriceLists\Webhook\BatchQueue::unschedule_all();
	// W5/T045: same rationale as the two lines above -- clearing a
	// scheduled cron event this plugin created is not a business-data
	// deletion, so it happens unconditionally, before the purge opt-in.
	\B2BPriceLists\Webhook\RetentionPurger::unschedule();
	// Same rationale again (notification emails, 2026-09): the hourly
	// digest schedule and its own pending queue are this feature's
	// housekeeping state, not business data.
	\B2BPriceLists\Webhook\Notifier::unschedule();
	delete_option( \B2BPriceLists\Webhook\Notifier::QUEUE_OPTION );
}

// Explicit opt-in required. addendum documents two possible type
// contracts between the future settings UI and this comparison; this file
// implements contract (b): the raw option value is normalized with
// `FILTER_VALIDATE_BOOLEAN` before comparison, so the settings UI
// (`SettingsController::handle_save()`) can persist a plain PHP boolean the
// same natural way every other checkbox on that screen already does,
// without needing to know about WordPress' own `true` -> `'1'` string
// round-trip through `update_option()`. Fails safe: an unset, empty, or
// unrecognized value normalizes to `false` (never purges).
$purge_requested = get_option( 'b2bpl_purge_data_on_uninstall', false );

if ( ! filter_var( $purge_requested, FILTER_VALIDATE_BOOLEAN ) ) {
	return;
}

/**
 * Purge branch (opt-in only).
 */
global $wpdb;

if ( ! file_exists( $autoloader ) ) {
	return;
}

require_once $autoloader;

$tables = \B2BPriceLists\Database\Schema::all_tables_for_purge();

foreach ( $tables as $table ) {
	// Table names are not user input (built from a fixed, hard-coded
	// list above), so this direct query is safe without `$wpdb->prepare()`,
	// which does not support table name placeholders.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

delete_option( 'b2bpl_db_schema_version' );
delete_option( 'b2bpl_purge_data_on_uninstall' );
delete_option( \B2BPriceLists\Support\TableSettings::OPTION_NAME );
delete_option( \B2BPriceLists\Webhook\Settings::OPTION_NAME );
delete_option( \B2BPriceLists\Webhook\RetentionPurger::LAST_PURGE_OPTION );

/**
 * Historical orders backfill (1.4.0): its own options, transients and any
 * queued Action Scheduler action, all opt-in-purge only -- like everything
 * else in this branch, none of it is business data, but it is this
 * feature's own state, not something a conservative uninstall should leave
 * behind once an administrator explicitly asked to purge everything.
 */
delete_option( \B2BPriceLists\Order\HistoricalOrdersBackfill::OPTION_CURSOR );
delete_option( \B2BPriceLists\Order\HistoricalOrdersBackfill::OPTION_STATUS );
delete_option( \B2BPriceLists\Order\HistoricalOrdersBackfill::OPTION_TOTALS );
delete_option( \B2BPriceLists\Order\HistoricalOrdersBackfill::OPTION_SCHEDULED_VERSION );
delete_transient( \B2BPriceLists\Order\HistoricalOrdersBackfill::LOCK_TRANSIENT );
delete_transient( \B2BPriceLists\Order\HistoricalOrdersBackfill::RECOVERY_THROTTLE_TRANSIENT );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( \B2BPriceLists\Order\HistoricalOrdersBackfill::HOOK, array(), \B2BPriceLists\Order\HistoricalOrdersBackfill::AS_GROUP );
}
