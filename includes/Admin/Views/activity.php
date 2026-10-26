<?php
/**
 * View: activity log.
 *
 * Expected variables: $filters (array), $entries (array), $total (int),
 * $page (int), $pages (int), $months (array), $authors (array), $lists
 * (array), $actions (string[]), $oldest (string), $next_archive_at (string),
 * $retention_months (int), $can_export (bool), $export_url (string),
 * $archives (array), $archive_url (callable), $page_url (callable).
 *
 * Every stored date is site-local (`current_time( 'mysql' )`), so it is
 * printed with `mysql2date()`, which reads it in the site's time zone.
 * `wp_date( strtotime() )` would read it as UTC and shift it again.
 *
 * Each `$entries` row also carries `source` (`'admin'`|`'webhook'`, default
 * `'admin'`) and `batch_id` (W5): a webhook-sourced entry shows a "Webhook"
 * badge linking to that batch's detail on the "Integration" screen.
 *
 * @package B2BPriceLists\Admin\Views
 */

use B2BPriceLists\Activity\ActivityFormatter;
use B2BPriceLists\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$b2bpl_date_format = get_option( 'date_format', 'Y-m-d' );
$b2bpl_time_format = get_option( 'time_format', 'H:i' );
$b2bpl_has_filters = ( '' !== $filters['from'] || '' !== $filters['to'] || $filters['user_id'] || $filters['list_id'] || '' !== $filters['action'] );

