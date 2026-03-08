<?php
namespace Gravity_Flow\Gravity_Flow\Rest_API\V2\Controllers;

use GFAPI;
use Gravity_Flow_API;
use Gravity_Flow_Step;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Controller for handling REST API requests related to workflow steps.
 *
 * @since 3.0.0
 */
class Steps_Controller extends Gravity_Flow_REST_Controller {

	/**
	 * The base path for the assignees route.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $rest_base = 'entries/(?P<entry_id>\d+)/workflow/steps';

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 3.0.0
	 */
	public function register_routes() {

		register_rest_route( $this->namespace, '/forms/(?P<form_id>\d+)/workflow/steps',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items_by_form' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [],
				],
			]
		);

		register_rest_route( $this->namespace, '/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items_by_entry' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [],
				],
			]
		);

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<step_id>(\d+|current))',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [],
				],
			]
		);

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<step_id>(\d+|current))/restart',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'restart'  ],
					'permission_callback' => [ $this, 'restart_permissions_check' ],
					'args'                => [],
				],
			]
		);

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<step_id>(\d+|current))/send',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'send'  ],
					'permission_callback' => [ $this, 'send_permissions_check' ],
					'args'                => [],
				],
			]
		);

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<step_id>(\d+|current))/process',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'process' ],
					'permission_callback' => [ $this, 'process_permissions_check' ],
					'args'                => [
						'status'           => [
							'description' => esc_html__( 'The new status for the step (e.g. approved for an approval step or complete for a user input step ).', 'gravityflow' ),
							'type'        => 'string',
							'required'    => true,
						],
						'gravityflow_note' => [
							'description' => esc_html__( 'A note to be added to the step. This may be required depending on the step configuration.', 'gravityflow' ),
							'type'        => 'string',
						],
						'input_[FIELD ID]' => [
							'description' => esc_html__( 'Optionally update an entry field. Different field types have different formats. The expected input names are identical to the input names found in the form markup', 'gravityflow' ),
							'type'        => 'string',
							'required'    => false,
						],
					],
				],
			]
		);
	}

	/**
	 * Get a collection of steps associated with an entry.
	 *
	 * @since 3.0.0
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error | WP_REST_Response
	 */
	public function get_items_by_entry( $request ) {

		// Get parameters passed in the route
		$entry = $this->get_entry_param( $request );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		return $this->get_steps( $entry['form_id'], $entry );
	}

	/**
	 * Get a collection of steps associated with a form.
	 *
	 * @since 3.0.0
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error | WP_REST_Response
	 */
	public function get_items_by_form( $request ) {

		return $this->get_steps( (int) $request->get_param( 'form_id' ) );
	}

	/**
	 * Gets the details of a specific step associated with an entry.
	 *
	 * @since 3.0.0
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error | WP_REST_Response
	 */
	public function get_item( $request ) {

		$step = $this->get_step_param( $request, $api, $entry );
		if ( is_wp_error( $step ) ) {
			return $step;
		}

		return $this->get_step_response( $step, $entry, $api );
	}

	/**
	 * Check if a given request has access to get items
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ) {

		/**
		 * Filters the capability required to get steps via the REST API.
		 *
		 * @since 3.0.0
		 *
		 * @param string|array    $capability The capability required for this endpoint.
		 * @param WP_REST_Request $request    Full data about the request.
		 */
		$capability = apply_filters( 'gravityflow_rest_api_capability_get_forms_steps', 'gravityflow_create_steps', $request );

		if ( ! $this->current_user_can_any( $capability, $request ) ) {
			return new WP_Error(
				'permission_denied',
				esc_html__( 'You are not allowed to view steps.', 'gravityflow' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Restart the current step.
	 *
	 * @since 3.0.0
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error | WP_REST_Response Returns the current step or a WP_Error if the step could not be restarted
	 */
	public function restart( $request ) {

		$step = $this->get_step_param( $request, $api, $entry );
		if ( is_wp_error( $step ) ) {
			return $step;
		}

		// Getting the current step for the workflow.
		$current_step = $api->get_current_step( $entry );

		if ( ! $current_step ) {
			return new WP_Error( 'no_active_step', esc_html__( 'This entry does not have an active workflow step', 'gravityflow' ), [ 'status' => 422 ] );
		}

		if ( $step->get_id() !== $current_step->get_id() ) {
			return new WP_Error( 'invalid_step', esc_html__( 'You can only restart the current step of a workflow.', 'gravityflow' ), [ 'status' => 422 ] );
		}

		$api->restart_step( $entry ); // Restarting the current step.

		return self::get_workflow_response( $entry, $api );
	}

	/**
	 * Check if a given request has access to restarting a step
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function restart_permissions_check( $request ) {

		/**
		 * Filters the capability required to restart a step via the REST API.
		 *
		 * @since 3.0.0
		 *
		 * @param string|array    $capability The capability required for this endpoint.
		 * @param WP_REST_Request $request    Full data about the request.
		 */
		$capability = apply_filters( 'gravityflow_rest_api_capability_restart_step', 'gravityflow_admin_actions', $request );

		if ( ! $this->current_user_can_any( $capability, $request ) ) {
			return new WP_Error(
				'permission_denied',
				esc_html__( 'You are not allowed to restart steps.', 'gravityflow' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Sends the workflow to a specific step.
	 *
	 * @since 3.0.0
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error | WP_REST_Response Returns the current step after starting the specified step or a WP_Error if the entry or step could not be found.
	 */
	public function send( $request ) {

		$step = $this->get_step_param( $request, $api, $entry );
		if ( is_wp_error( $step ) ) {
			return $step;
		}

		// Getting the current step for the workflow.
		$current_step = $api->get_current_step( $entry );

		if ( ! $current_step ) {
			return new WP_Error( 'no_active_step', esc_html__( 'This entry does not have an active workflow step.', 'gravityflow' ), [ 'status' => 422 ] );
		}

		if ( $step->get_id() === $current_step->get_id() ) {
			return new WP_Error( 'invalid_step', esc_html__( 'You cannot send the workflow to a step that is already the current step.', 'gravityflow' ), [ 'status' => 422 ] );
		}

		$api->send_to_step( $entry, $step->get_id() ); // Sending to a different step.

		return self::get_workflow_response( $entry, $api );
	}

	/**
	 * Check if a given request has access to send the workflow to a step.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function send_permissions_check( $request ) {

		/**
		 * Filters the capability required to send a step via the REST API.
		 *
		 * @since 3.0.0
		 *
		 * @param string|array    $capability The capability required for this endpoint.
		 * @param WP_REST_Request $request    Full data about the request.
		 */
		$capability = apply_filters( 'gravityflow_rest_api_capability_send_step', 'gravityflow_admin_actions', $request );

		if ( ! $this->current_user_can_any( $capability, $request ) ) {
			return new WP_Error(
				'permission_denied',
				esc_html__( 'You are not allowed to send the workflow to a step.', 'gravityflow' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Processes the specific workflow step.
	 *
	 * @since 3.0.0
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error | WP_REST_Response
	 */
	public function process( $request ){

		// Getting specified step to be processed.
		$step = $this->get_step_param( $request, $api, $entry );
		if ( is_wp_error( $step ) ) {
			return $step;
		}

		// Getting the current step for the workflow.
		$current_step = $api->get_current_step( $entry );
		if ( ! $current_step ) {
			return new WP_Error( 'no_active_step', esc_html__( 'This entry does not have an active workflow step.', 'gravityflow' ), [ 'status' => 422 ] );
		}

		if ( $step->get_id() !== $current_step->get_id() ) {
			return new WP_Error( 'invalid_step', esc_html__( 'You can only process the current step of a workflow.', 'gravityflow' ), [ 'status' => 422 ] );
		}

		// The actual action (approve, reject, revert) is handled by the step's rest_callback.
		$response = $step->rest_process_step( $request );

		if ( is_wp_error( $response ) ) {
			if ( $response->get_error_code() === 'not_implemented' ) {
				$response = new WP_Error( 'operation_not_supported', esc_html__( 'The entry is on a workflow step type which cannot be updated via the API.', 'gravityflow' ), array( 'status' => 422 ) );
			}
			return $response;
		}

		// Process workflow after the step's action has been fully handled.
		$api->process_workflow( $entry['id'] );

		return self::get_workflow_response( $entry, $api );
	}

	/**
	 * Check if a given request has access starting a step
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function process_permissions_check( $request ) {

		/**
		 * Filters the capability required to start a step via the REST API.
		 *
		 * @since 3.0.0
		 *
		 * @param string|array    $capability The capability required for this endpoint.
		 * @param WP_REST_Request $request    Full data about the request.
		 */
		$capability = apply_filters( 'gravityflow_rest_api_capability_process_step', 'gravityflow_admin_actions', $request );

		if ( ! $this->current_user_can_any( $capability, $request ) ) {
			return new WP_Error( 'permission_denied', esc_html__( 'You are not allowed to process steps.', 'gravityflow' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * Get a collection of steps based on the specified form id.
	 *
	 * @since 3.0.0
	 *
	 * @param int $form_id The form id.
	 *
	 * @return WP_Error | WP_REST_Response Returns a WP_Error if the form is not found, otherwise returns a WP_REST_Response containing the steps.
	 */
	private function get_steps( $form_id, $entry = null ) {

		$form = GFAPI::get_form( $form_id );

		if ( ! $form ) {
			return new \WP_Error( 'form_not_found', esc_html__( 'Form could not be found.', 'gravityflow' ), array( 'status' => 404 ) );
		}

		$api   = new Gravity_Flow_API( $form_id );
		$steps = $api->get_steps();

		return $this->get_step_response( $steps, $entry, $api );
	}

	/**
	 * Get the entry based on the entry_id parameter.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return array|WP_Error Returns the entry array or a WP_Error if the entry could not be found.
	 */
	private function get_entry_param( $request ) {
		$entry_id = (int) $request->get_param( 'entry_id' );
		$entry    = GFAPI::get_entry( $entry_id );

		if ( empty( $entry ) || is_wp_error( $entry ) ) {
			return new WP_Error( 'entry_not_found', esc_html__( 'Invalid entry id. Entry could not be found.', 'gravityflow' ), array( 'status' => 404 ) );
		}

		return $entry;
	}

	/**
	 * Get the step based on the step_id parameter.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request  $request Full data about the request.
	 * @param Gravity_Flow_API $api     The Gravity Flow API instance. Passed by reference and will be set to an instance of the API in this method.
	 * @param array            $entry   The entry array. Passed by reference and will be set to an entry object instance in this method.
	 *
	 * @return object|WP_Error Returns the step object or a WP_Error if the step could not be found or does not belong to the same form as the entry.
	 */
	private function get_step_param( $request, &$api, &$entry ) {

		// Getting the entry based on the entry_id parameter.
		$entry = GFAPI::get_entry( $request->get_param( 'entry_id' ) );
		if ( ! $entry || is_wp_error( $entry ) ) {
			return new WP_Error( 'entry_not_found', esc_html__( 'Invalid entry id. Entry could not be found.', 'gravityflow' ), array( 'status' => 404 ) );
		}

		// Initializing the Gravity Flow API.
		$api     = new Gravity_Flow_API( $entry['form_id'] );
		$step_id = $request->get_param( 'step_id' );

		if ( $step_id === 'current' ) {
			$step = $api->get_current_step( $entry );
			if ( ! $step ) {
				return new WP_Error( 'no_active_step', esc_html__( 'This entry does not have an active workflow step.', 'gravityflow' ), array( 'status' => 422 ) );
			}
		} else {
			$step = $api->get_step( (int) $step_id, $entry );
		}

		// Validate that the step exists.
		if ( ! $step ) {
			return new WP_Error( 'step_not_found', esc_html__( 'Invalid step id. Step could not be found.', 'gravityflow' ), array( 'status' => 404 ) );
		}

		// Validate that the step belongs to the same form as the entry.
		if ( $step->get_form_id() !== absint( $entry['form_id'] ) ) {
			return new WP_Error( 'invalid_step', esc_html__( 'Step does not belong to the same form as the entry.', 'gravityflow' ), array( 'status' => 422 ) );
		}

		return $step;
	}

	/**
	 * Prepare the step(s) for the REST response.
	 *
	 * @since 3.0.0
	 *
	 * @param object|array     $steps The step object or an array of step objects.
	 * @param array            $entry The entry array.
	 * @param Gravity_Flow_API $api   The Gravity Flow API instance.
	 *
	 * @return WP_REST_Response Returns a WP_REST_Response containing the step data.
	 */
	private function get_step_response( $steps, $entry, $api ) {
		if ( is_array( $steps ) ){
			$data = \Gravity_Flow_Web_API::steps_collection_to_api_response( $steps, $entry, $api);
		} else if ( ! empty( $steps ) ) {
			$data = \Gravity_Flow_Web_API::steps_collection_to_api_response( [ $steps ], $entry, $api)[0];
		} else {
			$data = null;
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Gets a workflow response with workflow status and current step.
	 *
	 * @since 3.0.0
	 *
	 * @param array            $entry The entry array.
	 * @param Gravity_Flow_API $api   The Gravity Flow API instance.
	 *
	 * @return WP_REST_Response Returns a WP_REST_Response containing the workflow data with workflow status and current step.
	 */
	public static function get_workflow_response( $entry, $api ) {

		// Getting a fresh copy of the entry because it might have changed after processing the workflow
		$entry = GFAPI::get_entry( $entry['id'] );

		// Getting the current step for the workflow.
		$current_step = $api->get_current_step( $entry );

		$step = ! empty( $current_step ) ? \Gravity_Flow_Web_API::steps_collection_to_api_response( [ $current_step ], $entry, $api)[0] : null;
		$data = array(
			'final_status' => rgar( $entry, 'workflow_final_status' ),
			'timestamp'    => rgar( $entry, 'workflow_timestamp' ),
			'current_step' => $step,
		);
		return new \WP_REST_Response( $data, 200 );
	}
}
