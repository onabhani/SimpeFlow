<?php
namespace SFA\ProductionScheduling\GravityForms;

use SFA\ProductionScheduling\Admin\FormSettings;

/**
 * Booking Handler
 *
 * Saves production bookings after successful form submission
 */
class BookingHandler {

	public function __construct() {
		// Hook into form submission for immediate booking (default behavior)
		add_action( 'gform_after_submission', [ $this, 'save_production_booking' ], 10, 2 );

		// Hook into GravityFlow step completion for step-based booking
		add_action( 'gravityflow_step_complete', [ $this, 'save_production_booking_after_step' ], 10, 4 );
	}

	/**
	 * Save production booking after workflow step completion
	 *
	 * @param int    $entry_id
	 * @param int    $step_id
	 * @param array  $form
	 * @param object $step
	 */
	public function save_production_booking_after_step( $entry_id, $step_id, $form, $step ) {
		// Check if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			return;
		}

		// Check if booking should happen after this specific step
		$booking_step_id = FormSettings::get_booking_step_id( $form );
		if ( $booking_step_id !== $step_id ) {
			return; // Not the booking trigger step
		}

		// Get entry
		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! $entry ) {
			return;
		}

		// Process the booking
		$this->process_production_booking( $entry, $form );
	}

	/**
	 * Save production booking to entry meta
	 *
	 * @param array $entry
	 * @param array $form
	 */
	public function save_production_booking( $entry, $form ) {
		// Only save if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			return;
		}

		// Check if booking should happen at a specific workflow step
		$booking_step_id = FormSettings::get_booking_step_id( $form );
		if ( $booking_step_id > 0 ) {
			// Booking is deferred to a workflow step, don't create it on submission
			return;
		}

		// Process the booking immediately
		$this->process_production_booking( $entry, $form );
	}

	/**
	 * Process production booking (shared logic)
	 *
	 * @param array $entry
	 * @param array $form
	 */
	private function process_production_booking( $entry, $form ) {

		$lm_field_id = FormSettings::get_lm_field_id( $form );
		$install_field_id = FormSettings::get_install_field_id( $form );
		$prod_start_field_id = FormSettings::get_prod_start_field_id( $form );
		$prod_end_field_id = FormSettings::get_prod_end_field_id( $form );
		$production_fields = FormSettings::get_production_fields( $form );

		// Require installation field and either production fields or legacy LM field
		if ( ! $install_field_id ) {
			return;
		}

		if ( empty( $production_fields ) && ! $lm_field_id ) {
			return;
		}

		$entry_id = (int) $entry['id'];
		$installation_date = isset( $entry[ $install_field_id ] ) ? $entry[ $install_field_id ] : '';

		// Handle multi-field or legacy mode
		$total_slots = 0;
		$lm_required = 0; // Initialize for both modes

		if ( ! empty( $production_fields ) ) {
			// Multi-field mode
			$field_values = array();
			$field_breakdown = array(); // For entry meta storage
			$has_values = false;

			foreach ( $production_fields as $prod_field_config ) {
				$field_id = $prod_field_config['field_id'];
				$field_type = $prod_field_config['field_type'];
				$value = isset( $entry[ $field_id ] ) ? floatval( $entry[ $field_id ] ) : 0;

				$field_values[ $field_id ] = $value;

				if ( $value > 0 ) {
					$has_values = true;
					$field_breakdown[] = array(
						'field_id' => $field_id,
						'field_type' => $field_type,
						'value' => $value,
					);
				}
			}

			// Skip booking if no production values entered yet (prevents premature booking)
			if ( ! $has_values ) {
				return;
			}

			// Calculate total slots
			$total_slots = FormSettings::calculate_total_slots( $field_values, $production_fields );

			if ( $total_slots <= 0 ) {
				return;
			}

			// For multi-field mode, lm_required represents total slots
			$lm_required = $total_slots;

			// Store field breakdown in entry meta
			gform_update_meta( $entry_id, '_prod_field_breakdown', wp_json_encode( $field_breakdown ) );
			gform_update_meta( $entry_id, '_prod_total_slots', $total_slots );

			// Recalculate schedule with live data (multi-field)
			$schedule = BillingStepPreview::calculate_schedule( $field_values, $production_fields, $entry_id );
		} else {
			// Legacy mode (single LM field)
			$lm_required = isset( $entry[ $lm_field_id ] ) ? absint( $entry[ $lm_field_id ] ) : 0;

			if ( $lm_required <= 0 ) {
				return;
			}

			$total_slots = $lm_required;

			// Store LM in entry meta for backwards compatibility
			gform_update_meta( $entry_id, '_prod_lm_required', $lm_required );
			gform_update_meta( $entry_id, '_prod_total_slots', $total_slots );

			// Recalculate schedule with live data (legacy)
			$schedule = BillingStepPreview::calculate_schedule( $lm_required, null, $entry_id );
		}

		if ( is_wp_error( $schedule ) ) {
			// Log error but don't block submission (validation should have caught this)
			error_log( sprintf(
				'Production scheduling error for entry %d: %s',
				$entry_id,
				$schedule->get_error_message()
			) );
			return;
		}

		// Use submitted installation date if valid, otherwise use minimum
		if ( ! $installation_date || $installation_date < $schedule['installation_minimum'] ) {
			$installation_date = $schedule['installation_minimum'];
		}

		// Get production dates from form fields if they were submitted
		// (JavaScript should have populated these, but we verify against calculated schedule)
		$prod_start_date = $schedule['production_start'];
		$prod_end_date = $schedule['production_end'];

		if ( $prod_start_field_id && isset( $entry[ $prod_start_field_id ] ) && $entry[ $prod_start_field_id ] ) {
			// Convert DD/MM/YYYY to YYYY-MM-DD if needed
			$prod_start_date = $this->normalize_date( $entry[ $prod_start_field_id ] );
		}
		if ( $prod_end_field_id && isset( $entry[ $prod_end_field_id ] ) && $entry[ $prod_end_field_id ] ) {
			// Convert DD/MM/YYYY to YYYY-MM-DD if needed
			$prod_end_date = $this->normalize_date( $entry[ $prod_end_field_id ] );
		}

		// Save to entry meta
		gform_update_meta( $entry_id, '_prod_lm_required', $lm_required );
		gform_update_meta( $entry_id, '_prod_slots_allocation', wp_json_encode( $schedule['allocation'] ) );
		gform_update_meta( $entry_id, '_prod_start_date', $prod_start_date );
		gform_update_meta( $entry_id, '_prod_end_date', $prod_end_date );
		gform_update_meta( $entry_id, '_install_date', $installation_date );
		gform_update_meta( $entry_id, '_prod_booking_status', 'confirmed' );
		gform_update_meta( $entry_id, '_prod_booked_at', current_time( 'mysql' ) );
		gform_update_meta( $entry_id, '_prod_booked_by', get_current_user_id() );

		// Store the daily capacity at time of booking for historical tracking
		$daily_capacity_at_booking = (int) get_option( 'sfa_prod_daily_capacity', 10 );
		gform_update_meta( $entry_id, '_prod_daily_capacity_at_booking', $daily_capacity_at_booking );

		// Clear cache for affected dates
		foreach ( array_keys( $schedule['allocation'] ) as $date ) {
			$year_month = substr( $date, 0, 7 );
			wp_cache_delete( 'sfa_prod_availability_' . $year_month );
		}

		// Clear general availability cache
		wp_cache_delete( 'sfa_prod_availability_next_30_days' );

		// Allow other plugins to react to booking
		do_action( 'sfa_production_booking_saved', $entry_id, $schedule, $installation_date );
	}

	/**
	 * Normalize date format from DD/MM/YYYY or YYYY-MM-DD to YYYY-MM-DD
	 *
	 * @param string $date_str
	 * @return string
	 */
	private function normalize_date( $date_str ) {
		$date_str = trim( $date_str );

		// Check if already in YYYY-MM-DD format
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_str ) ) {
			return $date_str;
		}

		// Check if in DD/MM/YYYY format
		if ( preg_match( '/^(\d{2})\/(\d{2})\/(\d{4})$/', $date_str, $matches ) ) {
			// Convert DD/MM/YYYY to YYYY-MM-DD
			return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
		}

		// Try to parse with strtotime as fallback
		$timestamp = strtotime( $date_str );
		if ( $timestamp !== false ) {
			return date( 'Y-m-d', $timestamp );
		}

		// Return as-is if we can't parse it
		return $date_str;
	}
}