// Figures taken from what the view already received; no extra queries.
$b2bpl_archive_count = count( $archives );
?>
<div class="wrap b2bpl-admin b2bpl-activity">
	<?php
	UI::screen_header(
		array(
			'title'    => __( 'Activity', 'b2b-price-lists' ),
			'subtitle' => __( 'Who changed what in your price lists, and when', 'b2b-price-lists' ),
			'icon'     => 'dashicons-backup',
		)
	);
	?>
	<hr class="wp-header-end">

	<?php UI::main_nav( 'activity' ); ?>

	<?php
	UI::hero(
		sprintf(
			/* translators: %d: number of months of history kept. */
			esc_html( _n( 'Everything anybody changes in this plugin is recorded here, and each entry stays on this list for %d month before it moves to the monthly archives at the bottom of the page.', 'Everything anybody changes in this plugin is recorded here, and each entry stays on this list for %d months before it moves to the monthly archives at the bottom of the page.', (int) $retention_months, 'b2b-price-lists' ) ),
			(int) $retention_months
		),
		'dashicons-backup',
		true
	);
	?>

	<div class="b2bpl-stats">
		<?php
		UI::stat(
			number_format_i18n( (int) $total ),
			__( 'Entries', 'b2b-price-lists' ),
			$b2bpl_has_filters || '' !== $filters['month']
				? __( 'Matching the current filters', 'b2b-price-lists' )
				: __( 'Recorded in the whole log', 'b2b-price-lists' ),
			'dashicons-list-view',
			'teal'
		);

		UI::stat(
			number_format_i18n( $b2bpl_archive_count ),
			__( 'Monthly archives', 'b2b-price-lists' ),
			__( 'Saved before older entries leave the list', 'b2b-price-lists' ),
			'dashicons-archive',
			'blue'
		);

		if ( '' !== $oldest ) {
			UI::stat(
				mysql2date( $b2bpl_date_format, $oldest ),
				__( 'Oldest entry kept', 'b2b-price-lists' ),
				__( 'Still on the list below', 'b2b-price-lists' ),
				'dashicons-clock',
				'amber'
			);
		}
		?>
	</div>

	<?php if ( ! empty( $months ) ) : ?>
		<nav class="b2bpl-months" aria-label="<?php esc_attr_e( 'Filter by month', 'b2b-price-lists' ); ?>">
			<a href="<?php echo esc_url( call_user_func( $page_url ) ); ?>"
				class="b2bpl-months__chip <?php echo '' === $filters['month'] && ! $b2bpl_has_filters ? 'is-current' : ''; ?>">
				<?php esc_html_e( 'All', 'b2b-price-lists' ); ?>
			</a>
			<?php foreach ( $months as $b2bpl_month ) : ?>
				<a href="<?php echo esc_url( call_user_func( $page_url, array( 'month' => $b2bpl_month['month'] ) ) ); ?>"
					class="b2bpl-months__chip <?php echo $filters['month'] === $b2bpl_month['month'] ? 'is-current' : ''; ?>">
					<?php echo esc_html( mysql2date( 'F Y', $b2bpl_month['month'] . '-15 12:00:00' ) ); ?>
					<span class="b2bpl-months__count"><?php echo esc_html( (string) $b2bpl_month['total'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="b2bpl-toolbar">
		<form method="get" class="b2bpl-card b2bpl-activity__filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( \B2BPriceLists\Admin\ActivityController::PAGE_SLUG ); ?>">
			<?php if ( '' !== $filters['month'] ) : ?>
				<input type="hidden" name="month" value="<?php echo esc_attr( $filters['month'] ); ?>">
			<?php endif; ?>

			<div class="b2bpl-activity__filter">
				<label for="b2bpl-from"><?php esc_html_e( 'From', 'b2b-price-lists' ); ?></label>
				<input type="date" id="b2bpl-from" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>">
			</div>

			<div class="b2bpl-activity__filter">
				<label for="b2bpl-to"><?php esc_html_e( 'To', 'b2b-price-lists' ); ?></label>
				<input type="date" id="b2bpl-to" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>">
			</div>

			<div class="b2bpl-activity__filter">
				<label for="b2bpl-user"><?php esc_html_e( 'Who', 'b2b-price-lists' ); ?></label>
				<select id="b2bpl-user" name="user_id">
					<option value="0"><?php esc_html_e( 'Anybody', 'b2b-price-lists' ); ?></option>
					<?php foreach ( $authors as $b2bpl_author ) : ?>
						<option value="<?php echo esc_attr( (string) $b2bpl_author['user_id'] ); ?>" <?php selected( $filters['user_id'], $b2bpl_author['user_id'] ); ?>>
							<?php echo esc_html( '' !== $b2bpl_author['user_name'] ? $b2bpl_author['user_name'] : sprintf( '#%d', $b2bpl_author['user_id'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="b2bpl-activity__filter">
				<label for="b2bpl-list"><?php esc_html_e( 'Price list', 'b2b-price-lists' ); ?></label>
				<select id="b2bpl-list" name="list_id">
					<option value="0"><?php esc_html_e( 'All lists', 'b2b-price-lists' ); ?></option>
					<?php foreach ( $lists as $b2bpl_list ) : ?>
						<option value="<?php echo esc_attr( (string) $b2bpl_list['list_id'] ); ?>" <?php selected( $filters['list_id'], $b2bpl_list['list_id'] ); ?>>
							<?php echo esc_html( '' !== $b2bpl_list['list_name'] ? $b2bpl_list['list_name'] : sprintf( '#%d', $b2bpl_list['list_id'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="b2bpl-activity__filter">
				<label for="b2bpl-event"><?php esc_html_e( 'Action', 'b2b-price-lists' ); ?></label>
				<select id="b2bpl-event" name="b2bpl_event">
					<option value=""><?php esc_html_e( 'Every action', 'b2b-price-lists' ); ?></option>
					<?php foreach ( $actions as $b2bpl_action ) : ?>
						<option value="<?php echo esc_attr( $b2bpl_action ); ?>" <?php selected( $filters['action'], $b2bpl_action ); ?>>
							<?php echo esc_html( ActivityFormatter::action_label( $b2bpl_action ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="b2bpl-activity__filter b2bpl-activity__filter--actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'b2b-price-lists' ); ?></button>
				<?php if ( $b2bpl_has_filters || '' !== $filters['month'] ) : ?>
					<a href="<?php echo esc_url( call_user_func( $page_url ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'b2b-price-lists' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
	</div>

	<?php
	UI::card_open( __( 'Recorded activity', 'b2b-price-lists' ), 'dashicons-list-view', 'teal' );
	?>
	<table class="wp-list-table widefat striped b2bpl-activity-table">
		<thead>
			<tr>
				<th class="b2bpl-activity-table__when"><?php esc_html_e( 'When', 'b2b-price-lists' ); ?></th>
				<th class="b2bpl-activity-table__who"><?php esc_html_e( 'Who', 'b2b-price-lists' ); ?></th>
				<th><?php esc_html_e( 'What changed', 'b2b-price-lists' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $entries ) ) : ?>
				<tr>
					<td colspan="3" class="b2bpl-empty">
						<p><?php esc_html_e( 'No activity recorded for these filters.', 'b2b-price-lists' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $entries as $b2bpl_entry ) : ?>
				<?php
				$b2bpl_created = (string) $b2bpl_entry['created_at'];
				$b2bpl_details = ActivityFormatter::details( $b2bpl_entry );
				$b2bpl_tone    = ActivityFormatter::action_tone( (string) $b2bpl_entry['action'] );
				?>
				<tr>
					<td class="b2bpl-activity-table__when">
						<strong><?php echo esc_html( mysql2date( $b2bpl_date_format, $b2bpl_created ) ); ?></strong>
						<span class="b2bpl-muted"><?php echo esc_html( mysql2date( $b2bpl_time_format, $b2bpl_created ) ); ?></span>
					</td>
					<td class="b2bpl-activity-table__who">
						<?php if ( '' !== (string) $b2bpl_entry['user_name'] ) : ?>
							<strong><?php echo esc_html( $b2bpl_entry['user_name'] ); ?></strong>
						<?php else : ?>
							<em class="b2bpl-muted"><?php esc_html_e( 'System', 'b2b-price-lists' ); ?></em>
						<?php endif; ?>
						<?php if ( (int) $b2bpl_entry['user_id'] > 0 ) : ?>
							<span class="b2bpl-user-id">#<?php echo esc_html( (string) (int) $b2bpl_entry['user_id'] ); ?></span>
						<?php endif; ?>
						<?php if ( 'webhook' === (string) ( $b2bpl_entry['source'] ?? 'admin' ) ) : ?>
							<br>
							<?php if ( ! empty( $b2bpl_entry['batch_id'] ) ) : ?>
								<a class="b2bpl-badge" href="<?php echo esc_url( \B2BPriceLists\Admin\IntegrationLogController::instance()->detail_url_for_batch_id( (string) $b2bpl_entry['batch_id'] ) ); ?>">
									<?php esc_html_e( 'Webhook', 'b2b-price-lists' ); ?>
								</a>
							<?php else : ?>
								<span class="b2bpl-badge"><?php esc_html_e( 'Webhook', 'b2b-price-lists' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td>
						<span class="b2bpl-event b2bpl-event--<?php echo esc_attr( $b2bpl_tone ); ?>">
							<?php echo esc_html( ActivityFormatter::action_label( (string) $b2bpl_entry['action'] ) ); ?>
						</span>
						<span class="b2bpl-event__summary"><?php echo esc_html( ActivityFormatter::summary( $b2bpl_entry ) ); ?></span>

						<?php if ( ! empty( $b2bpl_details ) ) : ?>
							<ul class="b2bpl-event__details">
								<?php foreach ( $b2bpl_details as $b2bpl_detail ) : ?>
									<li><?php echo esc_html( $b2bpl_detail ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
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
							'base'      => call_user_func( $page_url, array( 'paged' => '%#%' ) ),
							'format'    => '',
							'current'   => (int) $page,
							'total'     => (int) $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'add_args'  => array_filter(
								array(
									'month'       => $filters['month'],
									'from'        => $filters['from'],
									'to'          => $filters['to'],
									'user_id'     => $filters['user_id'],
									'list_id'     => $filters['list_id'],
									'b2bpl_event' => $filters['action'],
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

	<?php
	UI::card_open( __( 'Export', 'b2b-price-lists' ), 'dashicons-download', 'blue' );
	?>
	<div class="b2bpl-activity__toolbar">
		<p class="b2bpl-activity__count">
			<?php
			printf(
				/* translators: %s: number of matching entries. */
				esc_html( _n( '%s entry', '%s entries', (int) $total, 'b2b-price-lists' ) ),
				esc_html( number_format_i18n( (int) $total ) )
			);
			?>
		</p>

		<?php if ( $can_export ) : ?>
			<?php
			$b2bpl_export_label = $b2bpl_has_filters
				? sprintf(
					/* translators: %s: number of entries matching the current filters. */
					_n( 'Export this %s entry (CSV)', 'Export these %s entries (CSV)', (int) $total, 'b2b-price-lists' ),
					number_format_i18n( (int) $total )
				)
				: sprintf(
					/* translators: %s: number of entries in the whole log. */
					_n( 'Export the whole history, %s entry (CSV)', 'Export the whole history, %s entries (CSV)', (int) $total, 'b2b-price-lists' ),
					number_format_i18n( (int) $total )
				);
			?>
			<?php if ( (int) $total > 0 ) : ?>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button b2bpl-activity__export">
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<?php echo esc_html( $b2bpl_export_label ); ?>
				</a>
			<?php else : ?>
				<span class="button disabled b2bpl-activity__export" aria-disabled="true">
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<?php esc_html_e( 'Nothing to export', 'b2b-price-lists' ); ?>
				</span>
			<?php endif; ?>
		<?php else : ?>
			<span class="b2bpl-muted b2bpl-activity__export-note">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<?php esc_html_e( 'Only site administrators can export the history.', 'b2b-price-lists' ); ?>
			</span>
		<?php endif; ?>
	</div>
	<?php UI::card_close(); ?>

	<?php
	UI::card_open(
		__( 'Automatic archives', 'b2b-price-lists' ),
		'dashicons-archive',
		'blue',
		array(
			'id'    => 'b2bpl-archives',
			'class' => 'b2bpl-archives',
		)
	);
	?>
	<p class="b2bpl-archives__intro">
		<?php
		printf(
			/* translators: %d: number of months of history kept. */
			esc_html( _n( 'Nothing is deleted without being saved first. Once a day, entries older than %d month are written into the archive of their month and only then removed from the list above. Each archive has the same columns as the export, and archives are never deleted automatically.', 'Nothing is deleted without being saved first. Once a day, entries older than %d months are written into the archive of their month and only then removed from the list above. Each archive has the same columns as the export, and archives are never deleted automatically.', (int) $retention_months, 'b2b-price-lists' ) ),
			(int) $retention_months
		);
		?>
	</p>

	<?php if ( empty( $archives ) ) : ?>
		<p class="b2bpl-archives__empty">
			<?php if ( '' !== $next_archive_at ) : ?>
				<?php
				printf(
					/* translators: %s: date on which the first archive will be created. */
					esc_html__( 'There are no archives yet. The first one will be created on %s, when the oldest entry kept today reaches the limit.', 'b2b-price-lists' ),
					'<strong>' . esc_html( mysql2date( $b2bpl_date_format, $next_archive_at ) ) . '</strong>'
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'There are no archives yet.', 'b2b-price-lists' ); ?>
			<?php endif; ?>
		</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped b2bpl-archives__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Month', 'b2b-price-lists' ); ?></th>
					<th class="num"><?php esc_html_e( 'Entries', 'b2b-price-lists' ); ?></th>
					<th><?php esc_html_e( 'Covers', 'b2b-price-lists' ); ?></th>
					<th><?php esc_html_e( 'Last added to', 'b2b-price-lists' ); ?></th>
					<th class="b2bpl-archives__download"><span class="screen-reader-text"><?php esc_html_e( 'Download', 'b2b-price-lists' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $archives as $b2bpl_archive ) : ?>
					<tr>
						<td><strong><?php echo esc_html( mysql2date( 'F Y', $b2bpl_archive['period'] . '-15 12:00:00' ) ); ?></strong></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $b2bpl_archive['entries'] ) ); ?></td>
						<td>
							<?php
							printf(
								/* translators: 1: date of the oldest archived entry, 2: date of the newest one. */
								esc_html__( '%1$s to %2$s', 'b2b-price-lists' ),
								esc_html( mysql2date( $b2bpl_date_format, $b2bpl_archive['first_at'] ) ),
								esc_html( mysql2date( $b2bpl_date_format, $b2bpl_archive['last_at'] ) )
							);
							?>
						</td>
						<td><?php echo esc_html( mysql2date( $b2bpl_date_format . ' ' . $b2bpl_time_format, $b2bpl_archive['updated_at'] ) ); ?></td>
						<td class="b2bpl-archives__download">
							<?php if ( $can_export ) : ?>
								<a href="<?php echo esc_url( call_user_func( $archive_url, $b2bpl_archive['id'] ) ); ?>" class="button button-small">
									<span class="dashicons dashicons-download" aria-hidden="true"></span>
									<?php esc_html_e( 'Download CSV', 'b2b-price-lists' ); ?>
								</a>
							<?php else : ?>
								<span class="b2bpl-muted"><?php esc_html_e( 'Administrators only', 'b2b-price-lists' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php UI::card_close(); ?>
</div>
