<?php
/**
 * Marks the whole order as B2B or not, at checkout time, via the
 * `WC_Order` CRUD API exclusively.
 *
 * Two hooks, one shared implementation, verified against the WooCommerce
 * 11.1.0 source running in the staging environment:
 * - Classic checkout: `woocommerce_checkout_create_order` fires from
 *   `WC_Checkout::create_order()` *after* `set_customer_id()` and
 *   `set_data_from_cart()` have already created every line item in memory
 *   (`$order->get_items()`), and *before* the order's own `save()` -- which
 *   `create_order()` performs itself right after this hook fires. This
 *   callback therefore only ever calls `update_meta_data()`/`delete_meta_data()`
 *   here, never its own `save()`, exactly like `Order\OrderAuditMeta`
 *   already does for line items: an extra write on every single checkout
 *   would be purely redundant.
 * - Store API/Checkout Block: `woocommerce_store_api_checkout_update_order_meta`
 *   fires from `Checkout::create_or_update_draft_order()` after
 *   `update_order_from_cart()` has done the same, but nothing is
 *   guaranteed to save this draft order again afterwards, and this route
 *   can run more than once against the same draft as the customer edits
 *   the checkout form -- so this path recomputes from scratch and ends
 *   with its own explicit `$order->save()`.
 *
 * Always uses `$order->get_customer_id()` (the order's own registered
 * customer), never the current request's logged-in user -- unlike
 * `Order\OrderAuditMeta`, which snapshots a *line's* price at the moment it
 * is added to a live checkout request and therefore does rely on the
 * current user. An admin-created order (never routed through either hook
 * above) is correctly never marked.
 *
 * @package B2BPriceLists\Order
 */

namespace B2BPriceLists\Order;

use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\ListUsersRepository;
use B2BPriceLists\Support\Cache;
use B2BPriceLists\Support\Logger;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderTypeAssigner
 */
class OrderTypeAssigner {

	/**
	 * User -> list assignment repository.
	 *
	 * @var ListUsersRepository
	 */
	private $list_users;

	/**
	 * Lists repository.
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * Constructor.
	 *
	 * @param ListUsersRepository|null $list_users Optional, for tests.
	 * @param ListsRepository|null     $lists      Optional, for tests.
	 */
	public function __construct( ?ListUsersRepository $list_users = null, ?ListsRepository $lists = null ) {
		$this->list_users = $list_users ?? new ListUsersRepository();
		$this->lists      = $lists ?? new ListsRepository();
	}

	/**
	 * Registers every hook. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'assign_from_classic_checkout' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'assign_from_store_api' ), 20, 1 );
	}

	/**
	 * `woocommerce_checkout_create_order` callback. Never saves the order
	 * itself -- `WC_Checkout::create_order()` does that right after this
	 * hook fires (verified against WC 11.1.0).
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data (unused).
	 * @return void
	 */
	public function assign_from_classic_checkout( $order, $data ) {
		unset( $data );

		$this->apply( $order, false );
	}

	/**
	 * `woocommerce_store_api_checkout_update_order_meta` callback. Always
	 * saves the order itself: unlike the classic checkout, nothing else is
	 * guaranteed to persist this draft afterwards, and this route can run
	 * more than once against the very same draft.
	 *
	 * @param WC_Order $order Draft order being updated.
	 * @return void
	 */
	public function assign_from_store_api( $order ) {
		$this->apply( $order, true );
	}

	/**
	 * Pure decision of the order-level B2B meta (or `null` for none at
	 * all), given facts already read from WordPress/WooCommerce by the
	 * caller. A guest order (`$customer_id <= 0`) is never B2B,
	 * checked explicitly here -- rather than left solely to
	 * `$assigned_list` already being `null` for a guest -- so this single
	 * method is the one place that answers "what should this order's B2B
	 * meta be", directly unit-testable without a `WC_Order`.
	 *
	 * @param int                      $customer_id   `$order->get_customer_id()`.
	 * @param array<string,mixed>|null $assigned_list Customer's currently
	 *                                                 assigned list row, or
	 *                                                 `null`.
	 * @param array<int,int|null>      $line_list_ids One entry per product
	 *                                                 line item.
	 * @return array<string,int|string>|null
	 */
	public static function decide( $customer_id, ?array $assigned_list, array $line_list_ids ) {
		if ( $customer_id <= 0 ) {
			return null; // Guest checkout never has a B2B list.
		}

		return OrderTypeMeta::build( $assigned_list, $line_list_ids );
	}

	/**
	 * Recomputes and applies (or clears) the order-level B2B mark, then
	 * optionally saves the order itself -- idempotent, so re-running it
	 * against the same draft (Store API) or a manual re-save always
	 * converges on the same result.
	 *
	 * @param mixed $order Expected to be a `WC_Order`.
	 * @param bool  $save  Whether this call must persist the order itself.
	 * @return void
	 */
	private function apply( $order, $save ) {
		try {
			if ( ! ( $order instanceof WC_Order ) ) {
				return;
			}

			$customer_id   = (int) $order->get_customer_id();
			$assigned_list = $customer_id > 0 ? $this->resolve_assigned_list( $customer_id ) : null;
			$line_list_ids = $this->collect_line_list_ids( $order );

			$meta_values = self::decide( $customer_id, $assigned_list, $line_list_ids );

			// Always clear every key first: the order may already carry a
			// stale mark from an earlier pass over the same Store API draft
			// (e.g. the customer's list was deactivated between two
			// updates of the same draft order).
			foreach ( OrderTypeMeta::meta_keys() as $meta_key ) {
				$order->delete_meta_data( $meta_key );
			}

			if ( null !== $meta_values ) {
				foreach ( $meta_values as $meta_key => $meta_value ) {
					$order->update_meta_data( $meta_key, $meta_value );
				}
			}

			if ( $save ) {
				$order->save();
			}

			Cache::invalidate_b2b_orders_count();
		} catch ( Throwable $exception ) {
			Logger::error(
				'Unexpected exception while assigning the B2B order type mark; the order meta may be incomplete.',
				array(
					'order_id' => ( $order instanceof WC_Order ) ? $order->get_id() : 0,
					'message'  => $exception->getMessage(),
				)
			);
		}
	}

	/**
	 * The customer's currently assigned list row, or `null` for a guest, a
	 * customer with no assignment, or a stale assignment pointing at a
	 * deleted list.
	 *
	 * @param int $customer_id `$order->get_customer_id()`.
	 * @return array<string,mixed>|null
	 */
	private function resolve_assigned_list( $customer_id ) {
		if ( $customer_id <= 0 ) {
			return null; // Guest checkout never has a B2B list.
		}

		$list_id = $this->list_users->get_list_id_for_user( $customer_id );

		if ( null === $list_id ) {
			return null;
		}

		return $this->lists->find( $list_id );
	}

	/**
	 * Every product line item's recorded `_b2bpl_list_id`, or
	 * `null` for a line that received no B2B price -- one entry per line,
	 * counted from `$order->get_items` from scratch,
	 * never an incremental counter.
	 *
	 * @param WC_Order $order Order being built.
	 * @return array<int,int|null>
	 */
	private function collect_line_list_ids( WC_Order $order ) {
		$line_list_ids = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$meta = $item->get_meta( OrderSnapshotBuilder::META_LIST_ID, true );

			$line_list_ids[] = ( '' === $meta || null === $meta ) ? null : (int) $meta;
		}

		return $line_list_ids;
	}
}
