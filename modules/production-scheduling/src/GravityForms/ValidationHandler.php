<?php
namespace SFA\ProductionScheduling\GravityForms;

use SFA\ProductionScheduling\Admin\FormSettings;

/**
 * Form Validation Handler
 *
 * Validates production scheduling before form submission
 */
class ValidationHandler {

	public function __construct() {
		add_filter( 'gform_validation', [ $this, 'validate_production_schedule' ] );
	}

	/**
	 * Validate production schedule on form submission
	 *
	 * @param array $validation_result
	 * @return array
	 */
	public function validate_production_schedule( $validation_result ) {
		$form = $validation_result['form'];

		// Skip validation if we're in the admin area
		// Production scheduling validation should only run on front-end form submissions
		if ( is_admin() ) {
			return $validation_result;
		}

		// Skip validation during workflow administrative actions
		// Validation should only run during actual form submissions
		if ( doing_action( 'gravityflow_workflow_complete' ) || doing_action( 'gravityflow_post_process_workflow' ) ) {
			return $validation_result;
		}

		// Skip validation if this is a workflow inbox update (admin changing steps manually)
		if ( isset( $_POST['action'] ) && strpos( (string) $_POST['action'], 'gravityflow' ) !== false ) {
			return $validation_result;
		}

		// Skip if this is an entry update rather than a new submission
		if ( isset( $_POST['gform_update_entry'] ) || isset( $_POST['screen_mode'] ) ) {
			return $validation_result;
		}

		// Only validate if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			return $validation_result;
		}

		$lm_field_id = FormSettings::get_lm_field_id( $form );
		$install_field_id = FormSettings::get_install_field_id( $form );
		$production_fields = FormSettings::get_production_fields( $form );

		// Require installation field and either production fields or legacy LM field
		if ( ! $install_field_id ) {
			return $validation_result;
		}

		if ( empty( $production_fields ) && ! $lm_field_id ) {
			return $validation_result;
		}

		// Find installation date field
		$install_field = null;
		foreach ( $form['fields'] as $field ) {
			if ( $field->id == $install_field_id ) {
				$install_field = $field;
				break;
			}
		}

		if ( ! $install_field ) {
			return $validation_result;
		}

		// Skip validation if Installation Date field is hidden by conditional logic
		if ( \GFFormsModel::is_field_hidden( $form, $install_field, array(), null ) ) {
			return $validation_result;
		}

		$installation_date = rgpost( 'input_' . $install_field_id );

		// Handle multi-field or legacy mode
		if ( ! empty( $production_fields ) ) {
			// Multi-field mode
			$field_values = array();
			$all_fields_hidden = true;

			foreach ( $production_fields as $prod_field_config ) {
				$field_id = $prod_field_config['field_id'];

				// Find the field object
				$field_obj = null;
				foreach ( $form['fields'] as $field ) {
					if ( $field->id == $field_id ) {
						$field_obj = $field;
						break;
					}
				}

				// Skip if field not found
				if ( ! $field_obj ) {
					continue;
				}

				// Check if field is hidden
				if ( ! \GFFormsModel::is_field_hidden( $form, $field_obj, array(), null ) ) {
					$all_fields_hidden = false;
				}

				// Get field value
				$value = rgpost( 'input_' . $field_id );
				$field_values[ $field_id ] = floatval( $value );
			}

			// Skip validation if all production fields are hidden
			if ( $all_fields_hidden ) {
				return $validation_result;
			}

			// Calculate total slots
			$total_slots = FormSettings::calculate_total_slots( $field_values, $production_fields );

			// Skip validation if no production value
			if ( $total_slots <= 0 ) {
				return $validation_result;
			}

			// Recalculate schedule with LIVE data (multi-field)
			$schedule = BillingStepPreview::calculate_schedule( $field_values, $production_fields );
		} else {
			// Legacy mode (single LM field)
			$lm_field = null;
			foreach ( $form['fields'] as $field ) {
				if ( $field->id == $lm_field_id ) {
					$lm_field = $field;
					break;
				}
			}

			if ( ! $lm_field ) {
				return $validation_result;
			}

			// Skip validation if LM field is hidden by conditional logic
			if ( \GFFormsModel::is_field_hidden( $form, $lm_field, array(), null ) ) {
				return $validation_result;
			}

			// Get LM from submitted form
			$lm_required = absint( rgpost( 'input_' . $lm_field_id ) );

			// Skip validation if LM field has no value (might be hidden by other means)
			if ( $lm_required <= 0 ) {
				return $validation_result;
			}

			// Recalculate schedule with LIVE data (legacy)
			$schedule = BillingStepPreview::calculate_schedule( $lm_required );
		}

