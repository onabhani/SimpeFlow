<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Form_Steps;

use Gravity_Flow\Gravity_Flow\Ajax\Endpoint as Ajax_Endpoint;
use \GFAPI;

/**
 * Inbox_Form_Steps endpoint
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
		return GFAPI::current_user_can_any( array( 'gravityflow_reports' ) );
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
		$steps = gravity_flow()->get_steps( $request['id'] );

		$_steps = array();
		foreach ( $steps as $step ) {
			$assignees     = $step->get_assignees();
			$assignee_vars = array();

			foreach ( $assignees as $assignee ) {
				$assignee_id = $assignee->get_id();
				if ( ! empty( $assignee_id ) ) {
					$assignee_vars[] = array(
						'key'  => $assignee->get_key(),
						'name' => $assignee->get_display_name(),
					);
				}
			}

			$_steps[ $step->get_id() ] = array(
				'id'        => $step->get_id(),
				'name'      => $step->get_name(),
				'assignees' => $assignee_vars,
			);
		}

		return $this->response_factory->create( $_steps, 200 );
	}

}