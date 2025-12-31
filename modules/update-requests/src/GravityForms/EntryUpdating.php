<?php
namespace SFA\UpdateRequests\GravityForms;

/**
 * Entry Updating
 *
 * Updates parent job entry with data from approved update request
 * Handles field mapping and data synchronization
 */
class EntryUpdating {

	public function __construct() {
		// Hook after approval to trigger entry update
		add_action( 'sfa_update_request_approved', [ $this, 'process_entry_update' ], 10, 2 );

		// Manual trigger via admin action
		add_action( 'admin_post_sfa_ur_apply_update', [ $this, 'manual_apply_update' ] );
	}

	/**
	 * Process entry update after approval
	 *
	 * @param int $child_entry_id Update request entry ID
	 * @param int $user_id User who approved
	 */
	public function process_entry_update( $child_entry_id, $user_id ) {
		// Get update request type
		$request_type = gform_get_meta( $child_entry_id, '_ur_type' );

		if ( $request_type !== 'entry_updating' ) {
			// Only process 'entry_updating' type for now
			// 'following_invoice' is handled separately
			error_log( sprintf(
				'Update Requests: Skipping entry update for type "%s" (entry %d)',
				$request_type,
				$child_entry_id
			) );
			return;
		}

		// Get parent entry ID
		$parent_id = gform_get_meta( $child_entry_id, '_ur_parent_id' );

		if ( ! $parent_id ) {
			error_log( sprintf(
				'Update Requests: No parent ID found for entry %d',
				$child_entry_id
			) );
			return;
		}

		// Get both entries
		$child_entry = \GFAPI::get_entry( $child_entry_id );
		$parent_entry = \GFAPI::get_entry( $parent_id );

		if ( is_wp_error( $child_entry ) || is_wp_error( $parent_entry ) ) {
			error_log( sprintf(
				'Update Requests: Failed to load entries (child: %d, parent: %d)',
				$child_entry_id,
				$parent_id
			) );
			return;
		}

		// Get form
		$form = \GFAPI::get_form( $child_entry['form_id'] );

		if ( ! $form ) {
			error_log( sprintf(
				'Update Requests: Form not found for entry %d',
				$child_entry_id
			) );
			return;
		}

		// Map fields and update parent
		$updated_fields = $this->map_and_update_fields( $child_entry, $parent_entry, $form );

		if ( empty( $updated_fields ) ) {
			error_log( sprintf(
				'Update Requests: No fields updated for entry %d',
				$child_entry_id
			) );
			return;
		}

		// Update parent entry
		$result = \GFAPI::update_entry( $parent_entry );

		if ( is_wp_error( $result ) ) {
			error_log( sprintf(
				'Update Requests: Failed to update parent entry %d: %s',
				$parent_id,
				$result->get_error_message()
			) );
			return;
		}

		// Record update in meta
		gform_update_meta( $child_entry_id, '_ur_applied_at', current_time( 'mysql' ) );
		gform_update_meta( $child_entry_id, '_ur_applied_by', $user_id );
		gform_update_meta( $child_entry_id, '_ur_updated_fields', wp_json_encode( $updated_fields ) );

		// Add note to parent entry
		$this->add_update_note_to_parent( $parent_id, $child_entry_id, $updated_fields, $user_id );

		error_log( sprintf(
			'Update Requests: Successfully updated parent entry %d from child %d (%d fields updated)',
			$parent_id,
			$child_entry_id,
			count( $updated_fields )
		) );

		do_action( 'sfa_update_request_applied', $child_entry_id, $parent_id, $updated_fields );
	}

	/**
	 * Map fields from child to parent and update
	 *
	 * @param array $child_entry
	 * @param array $parent_entry
	 * @param array $form
	 * @return array Updated field IDs and values
	 */
	private function map_and_update_fields( $child_entry, &$parent_entry, $form ) {
		$updated_fields = [];

		foreach ( $form['fields'] as $field ) {
			// Skip special fields
			if ( in_array( $field->adminLabel, [ '_ur_mode', '_ur_parent_id', '_ur_type', '_ur_files', '_ur_file_notice', '_ur_drawing_selection' ], true ) ) {
				continue;
			}

			// Skip fields that shouldn't be copied
			if ( $field->type === 'html' || $field->type === 'section' || $field->type === 'page' ) {
				continue;
			}

			$field_id = $field->id;

			// Get child value
			$child_value = isset( $child_entry[ $field_id ] ) ? $child_entry[ $field_id ] : '';

			// Skip empty values
			if ( empty( $child_value ) && $child_value !== '0' ) {
				continue;
			}

			// Get parent value for comparison
			$parent_value = isset( $parent_entry[ $field_id ] ) ? $parent_entry[ $field_id ] : '';

			// Skip if values are the same
			if ( $child_value === $parent_value ) {
				continue;
			}

			// Update parent entry field
			$parent_entry[ $field_id ] = $child_value;

			// Track updated field
			$updated_fields[] = [
				'field_id' => $field_id,
				'field_label' => $field->label,
				'old_value' => $parent_value,
				'new_value' => $child_value,
			];
		}

		return $updated_fields;
	}

	/**
	 * Add note to parent entry about update
	 *
	 * @param int   $parent_id
	 * @param int   $child_id
	 * @param array $updated_fields
	 * @param int   $user_id
	 */
	private function add_update_note_to_parent( $parent_id, $child_id, $updated_fields, $user_id ) {
		$user = get_userdata( $user_id );
		$username = $user ? $user->display_name : 'Unknown';

		$note = "Update Request Applied\n\n";
		$note .= "Update Request Entry: #$child_id\n";
		$note .= "Applied By: $username\n";
		$note .= "Applied At: " . current_time( 'mysql' ) . "\n\n";
		$note .= "Fields Updated:\n";

		foreach ( $updated_fields as $field ) {
			$note .= "• {$field['field_label']}\n";
			$note .= "  From: {$field['old_value']}\n";
			$note .= "  To: {$field['new_value']}\n\n";
		}

		\GFAPI::add_note( $parent_id, 0, $username, $note );
	}

	/**
	 * Manual apply update (triggered from admin)
	 */
	public function manual_apply_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}

		check_admin_referer( 'sfa_ur_apply_update' );

		$child_entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;

		if ( ! $child_entry_id ) {
			wp_die( 'Invalid entry ID.' );
		}

		// Check if approved
		$status = gform_get_meta( $child_entry_id, '_ur_status' );

		if ( $status !== 'approved' ) {
			wp_die( 'Update request must be approved before applying.' );
		}

		// Process update
		$this->process_entry_update( $child_entry_id, get_current_user_id() );

		// Redirect back
		$redirect_url = isset( $_GET['redirect'] ) ? urldecode( $_GET['redirect'] ) : admin_url( 'admin.php?page=gf_entries' );
		wp_safe_redirect( add_query_arg( 'ur_applied', '1', $redirect_url ) );
		exit;
	}
}
