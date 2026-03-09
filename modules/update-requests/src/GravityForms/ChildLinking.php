<?php
namespace SFA\UpdateRequests\GravityForms;

/**
 * Child Linking
 *
 * Links child update request entries to parent job entry
 * Stores child entry IDs in parent's _ur_children meta as JSON array
 */
class ChildLinking {

	public function __construct() {
		// Hook after form submission to link child to parent
		add_action( 'gform_after_submission', [ $this, 'link_child_to_parent' ], 10, 2 );
	}

	/**
	 * Link child entry to parent after submission
	 *
	 * @param array $entry
	 * @param array $form
	 */
	public function link_child_to_parent( $entry, $form ) {
		$entry_id = (int) $entry['id'];

		// Get hidden fields
		$mode = $this->get_field_value_by_admin_label( $entry, $form, '_ur_mode' );
		$parent_id = $this->get_field_value_by_admin_label( $entry, $form, '_ur_parent_id' );
		$request_type = $this->get_field_value_by_admin_label( $entry, $form, '_ur_type' );

		// Only process if in update request mode
		if ( $mode !== 'update_request' || ! $parent_id ) {
			return;
		}

		$parent_id = absint( $parent_id );

		// Verify parent entry exists
		$parent_entry = \GFAPI::get_entry( $parent_id );
		if ( is_wp_error( $parent_entry ) || ! $parent_entry ) {
			error_log( sprintf(
				'Update Requests: Failed to link child entry %d - parent entry %d not found',
				$entry_id,
				$parent_id
			) );
			return;
		}

		// Store parent ID in child entry meta
		gform_update_meta( $entry_id, '_ur_parent_id', $parent_id );
		gform_update_meta( $entry_id, '_ur_mode', 'update_request' );
		gform_update_meta( $entry_id, '_ur_type', $request_type );
		gform_update_meta( $entry_id, '_ur_status', 'submitted' );
		gform_update_meta( $entry_id, '_ur_submitted_at', current_time( 'mysql' ) );
		gform_update_meta( $entry_id, '_ur_submitted_by', get_current_user_id() );

		// Acquire lock to prevent concurrent _ur_children modifications
		global $wpdb;
		$lock_name = 'sfa_ur_children_' . $parent_id;
		$lock_acquired = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 15)", $lock_name ) );

		if ( ! $lock_acquired ) {
			error_log( sprintf(
				'Update Requests: Failed to acquire lock for parent entry %d',
				$parent_id
			) );
			return;
		}

		// Get existing children array from parent
		$children_json = gform_get_meta( $parent_id, '_ur_children' );
		$children = $children_json ? json_decode( $children_json, true ) : [];

		if ( ! is_array( $children ) ) {
			$children = [];
		}

		// Append this child entry
		$children[] = [
			'entry_id' => $entry_id,
			'request_type' => $request_type,
			'status' => 'submitted',
			'submitted_at' => current_time( 'mysql' ),
			'submitted_by' => get_current_user_id(),
		];

		// Update parent's children array
		gform_update_meta( $parent_id, '_ur_children', wp_json_encode( $children ) );

		// Release lock
		$wpdb->get_var( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );

		error_log( sprintf(
			'Update Requests: Linked child entry %d to parent %d (type: %s)',
			$entry_id,
			$parent_id,
			$request_type
		) );

		// Allow other plugins to react
		do_action( 'sfa_update_request_linked', $entry_id, $parent_id, $request_type );
	}

	/**
	 * Get field value by admin label
	 *
	 * @param array  $entry
	 * @param array  $form
	 * @param string $admin_label
	 * @return string
	 */
	private function get_field_value_by_admin_label( $entry, $form, $admin_label ) {
		foreach ( $form['fields'] as $field ) {
			if ( $field->adminLabel === $admin_label ) {
				return isset( $entry[ $field->id ] ) ? $entry[ $field->id ] : '';
			}
		}
		return '';
	}
}
