<?php
/**
 * Read-only "B2B order" summary box on the native order edit screen
 *: which list the order was bought with, how many lines got
 * a special price (informational, never presented as an error -- a B2B
 * order with zero special-priced lines is still fully B2B, spec.md edge
 * case), and a notice when the customer's list today differs from the one
 * used at purchase time.
 *
 * Reads exclusively from the order's own already-persisted meta
 * (`Order\OrderTypeMeta`) for the purchase-time facts, and from the live
 * assignment repositories only to compare against *today's* list -- never
 * to recompute or second-guess what was charged (that already happened,
 * server-side, at checkout).
 *
 * @package B2BPriceLists\Order
 */

namespace B2BPriceLists\Order;

use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\ListUsersRepository;
use Throwable;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderB2BSummaryBox
 */
class OrderB2BSummaryBox {

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
	 * Registers the hook. Called once from `Plugin::init()`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render' ) );
	}

	/**
	 * Renders the summary box. Prints nothing at all for a B2C order.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return void
	 */
	public function render( $order ) {
		try {
			if ( ! ( $order instanceof WC_Order ) ) {
				return;
			}

			if ( OrderTypeMeta::TYPE_B2B !== $order->get_meta( OrderTypeMeta::META_ORDER_TYPE, true ) ) {
				return;
			}

			$list_id     = absint( $order->get_meta( OrderTypeMeta::META_ORDER_LIST_ID, true ) );
			$list_name   = (string) $order->get_meta( OrderTypeMeta::META_ORDER_LIST_NAME, true );
			$b2b_lines   = absint( $order->get_meta( OrderTypeMeta::META_ORDER_B2B_LINES, true ) );
			$total_lines = absint( $order->get_meta( OrderTypeMeta::META_ORDER_TOTAL_LINES, true ) );

			if ( '' === $list_name ) {
				/* translators: %d: id of a B2B list that no longer exists. */
				$list_name = sprintf( __( '(deleted list #%d)', 'b2b-price-lists' ), $list_id );
			}

			echo '<div class="b2bpl-order-summary">';
			echo '<h4>' . esc_html__( 'B2B order', 'b2b-price-lists' ) . '</h4>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: name of the list used at the time of purchase. */
					__( 'List used at purchase: %s', 'b2b-price-lists' ),
					$list_name
				)
			) . '</p>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: number of order lines with a B2B price, 2: total number of product lines in the order. */
					__( '%1$d of %2$d products had a special price.', 'b2b-price-lists' ),
					$b2b_lines,
					$total_lines
				)
			) . '</p>';

			$notice = $this->current_list_notice( (int) $order->get_customer_id(), $list_id );

			if ( '' !== $notice ) {
				echo '<p class="b2bpl-order-summary__notice">' . esc_html( $notice ) . '</p>';
			}

			echo '</div>';
		} catch ( Throwable $exception ) {
			unset( $exception );

			// Never break the order edit screen over a display-only failure.
		}
	}

	/**
	 * A notice when the customer's *current* list assignment differs from
	 * the one recorded on the order, or when they no longer have any list
	 * at all -- both purely informational. Returns
	 * an empty string when there is nothing to say (guest order, or the
	 * customer's current list still matches).
	 *
	 * @param int $customer_id `$order->get_customer_id()`.
	 * @param int $order_list_id List id recorded on the order at purchase time.
	 * @return string
	 */
	private function current_list_notice( $customer_id, $order_list_id ) {
		if ( $customer_id <= 0 ) {
			return '';
		}

		$current_list_id = $this->list_users->get_list_id_for_user( $customer_id );

		if ( null === $current_list_id ) {
			return __( 'This customer currently has no B2B list assigned.', 'b2b-price-lists' );
		}

		if ( (int) $current_list_id === $order_list_id ) {
			return '';
		}

		$current_list = $this->lists->find( $current_list_id );
		$current_name = ( $current_list && '' !== $current_list['name'] )
			? (string) $current_list['name']
			/* translators: %d: id of a B2B list that no longer exists. */
			: sprintf( __( '(deleted list #%d)', 'b2b-price-lists' ), $current_list_id );

		return sprintf(
			/* translators: %s: name of the customer's current list, different from the one used to buy this order. */
			__( 'Current list for this customer: %s (different from the one used to buy).', 'b2b-price-lists' ),
			$current_name
		);
	}
}
