<?php
/**
 * View: "Integration -> Log" (T041), the paginated, filterable list of
 * every webhook request received.
 *
 * Expected variables: $filters (array), $rows (array), $total (int),
 * $page (int), $pages (int), $statuses (string[]), $endpoints (string[]),
 * $stuck_count (int), $can_export (bool), $detail_url (callable),
 * $logs_url (callable), $retry_queue_nonce_action (string),
 * $retry_queue_action_url (string).
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Admin\IntegrationLogController;
use B2BPriceLists\Admin\Menu;
use B2BPriceLists\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$b2bpl_date_format = get_option( 'date_format', 'Y-m-d' );
$b2bpl_time_format = get_option( 'time_format', 'H:i' );
$b2bpl_notice      = isset( $_GET['b2bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['b2bpl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap b2bpl-admin b2bpl-integration">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'B2B Price Lists — Integration', 'b2b-price-lists' ),
			'subtitle' => __( 'Everything the outside system has sent to this store', 'b2b-price-lists' ),
			'icon'     => 'dashicons-rest-api',
		)
	);
	?>
	<hr class="wp-header-end">

	<?php UI::main_nav( 'integration' ); ?>

	<?php if ( 'queue-retried' === $b2bpl_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The queue was checked and any pending batch was re-enqueued.', 'b2b-price-lists' ); ?></p></div>
	<?php endif; ?>

	<?php
	UI::tabs(
		array(
			array(
				'label'  => __( 'Log', 'b2b-price-lists' ),
				'url'    => call_user_func( $logs_url ),
				'icon'   => 'dashicons-list-view',
				'active' => true,
			),
			array(
				'label' => __( 'Settings', 'b2b-price-lists' ),
				'url'   => call_user_func( $logs_url, array( 'tab' => 'settings' ) ),
				'icon'  => 'dashicons-admin-generic',
			),
			array(
				'label' => __( 'Usage guide', 'b2b-price-lists' ),
				'url'   => call_user_func( $logs_url, array( 'tab' => 'guide' ) ),
				'icon'  => 'dashicons-book',
			),
		),
		__( 'Integration sections', 'b2b-price-lists' )
	);
	?>

	<?php
	require __DIR__ . '/integration-stuck-notice.php';
	?>

	<?php
	UI::hero(
		esc_html__( 'Every request the webhook received, accepted or refused, with its result. Nothing here is guessed: everything shown was recorded exactly as it happened.', 'b2b-price-lists' ),
		'dashicons-rest-api',
		true
	);
	?>

	<?php UI::card_open( __( 'Narrow down the list', 'b2b-price-lists' ), 'dashicons-filter', 'teal' ); ?>
	<form method="get" class="b2bpl-integration-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( IntegrationLogController::PAGE_SLUG ); ?>">
		<input type="hidden" name="tab" value="logs">

		<div>
			<label for="b2bpl-int-from"><?php esc_html_e( 'From', 'b2b-price-lists' ); ?></label>
			<input type="date" id="b2bpl-int-from" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>">
		</div>
		<div>
			<label for="b2bpl-int-to"><?php esc_html_e( 'To', 'b2b-price-lists' ); ?></label>
			<input type="date" id="b2bpl-int-to" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>">
		</div>
		<div>
			<label for="b2bpl-int-endpoint"><?php esc_html_e( 'Endpoint', 'b2b-price-lists' ); ?></label>
			<select id="b2bpl-int-endpoint" name="endpoint">
				<option value=""><?php esc_html_e( 'Any endpoint', 'b2b-price-lists' ); ?></option>
				<?php foreach ( $endpoints as $b2bpl_endpoint ) : ?>
					<option value="<?php echo esc_attr( $b2bpl_endpoint ); ?>" <?php selected( $filters['endpoint'], $b2bpl_endpoint ); ?>>
						<?php echo esc_html( IntegrationLogController::endpoint_label( $b2bpl_endpoint ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label for="b2bpl-int-status"><?php esc_html_e( 'Status', 'b2b-price-lists' ); ?></label>
			<select id="b2bpl-int-status" name="status">
				<option value=""><?php esc_html_e( 'Any status', 'b2b-price-lists' ); ?></option>
				<?php foreach ( $statuses as $b2bpl_status ) : ?>
					<option value="<?php echo esc_attr( $b2bpl_status ); ?>" <?php selected( $filters['status'], $b2bpl_status ); ?>>
						<?php echo esc_html( IntegrationLogController::status_label( $b2bpl_status ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label for="b2bpl-int-http"><?php esc_html_e( 'HTTP code', 'b2b-price-lists' ); ?></label>
			<input type="number" id="b2bpl-int-http" name="http_status" min="0" value="<?php echo $filters['http_status'] ? esc_attr( (string) $filters['http_status'] ) : ''; ?>">
		</div>
		<div>
			<label for="b2bpl-int-text"><?php esc_html_e( 'Batch id, idempotency key or reference', 'b2b-price-lists' ); ?></label>
			<input type="text" id="b2bpl-int-text" name="text" class="regular-text" value="<?php echo esc_attr( $filters['text'] ); ?>">
		</div>
		<div>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'b2b-price-lists' ); ?></button>
			<a href="<?php echo esc_url( call_user_func( $logs_url ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'b2b-price-lists' ); ?></a>
		</div>
	</form>
	<?php UI::card_close(); ?>

	<?php UI::card_open( __( 'Requests received', 'b2b-price-lists' ), 'dashicons-list-view', 'teal' ); ?>
	<p class="b2bpl-activity__count">
		<?php
		printf(
			/* translators: %s: number of matching requests. */
			esc_html( _n( '%s request', '%s requests', (int) $total, 'b2b-price-lists' ) ),
			esc_html( number_format_i18n( (int) $total ) )
		);
		?>
	</p>

	<table class="wp-list-table widefat striped b2bpl-integration-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Received', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Endpoint', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'User', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'IP', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'HTTP', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Status', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'Items (ok / partial / error)', 'b2b-price-lists' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Detail', 'b2b-price-lists' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="8" class="b2bpl-empty">
						<p><?php esc_html_e( 'No requests recorded for these filters.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $rows as $b2bpl_row ) : ?>
				<?php $b2bpl_received = str_replace( 'T', ' ', (string) $b2bpl_row['received_at'] ); ?>
				<tr>
					<td>
						<strong><?php echo esc_html( mysql2date( $b2bpl_date_format, $b2bpl_received, false ) ); ?></strong>
						<br>
						<span class="b2bpl-muted"><?php echo esc_html( mysql2date( $b2bpl_time_format, $b2bpl_received, false ) ); ?> UTC</span>
					</td>
					<td><?php echo esc_html( IntegrationLogController::endpoint_label( (string) $b2bpl_row['endpoint'] ) ); ?></td>
					<td>
						<?php if ( '' !== (string) $b2bpl_row['auth_user_login'] ) : ?>
							<?php echo esc_html( (string) $b2bpl_row['auth_user_login'] ); ?>
						<?php else : ?>
							<em class="b2bpl-muted"><?php esc_html_e( 'Not authenticated', 'b2b-price-lists' ); ?></em>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) $b2bpl_row['ip'] ); ?></td>
					<td><?php echo esc_html( (string) (int) $b2bpl_row['http_status'] ); ?></td>
					<td>
						<span class="b2bpl-status-pill b2bpl-status-pill--<?php echo esc_attr( (string) $b2bpl_row['status'] ); ?>">
							<?php echo esc_html( IntegrationLogController::status_label( (string) $b2bpl_row['status'] ) ); ?>
						</span>
					</td>
					<td>
						<?php
						printf(
							'%1$s / %2$s / %3$s',
							esc_html( (string) (int) $b2bpl_row['items_success'] ),
							esc_html( (string) (int) $b2bpl_row['items_partial'] ),
							esc_html( (string) (int) $b2bpl_row['items_error'] )
						);
						?>
					</td>
					<td>
						<a href="<?php echo esc_url( call_user_func( $detail_url, $b2bpl_row['id'] ) ); ?>" class="button button-small">
							<?php esc_html_e( 'View', 'b2b-price-lists' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav b2bpl-activity__nav">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => call_user_func( $logs_url, array( 'paged' => '%#%' ) ),
							'format'    => '',
							'current'   => (int) $page,
							'total'     => (int) $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'add_args'  => array_filter(
								array(
									'from'        => $filters['from'],
									'to'          => $filters['to'],
									'endpoint'    => $filters['endpoint'],
									'status'      => $filters['status'],
									'http_status' => $filters['http_status'],
									'text'        => $filters['text'],
								)
							),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>
	<?php UI::card_close(); ?>
</div>
