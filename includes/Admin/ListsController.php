<?php
/**
 * Admin controller for `WooCommerce -> B2B Price Lists`: list overview,
 * create/edit form, user assignment tab, and cascading deletion with
 * explicit confirmation. Delegates rule group management to
 * `RuleGroupController` and persistence to `Domain\ListService`/
 * `Domain\UserAssignmentService` -- this class only wires requests to
 * those services and renders views, it never talks to `$wpdb` directly.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

use B2BPriceLists\Domain\ListService;
use B2BPriceLists\Domain\UserAssignmentService;
use B2BPriceLists\Repository\ListsRepository;
use B2BPriceLists\Repository\ListUsersRepository;
use B2BPriceLists\Support\Capabilities;
use B2BPriceLists\Support\Nonces;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ListsController
 */
class ListsController {

	/**
	 * Shared instance.
	 *
	 * @var ListsController|null
	 */
	private static $instance = null;

	/**
	 * List CRUD/duplication/cascading-deletion service.
	 *
	 * @var ListService
	 */
	private $list_service;

	/**
	 * User assignment service.
	 *
	 * @var UserAssignmentService
	 */
	private $assignment_service;

	/**
	 * Lists repository.
	 *
	 * @var ListsRepository
	 */
	private $lists;

	/**
	 * List/user assignment repository.
	 *
	 * @var ListUsersRepository
	 */
	private $list_users;

