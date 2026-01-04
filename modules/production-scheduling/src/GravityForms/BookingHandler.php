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

		// Hook into entry updates (when editing entries directly)
		add_action( 'gform_after_update_entry', [ $this, 'handle_entry_update' ], 10, 3 );

		// Hook into entry deletion to remove bookings
		add_action( 'gform_delete_entry', [ $this, 'handle_entry_deletion' ], 10, 1 );

		// Hook into workflow cancellation to mark bookings as canceled
		add_action( 'gravityflow_workflow_cancelled', [ $this, 'handle_workflow_cancellation' ], 10, 3 );

		// Hook into workflow step processing to update bookings when entries are edited in workflow inbox
		add_action( 'gravityflow_post_process_workflow', [ $this, 'handle_workflow_processing' ], 10, 4 );

		// Hook into entry status changes (for trash/spam/delete)
		add_action( 'gform_update_status', [ $this, 'handle_entry_status_change' ], 10, 3 );

		// Hook into workflow status changes
		add_action( 'gravityflow_status_updated', [ $this, 'handle_workflow_status_change' ], 10, 4 );

		// Hook into entry meta updates to catch workflow cancellation
		add_action( 'updated_post_meta', [ $this, 'handle_meta_update' ], 10, 4 );

		// Hook directly into GravityFlow cancel workflow action (try multiple possible action names)
		add_action( 'wp_ajax_gravityflow_cancel_workflow', [ $this, 'handle_cancel_workflow_ajax' ], 5 );
		add_action( 'wp_ajax_gf_cancel_workflow', [ $this, 'handle_cancel_workflow_ajax' ], 5 );

		// Hook into admin_init to catch cancel workflow action
		add_action( 'admin_init', [ $this, 'check_cancel_workflow_request' ] );

		// Debug: Log all gravityflow hooks
		error_log( 'Production Booking: BookingHandler initialized with hooks' );
	}

	/**
	 * Handle entry update (when editing entries directly)
	 *
	 * @param array $form  The form object
	 * @param int   $entry_id The entry ID
	 * @param array $original_entry The original entry before update
	 */
	public function handle_entry_update( $form, $entry_id, $original_entry ) {
		// Get updated entry
		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! $entry ) {
			return;
		}

		// Check if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			return;
		}

		// Check if booking should happen at a specific workflow step
		$booking_step_id = FormSettings::get_booking_step_id( $form );
		if ( $booking_step_id > 0 ) {
			// Step-based booking: only update if entry has existing booking
			// (don't create new booking on edit, only update existing ones)
			$existing_booking = gform_get_meta( $entry_id, '_install_date' );
			if ( ! $existing_booking ) {
				return; // No existing booking, skip
			}
		}

		// Process the booking (will update if exists, or create if immediate mode)
		$this->process_production_booking( $entry, $form );
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

		// Check if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			return;
		}

		// Check if booking should happen after this specific step
		$booking_step_id = FormSettings::get_booking_step_id( $form );

		if ( $booking_step_id != $step_id ) {
			return; // Not the booking trigger step
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

		// Normalize installation date format (convert DD/MM/YYYY to YYYY-MM-DD if needed)
		if ( $installation_date ) {
			$installation_date = $this->normalize_date( $installation_date );
		}

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
			// IMPORTANT: Save _prod_lm_required for date preservation logic to work
			gform_update_meta( $entry_id, '_prod_lm_required', $lm_required );

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
			gform_update_meta( $entry_id, '_prod_total_slots', $total_slots );
			gform_update_meta( $entry_id, '_prod_lm_required', $lm_required );

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

		// Check if this is a re-booking (existing booking meta)
		$existing_install_date = gform_get_meta( $entry_id, '_install_date' );
		$existing_lm = gform_get_meta( $entry_id, '_prod_lm_required' );
		$existing_prod_start = gform_get_meta( $entry_id, '_prod_start_date' );
		$existing_prod_end = gform_get_meta( $entry_id, '_prod_end_date' );
		$existing_allocation = gform_get_meta( $entry_id, '_prod_slots_allocation' );

		// Determine if LM changed
		$lm_changed = $existing_lm && ( (float) $existing_lm !== (float) $lm_required );

		error_log( sprintf(
			'Production Booking DEBUG for entry %d: existing_lm=%s, new_lm=%s, lm_changed=%s, existing_install=%s, submitted_install=%s',
			$entry_id,
			$existing_lm ? $existing_lm : 'NULL',
			$lm_required,
			$lm_changed ? 'TRUE' : 'FALSE',
			$existing_install_date ? $existing_install_date : 'NULL',
			$installation_date
		) );

		if ( $existing_install_date && $existing_allocation && ! $lm_changed ) {
			error_log( sprintf( 'Production Booking: PRESERVING existing dates for entry %d (IGNORING submitted date)', $entry_id ) );
			// Re-booking with unchanged LM: Keep ALL existing booking data
			// IGNORE the submitted installation_date entirely - JavaScript may have changed it
			$installation_date = $existing_install_date;
			$prod_start_date = $existing_prod_start;
			$prod_end_date = $existing_prod_end;

			// Use existing allocation (don't recalculate)
			// This ensures the exact same slots are preserved
			$use_existing_allocation = true;

			// DON'T recalculate schedule at all - we're using existing data
			// Skip the schedule calculation entirely
		} else {
			$use_existing_allocation = false;
			// New booking OR LM changed: Calculate new schedule
			$date_changed = $existing_install_date && ( $submitted_installation_date !== $existing_install_date );

			if ( $existing_install_date && $lm_changed ) {
				// LM changed: Use submitted date if valid, otherwise use calculated minimum
				if ( $submitted_installation_date && $submitted_installation_date >= $schedule['installation_minimum'] ) {
					$installation_date = $submitted_installation_date;
				} else {
					$installation_date = $schedule['installation_minimum'];
				}
			} elseif ( ! $existing_install_date ) {
				// New booking: use submitted date if valid, otherwise use calculated minimum
				if ( $submitted_installation_date && $submitted_installation_date >= $schedule['installation_minimum'] ) {
					$installation_date = $submitted_installation_date;
				} else {
					$installation_date = $schedule['installation_minimum'];
				}
			}

			// Get production dates from calculated schedule
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
		}

		// Clear cache for old allocation dates (only if recalculating)
		if ( ! $use_existing_allocation ) {
			$old_allocation_json = gform_get_meta( $entry_id, '_prod_slots_allocation' );
			if ( $old_allocation_json ) {
				$old_allocation = json_decode( $old_allocation_json, true );
				if ( is_array( $old_allocation ) ) {
					foreach ( array_keys( $old_allocation ) as $old_date ) {
						$year_month = substr( $old_date, 0, 7 );
						wp_cache_delete( 'sfa_prod_availability_' . $year_month );
					}
				}
			}
		}

		// Save to entry meta (note: _prod_lm_required already saved above in both multi-field and legacy modes)

		// Use existing or new allocation based on whether data was preserved
		if ( $use_existing_allocation ) {
			// Keep existing allocation (no changes needed)
			$allocation_to_save = $existing_allocation;
			$allocation_array = json_decode( $existing_allocation, true );
		} else {
			// Use newly calculated allocation
			$allocation_to_save = wp_json_encode( $schedule['allocation'] );
			$allocation_array = $schedule['allocation'];
		}

		gform_update_meta( $entry_id, '_prod_slots_allocation', $allocation_to_save );
		gform_update_meta( $entry_id, '_prod_start_date', $prod_start_date );
		gform_update_meta( $entry_id, '_prod_end_date', $prod_end_date );
		gform_update_meta( $entry_id, '_install_date', $installation_date );
		gform_update_meta( $entry_id, '_prod_booking_status', 'confirmed' );
		gform_update_meta( $entry_id, '_prod_booked_at', current_time( 'mysql' ) );
		gform_update_meta( $entry_id, '_prod_booked_by', get_current_user_id() );

		error_log( sprintf(
			'Production Booking SAVED for entry %d: install_date=%s, prod_start=%s, prod_end=%s, lm=%s',
			$entry_id,
			$installation_date,
			$prod_start_date,
			$prod_end_date,
			$lm_required
		) );

		// Store the daily capacity at time of booking for historical tracking
		$daily_capacity_at_booking = (int) get_option( 'sfa_prod_daily_capacity', 10 );
		gform_update_meta( $entry_id, '_prod_daily_capacity_at_booking', $daily_capacity_at_booking );

		// Clear cache for affected dates
		if ( is_array( $allocation_array ) ) {
			foreach ( array_keys( $allocation_array ) as $date ) {
				$year_month = substr( $date, 0, 7 );
				wp_cache_delete( 'sfa_prod_availability_' . $year_month );
			}
		}

		// Clear general availability cache
		wp_cache_delete( 'sfa_prod_availability_next_30_days' );

		// Allow other plugins to react to booking
		do_action( 'sfa_production_booking_saved', $entry_id, $schedule, $installation_date );
	}

	/**
	 * Handle workflow processing - update booking when entry edited in workflow inbox
	 *
	 * This handles cases where users edit entries in the workflow inbox and the
	 * step is processed again (but not necessarily completed)
	 *
	 * @param array  $form      The form object
	 * @param int    $entry_id  The entry ID
	 * @param object $step      The current step object
	 * @param int    $step_id   The step ID (NOT status!)
	 */
	public function handle_workflow_processing( $form, $entry_id, $step, $step_id ) {
		error_log( sprintf( 'Production Booking: Workflow processing - Entry %d, Step ID: %d', $entry_id, $step_id ) );

		// Check if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			error_log( 'Production Booking: Production scheduling not enabled' );
			return;
		}

		// Check if entry has an existing booking
		$existing_booking = gform_get_meta( $entry_id, '_install_date' );
		if ( ! $existing_booking ) {
			error_log( 'Production Booking: No existing booking found, skipping update' );
			return; // No existing booking to update
		}

		// Get updated entry
		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! $entry ) {
			error_log( 'Production Booking: Failed to load entry' );
			return;
		}

		error_log( sprintf( 'Production Booking: Updating booking for entry %d via workflow processing', $entry_id ) );
		// Update the booking with new values
		$this->process_production_booking( $entry, $form );
	}

	/**
	 * Handle entry status changes (trash, spam, delete, restore)
	 *
	 * @param int    $entry_id The entry ID
	 * @param string $status   The new status (active, trash, spam)
	 * @param string $old_status The old status
	 */
	public function handle_entry_status_change( $entry_id, $status, $old_status ) {
		error_log( sprintf( 'Production Booking: Entry %d status changed from %s to %s', $entry_id, $old_status, $status ) );

		// If entry is being trashed or marked as spam, remove the booking
		if ( in_array( $status, [ 'trash', 'spam' ], true ) ) {
			error_log( sprintf( 'Production Booking: Removing booking for trashed/spam entry %d', $entry_id ) );
			$this->handle_entry_deletion( $entry_id );
		}

		// If entry is being restored, we don't recreate booking (user must go through workflow again)
	}

	/**
	 * Handle workflow status changes (complete, cancelled, pending, etc)
	 *
	 * @param int    $entry_id The entry ID
	 * @param string $new_status The new workflow status
	 * @param string $old_status The old workflow status
	 * @param object $step The current step
	 */
	public function handle_workflow_status_change( $entry_id, $new_status, $old_status, $step ) {
		error_log( sprintf( 'Production Booking: Entry %d workflow status changed from %s to %s', $entry_id, $old_status, $new_status ) );

		// If workflow is cancelled, mark booking as canceled
		if ( 'cancelled' === $new_status || 'canceled' === $new_status ) {
			error_log( sprintf( 'Production Booking: Marking booking as canceled for entry %d', $entry_id ) );
			$existing_booking = gform_get_meta( $entry_id, '_install_date' );
			if ( $existing_booking ) {
				gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
				error_log( sprintf( 'Production Booking: Booking marked as canceled for entry %d', $entry_id ) );
			}
		}
	}

	/**
	 * Check for cancel workflow request in admin_init
	 *
	 * This catches when user clicks "Cancel Workflow" link
	 */
	public function check_cancel_workflow_request() {
		// Check if this is a cancel workflow request
		if ( ! isset( $_GET['gf_cancel_workflow'] ) && ! isset( $_POST['gf_cancel_workflow'] ) ) {
			return;
		}

		// Get entry ID from request
		$entry_id = isset( $_GET['lid'] ) ? absint( $_GET['lid'] ) : ( isset( $_POST['lid'] ) ? absint( $_POST['lid'] ) : 0 );

		if ( ! $entry_id ) {
			error_log( 'Production Booking: Cancel workflow request but no entry ID found' );
			return;
		}

		error_log( sprintf( 'Production Booking: Cancel workflow REQUEST detected for entry %d', $entry_id ) );

		// Mark booking as canceled
		$existing_booking = gform_get_meta( $entry_id, '_install_date' );
		if ( $existing_booking ) {
			gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
			error_log( sprintf( 'Production Booking: Booking marked as canceled via admin_init for entry %d', $entry_id ) );
		}
	}

	/**
	 * Handle cancel workflow AJAX action (before GravityFlow processes it)
	 *
	 * This hooks early into the AJAX action to catch cancellation
	 */
	public function handle_cancel_workflow_ajax() {
		// Get entry ID from request
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : (
			isset( $_POST['lid'] ) ? absint( $_POST['lid'] ) : (
				isset( $_GET['lid'] ) ? absint( $_GET['lid'] ) : 0
			)
		);

		if ( ! $entry_id ) {
			error_log( 'Production Booking: Cancel workflow AJAX but no entry ID in request' );
			return;
		}

		error_log( sprintf( 'Production Booking: Cancel workflow AJAX triggered for entry %d', $entry_id ) );

		// Mark booking as canceled immediately
		$existing_booking = gform_get_meta( $entry_id, '_install_date' );
		if ( $existing_booking ) {
			gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
			error_log( sprintf( 'Production Booking: Booking marked as canceled via AJAX for entry %d', $entry_id ) );
		}
	}

	/**
	 * Handle entry meta updates to catch workflow cancellation
	 *
	 * This catches when GravityFlow updates workflow status meta
	 *
	 * @param int    $meta_id    ID of updated metadata entry
	 * @param int    $object_id  Post ID (entry ID in GF context)
	 * @param string $meta_key   Metadata key
	 * @param mixed  $meta_value Metadata value
	 */
	public function handle_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
		// Check for various GravityFlow status meta keys
		$workflow_status_keys = [
			'workflow_final_status',
			'_gravityflow_workflow_status',
			'gravityflow_workflow_status'
		];

		if ( ! in_array( $meta_key, $workflow_status_keys, true ) ) {
			return;
		}

		$entry_id = $object_id;
		error_log( sprintf( 'Production Booking: Meta update - Entry %d, Key: %s, Value: %s', $entry_id, $meta_key, $meta_value ) );

		// If workflow is cancelled, mark booking as canceled
		if ( 'cancelled' === $meta_value || 'canceled' === $meta_value ) {
			error_log( sprintf( 'Production Booking: Workflow cancelled via meta - Entry %d', $entry_id ) );
			$existing_booking = gform_get_meta( $entry_id, '_install_date' );
			if ( $existing_booking ) {
				gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
				error_log( sprintf( 'Production Booking: Booking marked as canceled for entry %d', $entry_id ) );
			}
		}
	}

	/**
	 * Handle entry deletion - remove production booking
	 *
	 * @param int $entry_id The entry ID being deleted
	 */
	public function handle_entry_deletion( $entry_id ) {
		// Get existing booking data before deletion
		$existing_allocation = gform_get_meta( $entry_id, '_prod_slots_allocation' );

		if ( ! $existing_allocation ) {
			return; // No booking to remove
		}

		// Clear cache for allocated dates
		$allocation = json_decode( $existing_allocation, true );
		if ( is_array( $allocation ) ) {
			foreach ( array_keys( $allocation ) as $date ) {
				$year_month = substr( $date, 0, 7 );
				wp_cache_delete( 'sfa_prod_availability_' . $year_month );
			}
		}

		// Delete all production booking meta
		gform_delete_meta( $entry_id, '_prod_lm_required' );
		gform_delete_meta( $entry_id, '_prod_total_slots' );
		gform_delete_meta( $entry_id, '_prod_slots_allocation' );
		gform_delete_meta( $entry_id, '_prod_start_date' );
		gform_delete_meta( $entry_id, '_prod_end_date' );
		gform_delete_meta( $entry_id, '_install_date' );
		gform_delete_meta( $entry_id, '_prod_booking_status' );
		gform_delete_meta( $entry_id, '_prod_booked_at' );
		gform_delete_meta( $entry_id, '_prod_booked_by' );
		gform_delete_meta( $entry_id, '_prod_daily_capacity_at_booking' );
		gform_delete_meta( $entry_id, '_prod_field_breakdown' );

		// Clear general availability cache
		wp_cache_delete( 'sfa_prod_availability_next_30_days' );

		// Allow other plugins to react
		do_action( 'sfa_production_booking_deleted', $entry_id );
	}

	/**
	 * Handle workflow cancellation - mark booking as canceled
	 *
	 * @param int    $entry_id The entry ID
	 * @param array  $form     The form object
	 * @param object $step     The current step object
	 */
	public function handle_workflow_cancellation( $entry_id, $form, $step ) {
		// Check if entry has a production booking
		$existing_booking = gform_get_meta( $entry_id, '_install_date' );

		if ( ! $existing_booking ) {
			return; // No booking to cancel
		}

		// Mark booking as canceled (keep the allocation, just change status)
		gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );

		// Note: We keep the slots allocated (don't clear cache) so they remain reserved
		// This prevents overbooking in case the workflow is reactivated

		// Allow other plugins to react
		do_action( 'sfa_production_booking_canceled', $entry_id );
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
