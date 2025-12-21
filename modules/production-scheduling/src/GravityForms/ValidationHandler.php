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

		// Get LM and installation date from submitted form
		$lm_required = 0;
		$installation_date = '';

		foreach ( $form['fields'] as &$field ) {
			if ( $field->id == $lm_field_id ) {
				$lm_required = absint( rgpost( 'input_' . $lm_field_id ) );
			}
			if ( $field->id == $install_field_id ) {
				$installation_date = rgpost( 'input_' . $install_field_id );
			}
		}

		// Validate LM is greater than 0
		if ( $lm_required <= 0 ) {
			$validation_result['is_valid'] = false;

			foreach ( $form['fields'] as &$field ) {
				if ( $field->id == $lm_field_id ) {
					$field->failed_validation = true;
					$field->validation_message = 'Please enter a valid number of linear meters (greater than 0)';
					break;
				}
			}

			$validation_result['form'] = $form;
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
