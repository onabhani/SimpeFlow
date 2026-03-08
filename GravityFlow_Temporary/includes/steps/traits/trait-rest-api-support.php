<?php

trait Rest_API_Support {

	/**
	 * Gets the assignee based on the assignee_key parameter or the current user.
	 *
	 * @since 3.0
	 *
	 * @param WP_REST_Request $request The current REST API request.
	 * @param array           $form    The form currently being processed (passed by reference).
	 * @param array           $entry   The entry currently being processed (passed by reference).
	 *
	 * @return Gravity_Flow_Assignee|WP_Error
	 */
	private function rest_get_assignee( $request, &$form, &$entry ) {
		$form  = $this->get_form();
		$entry = $this->get_entry( true );

		// Validating that a current step exists for the entry.
		$api = new Gravity_Flow_API( $form['id'] );
		$current_step = $api->get_current_step( $entry );
		if ( ! $current_step ) {
			return new WP_Error( 'no_active_step', esc_html__( 'This entry does not have an active workflow step that can be processed.', 'gravityflow' ), array( 'status' => 404 ) );
		}

		// Getting assignee key. It can be passed in the request or if not present the current user will be used.
		if ( $request->has_param( 'assignee_key' ) ) {
			try {
				// Getting assignee based on assign_key parameter.
				$assignee = $current_step->get_assignee( $request->get_param( 'assignee_key' ) );
			} catch ( \Exception $e ) {
				$assignee = false;
			}
		} else {
			// Getting assignee based on current user.
			$assignee = $this->get_current_assignee();
		}

		$is_valid_assignee = $assignee && $current_step->has_assignee( $assignee->get_key() );
		if ( ! $is_valid_assignee ) {
			return new WP_Error( 'invalid_assignee', esc_html__( 'Invalid assignee key, assignee does not belong to the current step or assignee has already been processed.', 'gravityflow' ), [ 'status' => 422 ] );
		}

		return $assignee;
	}

	/**
	 * Hydrates the $_POST global with any parameters passed in the REST API request.
	 *
	 * @since 3.0
	 *
	 * @param int             $form_id The ID of the form currently being processed.
	 * @param WP_REST_Request $request The current REST API request.
	 *
	 * @return void
	 */
	private function rest_hydrate_post( $form_id, $request) {

		$_POST[ "is_submit_{$form_id}" ] = '1';

		foreach( $request->get_params() as $key => $value ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				$_POST[ $key ] = $value;
			}
		}
	}

	/**
	 * Format the result of the status update for REST response.
	 *
	 * @since 3.0.0
	 *
	 * @param mixed $result The result of the status update.
	 *
	 * @return mixed | WP_Error Returns the result or a WP_Error if there was an error.
	 */
	private function rest_format_result( $result ) {

		if ( is_wp_error( $result ) ) {
			// If error code is validation_error, add status code 422
			if ($result->get_error_code() === 'assignee_already_processed') {
				$result->error_data['assignee_already_processed']['status'] = 422;
			}
			return $result;
		}

		if ( $result === false ) {
			return new WP_Error( 'status_not_updated', esc_html__( 'Assignee status could not be updated. You have either provided an invalid status or there was extra validation performed via a filter that prevented the status from being changed.', 'gravityflow' ), array( 'status' => 422 ) );
		}

		return $result;
	}


}
