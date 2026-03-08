<?php
namespace Gravity_Flow\Gravity_Flow\Rest_API\V2\Controllers;

use GFAPI;
use Gravity_Flow_API;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Controller for handling REST API requests related to workflows.
 *
 * @since 3.0.0
 */
class Workflows_Controller extends Gravity_Flow_REST_Controller {

	/**
	 * The base path for the assignees route.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $rest_base = 'entries/(?P<entry_id>\d+)/workflow';

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 3.0.0
	 */
	public function register_routes() {

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/status',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'status' ],
					'permission_callback' => [ $this, 'status_permissions_check' ],
					'args'                => [],
				],
			]
		);


		register_rest_route( $this->namespace, '/' . $this->rest_base . '/restart',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'restart' ],
					'permission_callback' => [ $this, 'restart_permissions_check' ],
					'args'                => [],
				],
			]
		);

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/cancel',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'cancel' ],
					'permission_callback' => [ $this, 'cancel_permissions_check' ],
					'args'                => [],
				],
			]
		);
	}

	/**
	 * Handles the request to get the workflow status.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response | WP_Error
	 */
	public function status( $request ) {

		$steps = $this->get_workflow_steps( (int) $request->get_param( 'entry_id' ), $entry, $api );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}

		// Return the current workflow status response
		return Steps_Controller::get_workflow_response( $entry, $api );
	}

	/**
	 * Checks if the current user has permission to view a workflow status.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_Error | bool
	 */
	public function status_permissions_check( $request ) {
		/**
		 * Filters the capability required to view a workflow status via the REST API.
		 *
		 * @since 3.0.0
		 *
		 * @param string|array    $capability The capability required for this endpoint.
		 * @param WP_REST_Request $request    Full data about the request.
		 */
		$capability = apply_filters( 'gravityflow_rest_api_capability_workflow_status', 'gravityflow_create_steps', $request );

		if ( ! $this->current_user_can_any( $capability, $request ) ) {
			return new WP_Error(
				'permission_denied',
				esc_html__( 'You are not allowed view workflow statuses.', 'gravityflow' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}


	/**
	 * Handles the request to restart a workflow.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response | WP_Error
	 */
	public function restart( $request ) {

		$steps = $this->get_workflow_steps( (int) $request->get_param( 'entry_id' ), $entry, $api );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}

		// Restarting the workflow.
		$api->restart_workflow( $entry );

		// Return the current workflow status response
		return Steps_Controller::get_workflow_response( $entry, $api );
	}

	/**
	 * Checks if the current user has permission to restart a workflow.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_Error | bool
	 */
	public function restart_permissions_check( $request ) {
		/**
		 * Filters the capability required to restart a workflow via the REST API.
		 *
		 * @since 3.0.0
		 *
		 * @param string|array    $capability The capability required for this endpoint.
		 * @param WP_REST_Request $request    Full data about the request.
		 */
		$capability = apply_filters( 'gravityflow_rest_api_capability_restart_workflow', 'gravityflow_admin_actions', $request );

		if ( ! $this->current_user_can_any( $capability, $request ) ) {
			return new WP_Error(
				'permission_denied',
				esc_html__( 'You are not allowed to restart workflows.', 'gravityflow' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Handles the request to cancel a workflow.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response | WP_Error
	 */
	public function cancel( $request ) {

		$steps = $this->get_workflow_steps( (int) $request->get_param( 'entry_id' ), $entry, $api );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}

		$result = $api->cancel_workflow( $entry );

		if ( ! $result ) {
			return new WP_Error( 'no_active_step', esc_html__( 'This entry does not have an active workflow step. Workflow cannot be cancelled.', 'gravityflow' ), array( 'status' => 422 ) );
		}

		// Return the current workflow status response
		return Steps_Controller::get_workflow_response( $entry, $api );
	}

	/**
	 * Checks if the current user has permission to cancel a workflow.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_Error | bool
	 */
	public function cancel_permissions_check( $request ) {
		/**
		 * Filters the capability required to cancel a workflow via the REST API.
		 *
		 * @since 3.0.0
		 *
		 * @param string|array    $capability The capability required for this endpoint.
		 * @param WP_REST_Request $request    Full data about the request.
		 */
		$capability = apply_filters( 'gravityflow_rest_api_capability_cancel_workflow', 'gravityflow_admin_actions', $request );

		if ( ! $this->current_user_can_any( $capability, $request ) ) {
			return new WP_Error(
				'permission_denied',
				esc_html__( 'You are not allowed to cancel workflows.', 'gravityflow' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Retrieves the workflow steps for a given entry.
	 *
	 * @since 3.0.0
	 *
	 * @param int              $entry_id The ID of the entry.
	 * @param array            $entry    The entry data passed by reference.
	 * @param Gravity_Flow_API $api      The Gravity Flow API instance passed by reference.
	 *
	 * @return array|WP_Error Returns an array of workflow steps or WP_Error on failure.
	 */
	private function get_workflow_steps( $entry_id, &$entry, &$api ) {

		$entry = GFAPI::get_entry( $entry_id );

		if ( ! $entry || is_wp_error( $entry )) {
			return new WP_Error( 'entry_not_found', esc_html__( 'Invalid entry id. Entry could not be found.', 'gravityflow' ), [ 'status' => 404 ] );
		}

		$api   = new Gravity_Flow_API( $entry['form_id'] );
		$steps = $api->get_steps();

		if ( empty( $steps ) ) {
			return new WP_Error( 'no_workflow', esc_html__( 'This entry does not have an active workflow.', 'gravityflow' ), [ 'status' => 422 ] );
		}

		return $steps;
	}
}
