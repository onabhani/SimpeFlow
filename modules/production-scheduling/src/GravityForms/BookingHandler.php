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
		add_action( 'gform_after_submission', [ $this, 'save_production_booking' ], 10, 2 );
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

		$lm_field_id = FormSettings::get_lm_field_id( $form );
		$install_field_id = FormSettings::get_install_field_id( $form );
		$prod_start_field_id = FormSettings::get_prod_start_field_id( $form );
		$prod_end_field_id = FormSettings::get_prod_end_field_id( $form );

		if ( ! $lm_field_id || ! $install_field_id ) {
			return;
		}

		$entry_id = (int) $entry['id'];

		// Get values from entry
		$lm_required = isset( $entry[ $lm_field_id ] ) ? absint( $entry[ $lm_field_id ] ) : 0;
		$installation_date = isset( $entry[ $install_field_id ] ) ? $entry[ $install_field_id ] : '';

		if ( $lm_required <= 0 ) {
			return;
		}

		// Recalculate schedule one final time with live data
		$schedule = BillingStepPreview::calculate_schedule( $lm_required );

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
