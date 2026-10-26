<?php
/**
 * View: one webhook batch's full detail (T042/T044): metadata, JSON
 * received (formatted and original), response, per-item results, and the
 * event timeline. Every JSON block is pretty-printed and syntax-highlighted
 * by `Webhook\JsonFormatter`, folded inside `<details>`, with a copy button.
 *
 * Expected variables: $row (array), $items (array), $events (array),
 * $can_export (bool), $can_reprocess_batch (bool), $logs_url (callable),
 * $download_url (callable(string $file): string), $reprocess_item_nonce_action
 * (string), $reprocess_batch_nonce_action (string), $has_zip (bool).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\IntegrationLogController;
use B2BPriceLists\Admin\Menu;
use B2BPriceLists\Admin\UI;
use B2BPriceLists\Webhook\JsonFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$b2bpl_date_format = get_option( 'date_format', 'Y-m-d' );
$b2bpl_time_format = get_option( 'time_format', 'H:i' );
$b2bpl_notice      = isset( $_GET['b2bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

/**
 * Prints one metadata-safe UTC datetime, or an em dash when empty.
 *
 * @param string|null $value Stored `Y-m-d H:i:s(.v)` UTC value.
 * @return string Escaped HTML.
 */
$b2bpl_when = static function ( $value ) use ( $b2bpl_date_format, $b2bpl_time_format ) {
	$value = (string) $value;

	if ( '' === $value ) {
		return '—';
	}

	return esc_html( mysql2date( $b2bpl_date_format . ' ' . $b2bpl_time_format, $value, false ) ) . ' UTC';
};
?>
<div class="wrap b2bpl-admin b2bpl-integration">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'B2B Price Lists — Integration', 'b2b-price-lists' ),
			'subtitle' => __( 'One batch, exactly as it arrived and exactly what it did', 'b2b-price-lists' ),
			'icon'     => 'dashicons-rest-api',
			'actions'  => array(
				array(
					'label' => __( 'Back to the log', 'b2b-price-lists' ),
					'url'   => call_user_func( $logs_url ),
					'icon'  => 'dashicons-arrow-left-alt2',
				),
			),
		)
	);
	?>
	<hr class="wp-header-end">

	<?php UI::main_nav( 'integration' ); ?>

	<?php if ( 'reprocessing' === $b2bpl_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Reprocessing was requested. Results will update once the queue picks it up (usually within a minute).', 'b2b-price-lists' ); ?></p></div>
	<?php endif; ?>

	<?php UI::card_open( __( 'Batch', 'b2b-price-lists' ), 'dashicons-networking', 'teal' ); ?>

		<?php
		// Status first, on its own line with the batch's own id: squeezing the
		// id, the status and a sentence-long explanation into one paragraph
		// made all three hard to read.
		?>
		<p class="b2bpl-batch-head">
			<span class="b2bpl-status-pill b2bpl-status-pill--<?php echo esc_attr( (string) $row['status'] ); ?>">
				<?php echo esc_html( IntegrationLogController::status_label( (string) $row['status'] ) ); ?>
			</span>
			<span class="b2bpl-tag b2bpl-tag--code"><?php echo esc_html( (string) $row['batch_id'] ); ?></span>
		</p>

		<?php $b2bpl_explanation = IntegrationLogController::status_explanation( (string) $row['status'] ); ?>
		<?php if ( '' !== $b2bpl_explanation ) : ?>
			<p class="b2bpl-muted b2bpl-batch-explanation"><?php echo esc_html( $b2bpl_explanation ); ?></p>
		<?php endif; ?>

		<?php
		// Item counts, in colour and away from the metadata list: five pills
		// inside a definition list wrapped onto three lines and read as an
		// alarm. A zero stays neutral -- only a count above zero is tinted.
		$b2bpl_counts = array(
			array(
				'value' => (int) $row['items_total'],
				'label' => __( 'total', 'b2b-price-lists' ),
				'tone'  => 'soft',
			),
			array(
				'value' => (int) $row['items_success'],
				'label' => __( 'ok', 'b2b-price-lists' ),
				'tone'  => 'ok',
			),
			array(
				'value' => (int) $row['items_partial'],
				'label' => __( 'partial', 'b2b-price-lists' ),
				'tone'  => 'warn',
			),
			array(
				'value' => (int) $row['items_error'],
				'label' => __( 'error', 'b2b-price-lists' ),
				'tone'  => 'danger',
			),
			array(
				'value' => (int) $row['items_skipped'],
				'label' => __( 'skipped', 'b2b-price-lists' ),
				'tone'  => 'soft',
			),
		);
		?>
		<p class="b2bpl-batch-counts">
			<?php foreach ( $b2bpl_counts as $b2bpl_count ) : ?>
				<span class="b2bpl-tag b2bpl-tag--<?php echo esc_attr( $b2bpl_count['value'] > 0 ? $b2bpl_count['tone'] : 'soft' ); ?>">
					<?php echo esc_html( (string) $b2bpl_count['value'] ); ?> <em><?php echo esc_html( $b2bpl_count['label'] ); ?></em>
				</span>
			<?php endforeach; ?>
		</p>

		<dl class="b2bpl-integration-meta">
			<div>
				<dt><?php esc_html_e( 'Endpoint', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo esc_html( IntegrationLogController::endpoint_label( (string) $row['endpoint'] ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Method', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo esc_html( (string) $row['method'] ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'HTTP code', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo esc_html( (string) (int) $row['http_status'] ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'User', 'b2b-price-lists' ); ?></dt>
				<dd>
					<?php echo '' !== (string) $row['auth_user_login'] ? esc_html( (string) $row['auth_user_login'] ) : '—'; ?>
				</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'IP address', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo esc_html( (string) $row['ip'] ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'User agent', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo esc_html( (string) $row['user_agent'] ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Idempotency key', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo '' !== (string) $row['idempotency_key'] ? esc_html( (string) $row['idempotency_key'] ) : '—'; ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Received', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo $b2bpl_when( $row['received_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $b2bpl_when() already returns escaped HTML. ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Started', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo $b2bpl_when( $row['started_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Finished', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo $b2bpl_when( $row['finished_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Attempts', 'b2b-price-lists' ); ?></dt>
				<dd><?php echo esc_html( (string) (int) $row['attempts'] ); ?></dd>
			</div>
		</dl>
	<?php UI::card_close(); ?>

	<?php UI::card_open( __( 'Data received', 'b2b-price-lists' ), 'dashicons-media-code', 'teal' ); ?>

		<?php if ( null === $row['raw_body'] ) : ?>
			<p class="b2bpl-note b2bpl-note--calm">
				<?php esc_html_e( 'No content is saved for attempts with an incorrect username or password.', 'b2b-price-lists' ); ?>
			</p>
		<?php else : ?>
			<details class="b2bpl-collapsible" open>
				<summary><?php esc_html_e( 'Formatted (indented and highlighted)', 'b2b-price-lists' ); ?></summary>
				<div class="b2bpl-json-toolbar">
					<button type="button" class="button button-small" data-b2bpl-copy-target="b2bpl-json-received-formatted" data-b2bpl-copied-label="<?php esc_attr_e( 'Copied!', 'b2b-price-lists' ); ?>">
						<?php esc_html_e( 'Copy', 'b2b-price-lists' ); ?>
					</button>
				</div>
				<div id="b2bpl-json-received-formatted">
					<?php echo JsonFormatter::highlight_json_string( (string) ( $row['payload'] ?? $row['raw_body'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JsonFormatter escapes every token itself; see its class docblock. ?>
				</div>
			</details>

			<details class="b2bpl-collapsible">
				<summary><?php esc_html_e( 'Original (exactly as received)', 'b2b-price-lists' ); ?></summary>
				<pre class="b2bpl-json-raw"><?php echo esc_html( (string) $row['raw_body'] ); ?></pre>
			</details>

			<?php if ( ! empty( $row['body_truncated'] ) ) : ?>
				<p class="b2bpl-muted"><?php esc_html_e( 'The stored copy was truncated: the body exceeded the maximum size kept in the database.', 'b2b-price-lists' ); ?></p>
			<?php endif; ?>

			<?php if ( $can_export ) : ?>
				<p>
					<a class="button button-small" href="<?php echo esc_url( call_user_func( $download_url, 'received-formatted' ) ); ?>"><?php esc_html_e( 'Download formatted (.json)', 'b2b-price-lists' ); ?></a>
					<a class="button button-small" href="<?php echo esc_url( call_user_func( $download_url, 'received-original' ) ); ?>"><?php esc_html_e( 'Download original', 'b2b-price-lists' ); ?></a>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	<?php UI::card_close(); ?>

	<?php UI::card_open( __( 'Response sent', 'b2b-price-lists' ), 'dashicons-migrate', 'teal' ); ?>

		<?php if ( empty( $row['response_body'] ) ) : ?>
			<p class="b2bpl-muted"><?php esc_html_e( 'No response has been recorded for this batch yet.', 'b2b-price-lists' ); ?></p>
		<?php else : ?>
			<details class="b2bpl-collapsible" open>
				<summary><?php esc_html_e( 'Response (indented and highlighted)', 'b2b-price-lists' ); ?></summary>
				<div class="b2bpl-json-toolbar">
					<button type="button" class="button button-small" data-b2bpl-copy-target="b2bpl-json-response" data-b2bpl-copied-label="<?php esc_attr_e( 'Copied!', 'b2b-price-lists' ); ?>">
						<?php esc_html_e( 'Copy', 'b2b-price-lists' ); ?>
					</button>
				</div>
				<div id="b2bpl-json-response">
					<?php echo JsonFormatter::highlight_json_string( (string) $row['response_body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</details>

			<?php if ( $can_export ) : ?>
				<p><a class="button button-small" href="<?php echo esc_url( call_user_func( $download_url, 'response' ) ); ?>"><?php esc_html_e( 'Download response (.json)', 'b2b-price-lists' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
	<?php UI::card_close(); ?>

	<?php UI::card_open( __( 'Items', 'b2b-price-lists' ), 'dashicons-list-view', 'teal' ); ?>

		<?php if ( $can_reprocess_batch ) : ?>
			<form method="post" action="<?php echo esc_url( call_user_func( $logs_url ) ); ?>" class="b2bpl-inline-form">
				<?php wp_nonce_field( $reprocess_batch_nonce_action ); ?>
				<input type="hidden" name="b2bpl_action" value="reprocess-batch">
				<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Reprocess every failed item of this batch', 'b2b-price-lists' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php if ( empty( $items ) ) : ?>
			<p class="b2bpl-muted"><?php esc_html_e( 'This batch has no items yet (it may still be queued).', 'b2b-price-lists' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '#', 'b2b-price-lists' ); ?></th>
						<th><?php esc_html_e( 'Action', 'b2b-price-lists' ); ?></th>
						<th><?php esc_html_e( 'Reference', 'b2b-price-lists' ); ?></th>
						<th><?php esc_html_e( 'Status', 'b2b-price-lists' ); ?></th>
						<th><?php esc_html_e( 'Result / errors', 'b2b-price-lists' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Reprocess', 'b2b-price-lists' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $b2bpl_item ) : ?>
						<?php $b2bpl_errors = ! empty( $b2bpl_item['errors'] ) ? json_decode( (string) $b2bpl_item['errors'], true ) : array(); ?>
						<tr>
							<td><?php echo esc_html( (string) (int) $b2bpl_item['item_index'] ); ?></td>
							<td>
								<code><?php echo esc_html( (string) $b2bpl_item['action'] ); ?></code>
								<?php if ( '' !== (string) $b2bpl_item['ref'] ) : ?>
									<br><span class="b2bpl-muted"><?php echo esc_html( (string) $b2bpl_item['ref'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo '' !== (string) $b2bpl_item['external_ref'] ? esc_html( (string) $b2bpl_item['external_ref'] ) : '—'; ?></td>
							<td>
								<span class="b2bpl-status-pill b2bpl-status-pill--<?php echo esc_attr( (string) $b2bpl_item['status'] ); ?>">
									<?php echo esc_html( IntegrationLogController::item_status_label( (string) $b2bpl_item['status'] ) ); ?>
								</span>
								<?php if ( (int) $b2bpl_item['attempts'] > 1 ) : ?>
									<br><span class="b2bpl-muted">
										<?php
										printf(
											/* translators: %d: number of processing attempts. */
											esc_html__( '%d attempts', 'b2b-price-lists' ),
											(int) $b2bpl_item['attempts']
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $b2bpl_errors ) && is_array( $b2bpl_errors ) ) : ?>
									<ul class="b2bpl-item-errors">
										<?php foreach ( $b2bpl_errors as $b2bpl_error ) : ?>
											<li>
												<code><?php echo esc_html( (string) ( $b2bpl_error['code'] ?? '' ) ); ?></code>
												— <?php echo esc_html( (string) ( $b2bpl_error['message'] ?? '' ) ); ?>
												<?php if ( ! empty( $b2bpl_error['path'] ) ) : ?>
													<br><span class="b2bpl-muted"><?php echo esc_html( (string) $b2bpl_error['path'] ); ?></span>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php elseif ( ! empty( $b2bpl_item['result'] ) ) : ?>
									<details class="b2bpl-collapsible">
										<summary><?php esc_html_e( 'Result', 'b2b-price-lists' ); ?></summary>
										<?php echo JsonFormatter::highlight_json_string( (string) $b2bpl_item['result'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</details>
								<?php else : ?>
									<span class="b2bpl-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( IntegrationLogController::is_reprocessable_status( (string) $b2bpl_item['status'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( call_user_func( $logs_url ) ); ?>" class="b2bpl-inline-form">
										<?php wp_nonce_field( $reprocess_item_nonce_action ); ?>
										<input type="hidden" name="b2bpl_action" value="reprocess-item">
										<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) (int) $b2bpl_item['id'] ); ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Reprocess', 'b2b-price-lists' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $can_export ) : ?>
				<p><a class="button button-small" href="<?php echo esc_url( call_user_func( $download_url, 'items' ) ); ?>"><?php esc_html_e( 'Download item results (.json)', 'b2b-price-lists' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
	<?php UI::card_close(); ?>

	<?php UI::card_open( __( 'Timeline', 'b2b-price-lists' ), 'dashicons-clock', 'teal' ); ?>

		<?php if ( empty( $events ) ) : ?>
			<p class="b2bpl-muted"><?php esc_html_e( 'No events recorded yet.', 'b2b-price-lists' ); ?></p>
		<?php else : ?>
			<ul class="b2bpl-timeline">
				<?php foreach ( $events as $b2bpl_event ) : ?>
					<li class="b2bpl-timeline__event--<?php echo esc_attr( (string) $b2bpl_event['level'] ); ?>">
						<span class="b2bpl-timeline__when"><?php echo esc_html( mysql2date( $b2bpl_date_format . ' ' . $b2bpl_time_format, (string) $b2bpl_event['created_at'], false ) ); ?></span>
						<code><?php echo esc_html( (string) $b2bpl_event['code'] ); ?></code>
						— <?php echo esc_html( (string) $b2bpl_event['message'] ); ?>
						<?php if ( ! empty( $b2bpl_event['item_id'] ) ) : ?>
							<span class="b2bpl-muted">
								<?php
								printf(
									/* translators: %d: item id. */
									esc_html__( '(item #%d)', 'b2b-price-lists' ),
									(int) $b2bpl_event['item_id']
								);
								?>
							</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( $can_export ) : ?>
				<p>
					<a class="button button-small" href="<?php echo esc_url( call_user_func( $download_url, 'events-txt' ) ); ?>"><?php esc_html_e( 'Download events (.txt)', 'b2b-price-lists' ); ?></a>
					<a class="button button-small" href="<?php echo esc_url( call_user_func( $download_url, 'events-json' ) ); ?>"><?php esc_html_e( 'Download events (.json)', 'b2b-price-lists' ); ?></a>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	<?php UI::card_close(); ?>

	<?php if ( $can_export && $has_zip ) : ?>
		<?php UI::card_open( __( 'Whole batch', 'b2b-price-lists' ), 'dashicons-media-archive', 'teal' ); ?>
			<p><a class="button" href="<?php echo esc_url( call_user_func( $download_url, 'zip' ) ); ?>"><?php esc_html_e( 'Download everything (.zip)', 'b2b-price-lists' ); ?></a></p>
		<?php UI::card_close(); ?>
	<?php elseif ( ! $can_export ) : ?>
		<p class="b2bpl-muted">
			<span class="dashicons dashicons-lock" aria-hidden="true"></span>
			<?php esc_html_e( 'Only site administrators can download the integration log.', 'b2b-price-lists' ); ?>
		</p>
	<?php endif; ?>
</div>
