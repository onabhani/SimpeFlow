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
		// P3 FIX: Use WordPress timezone function for current month
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
	 * Render calendar grid
	 */
	private function render_calendar( $date, $bookings, $daily_capacity, $capacity_overrides, $off_days, $holidays ) {
		$days_in_month = (int) $date->format( 't' );
		$first_day_of_week = (int) $date->format( 'w' ); // 0=Sunday

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
						$is_holiday = in_array( $day_date, $holidays, true );

						// Get capacity for this day
						// Priority: historical capacity (if bookings exist) > capacity override > default capacity
						// This ensures we show the capacity that was in effect when bookings were made
						if ( isset( $bookings[ $day_date ] ) && $bookings[ $day_date ]['historical_capacity'] !== null ) {
							// Use historical capacity if bookings exist with capacity info
							$capacity = $bookings[ $day_date ]['historical_capacity'];
						} elseif ( isset( $capacity_overrides[ $day_date ] ) ) {
							// Use capacity override
							$capacity = $capacity_overrides[ $day_date ];
						} else {
							// Use default daily capacity
							$capacity = $daily_capacity;
						}

						// Get used capacity
						$used = isset( $bookings[ $day_date ] ) ? $bookings[ $day_date ]['total_lm'] : 0;

						// Calculate percentage
						$percentage = $capacity > 0 ? ( $used / $capacity ) * 100 : 0;

						// Determine color
						if ( $is_off_day || $is_holiday || $capacity === 0 ) {
							$bg_color = '#e0e0e0';
							$text = 'OFF';
						} elseif ( $percentage >= 100 ) {
							$bg_color = '#ffcccc';
							$text = "$used/$capacity FULL";
						} elseif ( $percentage >= 70 ) {
							$bg_color = '#fff4cc';
							$text = "$used/$capacity";
						} else {
							$bg_color = '#ccffcc';
							$text = "$used/$capacity";
						}

						echo '<td style="padding: 10px; border: 1px solid #ddd; background: ' . $bg_color . '; vertical-align: top; height: 80px; position: relative;">';
						echo '<div style="font-weight: bold; margin-bottom: 5px;">' . $current_day . '</div>';
						echo '<div style="font-size: 12px;">' . $text . '</div>';

						// Add indicator if using historical capacity
						if ( isset( $bookings[ $day_date ] ) && $bookings[ $day_date ]['historical_capacity'] !== null ) {
							$current_cap = isset( $capacity_overrides[ $day_date ] ) ? $capacity_overrides[ $day_date ] : $daily_capacity;
							if ( $capacity !== $current_cap ) {
								// Historical capacity differs from current - add visual indicator
								echo '<div style="font-size: 9px; color: #666; margin-top: 2px;" title="Using historical capacity. Bookings were made when capacity was ' . $capacity . ' LM, but current capacity setting is ' . $current_cap . ' LM.">⚠ Historical</div>';
							}
						}

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
								echo '<a href="' . esc_url( $workflow_url ) . '" target="_blank" class="sfa-tooltip-entry" style="display: block; padding: 6px 10px; border-bottom: 1px solid #f0f0f0; color: #0073aa; text-decoration: none; cursor: pointer;">';
								echo '<strong>#' . $entry_info['entry_id'] . '</strong>';
								echo ' - ' . $entry_info['lm_on_date'] . ' slot' . ( $entry_info['lm_on_date'] > 1 ? 's' : '' );
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

						// Build workflow-inbox URL
						$workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry_data['form_id'] . '&lid=' . $entry_data['entry_id'];
						?>
						<tr>
							<td><a href="<?php echo esc_url( $workflow_url ); ?>" target="_blank">
								#<?php echo $entry_data['entry_id']; ?>
							</a></td>
							<td><?php echo $entry_data['lm_required']; ?> LM</td>
							<td><?php echo date( 'M j', strtotime( $entry_data['prod_start'] ) ); ?> - <?php echo date( 'M j, Y', strtotime( $entry_data['prod_end'] ) ); ?></td>
							<td><?php echo date( 'M j, Y', strtotime( $entry_data['install_date'] ) ); ?></td>
							<td><?php echo esc_html( $username ); ?></td>
							<td><?php echo date( 'M j, Y g:i a', strtotime( $entry_data['booked_at'] ) ); ?></td>
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
	 * Load bookings for date range
	 */
	private function load_bookings( $start_date, $end_date ) {
		global $wpdb;

		// Query all entries with production bookings
		// FIX: Filter out trashed/spam entries like frontend does
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, meta_key, meta_value
				FROM {$wpdb->prefix}gf_entry_meta
				WHERE meta_key IN ('_prod_lm_required', '_prod_slots_allocation', '_prod_start_date', '_prod_end_date', '_install_date', '_prod_booking_status', '_prod_booked_at', '_prod_booked_by', '_prod_daily_capacity_at_booking')
				AND entry_id IN (
					SELECT DISTINCT em.entry_id
					FROM {$wpdb->prefix}gf_entry_meta em
					INNER JOIN {$wpdb->prefix}gf_entry e ON em.entry_id = e.id
					WHERE em.meta_key = '_prod_start_date'
					AND em.meta_value >= %s
					AND em.meta_value <= %s
					AND e.status = 'active'
				)
				ORDER BY entry_id",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

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

		// Organize by date
		$bookings = [];
		$form_title_cache = [];

		foreach ( $entries as $entry_id => $entry_data ) {
			if ( empty( $entry_data['slots_allocation'] ) ) {
				continue;
			}

			$allocation = json_decode( $entry_data['slots_allocation'], true );

			if ( ! is_array( $allocation ) ) {
				continue;
			}

			// Get form ID for entry
			$form_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT form_id FROM {$wpdb->prefix}gf_entry WHERE id = %d",
				$entry_id
			) );

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
			// This ensures cancelled workflows show as canceled even if hooks didn't fire
			$workflow_status = gform_get_meta( $entry_id, 'workflow_final_status' );
			if ( 'cancelled' === $workflow_status || 'canceled' === $workflow_status ) {
				if ( 'canceled' !== $booking_status ) {
					// Workflow is cancelled but booking status doesn't reflect it - fix it now
					gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
					$booking_status = 'canceled';
					error_log( sprintf( 'Production Booking: Synced canceled status for entry %d (workflow was cancelled)', $entry_id ) );
				}
			}

			foreach ( $allocation as $date => $lm ) {
				if ( ! isset( $bookings[ $date ] ) ) {
					$bookings[ $date ] = [
						'total_lm' => 0,
						'entries' => [],
						'historical_capacity' => null,  // Track the maximum capacity that was in effect for bookings
					];
				}

				// Track historical capacity (use the maximum capacity seen for this date)
				if ( $capacity_at_booking ) {
					if ( $bookings[ $date ]['historical_capacity'] === null ) {
						$bookings[ $date ]['historical_capacity'] = $capacity_at_booking;
					} else {
						$bookings[ $date ]['historical_capacity'] = max( $bookings[ $date ]['historical_capacity'], $capacity_at_booking );
					}
				}

				// Only count confirmed bookings toward capacity usage
				// Canceled bookings are shown but don't consume capacity
				if ( 'canceled' !== $booking_status ) {
					$bookings[ $date ]['total_lm'] += (int) $lm;
				}

				$bookings[ $date ]['entries'][] = [
					'entry_id' => $entry_id,
					'form_id' => $form_id,
					'form_title' => $form_title,
					'lm_on_date' => $lm,
					'lm_required' => isset( $entry_data['lm_required'] ) ? $entry_data['lm_required'] : 0,
					'prod_start' => isset( $entry_data['start_date'] ) ? $entry_data['start_date'] : '',
					'prod_end' => isset( $entry_data['end_date'] ) ? $entry_data['end_date'] : '',
					'install_date' => isset( $entry_data['install_date'] ) ? $entry_data['install_date'] : '',
					'status' => isset( $entry_data['booking_status'] ) ? $entry_data['booking_status'] : 'unknown',
					'booked_at' => isset( $entry_data['booked_at'] ) ? $entry_data['booked_at'] : '',
					'booked_by' => isset( $entry_data['booked_by'] ) ? $entry_data['booked_by'] : 0,
				];
			}
		}

		return $bookings;
	}
}