	/**
	 * Returns the shared instance (the admin menu callback needs one
	 * concrete callable target).
	 *
	 * @return ListsController
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->list_service       = new ListService();
		$this->assignment_service = new UserAssignmentService();
		$this->lists              = new ListsRepository();
		$this->list_users         = new ListUsersRepository();
	}

	/**
	 * Entry point registered with `add_submenu_page()`.
	 *
	 * By the time this runs, `handle_early_actions()` has already
	 * intercepted (and `exit`-ed after) every request that saves, deletes
	 * or otherwise redirects -- so this method only ever renders GET
	 * screens and never sends a redirect itself.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage B2B price lists.', 'b2b-price-lists' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_form( null );
				break;

			case 'edit':
				$this->render_form( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				break;

			case 'delete':
				$this->render_delete_confirmation( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				break;

			case 'settings':
				SettingsController::instance()->render();
				break;

			case 'help':
				$this->render_help();
				break;

			case 'list':
			default:
				$this->render_list();
				break;
		}
	}

	/**
	 * Registered on `load-{$hook_suffix}` (see `Menu::add_menu()`), which
	 * WordPress fires *before* `admin-header.php` sends any output --
	 * unlike the page callback (`render_page()`), which WordPress core
	 * only invokes after that HTML has already been echoed. Every branch
	 * of this method that mutates data or redirects therefore `exit`s
	 * here, before a single byte of the admin screen has been rendered.
	 *
	 * Intercepting every save/delete/redirect this early (rather than
	 * inline in `render_page()`) is what keeps `wp_safe_redirect()`
	 * reliable: calling it after admin-header.php has already printed the
	 * WP admin chrome would depend on output buffering to silently paper
	 * over a "headers already sent" condition -- fragile by construction,
	 * since it depends on how much every other active plugin's admin
	 * notices/menu happen to print before this page's own logic runs.
	 *
	 * @return void
	 */
	public function handle_early_actions() {
		if ( ! Capabilities::current_user_can_manage() ) {
			return; // render_page() performs the real capability check and wp_die()s.
		}

		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			$post_action = isset( $_POST['b2bpl_action'] ) ? sanitize_key( wp_unslash( $_POST['b2bpl_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only used to route to a handler; each handler verifies its own nonce via check_admin_referer() before doing anything.

			if ( '' !== $post_action ) {
				$this->handle_post( $post_action );
			}

			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'duplicate' === $action ) {
			$this->handle_duplicate();
			exit;
		}

		if ( 'toggle-status' === $action ) {
			$this->handle_toggle_status();
			exit;
		}
	}

	/**
	 * Routes a POST submission by its `b2bpl_action` value. Always exits
	 * (either via a redirect or, on an unrecognized action, a fallback
	 * one) -- called only from `handle_early_actions()`, before any
	 * output has been sent.
	 *
	 * @param string $post_action Requested action.
	 * @return void
	 */
	private function handle_post( $post_action ) {
		switch ( $post_action ) {
			case 'save-list':
				$this->handle_save();
				break;

			case 'delete-list':
				$this->handle_delete();
				break;

			case 'assign-user':
				$this->handle_assign_user();
				break;

			case 'unassign-user':
				$this->handle_unassign_user();
				break;

			case 'save-group':
				RuleGroupController::instance()->handle_save();
				break;

			case 'delete-group':
				RuleGroupController::instance()->handle_delete();
				break;

			case 'save-settings':
				SettingsController::instance()->handle_save();
				break;

			default:
				wp_safe_redirect( $this->list_url() );
		}

		exit;
	}

	/**
	 * Builds the base URL of the plugin's admin page.
	 *
	 * Public (not private): views render it via `call_user_func( $list_url, ... )`
	 * from outside this class's scope, which PHP only allows for
	 * public/protected visibility regardless of the bound `$this`.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public function list_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) );
	}

	/**
	 * Renders the lists overview screen.
	 *
	 * @return void
	 */
	private function render_list() {
		$lists = $this->lists->all();

		$rows = array();

		foreach ( $lists as $list ) {
			$list['user_count'] = $this->list_users->count_for_list( $list['id'] );
			$rows[]             = $list;
		}

		View::render(
			'lists-table',
			array(
				'lists'    => $rows,
				'list_url' => array( $this, 'list_url' ),
			)
		);
	}

	/**
	 * Renders the built-in user guide (`action=help`). Read-only: it never
	 * touches data, so it needs no nonce and no POST branch of its own.
	 *
	 * @return void
	 */
	private function render_help() {
		View::render(
			'help',
			array(
				'list_url'     => array( $this, 'list_url' ),
				'settings_url' => SettingsController::instance()->settings_url(),
			)
		);
	}

	/**
	 * Renders the create/edit list form (details + user assignment tab).
	 *
	 * @param int|null $id List id, or null when creating.
	 * @return void
	 */
	private function render_form( $id ) {
		$list = null;

		if ( $id ) {
			$list = $this->lists->find( $id );

			if ( ! $list ) {
				wp_die( esc_html__( 'The list could not be found.', 'b2b-price-lists' ) );
			}
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'details'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $tab, array( 'details', 'users', 'groups' ), true ) ) {
			$tab = 'details';
		}

		if ( $list && 'groups' === $tab ) {
			RuleGroupController::instance()->render_tab( (int) $list['id'] );

			return;
		}

		$assigned_users = array();

		if ( $list ) {
			foreach ( $this->list_users->get_user_ids_for_list( $list['id'] ) as $user_id ) {
				$user = get_userdata( $user_id );

				if ( $user ) {
					$assigned_users[] = $user;
				}
			}
		}

		View::render(
			'list-form',
			array(
				'list'           => $list,
				'pending'        => $this->consume_pending_list_input(),
				'tab'            => $tab,
				'assigned_users' => $assigned_users,
				'list_url'       => array( $this, 'list_url' ),
				'nonce_field'    => Nonces::ADMIN_ACTION,
				'nonce_name'     => Nonces::FIELD_NAME,
			)
		);
	}

	/**
	 * Handles the create/edit form submission.
	 *
	 * @return void
	 */
	private function handle_save() {
		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug    = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$desc    = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : ListService::STATUS_INACTIVE;
		$user_id = get_current_user_id();

		// List-wide offer: kept as the raw submitted string here so
		// `Domain\ListService` can reject an out-of-range value with a
		// visible message instead of silently coercing it to "no offer".
		$discount = isset( $_POST['discount_percent'] ) && is_scalar( $_POST['discount_percent'] )
			? sanitize_text_field( wp_unslash( $_POST['discount_percent'] ) )
			: '';

		// T026: optional field, mirroring how the webhook (1.5.0) identifies
		// the same list. Trimmed here, not `sanitize_key()`'d -- an external
		// system's own code may legitimately contain characters `sanitize_key()`
		// would strip (uppercase letters, for instance).
		$external_code = isset( $_POST['external_code'] ) ? sanitize_text_field( wp_unslash( $_POST['external_code'] ) ) : '';

		$input = array(
			'name'             => $name,
			'slug'             => $slug,
			'description'      => $desc,
			'status'           => $status,
			'discount_percent' => $discount,
			'external_code'    => $external_code,
		);

		if ( $id ) {
			$input['updated_by'] = $user_id;
			$result              = $this->list_service->update( $id, $input );
		} else {
			$input['created_by'] = $user_id;
			$result              = $this->list_service->create( $input );
			$id                  = is_wp_error( $result ) ? 0 : $result;
		}

		if ( is_wp_error( $result ) ) {
			// Same treatment the rule form already gets: a rejected
			// submission is re-shown with what the admin actually typed, not
			// with the stored values -- otherwise the offending percentage
			// silently disappears and the error message has nothing to point at.
			$this->store_pending_list_input( $input );
			$this->redirect_with_error(
				$this->list_url(
					$id ? array(
						'action' => 'edit',
						'id'     => $id,
					) : array( 'action' => 'new' )
				),
				$result
			);

			return;
		}

		wp_safe_redirect(
			$this->list_url(
				array(
					'action'       => 'edit',
					'id'           => $id,
					'b2bpl_notice' => 'saved',
				)
			)
		);
	}

	/**
	 * Renders the deletion confirmation screen (informs the
	 * number of affected users/products before the destructive action can
	 * be confirmed).
	 *
	 * @param int $id List id.
	 * @return void
	 */
	private function render_delete_confirmation( $id ) {
		$list = $this->lists->find( $id );

		if ( ! $list ) {
			wp_die( esc_html__( 'The list could not be found.', 'b2b-price-lists' ) );
		}

		$counts = $this->list_service->get_affected_counts( $id );

		View::render(
			'delete-confirm',
			array(
				'list'        => $list,
				'counts'      => $counts,
				'list_url'    => array( $this, 'list_url' ),
				'nonce_field' => Nonces::ADMIN_ACTION,
				'nonce_name'  => Nonces::FIELD_NAME,
			)
		);
	}

	/**
	 * Handles the confirmed deletion POST.
	 *
	 * @return void
	 */
	private function handle_delete() {
		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect(
				$this->list_url(
					array(
						'action' => 'delete',
						'id'     => $id,
					)
				)
			);

			return;
		}

		$result = $this->list_service->delete( $id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error(
				$this->list_url(
					array(
						'action' => 'delete',
						'id'     => $id,
					)
				),
				$result
			);

			return;
		}

		wp_safe_redirect( $this->list_url( array( 'b2bpl_notice' => 'deleted' ) ) );
	}

