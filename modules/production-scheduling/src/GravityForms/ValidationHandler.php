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

		// Only validate if production scheduling is enabled
		if ( ! FormSettings::is_enabled( $form ) ) {
			return $validation_result;
		}

		$lm_field_id = FormSettings::get_lm_field_id( $form );
		$install_field_id = FormSettings::get_install_field_id( $form );

		if ( ! $lm_field_id || ! $install_field_id ) {
			return $validation_result;
		}

		// Check if fields are visible (not hidden by conditional logic or other modules)
		$lm_field = null;
		$install_field = null;

		foreach ( $form['fields'] as $field ) {
			if ( $field->id == $lm_field_id ) {
				$lm_field = $field;
			}
			if ( $field->id == $install_field_id ) {
				$install_field = $field;
			}
		}

		// Skip validation if fields are not found
		if ( ! $lm_field || ! $install_field ) {
			return $validation_result;
		}

		// Skip validation if LM field is hidden by conditional logic
		if ( \GFFormsModel::is_field_hidden( $form, $lm_field, array(), null ) ) {
			return $validation_result;
		}

		// Skip validation if Installation Date field is hidden by conditional logic
		if ( \GFFormsModel::is_field_hidden( $form, $install_field, array(), null ) ) {
			return $validation_result;
		}

		// Get LM and installation date from submitted form
		$lm_required = absint( rgpost( 'input_' . $lm_field_id ) );
		$installation_date = rgpost( 'input_' . $install_field_id );

		// Skip validation if LM field has no value (might be hidden by other means)
		if ( $lm_required <= 0 ) {
			// Don't fail validation, just skip production scheduling validation
			// The field itself may have its own required validation if needed
			return $validation_result;
		}

		// Recalculate schedule with LIVE data
		$schedule = BillingStepPreview::calculate_schedule( $lm_required );

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
