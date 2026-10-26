<?php
/**
 * Centralized nonce actions for every admin/AJAX endpoint of the plugin.
 *
 * @package B2BPriceLists\Support
 */

namespace B2BPriceLists\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nonces
 */
class Nonces {

	/**
	 * Nonce action for all authenticated admin-post form submissions
	 * (list CRUD, rule group CRUD, user assignment from the list editor).
	 *
	 * @var string
	 */
	const ADMIN_ACTION = 'b2bpl_admin_action';

	/**
	 * Nonce action for the "Lista de precios B2B" field on the WordPress
	 * user profile screen.
	 *
	 * @var string
	 */
	const USER_PROFILE_ACTION = 'b2bpl_user_profile_action';

	/**
	 * Nonce action for the admin-side AJAX product/variation search.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'b2bpl_ajax_action';

	/**
	 * Name of the request field/parameter carrying the nonce value.
	 *
	 * @var string
	 */
	const FIELD_NAME = 'b2bpl_nonce';
}
