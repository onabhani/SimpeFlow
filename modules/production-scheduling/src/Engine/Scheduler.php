<?php
namespace SFA\ProductionScheduling\Engine;

use DateTime;

/**
 * Core Production Scheduler
 *
 * Pure PHP class with no WordPress dependencies
 * Calculates production slot allocation and installation dates
 */
class Scheduler {

	/**
	 * Calculate production schedule for an order
	 *
	 * @param int      $lm_required        Linear meters needed
	 * @param DateTime $earliest_start     Earliest possible production start date
	 * @param int      $daily_capacity     Default production capacity (LM/day)
	 * @param array    $capacity_overrides [date_string => custom_capacity]
	 * @param array    $existing_bookings  [date_string => total_lm_used]
	 * @param array    $off_days           [0,5] = Sunday, Friday off (0=Sun, 6=Sat)
	 * @param array    $holidays           ["2025-12-25", "2026-01-01"]
	 * @param int      $install_buffer     Days to add after production (default 0)
	 *
	 * @return array {
	 *     allocation: ["2026-01-01" => 2, "2026-01-02" => 8],
	 *     production_start: "2026-01-01",
	 *     production_end: "2026-01-02",
	 *     installation_minimum: "2026-01-02",
	 *     total_days: 2
	 * }
	 */
	public function calculate_schedule(
		int $lm_required,
		DateTime $earliest_start,
		int $daily_capacity,
		array $capacity_overrides = [],
		array $existing_bookings = [],
		array $off_days = [],
		array $holidays = [],
		int $install_buffer = 0
	): array {
		if ( $lm_required <= 0 ) {
			throw new \InvalidArgumentException( 'LM required must be greater than 0' );
		}

		if ( $daily_capacity <= 0 ) {
			throw new \InvalidArgumentException( 'Daily capacity must be greater than 0' );
		}

		$allocation = [];
		$lm_remaining = $lm_required;
		$current_date = clone $earliest_start;
		$max_iterations = 365; // Safety limit
		$iterations = 0;

		// Find and allocate slots
		while ( $lm_remaining > 0 && $iterations < $max_iterations ) {
			$iterations++;
			$date_str = $current_date->format( 'Y-m-d' );

			// Skip if off day or holiday
			if ( $this->is_off_day( $current_date, $off_days, $holidays ) ) {
				$current_date->modify( '+1 day' );
				continue;
			}

			// Get capacity for this day
			$capacity = isset( $capacity_overrides[ $date_str ] )
				? (int) $capacity_overrides[ $date_str ]
				: $daily_capacity;

			// Skip if capacity is 0 (blocked day)
			if ( $capacity <= 0 ) {
				$current_date->modify( '+1 day' );
				continue;
			}

			// Get already used slots
			$used = isset( $existing_bookings[ $date_str ] )
				? (int) $existing_bookings[ $date_str ]
				: 0;

			// Calculate available slots
			$available = max( 0, $capacity - $used );

			if ( $available > 0 ) {
				// Take as many slots as we can from this day
				$to_allocate = min( $lm_remaining, $available );

				$allocation[ $date_str ] = $to_allocate;
				$lm_remaining -= $to_allocate;
			}

			$current_date->modify( '+1 day' );
		}

		// Check if we allocated everything
		if ( $lm_remaining > 0 ) {
			throw new \RuntimeException(
				sprintf(
					'Unable to allocate %d LM within next 365 days (capacity exhausted)',
					$lm_required
				)
			);
		}

		// Get start and end dates
		$dates = array_keys( $allocation );
		$production_start = reset( $dates );
		$production_end = end( $dates );

		// Calculate installation minimum date
		$production_end_dt = new DateTime( $production_end );
		$installation_min_dt = clone $production_end_dt;

		if ( $install_buffer > 0 ) {
			$installation_min_dt->modify( "+{$install_buffer} days" );
		}

		return [
			'allocation'            => $allocation,
			'production_start'      => $production_start,
			'production_end'        => $production_end,
			'installation_minimum'  => $installation_min_dt->format( 'Y-m-d' ),
			'total_days'            => count( $allocation ),
		];
	}

