<?php
/**
 * Partial: "stuck queue" admin notice (T046), included from both the
 * "Integration -> Log" and "Integration -> Settings" screens.
 *
 * Expected variables: $stuck_count (int), $retry_queue_nonce_action (string),
 * $retry_queue_action_url (string) -- always the "Integration -> Log" URL,
 * regardless of which screen this partial is shown on, so the submission
 * reaches `Admin\IntegrationLogController::handle_early_actions()` (bound to
 * that screen's own `load-{$hook_suffix}`).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $stuck_count ) ) {
	return;
}

UI::card_open(
	__( 'The queue needs a nudge', 'b2b-price-lists' ),
	'dashicons-warning',
	'purple',
	array( 'accent' => true )
);
?>
<div class="b2bpl-stuck-notice">
	<p>
		<?php
		printf(
			esc_html(
				/* translators: %d: number of batches stuck in the queue. */
				_n(
					'%d batch has been waiting in the queue for more than 15 minutes without being processed.',
					'%d batches have been waiting in the queue for more than 15 minutes without being processed.',
					(int) $stuck_count,
					'b2b-price-lists'
				)
			),
			(int) $stuck_count
		);
		?>
	</p>
	<form method="post" action="<?php echo esc_url( $retry_queue_action_url ); ?>">
		<?php wp_nonce_field( $retry_queue_nonce_action ); ?>
		<?php wp_referer_field(); ?>
		<input type="hidden" name="b2bpl_action" value="retry-queue">
		<button type="submit" class="button">
			<?php esc_html_e( 'Retry the queue', 'b2b-price-lists' ); ?>
		</button>
	</form>
</div>
<?php
UI::card_close();
