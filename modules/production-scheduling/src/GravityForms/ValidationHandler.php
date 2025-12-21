<?php
namespace SFA\ProductionScheduling\GravityForms;

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

		// TODO: Check if this form has production scheduling enabled

		// TODO: Get LM and installation date from submitted form

		// TODO: Recalculate schedule with LIVE data

		// TODO: Validate installation date is not too early

		// TODO: Check capacity is still available (race condition check)

		return $validation_result;
	}
}
