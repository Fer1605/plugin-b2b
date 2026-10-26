<?php
/**
 * View: rule group deletion confirmation.
 *
 * Expected variables: $list_id (int), $list (array|null), $group (array),
 * $tab_url (callable), $nonce_field (string), $nonce_name (string).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\UI;
use B2BPriceLists\Admin\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap b2bpl-admin">
	<?php
	View::render(
		'list-context',
		array(
			'list_id' => $list_id,
			'list'    => $list,
		)
	);
	?>

	<?php
	UI::screen_header(
		array(
			'title'    => __( 'Delete rule', 'b2b-price-lists' ),
			'subtitle' => __( 'Check what you are removing before you confirm', 'b2b-price-lists' ),
			'icon'     => 'dashicons-trash',
			'badge'    => array(
				'label' => __( 'Permanent', 'b2b-price-lists' ),
				'tone'  => 'warn',
				'icon'  => 'dashicons-warning',
			),
		)
	);
	?>
	<hr class="wp-header-end">

	<p class="b2bpl-breadcrumb"><a href="<?php echo esc_url( call_user_func( $tab_url, $list_id ) ); ?>">&larr; <?php esc_html_e( 'Back to Products & prices', 'b2b-price-lists' ); ?></a></p>

	<?php
	UI::card_open(
		__( 'This cannot be undone', 'b2b-price-lists' ),
		'dashicons-warning',
		'amber',
		// The danger modifier keeps the red surface and title this screen has
		// always used: the tone only tints the icon.
		array( 'class' => 'b2bpl-card--danger' )
	);
	?>
		<p>
			<?php
			printf(
				/* translators: %s: product label. */
				esc_html__( 'You are about to permanently delete the price rule for "%s", including its price ranges and variation assignments. This cannot be undone.', 'b2b-price-lists' ),
				esc_html( $group['product_label'] )
			);
			?>
		</p>
	<?php UI::card_close(); ?>

	<form method="post" action="<?php echo esc_url( call_user_func( $tab_url, $list_id ) ); ?>">
		<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
		<input type="hidden" name="b2bpl_action" value="delete-group">
		<input type="hidden" name="list_id" value="<?php echo esc_attr( (string) $list_id ); ?>">
		<input type="hidden" name="rule_group_id" value="<?php echo esc_attr( (string) $group['id'] ); ?>">
		<input type="hidden" name="confirm" value="1">
		<p>
			<button type="submit" class="button button-primary b2bpl-button-danger">
				<?php esc_html_e( 'Yes, delete this rule permanently', 'b2b-price-lists' ); ?>
			</button>
			<a href="<?php echo esc_url( call_user_func( $tab_url, $list_id ) ); ?>" class="button">
				<?php esc_html_e( 'Cancel', 'b2b-price-lists' ); ?>
			</a>
		</p>
	</form>
</div>
