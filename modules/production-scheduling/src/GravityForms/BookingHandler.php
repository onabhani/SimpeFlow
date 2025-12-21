<?php
namespace SFA\ProductionScheduling\GravityForms;

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
		// TODO: Check if this form has production scheduling enabled

		// TODO: Get LM from entry

		// TODO: Recalculate schedule one final time

		// TODO: Save to entry meta:
		// - _prod_lm_required
		// - _prod_slots_allocation (JSON)
		// - _prod_start_date
		// - _prod_end_date
		// - _install_date
		// - _prod_booking_status
		// - _prod_booked_at
		// - _prod_booked_by

		// TODO: Clear cache
	}
}
