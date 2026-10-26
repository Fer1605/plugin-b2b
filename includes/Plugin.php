<?php
/**
 * Main plugin bootstrap class.
 *
 * @package B2BPriceLists
 */

namespace B2BPriceLists;

use B2BPriceLists\Activity\ActivityLog;
use B2BPriceLists\Admin\Menu;
use B2BPriceLists\Admin\ProductInfoPanel;
use B2BPriceLists\Admin\UserAssignmentController;
use B2BPriceLists\Ajax\ProductSearchController;
use B2BPriceLists\Cart\CartPriceHandler;
use B2BPriceLists\Cleanup\EntityCleanup;
use B2BPriceLists\Database\Installer;
use B2BPriceLists\Frontend\Assets;
use B2BPriceLists\Frontend\PriceDisplay;
use B2BPriceLists\Frontend\PriceTableRenderer;
use B2BPriceLists\Order\HistoricalOrdersBackfill;
use B2BPriceLists\Order\OrderAuditMeta;
use B2BPriceLists\Order\OrderB2BSummaryBox;
use B2BPriceLists\Order\OrderSnapshotDisplay;
use B2BPriceLists\Order\OrderTypeAssigner;
use B2BPriceLists\Order\OrdersListIntegration;
use B2BPriceLists\Support\RoleInstaller;
use B2BPriceLists\Webhook\BatchQueue;
use B2BPriceLists\Webhook\IngestLogger;
use B2BPriceLists\Webhook\Notifier;
use B2BPriceLists\Webhook\RequestGuard;
use B2BPriceLists\Webhook\RestController;
use B2BPriceLists\Webhook\RetentionPurger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Single entry point for the plugin: registers every service (database
 * migrations, cleanup hooks, admin screens, AJAX endpoints) from `init()`,
 * which only runs once the environment gate in the main plugin file has
 * already confirmed PHP/WordPress/WooCommerce compatibility.
 */
class Plugin {

	/**
	 * The single instance of this class.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the single instance of the plugin, creating it on first call.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor: use Plugin::instance() instead.
	 */
	private function __construct() {}

	/**
	 * Initializes the plugin.
	 *
	 * In this phase there is no functional service to register yet. The
	 * `b2bpl_loaded` action is fired so that future phases have a stable
	 * hook to attach service registration to, without every phase needing
	 * to edit this method directly.
	 *
	 * @return void
	 */
	public function init() {
		// Keep the `b2bpl_*` tables in sync with B2BPL_DB_SCHEMA_VERSION on
		// every load where the environment is compatible (not only on
		// activation), so the schema self-heals if it was ever created
		// against an older constant value.
		Installer::maybe_upgrade();
		RoleInstaller::maybe_upgrade();

		EntityCleanup::register();
		ActivityLog::register();

		( new Menu() )->register();
		( new UserAssignmentController() )->register();
		( new ProductSearchController() )->register();
		( new ProductInfoPanel() )->register();

		( new PriceDisplay() )->register();
		( new PriceTableRenderer() )->register();
		( new Assets() )->register();

		( new CartPriceHandler() )->register();
		( new OrderAuditMeta() )->register();
		( new OrderSnapshotDisplay() )->register();
		( new OrderTypeAssigner() )->register();
		( new OrdersListIntegration() )->register();
		( new OrderB2BSummaryBox() )->register();

		// `register()` itself wires the one-time scheduling to Action
		// Scheduler's own `action_scheduler_init` (never directly here, on
		// `plugins_loaded` -- see the class docblock/`register()` for why).
		( new HistoricalOrdersBackfill() )->register();

		// Webhook (1.5.0): `RequestGuard` and
		// `IngestLogger` are registered unconditionally -- enforcing "the
		// webhook does not exist while disabled" (a 404, still logged) and
		// "every request is recorded" are both this plugin's job regardless
		// of the `enabled` setting. Only `RestController::register_routes()`
		// itself checks that setting, since routes must not even be
		// registered while disabled (R9).
		RequestGuard::register();
		IngestLogger::register();
		RestController::register();

		// W3: the queue itself (`as_enqueue_async_action`, the FIFO lock and
		// the recovery watchdog) -- registered unconditionally, same
		// rationale as the three lines above; `BatchQueue::register()` never
		// enqueues anything on its own while the webhook is disabled (a
		// disabled webhook never reaches `queued` in the first place, per
		// `RestController`/`Settings::is_enabled()`).
		BatchQueue::register();

		// W5: the log viewer/settings screens are plain `Admin\Menu`
		// submenu callbacks (no separate `register()` of their own, same
		// pattern as `ListsController`/`ActivityController`); only the
		// retention cron needs registering here, unconditionally -- like
		// `Activity\ActivityLog::register()` above, it purges only what is
		// actually past the configured retention window, never anything
		// while the webhook itself is disabled or empty.
		RetentionPurger::register();

		// Notification emails (site owner request, 2026-09): unconditional,
		// same rationale as `RequestGuard`/`IngestLogger` above -- the two
		// hooks it consumes (`Notifier::HOOK_REQUEST_REJECTED`/
		// `HOOK_BATCH_FINISHED`) are only ever fired for a genuine error,
		// and `Notifier` itself never sends anything while no recipient is
		// configured.
		Notifier::register();

		/**
		 * Fires once the plugin has finished its bootstrap.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'b2bpl_loaded', $this );
	}
}
