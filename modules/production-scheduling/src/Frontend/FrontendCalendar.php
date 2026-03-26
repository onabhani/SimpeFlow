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

		// AJAX handler for frontend entry search (logged-in users only)
		add_action( 'wp_ajax_sfa_prod_frontend_search_entry', [ $this, 'ajax_search_entry' ] );
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

				/* Entry search */
				.sfa-prod-search {
					margin: 20px 0;
					padding: 15px;
					background: #f9f9f9;
					border: 1px solid #ddd;
					border-radius: 4px;
				}
				.sfa-prod-search-row {
					display: flex;
					align-items: center;
					gap: 10px;
				}
				.sfa-prod-search-label {
					font-weight: 600;
					white-space: nowrap;
				}
				.sfa-prod-search-input {
					width: 200px;
					padding: 8px 12px;
					border: 1px solid #ccc;
					border-radius: 3px;
					font-size: 14px;
				}
				.sfa-prod-search-btn {
					padding: 8px 20px;
					background: #0073aa;
					color: white;
					border: none;
					border-radius: 3px;
					cursor: pointer;
					font-size: 14px;
				}
				.sfa-prod-search-btn:hover {
					background: #005a87;
				}
				.sfa-prod-search-btn:disabled {
					opacity: 0.6;
					cursor: not-allowed;
				}
				.sfa-prod-search-loading {
					color: #666;
					font-style: italic;
				}
				.sfa-prod-search-error {
					padding: 10px;
					margin-top: 15px;
					background: #fee;
					border-left: 3px solid #c00;
					color: #c00;
					border-radius: 2px;
				}
				.sfa-prod-search-result-card {
					margin-top: 15px;
					padding: 15px;
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
				}
				.sfa-prod-search-result-header {
					display: flex;
					justify-content: space-between;
					align-items: center;
					margin-bottom: 10px;
				}
				.sfa-prod-search-result-header h3 {
					margin: 0;
				}
				.sfa-prod-search-view-btn {
					padding: 6px 14px;
					background: #0073aa;
					color: white;
					text-decoration: none;
					border-radius: 3px;
					font-size: 13px;
				}
				.sfa-prod-search-view-btn:hover {
					background: #005a87;
					color: white;
				}
				.sfa-prod-search-label-cell {
					width: 180px;
					font-weight: 600;
				}
				.sfa-prod-search-install-date {
					font-size: 16px;
					font-weight: 700;
					color: #0073aa;
				}
				.sfa-prod-search-na {
					color: #999;
				}
				.sfa-prod-search-status {
					display: inline-block;
					padding: 3px 8px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}
				.sfa-prod-search-status--confirmed {
					background: #d4edda;
					color: #28a745;
				}
				.sfa-prod-search-alloc-tag {
					display: inline-block;
					margin: 2px 4px 2px 0;
					padding: 3px 8px;
					background: #e7f5fe;
					border-radius: 3px;
					font-size: 12px;
				}

				/* Responsive: Tablet */
				@media (max-width: 768px) {
					.sfa-prod-search-row {
						flex-wrap: wrap;
					}
					.sfa-prod-search-input {
						width: 150px;
					}
					.sfa-prod-search-result-header {
						flex-direction: column;
						align-items: flex-start;
						gap: 8px;
					}
					.sfa-prod-schedule-frontend {
						padding: 10px;
					}
					.sfa-prod-nav h2 {
						font-size: 1.2em;
					}
					.sfa-prod-nav a {
						padding: 6px 10px;
						font-size: 13px;
					}
					.sfa-prod-calendar-wrap {
						overflow-x: auto;
						-webkit-overflow-scrolling: touch;
						margin: 15px 0;
					}
					.sfa-prod-calendar {
						min-width: 560px;
						margin: 0;
					}
					.sfa-prod-calendar thead th {
						padding: 10px 6px;
						font-size: 13px;
					}
					.sfa-prod-calendar td {
						padding: 8px 5px;
						height: 70px;
						min-height: 70px;
					}
					.sfa-prod-day {
						font-size: 15px;
						margin-bottom: 4px;
					}
					.sfa-prod-capacity {
						font-size: 12px;
						padding: 2px 5px;
					}
					.sfa-prod-legend-item {
						display: block;
						margin-bottom: 8px;
					}
					.sfa-bookings-table-wrap {
						overflow-x: auto;
						-webkit-overflow-scrolling: touch;
					}
					.sfa-bookings-table {
						min-width: 700px;
					}
					.sfa-bookings-table th,
					.sfa-bookings-table td {
						padding: 8px;
						font-size: 13px;
					}
				}

				/* Responsive: Mobile */
				@media (max-width: 480px) {
					.sfa-prod-schedule-frontend {
						padding: 5px;
					}
					.sfa-prod-nav {
						gap: 5px;
					}
					.sfa-prod-nav h2 {
						font-size: 1em;
					}
					.sfa-prod-nav a {
						padding: 5px 8px;
						font-size: 12px;
					}
					.sfa-prod-calendar {
						min-width: 480px;
					}
					.sfa-prod-calendar thead th {
						padding: 8px 4px;
						font-size: 11px;
					}
					.sfa-prod-calendar td {
						padding: 5px 3px;
						height: 60px;
						min-height: 60px;
					}
					.sfa-prod-day {
						font-size: 13px;
						margin-bottom: 2px;
					}
					.sfa-prod-capacity {
						font-size: 10px;
						padding: 1px 3px;
					}
					.sfa-day-entries {
						font-size: 10px !important;
					}
					.sfa-entries-tooltip {
						min-width: 180px !important;
						font-size: 12px;
					}
					.sfa-prod-legend {
						padding: 10px;
						font-size: 13px;
					}
					.sfa-prod-legend-color {
						width: 16px;
						height: 16px;
					}
					.sfa-bookings-table th,
					.sfa-bookings-table td {
						padding: 6px;
						font-size: 12px;
						white-space: nowrap;
					}
				}
			</style>
			<script>
			jQuery(document).ready(function($) {
				// Show/hide entry tooltips on hover (desktop)
				$('.sfa-day-entries').hover(
					function() {
						var $tooltip = $(this).find('.sfa-entries-tooltip');
						$tooltip.show();
						// Reposition if overflowing right edge
						var tooltipRight = $tooltip.offset().left + $tooltip.outerWidth();
						var viewportWidth = $(window).width();
						if (tooltipRight > viewportWidth - 10) {
							$tooltip.css({ left: 'auto', right: '0' });
						}
					},
					function() {
						$(this).find('.sfa-entries-tooltip').hide();
					}
				);

				// Touch support for mobile (tap to toggle tooltip)
				$('.sfa-day-entries').on('click touchstart', function(e) {
					if (e.type === 'touchstart') {
						e.preventDefault();
					}
					var $tooltip = $(this).find('.sfa-entries-tooltip');
					// Hide all other tooltips first
					$('.sfa-entries-tooltip').not($tooltip).hide();
					$tooltip.toggle();
					// Reposition if overflowing
					if ($tooltip.is(':visible')) {
						var tooltipRight = $tooltip.offset().left + $tooltip.outerWidth();
						var viewportWidth = $(window).width();
						if (tooltipRight > viewportWidth - 10) {
							$tooltip.css({ left: 'auto', right: '0' });
						}
					}
				});

				// Close tooltips when tapping elsewhere
				$(document).on('click touchstart', function(e) {
					if (!$(e.target).closest('.sfa-day-entries').length) {
						$('.sfa-entries-tooltip').hide();
					}
				});
			});
			</script>

			<?php $this->render_entry_search(); ?>

			<?php $this->render_nearest_available_slot_card(); ?>

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
	 * Render nearest available slot countdown card
	 */
	private function render_nearest_available_slot_card() {
		$nearest = $this->get_nearest_available_slot();

		if ( ! $nearest ) {
			?>
			<div style="margin: 20px 0; padding: 20px; background: #f0f0f0; border-left: 4px solid #999; border-radius: 4px;">
				<div style="font-size: 14px; color: #666;">No available slots found in the next 90 days.</div>
			</div>
			<?php
			return;
		}

		$today = new \DateTime( current_time( 'Y-m-d' ) );
		$slot_date = new \DateTime( $nearest );
		$diff = $today->diff( $slot_date );
		$days_away = (int) $diff->days;

		if ( $days_away === 0 ) {
			$countdown_text = 'Today';
			$border_color = '#28a745';
			$icon = '&#9989;';
		} elseif ( $days_away === 1 ) {
			$countdown_text = 'Tomorrow';
			$border_color = '#28a745';
			$icon = '&#128197;';
		} else {
			$countdown_text = $days_away . ' days';
			$border_color = '#0073aa';
			$icon = '&#128197;';
		}

		$formatted_date = date( 'l, M j, Y', strtotime( $nearest ) );

		?>
		<div style="margin: 20px 0; padding: 20px; background: #fff; border-left: 4px solid <?php echo $border_color; ?>; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="display: flex; align-items: center; gap: 15px;">
				<div style="font-size: 32px; line-height: 1;"><?php echo $icon; ?></div>
				<div>
					<div style="font-size: 13px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Nearest Available Slot</div>
					<div style="font-size: 20px; font-weight: 700; color: #1d2327;">
						<?php if ( $days_away === 0 ): ?>
							<?php echo $countdown_text; ?> <span style="font-size: 14px; font-weight: 400; color: #666;">(<?php echo esc_html( $formatted_date ); ?>)</span>
						<?php else: ?>
							After: <span style="color: <?php echo $border_color; ?>;"><?php echo $countdown_text; ?></span>
							<span style="font-size: 14px; font-weight: 400; color: #666;">(<?php echo esc_html( $formatted_date ); ?>)</span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the nearest working day with available capacity (not full)
	 */
	private function get_nearest_available_slot() {
		global $wpdb;

		$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity', 10 );
		$repo = new CapacityRepository();

		$working_days_json = get_option( 'sfa_prod_working_days', wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ) );
		$working_days = json_decode( $working_days_json, true );
		$holidays_json = get_option( 'sfa_prod_holidays', wp_json_encode( [] ) );
		$holidays = json_decode( $holidays_json, true );

		$holiday_dates = [];
		foreach ( $holidays as $holiday ) {
			if ( is_array( $holiday ) && isset( $holiday['date'] ) ) {
				$holiday_dates[] = $holiday['date'];
			} elseif ( is_string( $holiday ) ) {
				$holiday_dates[] = $holiday;
			}
		}

		$today = new \DateTime( current_time( 'Y-m-d' ) );
		$end = clone $today;
		$end->modify( '+90 days' );

		$capacity_overrides = $repo->get_range( $today->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );

		// Get used capacity per day
		$used_per_day = [];
		$results = $wpdb->get_results(
			"SELECT em.meta_value
			FROM {$wpdb->prefix}gf_entry_meta em
			INNER JOIN {$wpdb->prefix}gf_entry e ON em.entry_id = e.id
			LEFT JOIN {$wpdb->prefix}gf_entry_meta bs
				ON em.entry_id = bs.entry_id
				AND bs.meta_key = '_prod_booking_status'
			WHERE em.meta_key = '_prod_slots_allocation'
			AND e.status = 'active'
			AND (bs.meta_value IS NULL OR bs.meta_value != 'canceled')",
			ARRAY_A
		);

		foreach ( $results as $row ) {
			$allocation = json_decode( $row['meta_value'], true );
			if ( ! is_array( $allocation ) ) {
				continue;
			}
			foreach ( $allocation as $date => $lm ) {
				if ( ! isset( $used_per_day[ $date ] ) ) {
					$used_per_day[ $date ] = 0;
				}
				$used_per_day[ $date ] += (int) $lm;
			}
		}

		// Walk each day from today looking for first available slot
		$current = clone $today;
		while ( $current <= $end ) {
			$date_str = $current->format( 'Y-m-d' );
			$day_of_week = (int) $current->format( 'w' );

			if ( ! in_array( $day_of_week, $working_days, true ) || in_array( $date_str, $holiday_dates, true ) ) {
				$current->modify( '+1 day' );
				continue;
			}

			$cap = isset( $capacity_overrides[ $date_str ] ) ? (int) $capacity_overrides[ $date_str ] : $daily_capacity;
			if ( $cap <= 0 ) {
				$current->modify( '+1 day' );
				continue;
			}

			$used = isset( $used_per_day[ $date_str ] ) ? $used_per_day[ $date_str ] : 0;

			if ( $used < $cap ) {
				return $date_str;
			}

			$current->modify( '+1 day' );
		}

		return null;
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
		<div class="sfa-prod-calendar-wrap">
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
							$text_color = '#666';
							$text = $holiday_label ? esc_html( $holiday_label ) : 'Holiday';
						} elseif ( $is_off_day || $capacity === 0 ) {
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
						if ( $is_holiday || $is_off_day || $capacity === 0 ) {
							echo '<div class="sfa-prod-capacity" style="color: ' . $text_color . ';">' . $text . '</div>';
						} else {
							echo '<div class="sfa-prod-capacity" style="color: ' . $text_color . ';">' . $text . ' LM</div>';
						}

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
								$is_date_only = isset( $entry_info['is_date_only'] ) && $entry_info['is_date_only'];
								echo '<a href="' . esc_url( $workflow_url ) . '" target="_blank" class="sfa-tooltip-entry" style="display: block; padding: 6px 10px; border-bottom: 1px solid #f0f0f0; color: #0073aa; text-decoration: none; cursor: pointer;">';
								echo '<strong>#' . $entry_info['entry_id'] . '</strong>';
								if ( $is_date_only ) {
									echo ' - <em style="color: #666;">Install only</em>';
								} else {
									echo ' - ' . $entry_info['lm_on_date'] . ' slot' . ( $entry_info['lm_on_date'] > 1 ? 's' : '' );
								}
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
		</div>
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

					// Read entry creator directly from gf_entry (more reliable than stored meta)
					global $wpdb;
					$booked_by = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT created_by FROM {$wpdb->prefix}gf_entry WHERE id = %d",
						$entry_id
					) );

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
				<div class="sfa-bookings-table-wrap">
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
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Search for an entry with an active booking
	 */
	public function ajax_search_entry() {
		check_ajax_referer( 'sfa_prod_frontend_search', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'simpleflow' ) ] );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;

		if ( ! $entry_id ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid entry ID.', 'simpleflow' ) ] );
		}

		$entry = \GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $entry ) ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Entry #%d not found.', 'simpleflow' ), $entry_id ) ] );
		}

		// Only return entries with active bookings
		$booking_status = gform_get_meta( $entry_id, '_prod_booking_status' );
		if ( $booking_status !== 'confirmed' ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Entry #%d does not have an active booking.', 'simpleflow' ), $entry_id ) ] );
		}

		$install_date   = gform_get_meta( $entry_id, '_install_date' );
		$prod_start     = gform_get_meta( $entry_id, '_prod_start_date' );
		$prod_end       = gform_get_meta( $entry_id, '_prod_end_date' );
		$lm_required    = gform_get_meta( $entry_id, '_prod_lm_required' );
		$allocation     = gform_get_meta( $entry_id, '_prod_slots_allocation' );
		$booked_at      = gform_get_meta( $entry_id, '_prod_booked_at' );

		$form       = \GFAPI::get_form( $entry['form_id'] );
		$form_title = is_array( $form ) ? rgar( $form, 'title' ) : '';

		$workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry_id;

		wp_send_json_success( [
			'entry_id'       => $entry_id,
			'form_id'        => $entry['form_id'],
			'form_title'     => $form_title,
			'install_date'   => $install_date ?: '',
			'prod_start'     => $prod_start ?: '',
			'prod_end'       => $prod_end ?: '',
			'lm_required'    => $lm_required ?: '0',
			'booking_status' => $booking_status,
			'allocation'     => $allocation ? json_decode( $allocation, true ) : [],
			'booked_at'      => $booked_at ?: '',
			'workflow_url'   => $workflow_url,
		] );
	}

	/**
	 * Render entry search bar
	 */
	private function render_entry_search() {
		$nonce = wp_create_nonce( 'sfa_prod_frontend_search' );
		?>
		<div class="sfa-prod-search">
			<div class="sfa-prod-search-row">
				<label for="sfa-entry-search" class="sfa-prod-search-label"><?php esc_html_e( 'Search Entry:', 'simpleflow' ); ?></label>
				<input type="number" id="sfa-entry-search" placeholder="<?php esc_attr_e( 'Enter Entry ID', 'simpleflow' ); ?>" min="1" class="sfa-prod-search-input">
				<button type="button" id="sfa-entry-search-btn" class="sfa-prod-search-btn"><?php esc_html_e( 'Search', 'simpleflow' ); ?></button>
				<span id="sfa-entry-search-loading" class="sfa-prod-search-loading" style="display: none;"><?php esc_html_e( 'Searching...', 'simpleflow' ); ?></span>
			</div>
			<div id="sfa-entry-search-result" style="display: none;"></div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var searchNonce = '<?php echo esc_js( $nonce ); ?>';

			function esc(str) {
				var d = document.createElement('div');
				d.appendChild(document.createTextNode(String(str)));
				return d.innerHTML;
			}

			function escAttr(str) {
				return esc(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
			}

			function formatDate(dateStr) {
				if (!dateStr) return '';
				var parts = String(dateStr).split('-');
				if (parts.length !== 3) return String(dateStr);
				var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
				return months[parseInt(parts[1]) - 1] + ' ' + parseInt(parts[2]) + ', ' + parts[0];
			}

			function searchEntry() {
				var entryId = $('#sfa-entry-search').val().trim();
				if (!entryId || parseInt(entryId) <= 0) {
					$('#sfa-entry-search-result').html(
						'<div class="sfa-prod-search-error"><?php echo esc_js( __( 'Please enter a valid entry ID.', 'simpleflow' ) ); ?></div>'
					).show();
					return;
				}

				$('#sfa-entry-search-btn').prop('disabled', true);
				$('#sfa-entry-search-loading').show();
				$('#sfa-entry-search-result').hide();

				$.ajax({
					url: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
					method: 'POST',
					data: {
						action: 'sfa_prod_frontend_search_entry',
						nonce: searchNonce,
						entry_id: entryId
					},
					success: function(response) {
						$('#sfa-entry-search-btn').prop('disabled', false);
						$('#sfa-entry-search-loading').hide();

						if (response.success) {
							displayResult(response.data);
						} else {
							$('#sfa-entry-search-result').html(
								'<div class="sfa-prod-search-error">' + esc(response.data.message) + '</div>'
							).show();
						}
					},
					error: function() {
						$('#sfa-entry-search-btn').prop('disabled', false);
						$('#sfa-entry-search-loading').hide();
						$('#sfa-entry-search-result').html(
							'<div class="sfa-prod-search-error"><?php echo esc_js( __( 'Network error. Please try again.', 'simpleflow' ) ); ?></div>'
						).show();
					}
				});
			}

			function displayResult(data) {
				var html = '<div class="sfa-prod-search-result-card">';
				html += '<div class="sfa-prod-search-result-header">';
				html += '<h3><?php echo esc_js( __( 'Entry', 'simpleflow' ) ); ?> #' + esc(data.entry_id) + '</h3>';
				html += '<a href="' + escAttr(data.workflow_url) + '" target="_blank" class="sfa-prod-search-view-btn"><?php echo esc_js( __( 'View Entry', 'simpleflow' ) ); ?></a>';
				html += '</div>';

				html += '<table class="sfa-bookings-table">';
				html += '<tbody>';
				html += '<tr><td class="sfa-prod-search-label-cell"><?php echo esc_js( __( 'Form', 'simpleflow' ) ); ?></td>';
				html += '<td>' + esc(data.form_title || 'Form #' + data.form_id) + '</td></tr>';

				html += '<tr><td class="sfa-prod-search-label-cell"><?php echo esc_js( __( 'Installation Date', 'simpleflow' ) ); ?></td>';
				if (data.install_date) {
					html += '<td class="sfa-prod-search-install-date">' + esc(formatDate(data.install_date)) + '</td>';
				} else {
					html += '<td><em class="sfa-prod-search-na"><?php echo esc_js( __( 'Not set', 'simpleflow' ) ); ?></em></td>';
				}
				html += '</tr>';

				if (data.prod_start && data.prod_end) {
					html += '<tr><td class="sfa-prod-search-label-cell"><?php echo esc_js( __( 'Production Dates', 'simpleflow' ) ); ?></td>';
					html += '<td>' + esc(formatDate(data.prod_start)) + ' - ' + esc(formatDate(data.prod_end)) + '</td></tr>';
				}

				html += '<tr><td class="sfa-prod-search-label-cell"><?php echo esc_js( __( 'LM Required', 'simpleflow' ) ); ?></td>';
				html += '<td>' + (parseInt(data.lm_required) > 0 ? esc(data.lm_required) + ' LM' : '<em class="sfa-prod-search-na"><?php echo esc_js( __( '0 (Install only)', 'simpleflow' ) ); ?></em>') + '</td></tr>';

				html += '<tr><td class="sfa-prod-search-label-cell"><?php echo esc_js( __( 'Booking Status', 'simpleflow' ) ); ?></td>';
				html += '<td><span class="sfa-prod-search-status sfa-prod-search-status--confirmed">' + esc(data.booking_status) + '</span></td></tr>';

				if (data.allocation && Object.keys(data.allocation).length > 0) {
					html += '<tr><td class="sfa-prod-search-label-cell" style="vertical-align: top;"><?php echo esc_js( __( 'Slot Allocation', 'simpleflow' ) ); ?></td>';
					html += '<td>';
					for (var date in data.allocation) {
						if (data.allocation.hasOwnProperty(date)) {
							html += '<span class="sfa-prod-search-alloc-tag">' + esc(formatDate(date)) + ': ' + esc(data.allocation[date]) + ' slot' + (data.allocation[date] > 1 ? 's' : '') + '</span>';
						}
					}
					html += '</td></tr>';
				}

				if (data.booked_at) {
					html += '<tr><td class="sfa-prod-search-label-cell"><?php echo esc_js( __( 'Booked At', 'simpleflow' ) ); ?></td>';
					html += '<td>' + esc(data.booked_at) + '</td></tr>';
				}

				html += '</tbody></table>';
				html += '</div>';

				$('#sfa-entry-search-result').html(html).show();
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
			LEFT JOIN {$wpdb->prefix}gf_entry_meta end_meta
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
			AND (end_meta.meta_value IS NULL OR end_meta.meta_value >= %s)
			AND (sm.meta_value IS NULL OR sm.meta_value != 'canceled')
			AND e.status = 'active'
		";

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $end_date, $start_date )
		);

		// Also query date-only bookings (0 LM entries with just installation date)
		$date_only_query = "
			SELECT
				inst.entry_id,
				e.form_id,
				inst.meta_value as install_date,
				f.title as form_name
			FROM {$wpdb->prefix}gf_entry_meta inst
			INNER JOIN {$wpdb->prefix}gf_entry e ON inst.entry_id = e.id
			INNER JOIN {$wpdb->prefix}gf_form f ON e.form_id = f.id
			LEFT JOIN {$wpdb->prefix}gf_entry_meta lm
				ON inst.entry_id = lm.entry_id
				AND lm.meta_key = '_prod_lm_required'
			LEFT JOIN {$wpdb->prefix}gf_entry_meta sm
				ON inst.entry_id = sm.entry_id
				AND sm.meta_key = '_prod_booking_status'
			WHERE inst.meta_key = '_install_date'
			AND inst.meta_value >= %s
			AND inst.meta_value <= %s
			AND (lm.meta_value IS NULL OR lm.meta_value = '' OR lm.meta_value = '0')
			AND (sm.meta_value IS NULL OR sm.meta_value != 'canceled')
			AND e.status = 'active'
		";

		$date_only_results = $wpdb->get_results(
			$wpdb->prepare( $date_only_query, $start_date, $end_date )
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

		// Add date-only bookings
		foreach ( $date_only_results as $row ) {
			$date = $row->install_date;
			if ( ! isset( $bookings[ $date ] ) ) {
				$bookings[ $date ] = [
					'total_lm' => 0,
					'entries' => [],
					'historical_capacity' => null,
				];
			}

			$bookings[ $date ]['entries'][] = [
				'entry_id' => $row->entry_id,
				'form_id' => $row->form_id,
				'lm_on_date' => 0,
				'form_name' => $row->form_name,
				'is_date_only' => true,
			];
		}

		return $bookings;
	}
}
