<?php

namespace Gravity_Flow\Gravity_Flow\Ajax;

class Ajax_Permissions_Handler {

	/**
	 * Override the permissions check to delete a file from a user input step.
	 *
	 * @since 2.8.3
	 *
	 * @param array $all_user_caps
	 * @param array $requested_caps
	 *
	 * @return array
	 */
	public function override_file_delete_perms( $all_user_caps, $requested_caps ) {
		$lead_id  = filter_var( rgpost( 'lead_id' ), FILTER_SANITIZE_NUMBER_INT );
		$field_id = filter_var( rgpost( 'field_id' ), FILTER_SANITIZE_NUMBER_INT );

		if ( empty( $lead_id ) ) {
			return $all_user_caps;
		}

		$entry = \GFAPI::get_entry( $lead_id );

		if ( empty( $entry['form_id'] ) ) {
			return $all_user_caps;
		}

		if ( ! $this->can_current_user_edit_field( $entry, $field_id ) ) {
			return $all_user_caps;
		}

		$all_user_caps['gravityforms_delete_entries'] = true;

		return $all_user_caps;
	}

	/**
	 * Check if current user is an assignee on the current step.
	 *
	 * @since 2.8.3
	 *
	 * @param array  $entry
	 * @param string $field_id
	 *
	 * @return bool
	 */
	private function can_current_user_edit_field( $entry, $field_id ) {
		$api  = new \Gravity_Flow_API( $entry['form_id'] );
		$step = $api->get_current_step( $entry );

		if ( empty( $step ) ) {
			return false;
		}

		$id_array = array( 'id' => $field_id );

		if ( ! \Gravity_Flow_Common::is_editable_field( (object) $id_array, $step ) ) {
			return false;
		}

		$current_user_assignee_key = $step->get_current_assignee_key();
		if ( ! $current_user_assignee_key ) {
			return false;
		}

		$assignee = $step->get_assignee( $current_user_assignee_key );

		return $assignee->is_current_user();
	}

}