	/**
	 * Handles a "duplicate" link click (GET + nonce -- not itself
	 * destructive; the duplicate is created inactive by design).
	 *
	 * @return void
	 */
	private function handle_duplicate() {
		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = $this->list_service->duplicate( $id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $this->list_url(), $result );

			return;
		}

		wp_safe_redirect(
			$this->list_url(
				array(
					'action'       => 'edit',
					'id'           => $result,
					'b2bpl_notice' => 'duplicated',
				)
			)
		);
	}

	/**
	 * Handles a "toggle status" link click.
	 *
	 * @return void
	 */
	private function handle_toggle_status() {
		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list = $this->lists->find( $id );

		if ( ! $list ) {
			wp_die( esc_html__( 'The list could not be found.', 'b2b-price-lists' ) );
		}

		$new_status = ListService::STATUS_ACTIVE === $list['status'] ? ListService::STATUS_INACTIVE : ListService::STATUS_ACTIVE;

		$result = $this->list_service->update(
			$id,
			array(
				'name'             => $list['name'],
				'slug'             => $list['slug'],
				'description'      => $list['description'],
				'status'           => $new_status,
				// Carried over verbatim: activating/deactivating a list must
				// never quietly drop the offer configured on it.
				'discount_percent' => $list['discount_percent'] ?? null,
				'updated_by'       => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $this->list_url(), $result );

			return;
		}

		wp_safe_redirect( $this->list_url( array( 'b2bpl_notice' => 'status-updated' ) ) );
	}

	/**
	 * Handles assigning a user to a list from the list editor. If the
	 * target user already belongs to a different list, a confirmation
	 * step is required before the reassignment is applied (mirrors the
	 * explicit-confirmation pattern used for list deletion).
	 *
	 * @return void
	 */
	private function handle_assign_user() {
		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$list_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$query   = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$confirm = ! empty( $_POST['confirm'] );

		$user = $this->find_user( $query );

		if ( ! $user ) {
			$this->redirect_with_error(
				$this->list_url(
					array(
						'action' => 'edit',
						'id'     => $list_id,
						'tab'    => 'users',
					)
				),
				new WP_Error( 'b2bpl_user_not_found', __( 'No user matched that ID, username or email.', 'b2b-price-lists' ) )
			);

			return;
		}

		$existing_list_id = $this->list_users->get_list_id_for_user( $user->ID );
		$existing_list    = $existing_list_id ? $this->lists->find( $existing_list_id ) : null;

		// a user parked on an INACTIVE list carries no
		// live pricing rule ( fallback already applies to them) --
		// moving them to a new active list is always safe and never
		// competes with an active assignment, so it proceeds automatically,
		// without the "are you sure" confirmation step. A user currently on
		// an ACTIVE list still requires that explicit confirmation, exactly
		// as before: at most one ACTIVE assignment must ever compete for
		// the same user at a time.
		$reassigning_from_inactive = $existing_list_id
			&& (int) $existing_list_id !== $list_id
			&& $existing_list
			&& ListService::STATUS_ACTIVE !== $existing_list['status'];

		if ( $existing_list_id && (int) $existing_list_id !== $list_id && ! $reassigning_from_inactive && ! $confirm ) {
			$this->redirect_with_error(
				$this->list_url(
					array(
						'action'             => 'edit',
						'id'                 => $list_id,
						'tab'                => 'users',
						'b2bpl_pending_user' => $user->ID,
					)
				),
				new WP_Error(
					'b2bpl_reassign_confirm',
					sprintf(
						/* translators: 1: user display name, 2: current list name. */
						__( '%1$s is already assigned to the list "%2$s". Confirm below to move them to this list instead.', 'b2b-price-lists' ),
						$user->display_name,
						$existing_list ? $existing_list['name'] : (string) $existing_list_id
					)
				)
			);

			return;
		}

		$result = $this->assignment_service->assign( $user->ID, $list_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error(
				$this->list_url(
					array(
						'action' => 'edit',
						'id'     => $list_id,
						'tab'    => 'users',
					)
				),
				$result
			);

			return;
		}

		if ( $reassigning_from_inactive ) {
			set_transient(
				'b2bpl_admin_notice_' . get_current_user_id(),
				sprintf(
					/* translators: 1: user display name, 2: previous (inactive) list name. */
					__( '%1$s was moved here automatically -- their previous list, "%2$s", is inactive.', 'b2b-price-lists' ),
					$user->display_name,
					$existing_list ? $existing_list['name'] : (string) $existing_list_id
				),
				60
			);
		}

		wp_safe_redirect(
			$this->list_url(
				array(
					'action'       => 'edit',
					'id'           => $list_id,
					'tab'          => 'users',
					'b2bpl_notice' => $reassigning_from_inactive ? 'user-reassigned' : 'user-assigned',
				)
			)
		);
	}

	/**
	 * Handles removing a user from a list from the list editor.
	 *
	 * @return void
	 */
	private function handle_unassign_user() {
		check_admin_referer( Nonces::ADMIN_ACTION, Nonces::FIELD_NAME );

		$list_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		$this->assignment_service->unassign( $user_id );

		wp_safe_redirect(
			$this->list_url(
				array(
					'action'       => 'edit',
					'id'           => $list_id,
					'tab'          => 'users',
					'b2bpl_notice' => 'user-unassigned',
				)
			)
		);
	}

	/**
	 * Persists a rejected list submission for one request, so the form can be
	 * re-rendered with the admin's own input instead of the stored values.
	 *
	 * @param array $input Already-sanitized list input.
	 * @return void
	 */
	private function store_pending_list_input( array $input ) {
		set_transient( 'b2bpl_pending_list_' . get_current_user_id(), $input, 60 );
	}

	/**
	 * Reads and clears the pending list input left by a failed submission.
	 *
	 * @return array|null
	 */
	private function consume_pending_list_input() {
		$key   = 'b2bpl_pending_list_' . get_current_user_id();
		$value = get_transient( $key );

		if ( false !== $value ) {
			delete_transient( $key );

			return is_array( $value ) ? $value : null;
		}

		return null;
	}

	/**
	 * Resolves a user from an ID, username or email string.
	 *
	 * @param string $query Raw user-supplied identifier.
	 * @return \WP_User|false
	 */
	private function find_user( $query ) {
		$query = trim( $query );

		if ( '' === $query ) {
			return false;
		}

		if ( is_numeric( $query ) ) {
			$user = get_user_by( 'id', (int) $query );

			if ( $user ) {
				return $user;
			}
		}

		if ( is_email( $query ) ) {
			$user = get_user_by( 'email', $query );

			if ( $user ) {
				return $user;
			}
		}

		return get_user_by( 'login', $query );
	}

	/**
	 * Redirects to a target URL with a `WP_Error` message carried via a
	 * one-time transient (avoids leaking arbitrary text through the URL).
	 *
	 * @param string   $url   Target URL.
	 * @param WP_Error $error Error to display on the next render.
	 * @return void
	 */
	private function redirect_with_error( $url, WP_Error $error ) {
		set_transient( 'b2bpl_admin_error_' . get_current_user_id(), $error->get_error_message(), 60 );
		wp_safe_redirect( add_query_arg( 'b2bpl_notice', 'error', $url ) );
	}

	/**
	 * Reads and clears the one-time error message set by
	 * `redirect_with_error()`, if any.
	 *
	 * @return string
	 */
	public static function consume_error_notice() {
		$key   = 'b2bpl_admin_error_' . get_current_user_id();
		$value = get_transient( $key );

		if ( false !== $value ) {
			delete_transient( $key );
		}

		return false === $value ? '' : (string) $value;
	}

	/**
	 * Reads and clears the one-time informational message set for the
	 * `user-reassigned` notice -- same one-time
	 * transient pattern as `consume_error_notice()`, kept as a separate key
	 * since this is a success message, never an error.
	 *
	 * @return string
	 */
	public static function consume_admin_notice() {
		$key   = 'b2bpl_admin_notice_' . get_current_user_id();
		$value = get_transient( $key );

		if ( false !== $value ) {
			delete_transient( $key );
		}

		return false === $value ? '' : (string) $value;
	}
}
