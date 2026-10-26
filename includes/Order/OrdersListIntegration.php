<?php
/**
 * Extends the native WooCommerce orders screen (HPOS and classic,
 *) with a "B2B" column/badge, "Type of order"/"B2B list"
 * filters, and a "B2B (n)" quick view -- no duplicate screen of its own.
 *
 * The screen id is always read at runtime via `wc_get_page_screen_id()`
 *, which already returns the correct value under either
 * storage mode, and every filter/view hook merges into whatever the native
 * screen already does -- it is never replaced.
 *
 * @package B2BPriceLists\Order
 */

namespace B2BPriceLists\Order;

use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Support\Cache;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrdersListIntegration
 */
class OrdersListIntegration {

	/**
	 * GET parameter names for the two custom filters, and for the "B2B (n)"
	 * view link.
	 *
	 * @var string
	 */
	const FILTER_TYPE_KEY = 'b2bpl_order_type';
	const FILTER_LIST_KEY = 'b2bpl_order_list';

	/**
	 * Column key added to the orders list table.
	 *
	 * @var string
	 */
	const COLUMN_KEY = 'b2bpl_order_type';

	/**
	 * Lists repository, used to populate the "B2B list" filter dropdown.
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * Constructor.
	 *
	 * @param ListsRepository|null $lists Optional, for tests.
	 */
	public function __construct( ?ListsRepository $lists = null ) {
		$this->lists = $lists ?? new ListsRepository();
	}

	/**
	 * Registers every hook. Called once from `Plugin::init()`.
	 *
	 * Deliberately branches once, at registration time, on whether HPOS is
	 * active -- rather than registering every possible hook name and having
	 * each callback guess which screen it is on -- because the classic
	 * `manage_shop_order_posts_custom_column`/`restrict_manage_posts`/
	 * `pre_get_posts` hooks and their HPOS equivalents are genuinely
	 * different hook names firing from different WooCommerce internals, not
	 * two spellings of the same event.
	 *
	 * @return void
	 */
	public function register() {
		// The column and quick view hook names contain the orders screen id,
		// which WooCommerce only resolves correctly once the admin screen
		// exists. Read at `plugins_loaded` it still returns the classic
		// `edit-shop_order` under HPOS, so both hooks silently never fired
		// (1.4.2 fix). They are attached on `current_screen` instead.
		add_action( 'current_screen', array( $this, 'register_screen_hooks' ) );

		if ( self::hpos_enabled() ) {
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filters_hpos' ), 10, 2 );
			add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_query_args' ) );
		} else {
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_classic' ), 10, 2 );
			add_action( 'restrict_manage_posts', array( $this, 'render_filters_classic' ), 10, 2 );
			add_action( 'pre_get_posts', array( $this, 'filter_classic_query' ) );
		}
	}

	/**
	 * `current_screen` callback: attaches the column and quick view hooks
	 * once the real screen id is known, and only on the orders list screen.
	 *
	 * @param \WP_Screen|object|null $screen Current admin screen.
	 * @return void
	 */
	public function register_screen_hooks( $screen ) {
		$screen_id = ( is_object( $screen ) && isset( $screen->id ) ) ? (string) $screen->id : '';

		foreach ( self::screen_hooks( $screen_id, self::orders_screen_id(), self::hpos_enabled() ) as $hook ) {
			add_filter( $hook['tag'], array( $this, $hook['method'] ), 10, $hook['args'] );
		}
	}

	/**
	 * Which screen-id-dependent hooks to attach for a given screen. Pure,
	 * so it can be unit-tested.
	 *
	 * @param string $screen_id        Id of the screen being loaded.
	 * @param string $orders_screen_id Id of the orders list screen.
	 * @param bool   $hpos             Whether HPOS is active.
	 * @return array<int,array{tag:string,method:string,args:int}>
	 */
	public static function screen_hooks( $screen_id, $orders_screen_id, $hpos ) {
		$screen_id = (string) $screen_id;

		if ( '' === $screen_id || (string) $orders_screen_id !== $screen_id ) {
			return array();
		}

		$hooks = array(
			array(
				'tag'    => "manage_{$screen_id}_columns",
				'method' => 'add_column',
				'args'   => 1,
			),
			array(
				'tag'    => "views_{$screen_id}",
				'method' => 'add_view',
				'args'   => 1,
			),
		);

		if ( $hpos ) {
			$hooks[] = array(
				'tag'    => "manage_{$screen_id}_custom_column",
				'method' => 'render_column_hpos',
				'args'   => 2,
			);
		}

		return $hooks;
	}

