<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Reports;

use Gravity_Flow\Gravity_Flow\Ajax\Endpoint as Ajax_Endpoint;
use \Gravity_Flow_Reports;
use \GFFormsModel;
use \GFAPI;

/**
 * Reports endpoint.
 *
 * @since 2.8
 */
class Endpoint extends Ajax_Endpoint {

	/**
	 * Permission callback.
	 *
	 * @since 2.8
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function permission_callback( $request ) {

		/**
		 * Filters the capability required to view reports via the REST API.
		 *
		 * @since 3.0.0
		 *
		 * @param string|array    $capability The capability required for this endpoint.
		 * @param WP_REST_Request $request    Full data about the request.
		 */
		$capability = apply_filters( 'gravityflow_rest_api_capability_reports', 'gravityflow_reports', $request );

		return GFAPI::current_user_can_any( $capability );
	}

	/**
	 * Handle the request.
	 *
	 * @since 2.8
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_HTTP_Response
	 */
	public function handle( $request ) {
		$assignee_key                        = sanitize_text_field( $request->get_param( config::ASSIGNEE ) );
		list( $assignee_type, $assignee_id ) = rgexplode( '|', $assignee_key, 2 );

		$range = sanitize_text_field( $request->get_param( config::RANGE ) );
		switch ( $range ) {
			case 'last-6-months':
				$start_date = date( 'Y-m-d', strtotime( '-6 months' ) );
				break;
			case 'last-3-months':
				$start_date = date( 'Y-m-d', strtotime( '-3 months' ) );
				break;
			default:
				$start_date = date( 'Y-m-d', strtotime( '-1 year' ) );
		}

		$app_settings  = gravity_flow()->get_app_settings();
		$allow_reports = rgar( $app_settings, 'allow_display_reports' );

		$args = array(
			'display_header'    => false,
			'form_id'           => $request->get_param( config::FORM ),
			'range'             => $range,
			'start_date'        => $start_date,
			'category'          => $request->get_param( config::CATEGORY ),
			'step_id'           => $request->get_param( config::STEP_ID ),
			'assignee'          => $assignee_key,
			'assignee_type'     => $assignee_type,
			'assignee_id'       => $assignee_id,
			'display_filter'    => $request->get_param( config::DISPLAY_FILTER ),
			'check_permissions' => ! $allow_reports,
		);

		$result = Gravity_Flow_Reports::output_reports( $args, 'json' );

		return $this->response_factory->create( $result, 200 );
	}

}