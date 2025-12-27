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

		// Skip validation during workflow administrative actions
		// Validation should only run during actual form submissions
		if ( doing_action( 'gravityflow_workflow_complete' ) || doing_action( 'gravityflow_post_process_workflow' ) ) {
			return $validation_result;
		}

		// Skip validation if this is a workflow inbox update (admin changing steps manually)
		if ( isset( $_POST['action'] ) && strpos( $_POST['action'], 'gravityflow' ) !== false ) {
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

		// Validate installation date is not too early
		if ( $installation_date && $installation_date < $schedule['installation_minimum'] ) {
			$validation_result['is_valid'] = false;

			foreach ( $form['fields'] as &$field ) {
				if ( $field->id == $install_field_id ) {
					$field->failed_validation = true;
					$field->validation_message = sprintf(
						'Installation date cannot be earlier than %s (production completes on %s)',
						date( 'F j, Y', strtotime( $schedule['installation_minimum'] ) ),
						date( 'F j, Y', strtotime( $schedule['production_end'] ) )
					);
					break;
				}
			}

			$validation_result['form'] = $form;
			return $validation_result;
		}

		// Validate installation date is not in the past
		if ( $installation_date && $installation_date < date( 'Y-m-d' ) ) {
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

		$validation_result['form'] = $form;
		return $validation_result;
	}
}
