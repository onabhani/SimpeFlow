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
	 * GravityFlow hook signature: do_action( 'gravityflow_step_complete', $step_id, $entry_id, $form_id, $step )
	 *
	 * @param int    $step_id     The step ID that completed
	 * @param int    $entry_id    The entry ID
	 * @param int    $form_id     The form ID (integer, not array)
	 * @param object $step        The step object
	 */
	public function save_production_booking_after_step( $step_id, $entry_id, $form_id, $step ) {
		// Get entry first to ensure we have the correct form ID
		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! $entry ) {
			error_log( sprintf(
				'Production Booking: Failed to load entry %d',
				$entry_id
			) );
			return;
		}

		// Load form (GravityFlow passes form_id as integer, not array)
		$form = \GFAPI::get_form( $form_id );

		if ( ! $form || is_wp_error( $form ) ) {
			error_log( sprintf(
				'Production Booking: Failed to load form %d for entry %d',
				$form_id,
				$entry_id
			) );
			return;
		}

		error_log( sprintf(
			'Production Booking: Loaded form %d for entry %d (step %d completed)',
			$form_id,
			$entry_id,
			$step_id
		) );

		// Check if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			error_log( sprintf(
				'Production Booking: Skipped entry %d - production scheduling not enabled for form %d',
				$entry_id,
				$form['id']
			) );
			return;
		}

		// Check if booking should happen after this specific step
		$booking_step_id = FormSettings::get_booking_step_id( $form );

		error_log( sprintf(
			'Production Booking: Entry %d - Step %d completed, booking step configured as %d',
			$entry_id,
			$step_id,
			$booking_step_id
		) );

		if ( $booking_step_id != $step_id ) {
			error_log( sprintf(
				'Production Booking: Skipped entry %d - step mismatch (completed: %d, required: %d)',
				$entry_id,
				$step_id,
				$booking_step_id
			) );
			return; // Not the booking trigger step
		}

		error_log( sprintf(
			'Production Booking: ✓ Creating booking for entry %d after step %d completion',
			$entry_id,
			$step_id
		) );

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

		// Normalize installation date format (convert DD/MM/YYYY to YYYY-MM-DD if needed)
		if ( $installation_date ) {
			$installation_date = $this->normalize_date( $installation_date );
		}

		error_log( sprintf(
			'Production Booking: Entry %d - Installation date from entry field %d: "%s" (normalized: "%s")',
			$entry_id,
			$install_field_id,
			isset( $entry[ $install_field_id ] ) ? $entry[ $install_field_id ] : '(empty)',
			$installation_date
		) );

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

		error_log( sprintf(
			'Production Booking: Entry %d - Schedule calculated. Min install date: %s, Submitted install date: %s',
			$entry_id,
			$schedule['installation_minimum'],
			$installation_date
		) );

		// Check if this is a re-booking (existing installation date in meta)
		$existing_install_date = gform_get_meta( $entry_id, '_install_date' );

		// Use submitted installation date if valid, otherwise use minimum
		// BUT: If this is a re-booking, preserve the original installation date
		$original_installation_date = $installation_date;
		if ( $existing_install_date ) {
			// Re-booking: preserve original installation date
			$installation_date = $existing_install_date;
			error_log( sprintf(
				'Production Booking: Entry %d - Re-booking detected. Preserving original installation date: %s (submitted: %s, calculated min: %s)',
				$entry_id,
				$installation_date,
				$original_installation_date,
				$schedule['installation_minimum']
			) );
		} elseif ( ! $installation_date || $installation_date < $schedule['installation_minimum'] ) {
			// New booking: use calculated minimum if submitted date is invalid/too early
			$installation_date = $schedule['installation_minimum'];
			error_log( sprintf(
				'Production Booking: Entry %d - New booking. Using calculated min date %s instead of submitted date %s',
				$entry_id,
				$installation_date,
				$original_installation_date ?: '(empty)'
			) );
		}

		error_log( sprintf(
			'Production Booking: Entry %d - Final installation date to save: %s',
			$entry_id,
			$installation_date
		) );

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

		// Clear cache for old allocation dates (before updating)
		$old_allocation_json = gform_get_meta( $entry_id, '_prod_slots_allocation' );
		if ( $old_allocation_json ) {
			$old_allocation = json_decode( $old_allocation_json, true );
			if ( is_array( $old_allocation ) ) {
				foreach ( array_keys( $old_allocation ) as $old_date ) {
					$year_month = substr( $old_date, 0, 7 );
					wp_cache_delete( 'sfa_prod_availability_' . $year_month );
					error_log( sprintf(
						'Production Booking: Entry %d - Clearing cache for old allocation date: %s',
						$entry_id,
						$old_date
					) );
				}
			}
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