	/**
	 * Calculate production schedule FORWARD from installation date
	 *
	 * Production is allocated forward starting from installation date, filling
	 * available slots based on configured daily capacity. If requested date is
	 * full or LM exceeds available capacity, overflow goes to subsequent days.
	 *
	 * Example: 20 LM with install date 20 March, configured capacity/day:
	 * - If 20 March has 9 available: allocates 9 LM there, 11 LM on 21 March
	 * - Installation date auto-adjusts to 21 March (last day of allocation)
	 *
	 * @param int      $lm_required        Linear meters needed
	 * @param DateTime $installation_date  Target installation date (first day of allocation)
	 * @param DateTime $earliest_start     Floor date - production cannot start before this
	 * @param int      $daily_capacity     Default production capacity (LM/day) from settings
	 * @param array    $capacity_overrides [date_string => custom_capacity]
	 * @param array    $existing_bookings  [date_string => total_lm_used]
	 * @param array    $off_days           [0,5] = Sunday, Friday off (0=Sun, 6=Sat)
	 * @param array    $holidays           ["2025-12-25", "2026-01-01"]
	 * @param int      $install_buffer     Not used in this mode (kept for API compatibility)
	 *
	 * @return array Same structure as calculate_schedule()
	 */
	public function calculate_schedule_backward(
		int $lm_required,
		DateTime $installation_date,
		DateTime $earliest_start,
		int $daily_capacity,
		array $capacity_overrides = [],
		array $existing_bookings = [],
		array $off_days = [],
		array $holidays = [],
		int $install_buffer = 0
	): array {
		if ( $lm_required <= 0 ) {
			throw new \InvalidArgumentException( 'LM required must be greater than 0' );
		}

		if ( $daily_capacity <= 0 ) {
			throw new \InvalidArgumentException( 'Daily capacity must be greater than 0' );
		}

		// Start from installation date
		$start_date = clone $installation_date;

		// If installation date is before earliest start, use earliest start
		if ( $start_date < $earliest_start ) {
			$start_date = clone $earliest_start;
		}

		$allocation = [];
		$lm_remaining = $lm_required;
		$current_date = clone $start_date;
		$max_iterations = 365;
		$iterations = 0;

		// Allocate FORWARD from installation date
		// Fill installation date first, then overflow to subsequent days
		while ( $lm_remaining > 0 && $iterations < $max_iterations ) {
			$iterations++;
			$date_str = $current_date->format( 'Y-m-d' );

			// Skip if off day or holiday
			if ( $this->is_off_day( $current_date, $off_days, $holidays ) ) {
				$current_date->modify( '+1 day' );
				continue;
			}

			// Get capacity for this day
			$capacity = isset( $capacity_overrides[ $date_str ] )
				? (int) $capacity_overrides[ $date_str ]
				: $daily_capacity;

			if ( $capacity <= 0 ) {
				$current_date->modify( '+1 day' );
				continue;
			}

			// Get already used slots
			$used = isset( $existing_bookings[ $date_str ] )
				? (int) $existing_bookings[ $date_str ]
				: 0;

			$available = max( 0, $capacity - $used );

			if ( $available > 0 ) {
				$to_allocate = min( $lm_remaining, $available );
				$allocation[ $date_str ] = $to_allocate;
				$lm_remaining -= $to_allocate;
			}

			$current_date->modify( '+1 day' );
		}

		if ( $lm_remaining > 0 ) {
			throw new \RuntimeException(
				sprintf(
					'Unable to allocate %d LM starting from %s (capacity exhausted within 365 days)',
					$lm_required,
					$installation_date->format( 'Y-m-d' )
				)
			);
		}

		$dates = array_keys( $allocation );
		$production_start = reset( $dates );
		$production_end = end( $dates );

		return [
			'allocation'            => $allocation,
			'production_start'      => $production_start,
			'production_end'        => $production_end,
			'installation_minimum'  => $installation_date->format( 'Y-m-d' ),
			'total_days'            => count( $allocation ),
		];
	}

	/**
	 * Check if a date is an off day (weekend or holiday)
	 *
	 * @param DateTime $date
	 * @param array    $off_days  [0,5] = Sunday, Friday off
	 * @param array    $holidays  ["2025-12-25"]
	 *
	 * @return bool
	 */
	private function is_off_day( DateTime $date, array $off_days, array $holidays ): bool {
		// Check if weekday is off
		$weekday = (int) $date->format( 'w' ); // 0=Sunday, 6=Saturday
		if ( in_array( $weekday, $off_days, true ) ) {
			return true;
		}

		// Check if holiday (supports both flat date strings and objects with date/label)
		$date_str = $date->format( 'Y-m-d' );
		foreach ( $holidays as $holiday ) {
			if ( is_string( $holiday ) && $holiday === $date_str ) {
				return true;
			}
			if ( is_array( $holiday ) && isset( $holiday['date'] ) && $holiday['date'] === $date_str ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get available capacity for a specific date
	 *
	 * @param string $date_str           "2026-01-01"
	 * @param int    $daily_capacity     Default capacity
	 * @param array  $capacity_overrides [date => capacity]
	 * @param array  $existing_bookings  [date => used]
	 * @param array  $off_days
	 * @param array  $holidays
	 *
	 * @return int Available slots (0 if off day)
	 */
	public function get_available_capacity(
		string $date_str,
		int $daily_capacity,
		array $capacity_overrides = [],
		array $existing_bookings = [],
		array $off_days = [],
		array $holidays = []
	): int {
		$date = new DateTime( $date_str );

		// Off days have 0 capacity
		if ( $this->is_off_day( $date, $off_days, $holidays ) ) {
			return 0;
		}

		// Get capacity for this day
		$capacity = isset( $capacity_overrides[ $date_str ] )
			? (int) $capacity_overrides[ $date_str ]
			: $daily_capacity;

		// Get used slots
		$used = isset( $existing_bookings[ $date_str ] )
			? (int) $existing_bookings[ $date_str ]
			: 0;

		return max( 0, $capacity - $used );
	}
}
