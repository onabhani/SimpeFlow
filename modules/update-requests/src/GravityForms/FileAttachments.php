<?php
namespace SFA\UpdateRequests\GravityForms;

/**
 * File Attachments
 *
 * Controls file upload field visibility based on approval status
 * Only allows file uploads AFTER update request is approved
 */
class FileAttachments {

	public function __construct() {
		// Hide file upload field before approval
		add_filter( 'gform_field_visibility', [ $this, 'control_file_field_visibility' ], 10, 3 );

		// Add conditional logic to file upload field
		add_filter( 'gform_pre_render', [ $this, 'add_file_field_conditional_logic' ] );

		// Validate file uploads (ensure only allowed after approval)
		add_filter( 'gform_field_validation', [ $this, 'validate_file_upload' ], 10, 4 );

		// Show notice when file upload becomes available
		add_filter( 'gform_pre_render', [ $this, 'add_file_upload_notice' ] );
	}

	/**
	 * Control file field visibility based on approval status
	 *
	 * @param string $visibility
	 * @param object $field
	 * @param array  $form
	 * @return string
	 */
	public function control_file_field_visibility( $visibility, $field, $form ) {
		// Only apply to file upload fields with admin label '_ur_files'
		if ( $field->type !== 'fileupload' || $field->adminLabel !== '_ur_files' ) {
			return $visibility;
		}

		// Check if this is an update request entry being edited
		$entry_id = $this->get_current_entry_id();

		if ( ! $entry_id ) {
			// New submission - hide file field
			return 'hidden';
		}

		// Check if update request
		$mode = gform_get_meta( $entry_id, '_ur_mode' );
		if ( $mode !== 'update_request' ) {
			return $visibility;
		}

		// Check approval status
		$status = gform_get_meta( $entry_id, '_ur_status' );

		if ( $status === 'approved' ) {
			// Approved - show file field
			return 'visible';
		} else {
			// Not approved yet - hide file field
			return 'hidden';
		}
	}

	/**
	 * Add conditional logic to file field
	 *
	 * @param array $form
	 * @return array
	 */
	public function add_file_field_conditional_logic( $form ) {
		$entry_id = $this->get_current_entry_id();

		if ( ! $entry_id ) {
			return $form;
		}

		// Check if update request
		$mode = gform_get_meta( $entry_id, '_ur_mode' );
		if ( $mode !== 'update_request' ) {
			return $form;
		}

		// Get approval status
		$status = gform_get_meta( $entry_id, '_ur_status' );

		// Find file upload field
		foreach ( $form['fields'] as &$field ) {
			if ( $field->type === 'fileupload' && $field->adminLabel === '_ur_files' ) {
				// Add CSS class based on status
				if ( $status === 'approved' ) {
					$field->cssClass = ( $field->cssClass ?? '' ) . ' ur-file-upload-approved';
				} else {
					$field->cssClass = ( $field->cssClass ?? '' ) . ' ur-file-upload-pending';

					// Add description explaining why field is hidden
					$field->description = '<strong style="color: #dc3232;">File uploads are only available after update request is approved.</strong>';
				}
			}
		}

		return $form;
	}

	/**
	 * Validate file upload (ensure only after approval)
	 *
	 * @param array  $result
	 * @param mixed  $value
	 * @param array  $form
	 * @param object $field
	 * @return array
	 */
	public function validate_file_upload( $result, $value, $form, $field ) {
		// Only apply to file upload fields with admin label '_ur_files'
		if ( $field->type !== 'fileupload' || $field->adminLabel !== '_ur_files' ) {
			return $result;
		}

		$entry_id = $this->get_current_entry_id();

		if ( ! $entry_id ) {
			// New submission - don't allow file upload
			$result['is_valid'] = false;
			$result['message'] = 'File uploads are only allowed after update request is approved.';
			return $result;
		}

		// Check if update request
		$mode = gform_get_meta( $entry_id, '_ur_mode' );
		if ( $mode !== 'update_request' ) {
			return $result;
		}

		// Check approval status
		$status = gform_get_meta( $entry_id, '_ur_status' );

		if ( $status !== 'approved' && ! empty( $value ) ) {
			// Not approved but file uploaded - reject
			$result['is_valid'] = false;
			$result['message'] = 'File uploads are only allowed after update request is approved.';

			error_log( sprintf(
				'Update Requests: Blocked file upload for entry %d (status: %s)',
				$entry_id,
				$status
			) );
		}

		return $result;
	}

	/**
	 * Add notice about file upload availability
	 *
	 * @param array $form
	 * @return array
	 */
	public function add_file_upload_notice( $form ) {
		$entry_id = $this->get_current_entry_id();

		if ( ! $entry_id ) {
			return $form;
		}

		// Check if update request
		$mode = gform_get_meta( $entry_id, '_ur_mode' );
		if ( $mode !== 'update_request' ) {
			return $form;
		}

		// Get approval status
		$status = gform_get_meta( $entry_id, '_ur_status' );

		// Find HTML field to inject notice (look for admin label '_ur_notice')
		foreach ( $form['fields'] as &$field ) {
			if ( $field->type === 'html' && $field->adminLabel === '_ur_file_notice' ) {
				if ( $status === 'approved' ) {
					$field->content = '<div style="padding: 15px; background: #d4edda; border-left: 4px solid #28a745; margin: 10px 0;">
						<strong style="color: #155724;">✓ Update Request Approved</strong><br>
						<span style="color: #155724;">You may now upload files related to this update request.</span>
					</div>';
				} elseif ( $status === 'rejected' ) {
					$field->content = '<div style="padding: 15px; background: #f8d7da; border-left: 4px solid #dc3545; margin: 10px 0;">
						<strong style="color: #721c24;">✗ Update Request Rejected</strong><br>
						<span style="color: #721c24;">This update request has been rejected. No file uploads are allowed.</span>
					</div>';
				} else {
					$field->content = '<div style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; margin: 10px 0;">
						<strong style="color: #856404;">⏳ Awaiting Approval</strong><br>
						<span style="color: #856404;">File uploads will be available after the update request is approved.</span>
					</div>';
				}
			}
		}

		return $form;
	}

	/**
	 * Get current entry ID from URL or global
	 *
	 * @return int|null
	 */
	private function get_current_entry_id() {
		// Check URL parameter
		if ( isset( $_GET['lid'] ) ) {
			return absint( $_GET['lid'] );
		}

		// Check global entry
		if ( isset( $GLOBALS['current_entry']['id'] ) ) {
			return absint( $GLOBALS['current_entry']['id'] );
		}

		// Check GF global
		if ( class_exists( 'GFFormDisplay' ) && isset( GFFormDisplay::$submission ) ) {
			$entry = GFFormDisplay::$submission['lead'];
			if ( isset( $entry['id'] ) ) {
				return absint( $entry['id'] );
			}
		}

		return null;
	}
}