		if ( is_wp_error( $schedule ) ) {
			$validation_result['is_valid'] = false;

			foreach ( $form['fields'] as &$field ) {
				if ( $field->id == $lm_field_id ) {
					$field->failed_validation = true;
					$field->validation_message = 'Unable to schedule production: ' . $schedule->get_error_message();
					break;
				}
			}

			$validation_result['form'] = $form;
			return $validation_result;
		}

		// Validate installation date is not in the past
		// P3 FIX: Use WordPress timezone function instead of PHP date()
		if ( $installation_date && $installation_date < current_time( 'Y-m-d' ) ) {
			$validation_result['is_valid'] = false;

			foreach ( $form['fields'] as &$field ) {
				if ( $field->id == $install_field_id ) {
					$field->failed_validation = true;
					$field->validation_message = 'Installation date cannot be in the past';
					break;
				}
			}

			$validation_result['form'] = $form;
			return $validation_result;
		}

		// Validate installation date availability
		// If user entered a date BEFORE the queue-based minimum, this is a manual booking.
		// Manual bookings bypass the queue but must have available capacity on the chosen date.
		if ( $installation_date && $installation_date < $schedule['installation_minimum'] ) {
			// Check if the manually chosen date has available capacity
			$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity', 10 );

			// Check for capacity override on this date
			$repo = new \SFA\ProductionScheduling\Database\CapacityRepository();
			$overrides = $repo->get_range( $installation_date, $installation_date );
			if ( isset( $overrides[ $installation_date ] ) ) {
				$daily_capacity = (int) $overrides[ $installation_date ];
			}

			// Check working day
			$working_days_json = get_option( 'sfa_prod_working_days', wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ) );
			$working_days = json_decode( $working_days_json, true );
			$day_of_week = (int) date( 'w', strtotime( $installation_date ) );
			$is_working_day = in_array( $day_of_week, $working_days, true );

			// Check holiday
			$holidays_json = get_option( 'sfa_prod_holidays', wp_json_encode( [] ) );
			$holidays_raw = json_decode( $holidays_json, true );
			$holidays = BillingStepPreview::extract_holiday_dates( $holidays_raw );
			$is_holiday = in_array( $installation_date, $holidays, true );

			if ( ! $is_working_day || $is_holiday || $daily_capacity <= 0 ) {
				// Non-working day or holiday: block
				$validation_result['is_valid'] = false;
				foreach ( $form['fields'] as &$field ) {
					if ( $field->id == $install_field_id ) {
						$field->failed_validation = true;
						$field->validation_message = sprintf(
							'%s is not a working day. Please choose a valid production date.',
							date( 'F j, Y', strtotime( $installation_date ) )
						);
						break;
					}
				}
				$validation_result['form'] = $form;
				return $validation_result;
			}

			// Check existing bookings on the chosen date
			$booking_data = BillingStepPreview::load_existing_bookings( $installation_date, $installation_date );
			$booked = isset( $booking_data['bookings'][ $installation_date ] ) ? (int) $booking_data['bookings'][ $installation_date ] : 0;

			if ( $booked >= $daily_capacity ) {
				// Date is fully booked - block submission
				$validation_result['is_valid'] = false;
				foreach ( $form['fields'] as &$field ) {
					if ( $field->id == $install_field_id ) {
						$field->failed_validation = true;
						$field->validation_message = sprintf(
							'%s is fully booked (%d/%d slots used). Please choose a different date.',
							date( 'F j, Y', strtotime( $installation_date ) ),
							$booked,
							$daily_capacity
						);
						break;
					}
				}
				$validation_result['form'] = $form;
				return $validation_result;
			}
			// Else: date has capacity, allow as manual booking
		}

		$validation_result['form'] = $form;
		return $validation_result;
	}
}
