<?php
namespace SFA\ProductionScheduling\Admin;

use SFA\ProductionScheduling\Database\CapacityRepository;

/**
 * Schedule View Page
 *
 * Displays production schedule calendar and bookings list
 */
class ScheduleView {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 100 ); // Load after SimpleFlow parent menu
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_sfa_prod_search_entry', [ $this, 'ajax_search_entry' ] );
	}

	/**
	 * Add schedule view page to admin menu
	 */
	public function add_menu_page() {
		// Add Production Scheduling under SimpleFlow
		add_submenu_page(
			'simpleflow',
			'Production Scheduling',
			'Production Scheduling',
			'manage_options',
			'sfa-production-schedule',
			[ $this, 'render_schedule_page' ]
		);
	}

	/**
	 * Enqueue assets for schedule page
	 */
	public function enqueue_assets( $hook ) {
		// Hook name for submenu page: {parent_slug}_page_{menu_slug}
		if ( $hook !== 'simpleflow_page_sfa-production-schedule' ) {
			return;
		}

		wp_enqueue_script( 'sfa-prod-calendar' );
		wp_enqueue_style( 'sfa-prod-styles' );
	}

	/**
	 * Render schedule page
	 */
	public function render_schedule_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}

		// Get current month or requested month
		$month = isset( $_GET['month'] ) ? sanitize_text_field( $_GET['month'] ) : current_time( 'Y-m' );

		// Parse month
		$date = new \DateTime( $month . '-01' );
		$year = $date->format( 'Y' );
		$month_num = $date->format( 'm' );

		// Get bookings for this month
		$start_date = $date->format( 'Y-m-01' );
		$end_date = $date->format( 'Y-m-t' );

		$bookings = $this->load_bookings( $start_date, $end_date );

		// Get capacity data
		$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity', 10 );
		$repo = new CapacityRepository();
		$capacity_overrides = $repo->get_range( $start_date, $end_date );

		// Get settings
		$working_days_json = get_option( 'sfa_prod_working_days', wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ) );
		$working_days = json_decode( $working_days_json, true );
		$holidays_json = get_option( 'sfa_prod_holidays', wp_json_encode( [] ) );
		$holidays = json_decode( $holidays_json, true );

		// Calculate all days (invert working days to get off days)
		$all_days = [ 0, 1, 2, 3, 4, 5, 6 ];
		$off_days = array_values( array_diff( $all_days, $working_days ) );

		// Render UI
		?>
		<div class="wrap">
			<h1>Production Schedule</h1>

			<?php $this->render_entry_search(); ?>

			<?php $this->render_month_navigation( $date ); ?>

			<?php $this->render_calendar( $date, $bookings, $daily_capacity, $capacity_overrides, $off_days, $holidays ); ?>

			<hr style="margin: 40px 0;">

			<?php $this->render_bookings_list( $bookings ); ?>
		</div>
		<?php
	}

	/**
	 * Render month navigation
	 */
	private function render_month_navigation( $date ) {
		$prev_month = clone $date;
		$prev_month->modify( '-1 month' );

		$next_month = clone $date;
		$next_month->modify( '+1 month' );

		$current_month = $date->format( 'F Y' );

		?>
		<div style="margin: 20px 0; display: flex; justify-content: space-between; align-items: center;">
			<a href="?page=sfa-production-schedule&month=<?php echo $prev_month->format( 'Y-m' ); ?>" class="button">
				◀ <?php echo $prev_month->format( 'M Y' ); ?>
			</a>

			<h2 style="margin: 0;"><?php echo $current_month; ?></h2>

			<a href="?page=sfa-production-schedule&month=<?php echo $next_month->format( 'Y-m' ); ?>" class="button">
				<?php echo $next_month->format( 'M Y' ); ?> ▶
			</a>
		</div>
		<?php
	}

	/**
	 * Render entry search bar
	 */
	private function render_entry_search() {
		$nonce = wp_create_nonce( 'sfa_prod_search_entry' );
		?>
		<div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
			<div style="display: flex; align-items: center; gap: 10px;">
				<label for="sfa-entry-search" style="font-weight: 600; white-space: nowrap;">Search Entry:</label>
				<input type="number" id="sfa-entry-search" placeholder="Enter Entry ID" min="1" style="width: 200px; padding: 6px 10px;">
				<button type="button" id="sfa-entry-search-btn" class="button button-primary">Search</button>
				<span id="sfa-entry-search-loading" style="display: none; color: #666;">Searching...</span>
			</div>
			<div id="sfa-entry-search-result" style="display: none; margin-top: 15px;"></div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var searchNonce = '<?php echo esc_js( $nonce ); ?>';

			function searchEntry() {
				var entryId = $('#sfa-entry-search').val().trim();
				if (!entryId || parseInt(entryId) <= 0) {
					$('#sfa-entry-search-result').html(
						'<div style="padding: 10px; background: #fee; border-left: 3px solid #c00; color: #c00;">Please enter a valid entry ID.</div>'
					).show();
					return;
				}

				$('#sfa-entry-search-btn').prop('disabled', true);
				$('#sfa-entry-search-loading').show();
				$('#sfa-entry-search-result').hide();

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: 'sfa_prod_search_entry',
						nonce: searchNonce,
						entry_id: entryId
					},
					success: function(response) {
						$('#sfa-entry-search-btn').prop('disabled', false);
						$('#sfa-entry-search-loading').hide();

						if (response.success) {
							displaySearchResult(response.data);
						} else {
							$('#sfa-entry-search-result').html(
								'<div style="padding: 10px; background: #fee; border-left: 3px solid #c00; color: #c00;">' + response.data.message + '</div>'
							).show();
						}
					},
					error: function() {
						$('#sfa-entry-search-btn').prop('disabled', false);
						$('#sfa-entry-search-loading').hide();
						$('#sfa-entry-search-result').html(
							'<div style="padding: 10px; background: #fee; border-left: 3px solid #c00; color: #c00;">Network error. Please try again.</div>'
						).show();
					}
				});
			}

			function displaySearchResult(data) {
				var statusColor = data.booking_status === 'confirmed' ? '#28a745' : (data.booking_status === 'canceled' ? '#dc3545' : '#6c757d');
				var statusBg = data.booking_status === 'confirmed' ? '#d4edda' : (data.booking_status === 'canceled' ? '#f8d7da' : '#e9ecef');

				var html = '<div style="padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">';
				html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
				html += '<h3 style="margin: 0;">Entry #' + data.entry_id + '</h3>';
				html += '<a href="' + data.workflow_url + '" target="_blank" class="button button-small">View Entry</a>';
				html += '</div>';

				html += '<table class="widefat" style="margin: 0;">';
				html += '<tbody>';

				html += '<tr><td style="width: 180px; font-weight: 600;">Form</td>';
				html += '<td>' + (data.form_title || 'Form #' + data.form_id) + '</td></tr>';

				html += '<tr><td style="font-weight: 600;">Installation Date</td>';
				if (data.install_date) {
					var installFormatted = formatDate(data.install_date);
					html += '<td style="font-size: 16px; font-weight: 700; color: #0073aa;">' + installFormatted + '</td>';
				} else {
					html += '<td><em style="color: #999;">Not set</em></td>';
				}
				html += '</tr>';

				if (data.prod_start && data.prod_end) {
					html += '<tr><td style="font-weight: 600;">Production Dates</td>';
					html += '<td>' + formatDate(data.prod_start) + ' - ' + formatDate(data.prod_end) + '</td></tr>';
				}

				html += '<tr><td style="font-weight: 600;">LM Required</td>';
				html += '<td>' + (parseInt(data.lm_required) > 0 ? data.lm_required + ' LM' : '<em style="color: #999;">0 (Install only)</em>') + '</td></tr>';

				html += '<tr><td style="font-weight: 600;">Booking Status</td>';
				html += '<td><span style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: ' + statusBg + '; color: ' + statusColor + '; text-transform: uppercase;">' + data.booking_status + '</span></td></tr>';

				html += '<tr><td style="font-weight: 600;">Entry Status</td>';
				html += '<td>' + data.entry_status + '</td></tr>';

				// Show allocation if available
				if (data.allocation && Object.keys(data.allocation).length > 0) {
					html += '<tr><td style="font-weight: 600; vertical-align: top;">Slot Allocation</td>';
					html += '<td>';
					for (var date in data.allocation) {
						if (data.allocation.hasOwnProperty(date)) {
							html += '<span style="display: inline-block; margin: 2px 4px 2px 0; padding: 3px 8px; background: #e7f5fe; border-radius: 3px; font-size: 12px;">' + formatDate(date) + ': ' + data.allocation[date] + ' slot' + (data.allocation[date] > 1 ? 's' : '') + '</span>';
						}
					}
					html += '</td></tr>';
				}

				html += '</tbody></table>';
				html += '</div>';

				$('#sfa-entry-search-result').html(html).show();
			}

			function formatDate(dateStr) {
				if (!dateStr) return '';
				var parts = dateStr.split('-');
				if (parts.length !== 3) return dateStr;
				var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
				return months[parseInt(parts[1]) - 1] + ' ' + parseInt(parts[2]) + ', ' + parts[0];
			}

			$('#sfa-entry-search-btn').on('click', searchEntry);

			$('#sfa-entry-search').on('keypress', function(e) {
				if (e.which === 13) {
					e.preventDefault();
					searchEntry();
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Render calendar grid
	 */
	private function render_calendar( $date, $bookings, $daily_capacity, $capacity_overrides, $off_days, $holidays ) {
		$days_in_month = (int) $date->format( 't' );
		$first_day_of_week = (int) $date->format( 'w' ); // 0=Sunday

		// Build holiday lookup map (date => label) for quick access
		$holiday_map = [];
		foreach ( $holidays as $holiday ) {
			if ( is_array( $holiday ) && isset( $holiday['date'] ) ) {
				$holiday_map[ $holiday['date'] ] = isset( $holiday['label'] ) ? $holiday['label'] : '';
			} elseif ( is_string( $holiday ) ) {
				// Old format fallback
				$holiday_map[ $holiday ] = '';
			}
		}

		?>
		<table class="widefat" style="width: 100%; border-collapse: collapse; margin: 20px 0;">
			<thead>
				<tr style="background: #f0f0f0;">
					<th style="padding: 10px; text-align: center;">Sun</th>
					<th style="padding: 10px; text-align: center;">Mon</th>
					<th style="padding: 10px; text-align: center;">Tue</th>
					<th style="padding: 10px; text-align: center;">Wed</th>
					<th style="padding: 10px; text-align: center;">Thu</th>
					<th style="padding: 10px; text-align: center;">Fri</th>
					<th style="padding: 10px; text-align: center;">Sat</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$current_day = 1;
				$weeks = ceil( ( $days_in_month + $first_day_of_week ) / 7 );

				for ( $week = 0; $week < $weeks; $week++ ) {
					echo '<tr>';

					for ( $day_of_week = 0; $day_of_week < 7; $day_of_week++ ) {
						if ( ( $week === 0 && $day_of_week < $first_day_of_week ) || $current_day > $days_in_month ) {
							echo '<td style="padding: 10px; border: 1px solid #ddd; background: #fafafa;"></td>';
							continue;
						}

						$day_date = $date->format( 'Y-m-' ) . str_pad( $current_day, 2, '0', STR_PAD_LEFT );

						// Check if off day or holiday
						$is_off_day = in_array( $day_of_week, $off_days, true );
						$is_holiday = isset( $holiday_map[ $day_date ] );
						$holiday_label = $is_holiday ? $holiday_map[ $day_date ] : '';

						// Get capacity for this day
						if ( isset( $bookings[ $day_date ] ) && $bookings[ $day_date ]['historical_capacity'] !== null ) {
							$capacity = $bookings[ $day_date ]['historical_capacity'];
						} elseif ( isset( $capacity_overrides[ $day_date ] ) ) {
							$capacity = $capacity_overrides[ $day_date ];
						} else {
							$capacity = $daily_capacity;
						}

						// Get used capacity
						$used = isset( $bookings[ $day_date ] ) ? $bookings[ $day_date ]['total_lm'] : 0;

						// Calculate percentage
						$percentage = $capacity > 0 ? ( $used / $capacity ) * 100 : 0;

						// Determine color and text
						if ( $is_holiday ) {
							$bg_color = '#e0e0e0';
							$text = $holiday_label ? esc_html( $holiday_label ) : 'Holiday';
						} elseif ( $is_off_day || $capacity === 0 ) {
							$bg_color = '#e0e0e0';
							$text = 'OFF';
						} elseif ( $percentage >= 100 ) {
							$bg_color = '#ffcccc';
							$text = "$used/$capacity FULL LM";
						} elseif ( $percentage >= 70 ) {
							$bg_color = '#fff4cc';
							$text = "$used/$capacity LM";
						} else {
							$bg_color = '#ccffcc';
							$text = "$used/$capacity LM";
						}

						echo '<td style="padding: 10px; border: 1px solid #ddd; background: ' . $bg_color . '; vertical-align: top; height: 80px; position: relative;">';
						echo '<div style="font-weight: bold; margin-bottom: 5px;">' . $current_day . '</div>';
						echo '<div style="font-size: 12px;">' . $text . '</div>';

						if ( isset( $bookings[ $day_date ] ) && ! empty( $bookings[ $day_date ]['entries'] ) ) {
							$entry_count = count( $bookings[ $day_date ]['entries'] );
							echo '<div class="sfa-day-entries" style="font-size: 11px; color: #0073aa; margin-top: 3px; cursor: pointer; position: relative;">';
							echo '<span style="text-decoration: underline dotted;">';
							echo $entry_count . ' order' . ( $entry_count > 1 ? 's' : '' );
							echo '</span>';

							// Tooltip with entry details
							echo '<div class="sfa-entries-tooltip" style="display: none; position: absolute; z-index: 1000; background: white; border: 1px solid #ccc; box-shadow: 0 2px 8px rgba(0,0,0,0.15); padding: 0; min-width: 220px; left: 0; top: 20px; border-radius: 3px; overflow: hidden;">';
							echo '<div style="font-weight: bold; padding: 8px 10px; background: #f8f8f8; border-bottom: 1px solid #eee;">Orders on ' . date( 'M j', strtotime( $day_date ) ) . ':</div>';
							foreach ( $bookings[ $day_date ]['entries'] as $entry_info ) {
								$workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry_info['form_id'] . '&lid=' . $entry_info['entry_id'];
								$form_title = isset( $entry_info['form_title'] ) ? $entry_info['form_title'] : '';
								$is_date_only = isset( $entry_info['is_date_only'] ) && $entry_info['is_date_only'];
								echo '<a href="' . esc_url( $workflow_url ) . '" target="_blank" class="sfa-tooltip-entry" style="display: block; padding: 6px 10px; border-bottom: 1px solid #f0f0f0; color: #0073aa; text-decoration: none; cursor: pointer;">';
								echo '<strong>#' . $entry_info['entry_id'] . '</strong>';
								if ( $is_date_only ) {
									echo ' - <em style="color: #666;">Install only</em>';
								} else {
									echo ' - ' . $entry_info['lm_on_date'] . ' slot' . ( $entry_info['lm_on_date'] > 1 ? 's' : '' );
								}
								if ( $form_title ) {
									echo ' <span style="color: #888;">(' . esc_html( $form_title ) . ')</span>';
								}
								echo '</a>';
							}
							echo '</div>';
							echo '</div>';
						}

						echo '</td>';

						$current_day++;
					}

					echo '</tr>';
				}
				?>
			</tbody>
		</table>

		<div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #0073aa;">
			<strong>Legend:</strong>
			<span style="display: inline-block; margin-left: 20px; padding: 5px 10px; background: #ccffcc;">Available (&lt;70%)</span>
			<span style="display: inline-block; margin-left: 10px; padding: 5px 10px; background: #fff4cc;">Filling Up (70-99%)</span>
			<span style="display: inline-block; margin-left: 10px; padding: 5px 10px; background: #ffcccc;">Full (100%)</span>
			<span style="display: inline-block; margin-left: 10px; padding: 5px 10px; background: #e0e0e0;">Off Day</span>
		</div>
		<?php
	}

	/**
	 * Render bookings list
	 */
	private function render_bookings_list( $bookings ) {
		?>
		<h2>Production Bookings</h2>

		<?php if ( empty( $bookings ) ): ?>
			<p>No bookings for this month.</p>
		<?php else: ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Entry ID</th>
						<th>LM</th>
						<th>Production Dates</th>
						<th>Installation Date</th>
						<th>Booked By</th>
						<th>Booked At</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$all_entries = [];

					foreach ( $bookings as $date => $day_data ) {
						foreach ( $day_data['entries'] as $entry_data ) {
							$entry_id = $entry_data['entry_id'];
							if ( ! isset( $all_entries[ $entry_id ] ) ) {
								$all_entries[ $entry_id ] = $entry_data;
							}
						}
					}

					foreach ( $all_entries as $entry_data ):
						$user = get_userdata( $entry_data['booked_by'] );
						$username = $user ? $user->display_name : 'Unknown';
						$is_date_only = isset( $entry_data['is_date_only'] ) && $entry_data['is_date_only'];

						// Build workflow-inbox URL
						$workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry_data['form_id'] . '&lid=' . $entry_data['entry_id'];
						?>
						<tr>
							<td><a href="<?php echo esc_url( $workflow_url ); ?>" target="_blank">
								#<?php echo $entry_data['entry_id']; ?>
							</a></td>
							<td>
								<?php if ( $is_date_only ): ?>
									<em style="color: #666;">Install only</em>
								<?php else: ?>
									<?php echo $entry_data['lm_required']; ?> LM
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $is_date_only || empty( $entry_data['prod_start'] ) ): ?>
									<em style="color: #999;">N/A</em>
								<?php else: ?>
									<?php echo date( 'M j', strtotime( $entry_data['prod_start'] ) ); ?> - <?php echo date( 'M j, Y', strtotime( $entry_data['prod_end'] ) ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo date( 'M j, Y', strtotime( $entry_data['install_date'] ) ); ?></td>
							<td><?php echo esc_html( $username ); ?></td>
							<td><?php echo $entry_data['booked_at'] ? date( 'M j, Y g:i a', strtotime( $entry_data['booked_at'] ) ) : '-'; ?></td>
							<td>
								<?php
								$status = $entry_data['status'];
								$status_color = 'confirmed' === $status ? '#28a745' : ( 'canceled' === $status ? '#dc3545' : '#6c757d' );
								$status_bg = 'confirmed' === $status ? '#d4edda' : ( 'canceled' === $status ? '#f8d7da' : '#e9ecef' );
								?>
								<span style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; text-transform: uppercase;">
									<?php echo esc_html( $status ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * AJAX handler: Search for an entry and return its installation date
	 */
	public function ajax_search_entry() {
		check_ajax_referer( 'sfa_prod_search_entry', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;

		if ( ! $entry_id ) {
			wp_send_json_error( [ 'message' => 'Please enter a valid entry ID.' ] );
		}

		// Get the entry
		$entry = \GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $entry ) ) {
			wp_send_json_error( [ 'message' => 'Entry #' . $entry_id . ' not found.' ] );
		}

		// Get installation date from entry meta
		$install_date = gform_get_meta( $entry_id, '_install_date' );
		$prod_start = gform_get_meta( $entry_id, '_prod_start_date' );
		$prod_end = gform_get_meta( $entry_id, '_prod_end_date' );
		$lm_required = gform_get_meta( $entry_id, '_prod_lm_required' );
		$booking_status = gform_get_meta( $entry_id, '_prod_booking_status' );
		$allocation = gform_get_meta( $entry_id, '_prod_slots_allocation' );

		// Get form title
		$form = \GFAPI::get_form( $entry['form_id'] );
		$form_title = is_array( $form ) ? rgar( $form, 'title' ) : '';

		// Build workflow URL
		$workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry_id;

		$data = [
			'entry_id'       => $entry_id,
			'form_id'        => $entry['form_id'],
			'form_title'     => $form_title,
			'install_date'   => $install_date ?: '',
			'prod_start'     => $prod_start ?: '',
			'prod_end'       => $prod_end ?: '',
			'lm_required'    => $lm_required ?: '0',
			'booking_status' => $booking_status ?: 'none',
			'allocation'     => $allocation ? json_decode( $allocation, true ) : [],
			'entry_status'   => $entry['status'],
			'workflow_url'   => $workflow_url,
		];

		wp_send_json_success( $data );
	}

	/**
	 * Load bookings for date range using STORED allocation
	 *
	 * Uses _prod_slots_allocation which was calculated by the Scheduler
	 * respecting capacity limits and existing bookings.
	 * Also includes date-only bookings (0 LM entries with just installation date).
	 */
	private function load_bookings( $start_date, $end_date ) {
		global $wpdb;

		// Query all entries with production bookings that overlap this date range
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, meta_key, meta_value
				FROM {$wpdb->prefix}gf_entry_meta
				WHERE meta_key IN ('_prod_lm_required', '_prod_slots_allocation', '_prod_start_date', '_prod_end_date', '_install_date', '_prod_booking_status', '_prod_booked_at', '_prod_booked_by', '_prod_daily_capacity_at_booking')
				AND entry_id IN (
					SELECT DISTINCT em.entry_id
					FROM {$wpdb->prefix}gf_entry_meta em
					INNER JOIN {$wpdb->prefix}gf_entry_meta start_meta
						ON em.entry_id = start_meta.entry_id
						AND start_meta.meta_key = '_prod_start_date'
					LEFT JOIN {$wpdb->prefix}gf_entry_meta end_meta
						ON em.entry_id = end_meta.entry_id
						AND end_meta.meta_key = '_prod_end_date'
					INNER JOIN {$wpdb->prefix}gf_entry e ON em.entry_id = e.id
					WHERE em.meta_key = '_prod_slots_allocation'
					AND start_meta.meta_value <= %s
					AND (end_meta.meta_value IS NULL OR end_meta.meta_value >= %s)
					AND e.status = 'active'
				)
				ORDER BY entry_id",
				$end_date,
				$start_date
			),
			ARRAY_A
		);

		// Query date-only bookings (0 LM entries with just installation date, no production allocation)
		$date_only_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, meta_key, meta_value
				FROM {$wpdb->prefix}gf_entry_meta
				WHERE meta_key IN ('_prod_lm_required', '_prod_slots_allocation', '_prod_start_date', '_prod_end_date', '_install_date', '_prod_booking_status', '_prod_booked_at', '_prod_booked_by', '_prod_daily_capacity_at_booking')
				AND entry_id IN (
					SELECT DISTINCT inst.entry_id
					FROM {$wpdb->prefix}gf_entry_meta inst
					INNER JOIN {$wpdb->prefix}gf_entry e ON inst.entry_id = e.id
					LEFT JOIN {$wpdb->prefix}gf_entry_meta lm
						ON inst.entry_id = lm.entry_id
						AND lm.meta_key = '_prod_lm_required'
					WHERE inst.meta_key = '_install_date'
					AND inst.meta_value >= %s
					AND inst.meta_value <= %s
					AND (lm.meta_value IS NULL OR lm.meta_value = '' OR lm.meta_value = '0')
					AND e.status = 'active'
				)
				ORDER BY entry_id",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		// Merge date-only results into main results
		$results = array_merge( $results, $date_only_results );

		// Group by entry ID
		$entries = [];
		foreach ( $results as $row ) {
			$entry_id = $row['entry_id'];
			if ( ! isset( $entries[ $entry_id ] ) ) {
				$entries[ $entry_id ] = [ 'entry_id' => $entry_id ];
			}
			$key = str_replace( '_prod_', '', str_replace( '_install_', 'install_', $row['meta_key'] ) );
			$entries[ $entry_id ][ $key ] = $row['meta_value'];
		}

		// Organize by allocation date
		$bookings = [];
		$form_title_cache = [];

		foreach ( $entries as $entry_id => $entry_data ) {
			// Get form ID and entry creator for entry
			$entry_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT form_id, created_by FROM {$wpdb->prefix}gf_entry WHERE id = %d",
				$entry_id
			), ARRAY_A );
			$form_id = $entry_row ? $entry_row['form_id'] : null;
			$entry_created_by = $entry_row ? (int) $entry_row['created_by'] : 0;

			// Get form title (cached)
			if ( $form_id && ! isset( $form_title_cache[ $form_id ] ) ) {
				$form_obj = \GFAPI::get_form( $form_id );
				$form_title_cache[ $form_id ] = is_array( $form_obj ) ? rgar( $form_obj, 'title' ) : '';
			}
			$form_title = $form_id ? ( $form_title_cache[ $form_id ] ?? '' ) : '';

			// Get booking status (default to confirmed for backwards compatibility)
			$booking_status = isset( $entry_data['booking_status'] ) ? $entry_data['booking_status'] : 'confirmed';

			// Get capacity at time of booking
			$capacity_at_booking = isset( $entry_data['daily_capacity_at_booking'] ) ? (int) $entry_data['daily_capacity_at_booking'] : null;

			// Sync booking status with GravityFlow workflow status
			$workflow_status = gform_get_meta( $entry_id, 'workflow_final_status' );
			if ( 'cancelled' === $workflow_status || 'canceled' === $workflow_status ) {
				if ( 'canceled' !== $booking_status ) {
					gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
					$booking_status = 'canceled';
				}
			}

			// Skip canceled bookings
			if ( 'canceled' === $booking_status ) {
				continue;
			}

			// Check if this is a date-only booking (0 LM, no allocation)
			$is_date_only = empty( $entry_data['slots_allocation'] ) || $entry_data['slots_allocation'] === '[]';
			$lm_required = isset( $entry_data['lm_required'] ) ? (int) $entry_data['lm_required'] : 0;

			if ( $is_date_only && isset( $entry_data['install_date'] ) && $entry_data['install_date'] ) {
				// Date-only booking: show at installation date with 0 LM
				$date = $entry_data['install_date'];

				if ( ! isset( $bookings[ $date ] ) ) {
					$bookings[ $date ] = [
						'total_lm' => 0,
						'entries' => [],
						'historical_capacity' => null,
					];
				}

				$bookings[ $date ]['entries'][] = [
					'entry_id' => $entry_id,
					'form_id' => $form_id,
					'form_title' => $form_title,
					'lm_on_date' => 0,
					'lm_required' => 0,
					'prod_start' => '',
					'prod_end' => '',
					'install_date' => $date,
					'status' => $booking_status,
					'booked_at' => isset( $entry_data['booked_at'] ) ? $entry_data['booked_at'] : '',
					'booked_by' => $entry_created_by,
					'is_date_only' => true,
				];
				continue;
			}

			// Regular booking with allocation
			if ( empty( $entry_data['slots_allocation'] ) ) {
				continue;
			}

			$allocation = json_decode( $entry_data['slots_allocation'], true );

			if ( ! is_array( $allocation ) ) {
				continue;
			}

			foreach ( $allocation as $date => $lm ) {
				if ( ! isset( $bookings[ $date ] ) ) {
					$bookings[ $date ] = [
						'total_lm' => 0,
						'entries' => [],
						'historical_capacity' => null,
					];
				}

				// Track historical capacity
				if ( $capacity_at_booking ) {
					if ( $bookings[ $date ]['historical_capacity'] === null ) {
						$bookings[ $date ]['historical_capacity'] = $capacity_at_booking;
					} else {
						$bookings[ $date ]['historical_capacity'] = max( $bookings[ $date ]['historical_capacity'], $capacity_at_booking );
					}
				}

				$bookings[ $date ]['total_lm'] += (int) $lm;

				$bookings[ $date ]['entries'][] = [
					'entry_id' => $entry_id,
					'form_id' => $form_id,
					'form_title' => $form_title,
					'lm_on_date' => $lm,
					'lm_required' => $lm_required,
					'prod_start' => isset( $entry_data['start_date'] ) ? $entry_data['start_date'] : '',
					'prod_end' => isset( $entry_data['end_date'] ) ? $entry_data['end_date'] : '',
					'install_date' => isset( $entry_data['install_date'] ) ? $entry_data['install_date'] : '',
					'status' => $booking_status,
					'booked_at' => isset( $entry_data['booked_at'] ) ? $entry_data['booked_at'] : '',
					'booked_by' => $entry_created_by,
				];
			}
		}

		return $bookings;
	}
}
