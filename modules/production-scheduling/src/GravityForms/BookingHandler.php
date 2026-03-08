<?php
namespace SFA\ProductionScheduling\GravityForms;

use SFA\ProductionScheduling\Admin\FormSettings;

/**
 * Booking Handler
 *
 * Saves production bookings after successful form submission
 */
class BookingHandler {

	/**
	 * Log a debug message only when WP_DEBUG and WP_DEBUG_LOG are enabled.
	 *
	 * @param string $message The message to log.
	 */
	private static function debug_log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( $message );
		}
	}

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

		// AJAX hook to store capacity choice
		add_action( 'wp_ajax_sfa_prod_store_capacity_choice', [ $this, 'ajax_store_capacity_choice' ] );

	}

	/**
	 * AJAX handler: Store capacity choice for an entry
	 *
	 * Stores the admin's over-capacity/fill-spill choice in a transient
	 * so it can be retrieved when the entry is saved.
	 */
	public function ajax_store_capacity_choice() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sfa_prod_admin_capacity_check' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
		}

		// Verify user is admin
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$choice = isset( $_POST['choice'] ) ? sanitize_text_field( $_POST['choice'] ) : '';

		if ( ! $entry_id || ! in_array( $choice, [ 'over_capacity', 'fill_spill' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid parameters' ] );
		}

		// Store in transient (expires in 5 minutes)
		$transient_key = 'sfa_capacity_choice_' . $entry_id;
		set_transient( $transient_key, $choice, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( [ 'stored' => true ] );
	}

	/**
	 * Handle entry update (when editing entries directly)
	 *
	 * @param array $form  The form object
	 * @param int   $entry_id The entry ID
	 * @param array $original_entry The original entry before update
	 */
	public function handle_entry_update( $form, $entry_id, $original_entry ) {
		self::debug_log( sprintf( 'SFA_PROD [gform_after_update_entry] entry=%d', $entry_id ) );
		// Get updated entry
		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! $entry ) {
			return;
		}

		// Reload form to ensure we have all custom settings (skip_booking_field, etc.)
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : ( isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0 );
		if ( $form_id > 0 ) {
			$reloaded_form = \GFAPI::get_form( $form_id );
			if ( ! is_wp_error( $reloaded_form ) && $reloaded_form ) {
				$form = $reloaded_form;
			}
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
		$gf_date_format = FormSettings::get_install_field_date_format( $form );
		if ( $installation_date ) {
			$installation_date = $this->normalize_date( $installation_date, $gf_date_format );
		}

		// Store the submitted installation date for later comparison (before any modifications)
		$submitted_installation_date = $installation_date;

		// Check if "Skip Production Booking" checkbox is checked
		$skip_booking_field_id = FormSettings::get_skip_booking_field_id( $form );
		$skip_booking = false;
		if ( $skip_booking_field_id > 0 ) {
			// Gravity Forms checkboxes store values as field_id.1, field_id.2, etc.
			// Check if any checkbox choice is checked (has a non-empty value)
			$field_id_prefix = (string) $skip_booking_field_id . '.';
			foreach ( $entry as $key => $value ) {
				$key_str = (string) $key;
				if ( strpos( $key_str, $field_id_prefix ) === 0 && ! empty( $value ) ) {
					$skip_booking = true;
					break;
				}
			}

			// Fallback: Also check via GFAPI for checkbox fields in case entry array doesn't have it
			if ( ! $skip_booking && function_exists( 'GFAPI' ) ) {
				$checkbox_value = rgar( $entry, $skip_booking_field_id );
				if ( ! empty( $checkbox_value ) ) {
					$skip_booking = true;
				}
			}
		}

		// If skip booking is checked and we have an installation date, save date-only booking
		if ( $skip_booking && $installation_date ) {
			// Clear old allocation cache before converting to date-only
			$old_allocation_json = gform_get_meta( $entry_id, '_prod_slots_allocation' );
			if ( $old_allocation_json ) {
				$old_allocation = json_decode( $old_allocation_json, true );
				if ( is_array( $old_allocation ) && ! empty( $old_allocation ) ) {
					foreach ( array_keys( $old_allocation ) as $old_date ) {
						$year_month = substr( $old_date, 0, 7 );
						wp_cache_delete( 'sfa_prod_availability_' . $year_month );
					}
				}
			}
			$this->save_date_only_booking( $entry_id, $installation_date, $install_field_id );
			return;
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

			// Store field breakdown in entry meta (lightweight; not part of allocation commit)
			gform_update_meta( $entry_id, '_prod_field_breakdown', wp_json_encode( $field_breakdown ) );
			// NOTE: _prod_lm_required and _prod_total_slots are deferred to the
			// transaction commit block so they stay in sync with allocation/dates.
		} else {
			// Legacy mode (single LM field)
			$lm_required = isset( $entry[ $lm_field_id ] ) ? absint( $entry[ $lm_field_id ] ) : 0;

			if ( $lm_required <= 0 ) {
				return;
			}

			$total_slots = $lm_required;

			// NOTE: _prod_lm_required is saved AFTER change detection (below) to avoid
			// overwriting the old value before comparing it.
		}

		// Check if this is a re-booking (existing booking meta)
		// CRITICAL: Read existing meta BEFORE writing new values so change detection works.
		$existing_install_date = gform_get_meta( $entry_id, '_install_date' );
		$existing_lm = gform_get_meta( $entry_id, '_prod_lm_required' );
		$existing_prod_start = gform_get_meta( $entry_id, '_prod_start_date' );
		$existing_prod_end = gform_get_meta( $entry_id, '_prod_end_date' );
		$existing_allocation = gform_get_meta( $entry_id, '_prod_slots_allocation' );

		// NOTE: _prod_lm_required and _prod_total_slots writes are deferred to the
		// transaction commit block to avoid stale values on early returns.

		// Determine if LM changed (use epsilon comparison to avoid floating-point precision issues).
		// Guard with !== null instead of truthy check so that a stored "0" (e.g. date-only booking)
		// changing to a non-zero value is correctly detected as a change.
		$lm_changed = ( $existing_lm !== null && $existing_lm !== '' )
			&& ( abs( (float) $existing_lm - (float) $lm_required ) > 1e-6 );

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

		// Track whether this is a manual booking (user chose date) vs automatic (queue-based)
		$is_manual_booking = false;

		// FORCE RECALCULATION if installation date changed OR LM changed OR dates are inconsistent
		self::debug_log( sprintf(
			'SFA_PROD CHANGE_DETECT entry=%d lm_changed=%s date_changed=%s dates_inconsistent=%s existing_lm=%s new_lm=%s existing_date=%s submitted_date=%s has_allocation=%s',
			$entry_id,
			$lm_changed ? 'YES' : 'NO',
			$date_changed ? 'YES' : 'NO',
			$dates_inconsistent ? 'YES' : 'NO',
			var_export( $existing_lm, true ),
			var_export( $lm_required, true ),
			var_export( $existing_install_date, true ),
			var_export( $submitted_installation_date, true ),
			$existing_allocation ? 'YES' : 'NO'
		) );
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

			// Build $schedule from existing data so the do_action hook always receives a valid payload
			$schedule = [
				'production_start'     => $prod_start_date,
				'production_end'       => $prod_end_date,
				'installation_minimum' => $installation_date,
				'total_days'           => 0,
				'allocation'           => json_decode( $existing_allocation, true ),
			];

			// LOG: Allocation preserved (no changes)
			$this->log_booking_audit( $entry_id, 'allocation_preserved', [
				'reason' => 'No changes detected',
				'existing_allocation' => $existing_allocation,
			] );
		} else {
			$use_existing_allocation = false;

			// LOG: Allocation being recalculated - capture reason and before state
			$recalc_reasons = [];
			if ( ! $existing_install_date ) {
				$recalc_reasons[] = 'New booking';
			}
			if ( ! $existing_allocation ) {
				$recalc_reasons[] = 'No existing allocation';
			}
			if ( $lm_changed ) {
				$recalc_reasons[] = 'LM changed: ' . $existing_lm . ' -> ' . $lm_required;
			}
			if ( $date_changed ) {
				$recalc_reasons[] = 'Date changed: ' . $existing_install_date . ' -> ' . $submitted_installation_date;
			}
			if ( $dates_inconsistent ) {
				$recalc_reasons[] = 'Dates inconsistent';
			}

			$this->log_booking_audit( $entry_id, 'allocation_recalculating', [
				'reasons' => $recalc_reasons,
				'before_allocation' => $existing_allocation,
				'before_install_date' => $existing_install_date,
				'before_prod_start' => $existing_prod_start,
				'before_prod_end' => $existing_prod_end,
				'before_lm' => $existing_lm,
				'new_lm' => $lm_required,
			] );

			// Determine booking type: MANUAL (user chose date) or AUTOMATIC (queue-based)
			// Manual bookings: user explicitly entered a date → forward schedule from that date
			// Automatic bookings: no date entered → forward schedule from queue position
			$is_manual_booking = false;
			$manual_start_date = null;

			// Check the frontend booking mode flag (set by billing-step.js)
			// 'manual' = user explicitly changed the installation date
			// 'automatic' = system auto-filled the date from preview calculation
			$frontend_booking_mode = isset( $_POST['_sfa_booking_mode'] ) ? sanitize_text_field( $_POST['_sfa_booking_mode'] ) : '';

			if ( $date_changed ) {
				// Existing entry with date change: MANUAL booking from the new date
				$is_manual_booking = true;
				$manual_start_date = $submitted_installation_date;
			} elseif ( ! $existing_install_date && $submitted_installation_date && $frontend_booking_mode !== 'automatic' ) {
				// New entry where user explicitly chose a date (or no frontend flag present,
				// e.g. admin-created entries, workflow steps): MANUAL booking from that date
				// Only frontend auto-filled dates (mode='automatic') skip this to use queue scheduling
				$is_manual_booking = true;
				$manual_start_date = $submitted_installation_date;
			} elseif ( $dates_inconsistent && $existing_install_date ) {
				// Inconsistent dates: treat as manual from existing date
				$is_manual_booking = true;
				$manual_start_date = $existing_install_date;
			}
			// Otherwise: AUTOMATIC booking (no manual_start_date, uses queue)
			// This includes new entries where the date was auto-filled by the preview

			// For MANUAL bookings: check if chosen date has available capacity
			// - For NEW submissions: block if date is fully booked (user should choose different date)
			// - For EDITS or reprocessing: allow even if fully booked - scheduler will spill to next day
			$is_new_submission = ! $existing_install_date && $submitted_installation_date;
			if ( $is_manual_booking && $manual_start_date && $is_new_submission ) {
				$capacity_check = $this->check_date_capacity( $manual_start_date, $entry_id );
				if ( $capacity_check['available'] <= 0 ) {
					// Date is fully booked - block NEW submissions only
					$formatted_date = date( 'F j, Y', strtotime( $manual_start_date ) );
					self::debug_log( sprintf(
						'Production Booking BLOCKED for entry %d: %s is fully booked (0/%d capacity)',
						$entry_id, $manual_start_date, $capacity_check['capacity']
					) );

					// Show error to admin
					if ( is_admin() && ! wp_doing_ajax() ) {
						add_action( 'admin_notices', function() use ( $formatted_date, $entry_id, $capacity_check ) {
							echo '<div class="notice notice-error is-dismissible">';
							echo '<p><strong>Production Scheduling Error (Entry #' . esc_html( $entry_id ) . '):</strong></p>';
							echo '<p>' . esc_html( $formatted_date ) . ' is fully booked (0/' . esc_html( $capacity_check['capacity'] ) . ' slots available).</p>';
							echo '<p>Please choose a different date with available capacity.</p>';
							echo '</div>';
						} );
					}

					// Log audit
					$this->log_booking_audit( $entry_id, 'booking_blocked', [
						'reason' => 'Date fully booked',
						'date' => $manual_start_date,
						'capacity' => $capacity_check['capacity'],
						'booked' => $capacity_check['booked'],
					] );

					return;
				}
			}

			// Check admin capacity choice (over_capacity vs fill_spill)
			// First check transient (set by AJAX before form submission), then fall back to POST
			$transient_key = 'sfa_capacity_choice_' . $entry_id;
			$capacity_choice = get_transient( $transient_key );

			if ( $capacity_choice ) {
				// Clear the transient after reading (one-time use)
				delete_transient( $transient_key );
			} else {
				// Fall back to POST (legacy support)
				$capacity_choice = isset( $_POST['sfa_capacity_choice'] ) ? sanitize_text_field( $_POST['sfa_capacity_choice'] ) : '';
			}

			self::debug_log( sprintf( 'SFA_PROD SCHEDULE entry=%d capacity_choice=%s manual_start_date=%s lm_required=%s', $entry_id, var_export( $capacity_choice, true ), var_export( $manual_start_date, true ), var_export( $lm_required, true ) ) );

			// Calculate schedule based on capacity choice or default behavior
			try {
				if ( $capacity_choice === 'over_capacity' && $manual_start_date ) {
					// OVER-CAPACITY MODE: Force ALL LM onto the selected date
					// Bypass scheduler entirely - admin explicitly chose to exceed capacity
					$schedule = [
						'production_start'     => $manual_start_date,
						'production_end'       => $manual_start_date,
						'installation_minimum' => $manual_start_date,
						'total_days'           => 1,
						'allocation'           => [ $manual_start_date => $lm_required ],
					];

					// Store booking mode for audit trail
					gform_update_meta( $entry_id, '_prod_capacity_mode', 'over_capacity' );
				} else {
					// FILL+SPILL MODE (default): Forward scheduling respects capacity limits
					// - Manual bookings: start from the chosen date (no queue logic)
					// - Automatic bookings: start from queue position (queue logic applied)
					if ( ! empty( $production_fields ) ) {
						$schedule = BillingStepPreview::calculate_schedule( $field_values, $production_fields, $entry_id, $manual_start_date );
					} else {
						$schedule = BillingStepPreview::calculate_schedule( $lm_required, null, $entry_id, $manual_start_date );
					}

					// Store booking mode for audit trail (only if explicitly chosen)
					if ( $capacity_choice === 'fill_spill' ) {
						gform_update_meta( $entry_id, '_prod_capacity_mode', 'fill_spill' );
					}
				}
			} catch ( \Throwable $e ) {
				error_log( sprintf(
					'Production Booking FATAL: Schedule calculation crashed for entry %d: %s (%s at %s:%d)',
					$entry_id, $e->getMessage(), get_class( $e ), $e->getFile(), $e->getLine()
				) );
				return;
			}

			// Handle schedule errors
			if ( is_wp_error( $schedule ) ) {
				error_log( sprintf(
					'Production scheduling error for entry %d: %s',
					$entry_id,
					$schedule->get_error_message()
				) );

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

			// SAFETY CHECK: Verify schedule is valid array before accessing keys
			if ( ! is_array( $schedule ) || is_wp_error( $schedule ) ) {
				error_log( sprintf(
					'Production Booking CRITICAL ERROR: Schedule is not a valid array for entry %d. Type: %s. Cannot save booking.',
					$entry_id,
					is_wp_error( $schedule ) ? 'WP_Error: ' . $schedule->get_error_message() : gettype( $schedule )
				) );
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

			// Get production dates from the calculated schedule
			$prod_start_date = $schedule['production_start'];
			$prod_end_date = $schedule['production_end'];

			// Installation date = production_end (last day of allocated slots)
			// This is the date when the order is ready for installation
			$installation_date = $prod_end_date;

			// Update entry fields to reflect the calculated schedule
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

		// CRITICAL SECURITY: Check if admin is bypassing capacity validation
		// Validation is skipped in admin area, so we need to warn about overbooking
		if ( ! $use_existing_allocation && is_admin() && ! wp_doing_ajax() && ! defined( 'DOING_CRON' ) ) {
			$this->check_admin_overbooking_warning( $schedule, $entry_id );
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

		// Determine booking type: 'automatic' or 'manual'
		// Automatic = system-calculated installation date was used (from queue)
		// Manual = user explicitly chose the installation date
		$existing_booking_type = gform_get_meta( $entry_id, '_prod_booking_type' );
		if ( $use_existing_allocation ) {
			// Preserving existing allocation - keep existing type (default to 'automatic' for legacy)
			$booking_type = $existing_booking_type ? $existing_booking_type : 'automatic';
		} elseif ( $is_manual_booking ) {
			// User explicitly chose the date - this is a manual booking
			$booking_type = 'manual';
		} else {
			// System calculated the date from queue - this is an automatic booking
			$booking_type = 'automatic';
		}

		// Save to entry meta (_prod_lm_required and _prod_total_slots are written inside the transaction below)

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
			self::debug_log( sprintf( 'SFA_PROD SAVE entry=%d install_date=%s start=%s end=%s allocation=%s', $entry_id, $installation_date, $prod_start_date, $prod_end_date, $allocation_to_save ) );
			gform_update_meta( $entry_id, '_prod_lm_required', $lm_required );
			gform_update_meta( $entry_id, '_prod_total_slots', $total_slots );
			gform_update_meta( $entry_id, '_prod_slots_allocation', $allocation_to_save );
			gform_update_meta( $entry_id, '_prod_start_date', $prod_start_date );
			gform_update_meta( $entry_id, '_prod_end_date', $prod_end_date );
			gform_update_meta( $entry_id, '_install_date', $installation_date );
			gform_update_meta( $entry_id, '_prod_booking_status', 'confirmed' );

			// Only set booked_at for NEW bookings - preserve the original timestamp on re-processing
			$existing_booked_at = gform_get_meta( $entry_id, '_prod_booked_at' );
			if ( ! $existing_booked_at ) {
				gform_update_meta( $entry_id, '_prod_booked_at', current_time( 'mysql' ) );
			}

			// Always store entry creator as booked_by (deterministic - never changes)
			$creator_id = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;
			gform_update_meta( $entry_id, '_prod_booked_by', $creator_id );

			// Store the daily capacity at time of booking for historical tracking
			$daily_capacity_at_booking = (int) get_option( 'sfa_prod_daily_capacity', 10 );
			gform_update_meta( $entry_id, '_prod_daily_capacity_at_booking', $daily_capacity_at_booking );

			// Store booking type for queue position tracking
			// 'automatic' = system-calculated date, 'manual' = user-selected date
			gform_update_meta( $entry_id, '_prod_booking_type', $booking_type );

			// All updates successful - commit transaction
			$wpdb->query( 'COMMIT' );

			// P2 FIX: Audit log for booking creation
			$this->log_booking_audit( $entry_id, 'booking_created', [
				'install_date' => $installation_date,
				'prod_start' => $prod_start_date,
				'prod_end' => $prod_end_date,
				'lm_required' => $lm_required,
				'capacity_at_booking' => $daily_capacity_at_booking,
				'booking_type' => $booking_type,
			] );

		} catch ( \Throwable $e ) {
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
	 * Save a date-only booking (installation date without production allocation)
	 *
	 * Used when an installation date is set but no LM/production slots are required.
	 * Stores the installation date and booking metadata without consuming any
	 * production capacity.
	 *
	 * @param int    $entry_id         The entry ID
	 * @param string $installation_date The installation date (YYYY-MM-DD)
	 * @param string $install_field_id  The Gravity Forms field ID for installation date
	 */
	private function save_date_only_booking( $entry_id, $installation_date, $install_field_id ) {
		// Store minimal booking meta - no allocation, no production dates
		gform_update_meta( $entry_id, '_prod_lm_required', 0 );
		gform_update_meta( $entry_id, '_prod_total_slots', 0 );
		gform_update_meta( $entry_id, '_prod_slots_allocation', wp_json_encode( [] ) );
		gform_update_meta( $entry_id, '_prod_start_date', '' );
		gform_update_meta( $entry_id, '_prod_end_date', '' );
		gform_update_meta( $entry_id, '_install_date', $installation_date );
		gform_update_meta( $entry_id, '_prod_booking_status', 'confirmed' );
		gform_update_meta( $entry_id, '_prod_booked_at', current_time( 'mysql' ) );

		$creator_id = 0;
		$entry = \GFAPI::get_entry( $entry_id );
		if ( ! is_wp_error( $entry ) && $entry ) {
			$creator_id = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;
		}
		gform_update_meta( $entry_id, '_prod_booked_by', $creator_id );

		// Sync the installation date field
		if ( $install_field_id ) {
			\GFAPI::update_entry_field( $entry_id, $install_field_id, $installation_date );
		}

		// Audit log
		$this->log_booking_audit( $entry_id, 'date_only_booking', [
			'install_date' => $installation_date,
			'lm_required' => 0,
		] );
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
		// Reload form to ensure we have all custom settings (skip_booking_field, etc.)
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;
		if ( $form_id > 0 ) {
			$reloaded_form = \GFAPI::get_form( $form_id );
			if ( ! is_wp_error( $reloaded_form ) && $reloaded_form ) {
				$form = $reloaded_form;
			}
		}

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
		self::debug_log( sprintf( 'SFA_PROD CANCEL [gravityflow_status_updated] entry=%d new=%s old=%s', $entry_id, $new_status, $old_status ) );
		// If workflow is cancelled, fully cancel the production booking
		if ( 'cancelled' === $new_status || 'canceled' === $new_status ) {
			$this->cancel_production_booking( $entry_id );
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
			return; // Not a cancel request — silent return (no log to avoid spam on every admin_init)
		}

		// SECURITY: Verify user has permission to cancel workflows (mirrors GravityFlow capability check)
		if ( ! current_user_can( 'gravityflow_workflow_detail' ) && ! current_user_can( 'gform_full_access' ) ) {
			return;
		}

		// SECURITY: Verify nonce to prevent CSRF (mirrors GravityFlow's nonce verification)
		$nonce = isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : ( isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : '' );
		if ( ! wp_verify_nonce( $nonce, 'gravityflow_cancel_workflow' ) ) {
			return;
		}

		// Get entry ID from request
		$entry_id = isset( $_GET['lid'] ) ? absint( $_GET['lid'] ) : ( isset( $_POST['lid'] ) ? absint( $_POST['lid'] ) : 0 );

		if ( ! $entry_id ) {
			return;
		}

		self::debug_log( sprintf( 'SFA_PROD CANCEL [check_cancel_workflow_request] entry=%d — deferring to gravityflow_workflow_cancelled / gravityflow_status_updated hooks', $entry_id ) );
		// Cancellation is handled by the authoritative GravityFlow hooks
		// (gravityflow_workflow_cancelled and gravityflow_status_updated) which fire
		// after GravityFlow confirms the status change. Performing the destructive
		// operation here would race ahead of GravityFlow's own processing.
	}

	/**
	 * Handle cancel workflow AJAX action (before GravityFlow processes it)
	 *
	 * This hooks early into the AJAX action to catch cancellation
	 */
	public function handle_cancel_workflow_ajax() {
		// SECURITY: Verify user has permission to cancel workflows (mirrors GravityFlow capability check)
		if ( ! current_user_can( 'gravityflow_workflow_detail' ) && ! current_user_can( 'gform_full_access' ) ) {
			return;
		}

		// SECURITY: Verify nonce to prevent CSRF (mirrors GravityFlow's AJAX nonce verification)
		$nonce = isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : ( isset( $_REQUEST['_wpnonce'] ) ? $_REQUEST['_wpnonce'] : '' );
		if ( ! wp_verify_nonce( $nonce, 'gravityflow_cancel_workflow' ) ) {
			return;
		}

		// Get entry ID from request
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : (
			isset( $_POST['lid'] ) ? absint( $_POST['lid'] ) : (
				isset( $_GET['lid'] ) ? absint( $_GET['lid'] ) : 0
			)
		);

		if ( ! $entry_id ) {
			return;
		}

		self::debug_log( sprintf( 'SFA_PROD CANCEL [handle_cancel_workflow_ajax] entry=%d — deferring to gravityflow_workflow_cancelled / gravityflow_status_updated hooks', $entry_id ) );
		// Cancellation is handled by the authoritative GravityFlow hooks
		// (gravityflow_workflow_cancelled and gravityflow_status_updated) which fire
		// after GravityFlow confirms the status change. Performing the destructive
		// operation here would race ahead of GravityFlow's own processing.
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
			$this->cancel_production_booking( (int) $row['entry_id'] );
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
		gform_delete_meta( $entry_id, '_prod_booking_type' );

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
		self::debug_log( sprintf( 'SFA_PROD CANCEL [gravityflow_workflow_cancelled] entry=%d', $entry_id ) );
		$this->cancel_production_booking( $entry_id );
	}

	/**
	 * Cancel a production booking: clear allocation, free capacity, and invalidate cache.
	 *
	 * Shared by all cancellation paths (workflow hook, status change, AJAX, admin_init).
	 *
	 * @param int $entry_id The entry ID whose booking should be cancelled.
	 */
	private function cancel_production_booking( $entry_id ) {
		$existing_booking = gform_get_meta( $entry_id, '_install_date' );

		if ( ! $existing_booking ) {
			self::debug_log( sprintf( 'SFA_PROD CANCEL cancel_production_booking entry=%d — no _install_date meta, nothing to cancel', $entry_id ) );
			return;
		}

		self::debug_log( sprintf( 'SFA_PROD CANCEL cancel_production_booking entry=%d — clearing all booking meta (install_date=%s)', $entry_id, $existing_booking ) );

		// Clear cache for allocated dates before removing meta
		$existing_allocation = gform_get_meta( $entry_id, '_prod_slots_allocation' );
		if ( $existing_allocation ) {
			$allocation = json_decode( $existing_allocation, true );
			if ( is_array( $allocation ) ) {
				foreach ( array_keys( $allocation ) as $date ) {
					$year_month = substr( $date, 0, 7 );
					wp_cache_delete( 'sfa_prod_availability_' . $year_month );
				}
			}
		}

		// Delete all production booking meta to fully free capacity
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
		gform_delete_meta( $entry_id, '_prod_booking_type' );
		gform_delete_meta( $entry_id, '_prod_capacity_mode' );

		// Clear general availability cache
		wp_cache_delete( 'sfa_prod_availability_next_30_days' );

		// Audit log
		$this->log_booking_audit( $entry_id, 'booking_canceled', [
			'allocation' => $existing_allocation,
		] );

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
	 * @param string $gf_date_format Optional GF field dateFormat ('dmy', 'mdy', etc.) to disambiguate.
	 * @return string
	 */
	private function normalize_date( $date_str, $gf_date_format = '' ) {
		$date_str = trim( $date_str );

		// Check if already in YYYY-MM-DD format
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_str ) ) {
			return $date_str;
		}

		// Handle M/D/YYYY or MM/DD/YYYY or DD/MM/YYYY format (1 or 2 digits for month/day)
		if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches ) ) {
			$part1 = (int) $matches[1];
			$part2 = (int) $matches[2];
			$year = $matches[3];

			// Use the GF field format setting when available to disambiguate
			$is_dmy = ( strpos( $gf_date_format, 'dmy' ) === 0 );

			if ( $is_dmy ) {
				// Field is DD/MM/YYYY — part1 is day, part2 is month
				$day   = str_pad( $part1, 2, '0', STR_PAD_LEFT );
				$month = str_pad( $part2, 2, '0', STR_PAD_LEFT );
			} elseif ( $gf_date_format && ! $is_dmy ) {
				// Field is MM/DD/YYYY (mdy or any other explicit format)
				$month = str_pad( $part1, 2, '0', STR_PAD_LEFT );
				$day   = str_pad( $part2, 2, '0', STR_PAD_LEFT );
			} elseif ( $part1 > 12 ) {
				// No format hint but part1 > 12, must be DD/MM/YYYY
				$day   = str_pad( $part1, 2, '0', STR_PAD_LEFT );
				$month = str_pad( $part2, 2, '0', STR_PAD_LEFT );
			} elseif ( $part2 > 12 ) {
				// No format hint but part2 > 12, must be MM/DD/YYYY
				$month = str_pad( $part1, 2, '0', STR_PAD_LEFT );
				$day   = str_pad( $part2, 2, '0', STR_PAD_LEFT );
			} else {
				// Ambiguous with no format hint - default to MM/DD/YYYY (US format)
				$month = str_pad( $part1, 2, '0', STR_PAD_LEFT );
				$day   = str_pad( $part2, 2, '0', STR_PAD_LEFT );
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
	 * Check available capacity for a specific date
	 *
	 * Used to validate manual date entries before scheduling.
	 *
	 * @param string   $date     The date to check (Y-m-d format)
	 * @param int|null $entry_id Entry ID to exclude (for edit mode)
	 * @return array ['capacity' => int, 'booked' => int, 'available' => int]
	 */
	private function check_date_capacity( $date, $entry_id = null ) {
		// Guard: reject invalid or past dates
		$timestamp = strtotime( $date );
		if ( $timestamp === false || date( 'Y-m-d', $timestamp ) < date( 'Y-m-d' ) ) {
			return [
				'capacity' => 0,
				'booked' => 0,
				'available' => 0,
				'reason' => 'invalid_or_past_date',
			];
		}

		$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity', 10 );

		// Check for capacity override on this date
		$repo = new \SFA\ProductionScheduling\Database\CapacityRepository();
		$overrides = $repo->get_range( $date, $date );
		if ( isset( $overrides[ $date ] ) ) {
			$daily_capacity = (int) $overrides[ $date ];
		}

		// Load existing bookings for this date
		$booking_data = BillingStepPreview::load_existing_bookings( $date, $date, $entry_id );
		$booked = isset( $booking_data['bookings'][ $date ] ) ? (int) $booking_data['bookings'][ $date ] : 0;

		// Check if this is a working day
		$working_days_json = get_option( 'sfa_prod_working_days', wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ) );
		$working_days = json_decode( $working_days_json, true );
		$day_of_week = (int) date( 'w', $timestamp );

		if ( ! in_array( $day_of_week, $working_days, true ) ) {
			// Non-working day: 0 capacity
			return [
				'capacity' => 0,
				'booked' => 0,
				'available' => 0,
				'reason' => 'non_working_day',
			];
		}

		// Check if this is a holiday
		$holidays_json = get_option( 'sfa_prod_holidays', wp_json_encode( [] ) );
		$holidays_raw = json_decode( $holidays_json, true );
		$holidays = BillingStepPreview::extract_holiday_dates( $holidays_raw );

		if ( in_array( $date, $holidays, true ) ) {
			// Holiday: 0 capacity
			return [
				'capacity' => 0,
				'booked' => 0,
				'available' => 0,
				'reason' => 'holiday',
			];
		}

		$available = max( 0, $daily_capacity - $booked );

		return [
			'capacity' => $daily_capacity,
			'booked' => $booked,
			'available' => $available,
		];
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

		// Log to error log for immediate visibility (only when debug is on)
		self::debug_log( sprintf(
			'PRODUCTION AUDIT: Entry %d - %s by user %d (%s) - %s',
			$entry_id,
			$action,
			$user_id,
			$username,
			wp_json_encode( $data )
		) );

		// Store in WordPress option for persistent audit trail (last 500 entries)
		$audit_log = get_option( 'sfa_prod_audit_log', [] );
		array_unshift( $audit_log, $audit_entry ); // Add to beginning
		$audit_log = array_slice( $audit_log, 0, 500 ); // Keep only last 500 entries
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

		// Get the Linear Meter field value OR calculate from production fields.
		// Prefer values POSTed from the admin form (current unsaved edits) over DB values.
		$lm_field_id = FormSettings::get_lm_field_id( $form );
		$production_fields = FormSettings::get_production_fields( $form );
		$lm_required = 0;
		$field_values = [];

		// Check if JS sent current field values from the DOM
		$posted_field_values = isset( $_POST['current_field_values'] ) && is_array( $_POST['current_field_values'] ) ? $_POST['current_field_values'] : null;
		$posted_legacy_lm = isset( $_POST['current_lm'] ) ? sanitize_text_field( $_POST['current_lm'] ) : null;

		if ( ! empty( $production_fields ) ) {
			// Multi-field mode: prefer POSTed values, fall back to DB entry
			foreach ( $production_fields as $pf ) {
				$fid = $pf['field_id'];
				if ( $posted_field_values && isset( $posted_field_values[ $fid ] ) ) {
					$field_values[ $fid ] = floatval( sanitize_text_field( $posted_field_values[ $fid ] ) );
				} else {
					$field_values[ $fid ] = isset( $entry[ $fid ] ) ? floatval( $entry[ $fid ] ) : 0;
				}
			}
			$lm_required = FormSettings::calculate_total_slots( $field_values, $production_fields );
		} elseif ( $lm_field_id ) {
			// Legacy mode: prefer POSTed LM value, fall back to DB entry
			if ( $posted_legacy_lm !== null ) {
				$lm_required = floatval( $posted_legacy_lm );
			} else {
				$lm_required = isset( $entry[ $lm_field_id ] ) ? floatval( $entry[ $lm_field_id ] ) : 0;
			}
		}

		if ( $lm_required <= 0 ) {
			wp_send_json_success( [ 'has_overbooking' => false ] );
		}

		// Normalize installation date using the field's configured format
		$gf_date_format = FormSettings::get_install_field_date_format( $form );
		$install_date = $this->normalize_date( $install_date, $gf_date_format );

		// Short-circuit: if the entry already has a booking and neither the
		// install date nor the LM changed, the backend will preserve the
		// existing allocation as-is ($use_existing_allocation = true), so no
		// capacity dialog is needed.
		$existing_install_date = gform_get_meta( $entry_id, '_install_date' );
		$existing_lm          = gform_get_meta( $entry_id, '_prod_lm_required' );
		$existing_allocation  = gform_get_meta( $entry_id, '_prod_slots_allocation' );

		if ( $existing_allocation && $existing_install_date === $install_date && abs( (float) $existing_lm - (float) $lm_required ) < 1e-6 ) {
			wp_send_json_success( [ 'has_overbooking' => false ] );
		}

		// Check available capacity on the target date FIRST
		// This determines if the order fits entirely on the target date or needs spill/over-capacity
		$capacity_check = $this->check_date_capacity( $install_date, $entry_id );
		$available_on_target = $capacity_check['available'];
		$target_capacity = $capacity_check['capacity'];

		// If order fits entirely on the target date, no dialog needed
		if ( $lm_required <= $available_on_target ) {
			wp_send_json_success( [ 'has_overbooking' => false ] );
		}

		// Order exceeds available capacity on target date - show dialog
		// Calculate the overage for the over-capacity option
		$existing_on_target = $capacity_check['booked'];
		$new_total_if_forced = $existing_on_target + $lm_required;
		$overage = $new_total_if_forced - $target_capacity;

		$overbooked_dates = [
			[
				'date'           => $install_date,
				'date_formatted' => date( 'F j, Y', strtotime( $install_date ) ),
				'existing'       => $existing_on_target,
				'new_total'      => $new_total_if_forced,
				'capacity'       => $target_capacity,
				'overage'        => max( 0, $overage ),
			],
		];

		// Calculate fill+spill allocation (forward scheduling from selected date)
		if ( ! empty( $production_fields ) ) {
			$fill_spill_schedule = BillingStepPreview::calculate_schedule( $field_values, $production_fields, $entry_id, $install_date );
		} else {
			$fill_spill_schedule = BillingStepPreview::calculate_schedule( $lm_required, null, $entry_id, $install_date );
		}

		$fill_spill_allocation = [];
		if ( ! is_wp_error( $fill_spill_schedule ) && ! empty( $fill_spill_schedule['allocation'] ) ) {
			foreach ( $fill_spill_schedule['allocation'] as $date => $lm ) {
				$fill_spill_allocation[] = [
					'date' => $date,
					'date_formatted' => date( 'F j, Y', strtotime( $date ) ),
					'lm' => $lm,
				];
			}
		}

		// Return result with both options
		wp_send_json_success( [
			'has_overbooking' => true,
			'total_lm' => $lm_required,
			'target_date' => $install_date,
			'target_date_formatted' => date( 'F j, Y', strtotime( $install_date ) ),
			'overbooked_dates' => $overbooked_dates,
			'fill_spill_allocation' => $fill_spill_allocation,
			'over_capacity_allocation' => [ $install_date => $lm_required ],
		] );
	}
}
