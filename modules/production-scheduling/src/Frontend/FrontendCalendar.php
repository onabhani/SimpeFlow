<?php
namespace SFA\ProductionScheduling\Frontend;

use SFA\ProductionScheduling\Database\CapacityRepository;

/**
 * Frontend Calendar View
 *
 * Displays production schedule calendar on frontend using shortcode [production_schedule]
 */
class FrontendCalendar {

	public function __construct() {
		// Register shortcode
		add_shortcode( 'production_schedule', [ $this, 'render_shortcode' ] );

		// Enqueue frontend assets
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue frontend assets
	 */
	public function enqueue_assets() {
		// Only enqueue if shortcode is being used
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'production_schedule' ) ) {
			wp_enqueue_style( 'sfa-prod-styles' );
			wp_enqueue_script( 'sfa-prod-calendar' );
		}
	}

	/**
	 * Shortcode handler
	 *
	 * Usage: [production_schedule]
	 * Optional attributes:
	 * - require_login="yes" (default: no) - Require user to be logged in
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( [
			'require_login' => 'no',
		], $atts );

		// Check login requirement
		if ( $atts['require_login'] === 'yes' && ! is_user_logged_in() ) {
			return '<p>You must be logged in to view the production schedule.</p>';
		}

		// Get current month or requested month
		$month = isset( $_GET['prod_month'] ) ? sanitize_text_field( $_GET['prod_month'] ) : current_time( 'Y-m' );

		// Parse month
		$date = new \DateTime( $month . '-01' );

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

		// Start output buffering
		ob_start();
		?>
		<div class="sfa-prod-schedule-frontend">
			<style>
				.sfa-prod-schedule-frontend {
					max-width: 1200px;
					margin: 20px auto;
					padding: 20px;
				}
				.sfa-prod-nav {
					display: flex;
					justify-content: space-between;
					align-items: center;
					margin: 20px 0;
				}
				.sfa-prod-nav a {
					padding: 8px 16px;
					background: #0073aa;
					color: white;
					text-decoration: none;
					border-radius: 3px;
				}
				.sfa-prod-nav a:hover {
					background: #005a87;
				}
				.sfa-prod-nav h2 {
					margin: 0;
				}
				.sfa-prod-calendar {
					width: 100%;
					border-collapse: collapse;
					margin: 20px 0;
					box-shadow: 0 2px 4px rgba(0,0,0,0.1);
				}
				.sfa-prod-calendar thead th {
					padding: 15px;
					background: #0073aa;
					color: white;
					text-align: center;
					font-weight: 600;
				}
				.sfa-prod-calendar td {
					padding: 15px 10px;
					border: 1px solid #ddd;
					vertical-align: top;
					min-height: 100px;
					height: 100px;
				}
				.sfa-prod-day {
					font-weight: bold;
					font-size: 18px;
					margin-bottom: 8px;
				}
				.sfa-prod-capacity {
					font-size: 14px;
					padding: 4px 8px;
					border-radius: 3px;
					display: inline-block;
				}
				.sfa-prod-legend {
					margin: 20px 0;
					padding: 15px;
					background: #f9f9f9;
					border-left: 4px solid #0073aa;
					border-radius: 3px;
				}
				.sfa-prod-legend-item {
					display: inline-block;
					margin-right: 20px;
					margin-bottom: 10px;
				}
				.sfa-prod-legend-color {
					display: inline-block;
					width: 20px;
					height: 20px;
					margin-right: 8px;
					vertical-align: middle;
					border: 1px solid #ddd;
				}
				.sfa-day-entries:hover .sfa-entries-tooltip {
					display: block !important;
				}
			</style>
			<script>
			jQuery(document).ready(function($) {
				// Show/hide entry tooltips on hover
				$('.sfa-day-entries').hover(
					function() {
						$(this).find('.sfa-entries-tooltip').show();
					},
					function() {
						$(this).find('.sfa-entries-tooltip').hide();
					}
				);
			});
			</script>

			<?php $this->render_month_navigation( $date ); ?>

			<?php $this->render_calendar( $date, $bookings, $daily_capacity, $capacity_overrides, $off_days, $holidays ); ?>

			<?php $this->render_legend(); ?>

			<hr style="margin: 40px 0;">

			<?php $this->render_bookings_list( $bookings, $date ); ?>
		</div>
		<?php
		return ob_get_clean();
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
		$current_url = strtok( $_SERVER['REQUEST_URI'], '?' );

		?>
		<div class="sfa-prod-nav">
			<a href="<?php echo esc_url( add_query_arg( 'prod_month', $prev_month->format( 'Y-m' ), $current_url ) ); ?>">
				◀ <?php echo $prev_month->format( 'M Y' ); ?>
			</a>

			<h2><?php echo $current_month; ?></h2>

			<a href="<?php echo esc_url( add_query_arg( 'prod_month', $next_month->format( 'Y-m' ), $current_url ) ); ?>">
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
		<table class="sfa-prod-calendar">
			<thead>
				<tr>
					<th>Sun</th>
					<th>Mon</th>
					<th>Tue</th>
					<th>Wed</th>
					<th>Thu</th>
					<th>Fri</th>
					<th>Sat</th>
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
							echo '<td style="background: #fafafa;"></td>';
							continue;
						}

						$day_date = $date->format( 'Y-m-' ) . str_pad( $current_day, 2, '0', STR_PAD_LEFT );

						// Check if off day or holiday
						$is_off_day = in_array( $day_of_week, $off_days, true );
						$is_holiday = in_array( $day_date, $holidays, true );

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

						// Determine color
						if ( $is_off_day || $is_holiday || $capacity === 0 ) {
							$bg_color = '#e0e0e0';
							$text_color = '#666';
							$text = 'OFF';
						} elseif ( $percentage >= 100 ) {
							$bg_color = '#ffcccc';
							$text_color = '#cc0000';
							$text = "$used/$capacity FULL";
						} elseif ( $percentage >= 70 ) {
							$bg_color = '#fff4cc';
							$text_color = '#cc8800';
							$text = "$used/$capacity";
						} else {
							$bg_color = '#ccffcc';
							$text_color = '#008800';
							$text = "$used/$capacity";
						}

						echo '<td style="background: ' . $bg_color . ';">';
						echo '<div class="sfa-prod-day">' . $current_day . '</div>';
						echo '<div class="sfa-prod-capacity" style="color: ' . $text_color . ';">' . $text . ' LM</div>';

						if ( isset( $bookings[ $day_date ] ) && ! empty( $bookings[ $day_date ]['entries'] ) ) {
							$entry_count = count( $bookings[ $day_date ]['entries'] );
							echo '<div class="sfa-day-entries" style="font-size: 12px; color: #0073aa; margin-top: 5px; cursor: help; position: relative;">';
							echo '<span style="text-decoration: underline dotted;">';
							echo $entry_count . ' order' . ( $entry_count > 1 ? 's' : '' );
							echo '</span>';

							// Tooltip with entry details
							echo '<div class="sfa-entries-tooltip" style="display: none; position: absolute; z-index: 1000; background: white; border: 1px solid #ccc; box-shadow: 0 2px 8px rgba(0,0,0,0.15); padding: 0; min-width: 220px; left: 0; top: 20px; border-radius: 3px; overflow: hidden;">';
							echo '<div style="font-weight: bold; padding: 8px 10px; background: #f8f8f8; border-bottom: 1px solid #eee;">Orders on ' . date( 'M j', strtotime( $day_date ) ) . ':</div>';
							foreach ( $bookings[ $day_date ]['entries'] as $entry_info ) {
								$workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry_info['form_id'] . '&lid=' . $entry_info['entry_id'];
								echo '<a href="' . esc_url( $workflow_url ) . '" target="_blank" class="sfa-tooltip-entry" style="display: block; padding: 6px 10px; border-bottom: 1px solid #f0f0f0; color: #0073aa; text-decoration: none; cursor: pointer;">';
								echo '<strong>#' . $entry_info['entry_id'] . '</strong>';
								echo ' - ' . $entry_info['lm_on_date'] . ' slot' . ( $entry_info['lm_on_date'] > 1 ? 's' : '' );
								if ( ! empty( $entry_info['form_name'] ) ) {
									echo ' <span style="color: #888;">(' . esc_html( $entry_info['form_name'] ) . ')</span>';
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
		<?php
	}

	/**
	 * Render legend
	 */
	private function render_legend() {
		?>
		<div class="sfa-prod-legend">
			<strong>Legend:</strong>
			<div style="margin-top: 10px;">
				<div class="sfa-prod-legend-item">
					<span class="sfa-prod-legend-color" style="background: #ccffcc;"></span>
					Available (0-69%)
				</div>
				<div class="sfa-prod-legend-item">
					<span class="sfa-prod-legend-color" style="background: #fff4cc;"></span>
					Nearly Full (70-99%)
				</div>
				<div class="sfa-prod-legend-item">
					<span class="sfa-prod-legend-color" style="background: #ffcccc;"></span>
					Full (100%+)
				</div>
				<div class="sfa-prod-legend-item">
					<span class="sfa-prod-legend-color" style="background: #e0e0e0;"></span>
					Off Day/Holiday
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render bookings list table
	 */
	private function render_bookings_list( $bookings, $date ) {
		// Flatten bookings into a list of entries
		$entries_list = [];

		foreach ( $bookings as $day_date => $day_info ) {
			if ( empty( $day_info['entries'] ) ) {
				continue;
			}

			foreach ( $day_info['entries'] as $entry_info ) {
				$entry_id = $entry_info['entry_id'];

				if ( ! isset( $entries_list[ $entry_id ] ) ) {
					// Load full booking data for this entry
					$install_date = gform_get_meta( $entry_id, '_install_date' );
					$prod_start = gform_get_meta( $entry_id, '_prod_start_date' );
					$prod_end = gform_get_meta( $entry_id, '_prod_end_date' );
					$lm_required = gform_get_meta( $entry_id, '_prod_lm_required' );
					$booked_at = gform_get_meta( $entry_id, '_prod_booked_at' );
					$booked_by = gform_get_meta( $entry_id, '_prod_booked_by' );

					$entries_list[ $entry_id ] = [
						'entry_id' => $entry_id,
						'form_id' => $entry_info['form_id'],
						'form_name' => $entry_info['form_name'],
						'install_date' => $install_date,
						'prod_start' => $prod_start,
						'prod_end' => $prod_end,
						'lm_required' => $lm_required,
						'booked_at' => $booked_at,
						'booked_by' => $booked_by,
					];
				}
			}
		}

		// Sort by installation date
		uasort( $entries_list, function( $a, $b ) {
			return strcmp( $a['install_date'], $b['install_date'] );
		} );

		?>
		<div class="sfa-prod-bookings-list">
			<h3 style="margin-bottom: 20px;">Production Bookings (<?php echo $date->format( 'F Y' ); ?>)</h3>

			<?php if ( empty( $entries_list ) ): ?>
				<p style="color: #666; font-style: italic;">No production bookings for this month.</p>
			<?php else: ?>
				<style>
					.sfa-bookings-table {
						width: 100%;
						border-collapse: collapse;
						background: white;
						box-shadow: 0 2px 4px rgba(0,0,0,0.1);
					}
					.sfa-bookings-table th {
						background: #f5f5f5;
						padding: 12px;
						text-align: left;
						font-weight: 600;
						border-bottom: 2px solid #ddd;
					}
					.sfa-bookings-table td {
						padding: 12px;
						border-bottom: 1px solid #eee;
					}
					.sfa-bookings-table tr:hover {
						background: #f9f9f9;
					}
				</style>
				<table class="sfa-bookings-table">
					<thead>
						<tr>
							<th>Entry #</th>
							<th>Form</th>
							<th>LM Required</th>
							<th>Production Dates</th>
							<th>Install Date</th>
							<th>Booked At</th>
							<th>Booked By</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries_list as $entry_data ): ?>
							<tr>
								<td>
									<?php $workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry_data['form_id'] . '&lid=' . $entry_data['entry_id']; ?>
									<a href="<?php echo esc_url( $workflow_url ); ?>" target="_blank" style="color: #0073aa;">
										<strong>#<?php echo $entry_data['entry_id']; ?></strong>
									</a>
								</td>
								<td><?php echo esc_html( $entry_data['form_name'] ); ?></td>
								<td><?php echo esc_html( $entry_data['lm_required'] ); ?> LM</td>
								<td>
									<?php if ( $entry_data['prod_start'] && $entry_data['prod_end'] ): ?>
										<?php echo date( 'M j', strtotime( $entry_data['prod_start'] ) ); ?> -
										<?php echo date( 'M j, Y', strtotime( $entry_data['prod_end'] ) ); ?>
									<?php else: ?>
										<span style="color: #999;">—</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $entry_data['install_date'] ): ?>
										<?php echo date( 'M j, Y', strtotime( $entry_data['install_date'] ) ); ?>
									<?php else: ?>
										<span style="color: #999;">—</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $entry_data['booked_at'] ): ?>
										<?php echo date( 'M j, Y g:i a', strtotime( $entry_data['booked_at'] ) ); ?>
									<?php else: ?>
										<span style="color: #999;">—</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $entry_data['booked_by'] ): ?>
										<?php
										$user = get_userdata( $entry_data['booked_by'] );
										echo $user ? esc_html( $user->display_name ) : 'User #' . $entry_data['booked_by'];
										?>
									<?php else: ?>
										<span style="color: #999;">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Load bookings for date range
	 */
	private function load_bookings( $start_date, $end_date ) {
		global $wpdb;

		$query = "
			SELECT
				em.entry_id,
				e.form_id,
				em.meta_value as allocation,
				cm.meta_value as capacity_at_booking,
				f.title as form_name
			FROM {$wpdb->prefix}gf_entry_meta em
			INNER JOIN {$wpdb->prefix}gf_entry_meta start_meta
				ON em.entry_id = start_meta.entry_id
				AND start_meta.meta_key = '_prod_start_date'
			INNER JOIN {$wpdb->prefix}gf_entry_meta end_meta
				ON em.entry_id = end_meta.entry_id
				AND end_meta.meta_key = '_prod_end_date'
			INNER JOIN {$wpdb->prefix}gf_entry e
				ON em.entry_id = e.id
			INNER JOIN {$wpdb->prefix}gf_form f
				ON e.form_id = f.id
			LEFT JOIN {$wpdb->prefix}gf_entry_meta cm
				ON em.entry_id = cm.entry_id
				AND cm.meta_key = '_prod_daily_capacity_at_booking'
			LEFT JOIN {$wpdb->prefix}gf_entry_meta sm
				ON em.entry_id = sm.entry_id
				AND sm.meta_key = '_prod_booking_status'
			WHERE em.meta_key = '_prod_slots_allocation'
			AND start_meta.meta_value <= %s
			AND end_meta.meta_value >= %s
			AND (sm.meta_value IS NULL OR sm.meta_value != 'canceled')
			AND e.status = 'active'
		";

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $end_date, $start_date )
		);

		$bookings = [];

		foreach ( $results as $row ) {
			$allocation = json_decode( $row->allocation, true );
			if ( ! is_array( $allocation ) ) {
				continue;
			}

			foreach ( $allocation as $date => $lm ) {
				if ( $date < $start_date || $date > $end_date ) {
					continue;
				}

				if ( ! isset( $bookings[ $date ] ) ) {
					$bookings[ $date ] = [
						'total_lm' => 0,
						'entries' => [],
						'historical_capacity' => null,
					];
				}

				$bookings[ $date ]['total_lm'] += $lm;
				$bookings[ $date ]['entries'][] = [
					'entry_id' => $row->entry_id,
					'form_id' => $row->form_id,
					'lm_on_date' => $lm,
					'form_name' => $row->form_name,
				];

				// Store historical capacity if available
				if ( $row->capacity_at_booking && $bookings[ $date ]['historical_capacity'] === null ) {
					$bookings[ $date ]['historical_capacity'] = (int) $row->capacity_at_booking;
				}
			}
		}

		return $bookings;
	}
}
