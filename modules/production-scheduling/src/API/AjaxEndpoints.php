<?php
namespace SFA\ProductionScheduling\API;

use SFA\ProductionScheduling\GravityForms\BillingStepPreview;

/**
 * AJAX Endpoints
 *
 * Handles AJAX requests for schedule preview and capacity checking
 */
class AjaxEndpoints {

	public function __construct() {
		add_action( 'wp_ajax_sfa_prod_preview_schedule', [ $this, 'ajax_preview_schedule' ] );
		add_action( 'wp_ajax_nopriv_sfa_prod_preview_schedule', [ $this, 'ajax_preview_schedule' ] );
	}

	/**
	 * AJAX handler: Preview production schedule
	 */
	public function ajax_preview_schedule() {
		check_ajax_referer( 'sfa_prod_preview', 'nonce' );

		$lm_required = isset( $_POST['lm_required'] ) ? absint( $_POST['lm_required'] ) : 0;

		if ( $lm_required <= 0 ) {
			wp_send_json_error( [
				'message' => 'Please enter valid LM (greater than 0)',
			] );
		}

		$schedule = BillingStepPreview::calculate_schedule( $lm_required );

		if ( is_wp_error( $schedule ) ) {
			wp_send_json_error( [
				'message' => $schedule->get_error_message(),
			] );
		}

		wp_send_json_success( [
			'production_start'      => $schedule['production_start'],
			'production_end'        => $schedule['production_end'],
			'installation_minimum'  => $schedule['installation_minimum'],
			'total_days'            => $schedule['total_days'],
			'allocation'            => $schedule['allocation'],
		] );
	}
}
