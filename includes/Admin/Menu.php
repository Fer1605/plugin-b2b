<?php
/**
 * Registers the "WooCommerce -> B2B Price Lists" admin menu and its
 * page-specific assets.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Order\OrdersListIntegration;
use B2BPriceLists\Support\Assets;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\Nonces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Menu
 */
class Menu {

	/**
	 * The menu/page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'b2b-price-lists';

	/**
	 * Hook suffixes returned by `add_submenu_page()`, used to scope asset
	 * enqueuing to this plugin's own admin screens.
	 *
	 * @var string[]
	 */
	private $hook_suffixes = array();

	/**
	 * Hook suffix of the "Integration" screen (W5), used to scope
	 * `assets/js/integration.js`'s enqueue to that one screen only -- it is
	 * never needed on the lists/activity/orders screens.
	 *
	 * @var string
	 */
	private $integration_hook = '';

	/**
	 * Registers the `admin_menu`/`admin_enqueue_scripts` hooks. Called once
	 * from `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the submenu page under the main "WooCommerce" menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		$hook_suffix           = add_submenu_page(
			'woocommerce',
			__( 'B2B Price Lists', 'b2b-price-lists' ),
			__( 'B2B Price Lists', 'b2b-price-lists' ),
			Capabilities::capability(),
			self::PAGE_SLUG,
			array( ListsController::instance(), 'render_page' )
		);
		$this->hook_suffixes[] = $hook_suffix;

		// The activity log is its own screen rather than another tab inside
		// the list editor: it spans every list (and the settings), so it does
		// not belong under any one of them.
		$activity_hook         = add_submenu_page(
			'woocommerce',
			__( 'B2B Price Lists — Activity', 'b2b-price-lists' ),
			__( '— Activity', 'b2b-price-lists' ),
			Capabilities::capability(),
			ActivityController::PAGE_SLUG,
			array( ActivityController::instance(), 'render' )
		);
		$this->hook_suffixes[] = $activity_hook;

		// The export and the archive downloads send a file, so they must run
		// before the admin header is printed -- same reason as below.
		add_action( 'load-' . $activity_hook, array( ActivityController::instance(), 'handle_early_actions' ) );

		// "Integration" (W5/T040-T046): one page, two tabs (Log/Settings,
		// `IntegrationLogController::render_page()`'s own router). Same
		// capability as every other submenu here -- the Settings tab itself
		// additionally requires `manage_options` internally
		// (`IntegrationSettingsController::render()`), so a Shop Manager can
		// open the page and see the log, but not the webhook's own
		// configuration (US5 acceptance scenario 4).
		$integration_hook       = add_submenu_page(
			'woocommerce',
			__( 'B2B Price Lists — Integration', 'b2b-price-lists' ),
			__( '— Integration', 'b2b-price-lists' ),
			Capabilities::capability(),
			IntegrationLogController::PAGE_SLUG,
			array( IntegrationLogController::instance(), 'render_page' )
		);
		$this->hook_suffixes[]  = $integration_hook;
		$this->integration_hook = $integration_hook;

		add_action( 'load-' . $integration_hook, array( IntegrationLogController::instance(), 'handle_early_actions' ) );

		// Every request that saves, deletes or redirects must run on
		// `load-{$hook_suffix}`, which WordPress fires before
		// `admin-header.php` sends any output. Doing this from the page
		// callback itself (registered above) would run it *after* the
		// admin chrome has already been echoed, making `wp_safe_redirect()`
		// unreliable (see `ListsController::handle_early_actions()`).
		add_action( 'load-' . $hook_suffix, array( ListsController::instance(), 'handle_early_actions' ) );

		// "Pedidos B2B" shortcut (US3/): only shown to a user
		// who can both manage this plugin and see orders at all -- checked
		// here, at menu-build time for the current request, rather than by
		// passing a single combined capability string to
		// `add_submenu_page()`, which only accepts one. It never renders a
		// page of its own: `load-{$hook_suffix}` redirects straight to the
		// native, already-filtered orders screen before any output is sent,
		// same pattern as `ListsController::handle_early_actions()`.
		if ( Capabilities::current_user_can_manage() && current_user_can( 'edit_shop_orders' ) ) {
			$orders_hook           = add_submenu_page(
				'woocommerce',
				__( 'B2B Price Lists — Orders', 'b2b-price-lists' ),
				__( '— B2B Orders', 'b2b-price-lists' ),
				Capabilities::capability(),
				'b2bpl-orders',
				'__return_null'
			);
			$this->hook_suffixes[] = $orders_hook;

			add_action( 'load-' . $orders_hook, array( OrdersListIntegration::class, 'handle_menu_redirect' ) );
		}
	}

	/**
	 * Enqueues admin CSS/JS only on this plugin's own admin screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$on_own_screen    = in_array( $hook_suffix, $this->hook_suffixes, true );
		$on_orders_screen = self::is_orders_screen();

		if ( ! $on_own_screen && ! $on_orders_screen ) {
			return;
		}

		wp_enqueue_style(
			'b2bpl-admin',
			B2BPL_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			Assets::version( 'assets/css/admin.css' )
		);

		// The native WooCommerce orders screen only needs the stylesheet
		// (the B2B column badge, filters and order summary box, all styled
		// with their own classes -- never `.b2bpl-admin`, which only wraps
		// this plugin's own screens). It never needs this plugin's own
		// admin script or localized product-search data.
		if ( ! $on_own_screen ) {
			return;
		}

		wp_enqueue_script(
			'b2bpl-admin',
			B2BPL_URL . 'assets/js/admin.js',
			array(),
			Assets::version( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'b2bpl-admin',
			'B2BPriceListsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Nonces::AJAX_ACTION ),
				'i18n'    => array(
					'searchPlaceholder'   => __( 'Search by name, SKU or ID…', 'b2b-price-lists' ),
					'noResults'           => __( 'No products found.', 'b2b-price-lists' ),
					'loading'             => __( 'Loading…', 'b2b-price-lists' ),
					'confirmRemoveRange'  => __( 'Remove this price range?', 'b2b-price-lists' ),
					'confirmRemoveUser'   => __( 'Remove this user from the list?', 'b2b-price-lists' ),
					'selectVariationsFor' => __( 'Select the variations for this rule', 'b2b-price-lists' ),
					'skuLabel'            => __( 'SKU', 'b2b-price-lists' ),
					'currentlySelected'   => __( 'Selected', 'b2b-price-lists' ),
				),
			)
		);

		// Only the "Integration" screen needs the JSON-viewer copy-to-clipboard
		// script (W5/T044); every other screen above never renders a
		// `[data-b2bpl-copy-target]` button, so loading it there would be
		// dead weight.
		if ( '' !== $this->integration_hook && $hook_suffix === $this->integration_hook ) {
			// Media library picker for the notification email logo (Settings tab).
			wp_enqueue_media();

			wp_enqueue_script(
				'b2bpl-integration',
				B2BPL_URL . 'assets/js/integration.js',
				array(),
				Assets::version( 'assets/js/integration.js' ),
				true
			);
		}
	}

	/**
	 * Whether the current admin screen is the native WooCommerce orders
	 * list (HPOS or classic). The screen id is read at runtime via
	 * `wc_get_page_screen_id`, never a hard-coded
	 * value, since it already returns the correct id under either storage
	 * mode.
	 *
	 * @return bool
	 */
	private static function is_orders_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return OrdersListIntegration::orders_screen_id() === $screen->id;
	}
}