	/**
	 * `load-{$hook_suffix}` target for the plugin menu's "— B2B Orders"
	 * shortcut (`Admin\Menu`): redirects straight to the native orders
	 * screen, pre-filtered to B2B, before any admin output is sent.
	 *
	 * @return void
	 */
	public static function handle_menu_redirect() {
		wp_safe_redirect( self::orders_url( array( self::FILTER_TYPE_KEY => 'b2b' ) ) );
		exit;
	}

	/**
	 * Whether HPOS (custom order tables) is the active order storage.
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The orders list screen id, read at runtime (never hard-coded): equal
	 * to `woocommerce_page_wc-orders` under HPOS and `edit-shop_order`
	 * under classic storage.
	 *
	 * @return string
	 */
	public static function orders_screen_id() {
		return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'edit-shop_order';
	}

	/**
	 * Base URL of the native orders list screen, under either storage mode.
	 *
	 * @param array<string,mixed> $args Extra query args (filters).
	 * @return string
	 */
	public static function orders_url( array $args = array() ) {
		$base = self::hpos_enabled()
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' );

		return add_query_arg( $args, $base );
	}

	/**
	 * Number of B2B orders, used by the "B2B (n)" view. Backed by a single
	 * `wc_get_orders()` count query (`paginate` + `limit=1`, never a full
	 * order load) and a short-lived cache, invalidated by
	 * `Order\OrderTypeAssigner`/`Order\HistoricalOrdersBackfill` whenever an
	 * order is (re)marked.
	 *
	 * @return int
	 */
	public function b2b_orders_count() {
		$cached = Cache::b2b_orders_count();

		if ( false !== $cached ) {
			return $cached;
		}

		$count = self::count_orders_by_meta(
			array(
				array(
					'key'   => OrderTypeMeta::META_ORDER_TYPE,
					'value' => OrderTypeMeta::TYPE_B2B,
				),
			)
		);

		Cache::set_b2b_orders_count( $count );

		return $count;
	}

	/**
	 * Number of orders bought with a given list (used by the "View orders
	 * for this list (n)" link in the list editor, `Admin\Views\list-context`).
	 * Not cached: it is only ever computed once per list-editor page load.
	 *
	 * @param int $list_id List id.
	 * @return int
	 */
	public static function count_for_list( $list_id ) {
		return self::count_orders_by_meta(
			array(
				array(
					'key'   => OrderTypeMeta::META_ORDER_LIST_ID,
					'value' => (int) $list_id,
				),
			)
		);
	}

	/**
	 * Label text for the "B2B orders for this list" button
	 * (`Views\list-context`), phrased so a zero count is stated plainly
	 * rather than looking like an error or being hidden altogether
	 * (1.4.1 feedback). Pure aside from translation functions, so it can
	 * be unit-tested directly.
	 *
	 * @param int $count Orders count for the list.
	 * @return string
	 */
	public static function orders_button_label( $count ) {
		$count = (int) $count;

		if ( $count > 0 ) {
			return sprintf(
				/* translators: %d: number of orders bought with this list. */
				__( 'B2B orders for this list (%d)', 'b2b-price-lists' ),
				$count
			);
		}

		return __( 'B2B orders (0)', 'b2b-price-lists' );
	}

	/**
	 * `title` attribute for the same button, explaining at zero that the
	 * button being visible does not imply the list already has orders.
	 *
	 * @param int $count Orders count for the list.
	 * @return string
	 */
	public static function orders_button_title( $count ) {
		if ( (int) $count > 0 ) {
			return __( 'Opens the WooCommerce Orders screen, filtered to this list', 'b2b-price-lists' );
		}

		return __( 'No orders have been placed with this list yet. Opens the WooCommerce Orders screen, filtered to this list.', 'b2b-price-lists' );
	}

