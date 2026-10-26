<?php
/**
 * View: explicit deletion confirmation screen.
 *
 * Expected variables: $list (array), $counts (array{users:int,products:int}),
 * $list_url (callable), $nonce_field (string), $nonce_name (string).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap b2bpl-admin">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'Delete price list permanently', 'b2b-price-lists' ),
			'subtitle' => __( 'Its customers go back to the store\'s normal prices straight away', 'b2b-price-lists' ),
			'icon'     => 'dashicons-trash',
			'badge'    => array(
				'label' => __( 'Cannot be undone', 'b2b-price-lists' ),
				'tone'  => 'warn',
				'icon'  => 'dashicons-warning',
			),
		)
	);
	?>
	<hr class="wp-header-end">

	<p class="b2bpl-breadcrumb"><a href="<?php echo esc_url( call_user_func( $list_url ) ); ?>">&larr; <?php esc_html_e( 'Back to lists', 'b2b-price-lists' ); ?></a></p>

	<?php
	UI::card_open(
		__( 'This cannot be undone', 'b2b-price-lists' ),
		'dashicons-warning',
		'amber',
		array( 'class' => 'b2bpl-card--danger' )
	);
	?>
		<p>
			<?php
			printf(
				/* translators: %s: list name. */
				esc_html__( 'You are about to permanently delete the list "%s". This cannot be undone.', 'b2b-price-lists' ),
				'<strong>' . esc_html( $list['name'] ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above.
			);
			?>
		</p>
		<ul class="b2bpl-impact">
			<li>
				<?php
				printf(
					/* translators: %d: number of affected users. */
					esc_html( _n( '%d user is currently assigned to this list and will immediately return to standard (B2C) pricing.', '%d users are currently assigned to this list and will immediately return to standard (B2C) pricing.', $counts['users'], 'b2b-price-lists' ) ),
					(int) $counts['users']
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %d: number of affected products. */
					esc_html( _n( '%d product has price rules defined in this list; those rules will be permanently deleted.', '%d products have price rules defined in this list; those rules will be permanently deleted.', $counts['products'], 'b2b-price-lists' ) ),
					(int) $counts['products']
				);
				?>
			</li>
		</ul>
		<p class="b2bpl-note b2bpl-note--calm"><?php esc_html_e( 'Any past order that already used a price from this list is not affected: order history keeps its own independent record.', 'b2b-price-lists' ); ?></p>
	<?php UI::card_close(); ?>

	<form method="post" action="<?php echo esc_url( call_user_func( $list_url ) ); ?>">
		<?php wp_nonce_field( $nonce_field, $nonce_name ); ?>
		<input type="hidden" name="b2bpl_action" value="delete-list">
		<input type="hidden" name="id" value="<?php echo esc_attr( (string) $list['id'] ); ?>">
		<input type="hidden" name="confirm" value="1">
		<p>
			<button type="submit" class="button button-primary b2bpl-button-danger">
				<?php esc_html_e( 'Yes, delete this list permanently', 'b2b-price-lists' ); ?>
			</button>
			<a href="<?php echo esc_url( call_user_func( $list_url ) ); ?>" class="button">
				<?php esc_html_e( 'Cancel', 'b2b-price-lists' ); ?>
			</a>
		</p>
	</form>
</div>
