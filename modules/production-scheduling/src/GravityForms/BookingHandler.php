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

		// Hook directly into GravityFlow cancel workflow action (try multiple possible action names)
		add_action( 'wp_ajax_gravityflow_cancel_workflow', [ $this, 'handle_cancel_workflow_ajax' ], 5 );
		add_action( 'wp_ajax_gf_cancel_workflow', [ $this, 'handle_cancel_workflow_ajax' ], 5 );

		// Hook into admin_init to catch cancel workflow action and sync cancelled statuses
		add_action( 'admin_init', [ $this, 'check_cancel_workflow_request' ] );
		add_action( 'admin_init', [ $this, 'sync_cancelled_workflow_bookings' ] );

		// AJAX hook for capacity check before admin save
		add_action( 'wp_ajax_sfa_prod_check_capacity_before_save', [ $this, 'ajax_check_capacity_before_save' ] );

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

		$entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;

		// Register shutdown handler to capture ANY fatal error (TypeError, class not found, etc.)
		register_shutdown_function( function () use ( $entry_id ) {
			$error = error_get_last();
			if ( $error && ( $error['type'] & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR ) ) ) {
				error_log( sprintf(
					'Production Booking SHUTDOWN FATAL for entry %d: [%s] %s in %s on line %d',
					$entry_id, $error['type'], $error['message'], $error['file'], $error['line']
				) );
			}
		} );

		// CRITICAL: Acquire MySQL named lock to prevent race conditions
		// GET_LOCK is atomic and blocks concurrent bookings until released
		global $wpdb;
		$lock_name = 'sfa_prod_booking';
		$lock_timeout = 15; // seconds to wait for lock
		$lock_acquired = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT GET_LOCK(%s, %d)",
			$lock_name, $lock_timeout
		) );

		if ( ! $lock_acquired ) {
			error_log( sprintf( 'Production Booking: FAILED to acquire lock for entry %d after %ds', $entry['id'], $lock_timeout ) );
			return;
		}

		try {

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

		// Store the submitted installation date for later comparison (before any modifications)
		$submitted_installation_date = $installation_date;

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
			try {
				$schedule = BillingStepPreview::calculate_schedule( $field_values, $production_fields, $entry_id );
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'Production Booking FATAL: Forward schedule crashed for entry %d: %s (%s at %s:%d)', $entry_id, $e->getMessage(), get_class( $e ), $e->getFile(), $e->getLine() ) );
				return;
			}
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
			try {
				$schedule = BillingStepPreview::calculate_schedule( $lm_required, null, $entry_id );
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'Production Booking FATAL: Forward schedule crashed for entry %d: %s (%s at %s:%d)', $entry_id, $e->getMessage(), get_class( $e ), $e->getFile(), $e->getLine() ) );
				return;
			}
		}

		if ( is_wp_error( $schedule ) ) {
			// Log error but don't block submission (validation should have caught this)
			error_log( sprintf(
				'Production scheduling error for entry %d: %s',
				$entry_id,
				$schedule->get_error_message()
			) );

			// CRITICAL: If admin is manually creating/editing entry, show warning
			if ( is_admin() && ! wp_doing_ajax() ) {
				add_action( 'admin_notices', function() use ( $schedule, $entry_id ) {
					echo '<div class="notice notice-error is-dismissible">';
					echo '<p><strong>Production Scheduling Error (Entry #' . $entry_id . '):</strong> ' . esc_html( $schedule->get_error_message() ) . '</p>';
					echo '<p>This usually means production capacity is fully booked for the next 365 days. The entry was NOT given a production schedule.</p>';
					echo '</div>';
				} );
			}

			return;
		}

		// CRITICAL SECURITY: Check if admin is bypassing capacity validation
		// Validation is skipped in admin area, so we need to warn about overbooking
		if ( is_admin() && ! wp_doing_ajax() && ! defined( 'DOING_CRON' ) ) {
			$this->check_admin_overbooking_warning( $schedule, $entry_id );
		}

		// Check if this is a re-booking (existing booking meta)
		$existing_install_date = gform_get_meta( $entry_id, '_install_date' );
		$existing_lm = gform_get_meta( $entry_id, '_prod_lm_required' );
		$existing_prod_start = gform_get_meta( $entry_id, '_prod_start_date' );
		$existing_prod_end = gform_get_meta( $entry_id, '_prod_end_date' );
		$existing_allocation = gform_get_meta( $entry_id, '_prod_slots_allocation' );

		// Determine if LM changed
		$lm_changed = $existing_lm && ( (float) $existing_lm !== (float) $lm_required );

		// Check if installation date changed (from ANY context - admin, frontend, or workflow)
		$date_changed = $existing_install_date && $submitted_installation_date && ( $submitted_installation_date !== $existing_install_date );

		// Check if existing production dates need recalculation
		// Production should be scheduled backward from install_date, so prod_end
		// should be close to (install_date - buffer). If it's far earlier, dates
		// are stale (from old forward scheduling) and need recalculation.
		$dates_inconsistent = false;
		if ( $existing_install_date && $existing_prod_start && $existing_prod_end ) {
			// Impossible states: production ends after installation, or start > end
			if ( $existing_prod_end > $existing_install_date || $existing_prod_start > $existing_prod_end ) {
				$dates_inconsistent = true;
			} else {
				// Check if prod_end is too far before install_date (stale forward-scheduled dates)
				$installation_buffer = (int) get_option( 'sfa_prod_installation_buffer', 0 );
				$expected_latest = strtotime( $existing_install_date . " -{$installation_buffer} days" );
				$actual_end = strtotime( $existing_prod_end );
				$gap_days = ( $expected_latest - $actual_end ) / 86400;

				// More than 2 days gap means dates are stale and need backward recalculation
				// (2-day margin accounts for weekends/holidays at the boundary)
				if ( $gap_days > 2 ) {
					$dates_inconsistent = true;
				}
			}

		}

		// FORCE RECALCULATION if installation date changed OR LM changed OR dates are inconsistent
		if ( $existing_install_date && $existing_allocation && ! $lm_changed && ! $date_changed && ! $dates_inconsistent ) {
			// Re-booking with unchanged LM: Keep ALL existing booking data
			// IGNORE the submitted installation_date entirely - JavaScript may have changed it
			// EXCEPTION: Allow date changes when manually editing in admin
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

			// Determine final installation date based on the scenario
			if ( $date_changed ) {
				// Installation date changed: ALWAYS use the new submitted date
				// This is the user's explicit choice - respect it
				$installation_date = $submitted_installation_date;
			} elseif ( $existing_install_date && $lm_changed ) {
				// LM changed but date didn't: Use submitted date if valid, otherwise use calculated minimum
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
			} elseif ( $dates_inconsistent ) {
				// Inconsistent dates: keep existing install date but recalculate allocation
				$installation_date = $existing_install_date;
			}

			// Schedule BACKWARD from installation date so production aligns with it.
			// The installation date is the anchor; production dates are derived from it,
			// respecting the installation buffer setting (0 = same day allowed).
			try {
				if ( ! empty( $production_fields ) ) {
					$new_schedule = BillingStepPreview::calculate_schedule_for_date( $installation_date, $field_values, $production_fields, $entry_id );
				} else {
					$new_schedule = BillingStepPreview::calculate_schedule_for_date( $installation_date, $lm_required, null, $entry_id );
				}

				if ( is_wp_error( $new_schedule ) ) {
					error_log( sprintf(
						'Production Booking ERROR: Backward scheduling failed for entry %d: %s. Using forward-scheduled fallback.',
						$entry_id, $new_schedule->get_error_message()
					) );
					// Keep using the forward-scheduled $schedule as fallback
				} else {
					$schedule = $new_schedule;
				}
			} catch ( \Throwable $e ) {
				error_log( sprintf(
					'Production Booking THROWABLE: Backward scheduling failed for entry %d: %s (Type: %s, File: %s, Line: %d)',
					$entry_id, $e->getMessage(), get_class( $e ), $e->getFile(), $e->getLine()
				) );
				// Keep using the forward-scheduled $schedule as fallback
			}

			// SAFETY CHECK: Verify schedule is valid array before accessing keys
			if ( ! is_array( $schedule ) || is_wp_error( $schedule ) ) {
				error_log( sprintf(
					'Production Booking CRITICAL ERROR: Schedule is not a valid array for entry %d. Type: %s. Cannot save booking.',
					$entry_id,
					is_wp_error( $schedule ) ? 'WP_Error: ' . $schedule->get_error_message() : gettype( $schedule )
				) );

				// Cannot proceed without valid schedule - this should never happen as initial schedule
				// calculation would have failed and returned early, but adding safety check
				return;
			}

			// SAFETY CHECK: Verify required schedule keys exist
			if ( ! isset( $schedule['production_start'] ) || ! isset( $schedule['production_end'] ) ) {
				error_log( sprintf(
					'Production Booking CRITICAL ERROR: Schedule missing required keys for entry %d. Keys: %s. Cannot save booking.',
					$entry_id,
					implode( ', ', array_keys( $schedule ) )
				) );
				return;
			}

			// Get production dates from the freshly calculated schedule.
			// IMPORTANT: Do NOT read dates back from the entry's form
			// fields here — they still contain the OLD values when only
			// the installation date was changed.  Instead, write the
			// recalculated dates INTO those fields so the entry stays
			// in sync with the new allocation.
			$prod_start_date = $schedule['production_start'];
			$prod_end_date = $schedule['production_end'];

			// AUTO-ADJUST installation date to the LAST day of allocation
			// This handles cases where:
			// - The requested installation date was full, so allocation spilled to next days
			// - The LM required more days than expected
			// Installation date = production_end (last day of allocated slots)
			$installation_date = $prod_end_date;

			if ( $prod_start_field_id ) {
				\GFAPI::update_entry_field( $entry_id, $prod_start_field_id, $prod_start_date );
			}
			if ( $prod_end_field_id ) {
				\GFAPI::update_entry_field( $entry_id, $prod_end_field_id, $prod_end_date );
			}
			// Always sync the installation date field to the actual last day of allocation
			if ( $install_field_id ) {
				\GFAPI::update_entry_field( $entry_id, $install_field_id, $installation_date );
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
			// SAFETY CHECK: Verify allocation key exists
			if ( ! isset( $schedule['allocation'] ) ) {
				error_log( sprintf(
					'Production Booking ERROR: Schedule missing allocation key for entry %d. Available keys: %s',
					$entry_id,
					implode( ', ', array_keys( $schedule ) )
				) );
				// Fall back to existing allocation if available, otherwise use empty array
				$allocation_to_save = $existing_allocation ? $existing_allocation : wp_json_encode( array() );
				$allocation_array = json_decode( $allocation_to_save, true );
			} else {
				$allocation_to_save = wp_json_encode( $schedule['allocation'] );
				$allocation_array = $schedule['allocation'];
			}
		}

		// P1 FIX: Wrap meta updates in database transaction to ensure atomicity
		// This prevents partial booking saves if database fails mid-operation
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			gform_update_meta( $entry_id, '_prod_slots_allocation', $allocation_to_save );
			gform_update_meta( $entry_id, '_prod_start_date', $prod_start_date );
			gform_update_meta( $entry_id, '_prod_end_date', $prod_end_date );
			gform_update_meta( $entry_id, '_install_date', $installation_date );
			gform_update_meta( $entry_id, '_prod_booking_status', 'confirmed' );
			gform_update_meta( $entry_id, '_prod_booked_at', current_time( 'mysql' ) );

			// Always store entry creator as booked_by (deterministic - never changes)
			$creator_id = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;
			gform_update_meta( $entry_id, '_prod_booked_by', $creator_id );

			// Store the daily capacity at time of booking for historical tracking
			$daily_capacity_at_booking = (int) get_option( 'sfa_prod_daily_capacity', 10 );
			gform_update_meta( $entry_id, '_prod_daily_capacity_at_booking', $daily_capacity_at_booking );

			// All updates successful - commit transaction
			$wpdb->query( 'COMMIT' );

			// P2 FIX: Audit log for booking creation
			$this->log_booking_audit( $entry_id, 'booking_created', [
				'install_date' => $installation_date,
				'prod_start' => $prod_start_date,
				'prod_end' => $prod_end_date,
				'lm_required' => $lm_required,
				'capacity_at_booking' => $daily_capacity_at_booking,
			] );

		} catch ( Exception $e ) {
			// Transaction failed - rollback all changes
			$wpdb->query( 'ROLLBACK' );
			error_log( sprintf(
				'Production Booking FAILED for entry %d: %s',
				$entry_id,
				$e->getMessage()
			) );
			throw $e; // Re-throw to trigger outer finally block for lock release
		}

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

		} finally {
			// CRITICAL: Always release MySQL lock, even if error occurs
			if ( $lock_acquired ) {
				$wpdb->get_var( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
			}
		}
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
		// Check if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			return;
		}

		// Check if entry has an existing booking
		$existing_booking = gform_get_meta( $entry_id, '_install_date' );
		if ( ! $existing_booking ) {
			return; // No existing booking to update
		}

		// Get updated entry
		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! $entry ) {
			return;
		}

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
		// P2 AUDIT: Log status change
		$this->log_booking_audit( $entry_id, 'status_changed', [
			'old_status' => $old_status,
			'new_status' => $status,
		] );

		// P2 FIX: If entry is being trashed or marked as spam, remove the booking
		// This handles both individual and BULK trash/spam operations
		if ( in_array( $status, [ 'trash', 'spam' ], true ) ) {
			$this->handle_entry_deletion( $entry_id );
		}

		// P3 FIX: If entry is being restored from trash, restore the booking if it existed
		if ( 'active' === $status && in_array( $old_status, [ 'trash', 'spam' ], true ) ) {
			$this->handle_entry_restore( $entry_id );
		}
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
		// If workflow is cancelled, mark booking as canceled
		if ( 'cancelled' === $new_status || 'canceled' === $new_status ) {
			$existing_booking = gform_get_meta( $entry_id, '_install_date' );
			if ( $existing_booking ) {
				gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
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
			return;
		}

		// Mark booking as canceled
		$existing_booking = gform_get_meta( $entry_id, '_install_date' );
		if ( $existing_booking ) {
			gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
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
			return;
		}

		// Mark booking as canceled immediately
		$existing_booking = gform_get_meta( $entry_id, '_install_date' );
		if ( $existing_booking ) {
			gform_update_meta( $entry_id, '_prod_booking_status', 'canceled' );
		}
	}

	/**
	 * Sync cancelled workflow bookings on admin page load
	 *
	 * Checks for entries where workflow is cancelled but booking status
	 * hasn't been updated. This is a safety net for when hooks don't fire.
	 * Only runs on production schedule admin page to avoid unnecessary queries.
	 */
	public function sync_cancelled_workflow_bookings() {
		// Only run on the production schedule page
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sfa-production-schedule' ) {
			return;
		}

		global $wpdb;

		// Find entries where workflow is cancelled but booking is still confirmed
		$entries = $wpdb->get_results(
			"SELECT bs.entry_id
			FROM {$wpdb->prefix}gf_entry_meta bs
			INNER JOIN {$wpdb->prefix}gf_entry_meta wf
				ON bs.entry_id = wf.entry_id
				AND wf.meta_key = 'workflow_final_status'
				AND wf.meta_value IN ('cancelled', 'canceled')
			WHERE bs.meta_key = '_prod_booking_status'
			AND bs.meta_value = 'confirmed'",
			ARRAY_A
		);

		foreach ( $entries as $row ) {
			gform_update_meta( (int) $row['entry_id'], '_prod_booking_status', 'canceled' );
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

		// P2 FIX: Check workflow state before freeing capacity
		// Only free capacity if workflow is complete or canceled
		$workflow_status = gform_get_meta( $entry_id, 'workflow_final_status' );
		$booking_status = gform_get_meta( $entry_id, '_prod_booking_status' );

		// Don't free capacity if workflow is still active (not complete/canceled)
		// Active statuses: pending, processing, approved, etc.
		if ( $workflow_status && ! in_array( $workflow_status, [ 'complete', 'cancelled', 'canceled' ], true ) ) {
			// P2 AUDIT: Log blocked deletion attempt
			$this->log_booking_audit( $entry_id, 'deletion_blocked', [
				'workflow_status' => $workflow_status,
				'booking_status' => $booking_status,
				'reason' => 'Workflow not complete',
			] );

			return; // Don't delete booking if workflow is still active
		}

		// P2 AUDIT: Log booking deletion
		$this->log_booking_audit( $entry_id, 'booking_deleted', [
			'workflow_status' => $workflow_status,
			'booking_status' => $booking_status,
			'allocation' => $existing_allocation,
		] );

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
	 * P3 FIX: Handle entry restore from trash
	 *
	 * When an entry is restored from trash, this function:
	 * 1. Checks if booking still exists (may not have been deleted if workflow was active)
	 * 2. Restores booking status if it exists
	 * 3. Does NOT recreate booking if it was deleted (user must resubmit)
	 *
	 * @param int $entry_id The entry ID
	 */
	public function handle_entry_restore( $entry_id ) {
		// Check if booking still exists
		$existing_allocation = gform_get_meta( $entry_id, '_prod_slots_allocation' );

		if ( $existing_allocation ) {
			// Booking was preserved during trash (workflow was likely active)
			// Restore the booking status
			$booking_status = gform_get_meta( $entry_id, '_prod_booking_status' );

			// If booking was marked as trashed/deleted, restore it to confirmed
			if ( in_array( $booking_status, [ 'trashed', 'deleted', 'canceled' ], true ) ) {
				gform_update_meta( $entry_id, '_prod_booking_status', 'confirmed' );

				// P2 AUDIT: Log booking restore
				$this->log_booking_audit( $entry_id, 'booking_restored', [
					'previous_status' => $booking_status,
					'new_status' => 'confirmed',
					'allocation' => $existing_allocation,
				] );

				// Clear cache
				$allocation = json_decode( $existing_allocation, true );
				if ( is_array( $allocation ) ) {
					foreach ( array_keys( $allocation ) as $date ) {
						$year_month = substr( $date, 0, 7 );
						wp_cache_delete( 'sfa_prod_availability_' . $year_month );
					}
				}
			}
		} else {
			// Booking was deleted - user must go through the workflow again
			// P2 AUDIT: Log that booking was not restored
			$this->log_booking_audit( $entry_id, 'restore_no_booking', [
				'reason' => 'Booking was deleted during trash',
			] );
		}
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
	 * Normalize date format to YYYY-MM-DD
	 *
	 * Handles multiple input formats:
	 * - YYYY-MM-DD (already normalized)
	 * - MM/DD/YYYY (US format - Gravity Forms default)
	 * - DD/MM/YYYY (European format)
	 * - M/D/YYYY (single digit month/day)
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

		// Handle M/D/YYYY or MM/DD/YYYY format (1 or 2 digits for month/day)
		if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches ) ) {
			$part1 = (int) $matches[1];
			$part2 = (int) $matches[2];
			$year = $matches[3];

			// Determine if MM/DD/YYYY or DD/MM/YYYY based on values
			if ( $part1 > 12 ) {
				// Must be DD/MM/YYYY (day is > 12)
				$month = str_pad( $part2, 2, '0', STR_PAD_LEFT );
				$day = str_pad( $part1, 2, '0', STR_PAD_LEFT );
			} elseif ( $part2 > 12 ) {
				// Must be MM/DD/YYYY (day is > 12)
				$month = str_pad( $part1, 2, '0', STR_PAD_LEFT );
				$day = str_pad( $part2, 2, '0', STR_PAD_LEFT );
			} else {
				// Ambiguous - default to MM/DD/YYYY (US format - Gravity Forms default)
				$month = str_pad( $part1, 2, '0', STR_PAD_LEFT );
				$day = str_pad( $part2, 2, '0', STR_PAD_LEFT );
			}

			$normalized = $year . '-' . $month . '-' . $day;

			// Validate the resulting date
			$timestamp = strtotime( $normalized );
			if ( $timestamp === false ) {
				// Try strtotime as fallback
				$timestamp = strtotime( $date_str );
				if ( $timestamp !== false ) {
					$normalized = date( 'Y-m-d', $timestamp );
				}
			}

			return $normalized;
		}

		// Try to parse with strtotime as fallback
		$timestamp = strtotime( $date_str );
		if ( $timestamp !== false ) {
			return date( 'Y-m-d', $timestamp );
		}

		// Return as-is if we can't parse it
		return $date_str;
	}

	/**
	 * Check if admin is creating an overbooking and show warning
	 *
	 * CRITICAL SECURITY: This prevents silent overbooking when admins manually
	 * create/edit entries, since validation is bypassed in admin area.
	 *
	 * @param array $schedule The calculated schedule
	 * @param int   $entry_id The entry ID
	 */
	private function check_admin_overbooking_warning( $schedule, $entry_id ) {
		if ( ! isset( $schedule['allocation'] ) || ! is_array( $schedule['allocation'] ) ) {
			return;
		}

		// Load current capacity settings
		$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity', 10 );
		$working_days_json = get_option( 'sfa_prod_working_days', wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ) );
		$working_days = json_decode( $working_days_json, true );
		$all_days = [ 0, 1, 2, 3, 4, 5, 6 ];
		$off_days = array_values( array_diff( $all_days, $working_days ) );

		// Load existing bookings for the dates in this schedule
		$dates_in_schedule = array_keys( $schedule['allocation'] );
		$start_date = min( $dates_in_schedule );
		$end_date = max( $dates_in_schedule );

		$booking_data = BillingStepPreview::load_existing_bookings( $start_date, $end_date, $entry_id );
		$existing_bookings = $booking_data['bookings'];

		// Check each date in the allocation
		$overbooked_dates = [];
		foreach ( $schedule['allocation'] as $date => $lm_to_add ) {
			$existing_lm = isset( $existing_bookings[ $date ] ) ? $existing_bookings[ $date ] : 0;
			$new_total = $existing_lm + $lm_to_add;

			if ( $new_total > $daily_capacity ) {
				$overbooked_dates[] = [
					'date' => $date,
					'existing' => $existing_lm,
					'new_total' => $new_total,
					'capacity' => $daily_capacity,
					'overage' => $new_total - $daily_capacity,
				];
			}
		}

		// Show warning if overbooking detected
		if ( ! empty( $overbooked_dates ) ) {
			add_action( 'admin_notices', function() use ( $overbooked_dates, $entry_id ) {
				echo '<div class="notice notice-warning is-dismissible">';
				echo '<p><strong>⚠️ CAPACITY WARNING (Entry #' . $entry_id . '):</strong></p>';
				echo '<p>This booking EXCEEDS production capacity on the following dates:</p>';
				echo '<ul style="list-style: disc; margin-left: 20px;">';
				foreach ( $overbooked_dates as $info ) {
					$date_formatted = date( 'F j, Y', strtotime( $info['date'] ) );
					echo '<li><strong>' . esc_html( $date_formatted ) . '</strong>: ';
					echo esc_html( $info['new_total'] ) . '/' . esc_html( $info['capacity'] ) . ' LM ';
					echo '(' . esc_html( $info['overage'] ) . ' LM over capacity)</li>';
				}
				echo '</ul>';
				echo '<p><em>The booking was saved anyway because you are an administrator. ';
				echo 'Regular users would be blocked from making this booking.</em></p>';
				echo '</div>';
			} );
		}
	}

	/**
	 * P2 FIX: Log audit trail for booking operations
	 *
	 * Logs important booking operations for security audit and debugging.
	 * This helps track:
	 * - Who created/modified/deleted bookings
	 * - When capacity was changed
	 * - Admin actions that bypassed validation
	 * - Blocked operations (e.g., deletion of active workflow entries)
	 *
	 * @param int    $entry_id The entry ID
	 * @param string $action   The action type (booking_created, booking_updated, booking_deleted, etc.)
	 * @param array  $data     Additional data to log
	 */
	private function log_booking_audit( $entry_id, $action, $data = [] ) {
		$user_id = get_current_user_id();
		$user_info = get_userdata( $user_id );
		$username = $user_info ? $user_info->user_login : 'unknown';

		// Build audit entry
		$audit_entry = [
			'timestamp' => current_time( 'mysql' ),
			'entry_id' => $entry_id,
			'user_id' => $user_id,
			'username' => $username,
			'action' => $action,
			'data' => $data,
			'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
		];

		// Log to error log for immediate visibility
		error_log( sprintf(
			'PRODUCTION AUDIT: Entry %d - %s by user %d (%s) - %s',
			$entry_id,
			$action,
			$user_id,
			$username,
			wp_json_encode( $data )
		) );

		// Store in WordPress option for persistent audit trail (last 100 entries)
		$audit_log = get_option( 'sfa_prod_audit_log', [] );
		array_unshift( $audit_log, $audit_entry ); // Add to beginning
		$audit_log = array_slice( $audit_log, 0, 100 ); // Keep only last 100 entries
		update_option( 'sfa_prod_audit_log', $audit_log, false ); // false = don't autoload

		// Allow other plugins to hook into audit events
		do_action( 'sfa_production_booking_audit', $entry_id, $action, $data, $audit_entry );
	}

	/**
	 * AJAX handler: Check capacity before admin saves entry
	 *
	 * Called by JavaScript before form submission to show confirmation dialog
	 * if the new installation date would cause overbooking.
	 */
	public function ajax_check_capacity_before_save() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sfa_prod_admin_capacity_check' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
		}

		// Verify user is admin
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
		}

		// Get parameters
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$install_date = isset( $_POST['install_date'] ) ? sanitize_text_field( $_POST['install_date'] ) : '';

		if ( ! $entry_id || ! $install_date ) {
			wp_send_json_error( [ 'message' => 'Missing entry_id or install_date' ] );
		}

		// Get entry
		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! $entry ) {
			wp_send_json_error( [ 'message' => 'Entry not found' ] );
		}

		// Get form
		$form = \GFAPI::get_form( $entry['form_id'] );
		if ( is_wp_error( $form ) || ! $form ) {
			wp_send_json_error( [ 'message' => 'Form not found' ] );
		}

		// Check if production scheduling is enabled for this form
		if ( ! FormSettings::is_enabled( $form ) ) {
			wp_send_json_success( [ 'has_overbooking' => false ] );
		}

		// Get the Linear Meter field value
		$lm_field_id = FormSettings::get_lm_field_id( $form );
		if ( ! $lm_field_id ) {
			wp_send_json_error( [ 'message' => 'LM field not configured' ] );
		}

		$lm_required = isset( $entry[ $lm_field_id ] ) ? floatval( $entry[ $lm_field_id ] ) : 0;
		if ( $lm_required <= 0 ) {
			wp_send_json_success( [ 'has_overbooking' => false ] );
		}

		// Normalize installation date
		$install_date = $this->normalize_date( $install_date );

		// Calculate schedule backward from the target installation date
		$production_fields = FormSettings::get_production_fields( $form );
		if ( ! empty( $production_fields ) ) {
			$field_values = [];
			foreach ( $production_fields as $pf ) {
				$fid = $pf['field_id'];
				$field_values[ $fid ] = isset( $entry[ $fid ] ) ? floatval( $entry[ $fid ] ) : 0;
			}
			$schedule = BillingStepPreview::calculate_schedule_for_date( $install_date, $field_values, $production_fields, $entry_id );
		} else {
			$schedule = BillingStepPreview::calculate_schedule_for_date( $install_date, $lm_required, null, $entry_id );
		}

		if ( is_wp_error( $schedule ) || ! $schedule || empty( $schedule['allocation'] ) ) {
			wp_send_json_error( [ 'message' => 'Could not calculate schedule' ] );
		}

		// Get daily capacity
		$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity', 10 );

		// Load existing bookings for the date range (excluding current entry)
		$prod_start_date = $schedule['production_start'];
		$prod_end_date = $schedule['production_end'];

		$booking_data = BillingStepPreview::load_existing_bookings( $prod_start_date, $prod_end_date, $entry_id );
		$existing_bookings = $booking_data['bookings'];

		// Check for overbooking
		$overbooked_dates = [];
		foreach ( $schedule['allocation'] as $date => $lm_to_add ) {
			$existing_lm = isset( $existing_bookings[ $date ] ) ? $existing_bookings[ $date ] : 0;
			$new_total = $existing_lm + $lm_to_add;

			if ( $new_total > $daily_capacity ) {
				$overbooked_dates[] = [
					'date' => $date,
					'date_formatted' => date( 'F j, Y', strtotime( $date ) ),
					'existing' => $existing_lm,
					'new_total' => $new_total,
					'capacity' => $daily_capacity,
					'overage' => $new_total - $daily_capacity,
				];
			}
		}

		// Return result
		if ( ! empty( $overbooked_dates ) ) {
			wp_send_json_success( [
				'has_overbooking' => true,
				'overbooked_dates' => $overbooked_dates,
			] );
		} else {
			wp_send_json_success( [ 'has_overbooking' => false ] );
		}
	}
}