	/**
	 * Shared `wc_get_orders()` count-only query.
	 *
	 * @param array $meta_query WooCommerce/WP_Meta_Query-shaped clauses.
	 * @return int
	 */
	private static function count_orders_by_meta( array $meta_query ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$result = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => 1,
				'paginate'   => true,
				'return'     => 'ids',
				'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- native wc_get_orders() argument, not a raw query.
			)
		);

		return ( is_object( $result ) && isset( $result->total ) ) ? (int) $result->total : 0;
	}

	/**
	 * Adds the "B2B" column, right before the order status column when
	 * present (falls back to appending it).
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$new      = array();
		$inserted = false;

		foreach ( $columns as $key => $label ) {
			if ( ! $inserted && 'order_status' === $key ) {
				$new[ self::COLUMN_KEY ] = __( 'B2B', 'b2b-price-lists' );
				$inserted                = true;
			}

			$new[ $key ] = $label;
		}

		if ( ! $inserted ) {
			$new[ self::COLUMN_KEY ] = __( 'B2B', 'b2b-price-lists' );
		}

		return $new;
	}

	/**
	 * HPOS `manage_{$screen_id}_custom_column` callback: receives the order
	 * object directly.
	 *
	 * @param string   $column Current column key.
	 * @param WC_Order $order  Order for this row.
	 * @return void
	 */
	public function render_column_hpos( $column, $order ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$this->render_badge( $order );
	}

	/**
	 * Classic `manage_shop_order_posts_custom_column` callback: receives
	 * the post id.
	 *
	 * @param string $column  Current column key.
	 * @param int    $post_id Order (post) id for this row.
	 * @return void
	 */
	public function render_column_classic( $column, $post_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );

		if ( $order instanceof WC_Order ) {
			$this->render_badge( $order );
		}
	}

	/**
	 * Renders the "B2B · {list name}" badge for a B2B order, linked to the
	 * "B2B list" filter for that same list. Prints nothing at
	 * all for a B2C order (US1 scenario 2).
	 *
	 * @param WC_Order $order Order for this row.
	 * @return void
	 */
	private function render_badge( WC_Order $order ) {
		$type = $order->get_meta( OrderTypeMeta::META_ORDER_TYPE, true );

		if ( OrderTypeMeta::TYPE_B2B !== $type ) {
			return;
		}

		$list_id   = absint( $order->get_meta( OrderTypeMeta::META_ORDER_LIST_ID, true ) );
		$list_name = (string) $order->get_meta( OrderTypeMeta::META_ORDER_LIST_NAME, true );

		if ( '' === $list_name ) {
			$list_name = sprintf(
				/* translators: %d: id of a B2B list that no longer exists. */
				__( '(deleted list #%d)', 'b2b-price-lists' ),
				$list_id
			);
		}

		$url = self::orders_url( array( self::FILTER_LIST_KEY => $list_id ) );

		printf(
			'<a href="%1$s" class="b2bpl-order-badge"><span class="b2bpl-order-badge__label">%2$s</span> · %3$s</a>',
			esc_url( $url ),
			esc_html__( 'B2B', 'b2b-price-lists' ),
			esc_html( $list_name )
		);
	}

	/**
	 * HPOS `woocommerce_order_list_table_restrict_manage_orders` callback.
	 *
	 * @param string $order_type Order type being listed (unused).
	 * @param string $which      'top' or 'bottom' table nav.
	 * @return void
	 */
	public function render_filters_hpos( $order_type, $which ) {
		unset( $order_type );

		$this->render_filter_controls( $which );
	}

	/**
	 * Classic `restrict_manage_posts` callback.
	 *
	 * @param string $post_type Post type being listed.
	 * @param string $which     'top' or 'bottom' table nav.
	 * @return void
	 */
	public function render_filters_classic( $post_type, $which ) {
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$this->render_filter_controls( $which );
	}

	/**
	 * Renders the "Order type" and "B2B list" `<select>` filters, once,
	 * above the table.
	 *
	 * @param string $which 'top' or 'bottom' table nav.
	 * @return void
	 */
	private function render_filter_controls( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$selected_type = $this->current_type_filter();
		$selected_list = $this->current_list_filter();
		?>
		<label class="screen-reader-text" for="b2bpl-filter-order-type"><?php esc_html_e( 'Order type', 'b2b-price-lists' ); ?></label>
		<select name="<?php echo esc_attr( self::FILTER_TYPE_KEY ); ?>" id="b2bpl-filter-order-type">
			<option value=""><?php esc_html_e( 'Order type: all', 'b2b-price-lists' ); ?></option>
			<option value="b2b" <?php selected( 'b2b', $selected_type ); ?>><?php esc_html_e( 'B2B', 'b2b-price-lists' ); ?></option>
			<option value="b2c" <?php selected( 'b2c', $selected_type ); ?>><?php esc_html_e( 'B2C', 'b2b-price-lists' ); ?></option>
		</select>
		<label class="screen-reader-text" for="b2bpl-filter-order-list"><?php esc_html_e( 'B2B list', 'b2b-price-lists' ); ?></label>
		<select name="<?php echo esc_attr( self::FILTER_LIST_KEY ); ?>" id="b2bpl-filter-order-list">
			<option value="0"><?php esc_html_e( 'B2B list: all', 'b2b-price-lists' ); ?></option>
			<?php foreach ( $this->lists->all() as $list ) : ?>
				<option value="<?php echo esc_attr( (string) (int) $list['id'] ); ?>" <?php selected( (int) $list['id'], $selected_list ); ?>>
					<?php echo esc_html( $list['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * HPOS `woocommerce_order_list_table_prepare_items_query_args`
	 * callback: merges into `meta_query`, never replaces it.
	 *
	 * @param array $query_args Existing `wc_get_orders()`-shaped args.
	 * @return array
	 */
	public function filter_query_args( $query_args ) {
		if ( ! is_array( $query_args ) ) {
			return $query_args;
		}

		$extra = $this->build_meta_query();

		if ( empty( $extra ) ) {
			return $query_args;
		}

		$meta_query   = isset( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] ) ? $query_args['meta_query'] : array();
		$meta_query[] = $extra;

		$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return $query_args;
	}

	/**
	 * Classic `pre_get_posts` callback: only ever touches the main query on
	 * the classic orders screen, never any other admin
	 * list or a secondary query a theme/plugin might run on the same page.
	 *
	 * @param \WP_Query $query Current query.
	 * @return void
	 */
	public function filter_classic_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || self::orders_screen_id() !== $screen->id ) {
			return;
		}

		$extra = $this->build_meta_query();

		if ( empty( $extra ) ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = $extra;

		$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	/**
	 * Builds the extra `meta_query` clause(s) for the current request's
	 * filters, or an empty array when neither filter is active.
	 *
	 * @return array
	 */
	private function build_meta_query() {
		$type    = $this->current_type_filter();
		$list_id = $this->current_list_filter();

		$clauses = array();

		if ( 'b2b' === $type ) {
			$clauses[] = array(
				'key'     => OrderTypeMeta::META_ORDER_TYPE,
				'value'   => OrderTypeMeta::TYPE_B2B,
				'compare' => '=',
			);
		} elseif ( 'b2c' === $type ) {
			// B2C orders never receive any `_b2bpl_*` meta at all
			//, so "not B2B" is exactly "this key does not exist"
			//.
			$clauses[] = array(
				'key'     => OrderTypeMeta::META_ORDER_TYPE,
				'compare' => 'NOT EXISTS',
			);
		}

		if ( $list_id > 0 ) {
			$clauses[] = array(
				'key'     => OrderTypeMeta::META_ORDER_LIST_ID,
				'value'   => $list_id,
				'compare' => '=',
			);
		}

		if ( empty( $clauses ) ) {
			return array();
		}

		if ( 1 === count( $clauses ) ) {
			return $clauses[0];
		}

		$clauses['relation'] = 'AND';

		return $clauses;
	}

	/**
	 * Adds the "B2B (n)" entry to the status views row
	 * ("All | Processing | Completed | ..."), linking to the orders screen
	 * pre-filtered to B2B.
	 *
	 * @param array<string,string> $views Existing views.
	 * @return array<string,string>
	 */
	public function add_view( $views ) {
		if ( ! is_array( $views ) ) {
			return $views;
		}

		$count = $this->b2b_orders_count();
		$url   = self::orders_url( array( self::FILTER_TYPE_KEY => 'b2b' ) );
		$class = ( 'b2b' === $this->current_type_filter() ) ? ' class="current"' : '';

		$views['b2bpl_b2b'] = '<a href="' . esc_url( $url ) . '"' . $class . '>'
			. esc_html__( 'B2B', 'b2b-price-lists' )
			. ' <span class="count">(' . esc_html( number_format_i18n( $count ) ) . ')</span></a>';

		return $views;
	}

	/**
	 * Sanitizes a candidate "order type" filter value against a fixed
	 * whitelist. Pure and WordPress-independent so it can
	 * be unit-tested directly.
	 *
	 * @param mixed $raw Raw candidate value.
	 * @return string `'b2b'`, `'b2c'`, or `''` for anything else.
	 */
	public static function sanitize_type_filter( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = strtolower( trim( (string) $raw ) );

		return in_array( $value, array( 'b2b', 'b2c' ), true ) ? $value : '';
	}

	/**
	 * The current request's sanitized "order type" filter.
	 *
	 * @return string
	 */
	private function current_type_filter() {
		if ( ! isset( $_GET[ self::FILTER_TYPE_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, not a state-changing action.
			return '';
		}

		return self::sanitize_type_filter( sanitize_text_field( wp_unslash( $_GET[ self::FILTER_TYPE_KEY ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The current request's sanitized "B2B list" filter (a list id, or `0`
	 * for "all").
	 *
	 * @return int
	 */
	private function current_list_filter() {
		if ( ! isset( $_GET[ self::FILTER_LIST_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 0;
		}

		return absint( wp_unslash( $_GET[ self::FILTER_LIST_KEY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
