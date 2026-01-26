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
			</style>

			<?php $this->render_month_navigation( $date ); ?>

			<?php $this->render_calendar( $date, $bookings, $daily_capacity, $capacity_overrides, $off_days, $holidays ); ?>

			<?php $this->render_legend(); ?>
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
							echo '<div style="font-size: 12px; color: #666; margin-top: 5px;">';
							echo $entry_count . ' order' . ( $entry_count > 1 ? 's' : '' );
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
	 * Load bookings for date range
	 */
	private function load_bookings( $start_date, $end_date ) {
		global $wpdb;

		$query = "
			SELECT
				em.entry_id,
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
