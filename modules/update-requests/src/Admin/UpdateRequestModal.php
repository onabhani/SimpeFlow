<?php
namespace SFA\UpdateRequests\Admin;

use SFA\UpdateRequests\GravityForms\VersionManager;

/**
 * Update Request Modal Handler
 *
 * Handles AJAX submissions from update request and following invoice modals
 */
class UpdateRequestModal {

	public function __construct() {
		// AJAX handlers
		add_action( 'wp_ajax_sfa_ur_submit_update', [ $this, 'handle_update_request' ] );
		add_action( 'wp_ajax_sfa_ur_submit_following', [ $this, 'handle_following_invoice' ] );

		// Admin-post handler for manual apply (when automatic apply fails)
		add_action( 'admin_post_sfa_ur_apply_update', [ $this, 'handle_manual_apply' ] );
	}

	/**
	 * Handle manual apply action
	 *
	 * URL: /wp-admin/admin-post.php?action=sfa_ur_apply_update&entry_id=123&_wpnonce=...
	 * Used by admins when automatic application fails
	 */
	public function handle_manual_apply() {
		// Get entry ID
		$entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;

		if ( ! $entry_id ) {
			wp_die( 'Missing entry ID', 'Error', [ 'response' => 400 ] );
		}

		// Verify nonce
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'sfa_ur_apply_' . $entry_id ) ) {
			wp_die( 'Invalid security token', 'Error', [ 'response' => 403 ] );
		}

		// Check permissions (admin only)
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions. Only administrators can manually apply updates.', 'Error', [ 'response' => 403 ] );
		}

		// Get entry and verify it's an update request
		$entry = \GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $entry ) ) {
			wp_die( 'Entry not found', 'Error', [ 'response' => 404 ] );
		}

		$mode = gform_get_meta( $entry_id, '_ur_mode' );
		if ( $mode !== 'update_request' ) {
			wp_die( 'This entry is not an update request', 'Error', [ 'response' => 400 ] );
		}

		// Check if already applied
		$applied_at = gform_get_meta( $entry_id, '_ur_applied_at' );
		if ( $applied_at ) {
			wp_die(
				sprintf( 'This update request was already applied on %s', $applied_at ),
				'Already Applied',
				[ 'response' => 400 ]
			);
		}

		// Get current status
		$status = gform_get_meta( $entry_id, '_ur_status' );

		// Force approve if still submitted
		if ( $status === 'submitted' ) {
			gform_update_meta( $entry_id, '_ur_status', 'approved' );
			gform_update_meta( $entry_id, '_ur_approved_at', current_time( 'mysql' ) );
			gform_update_meta( $entry_id, '_ur_approved_by', get_current_user_id() );

			// Add note about manual approval
			\GFAPI::add_note(
				$entry_id,
				get_current_user_id(),
				wp_get_current_user()->display_name,
				'<strong>Manual Approval:</strong> Update request was manually approved by admin during manual apply action.'
			);
		}

		// Trigger the apply action
		do_action( 'sfa_update_request_approved', $entry_id, get_current_user_id() );

		// Check if apply was successful
		$applied_at = gform_get_meta( $entry_id, '_ur_applied_at' );

		if ( $applied_at ) {
			// Success - redirect back with success message
			$parent_id = gform_get_meta( $entry_id, '_ur_parent_id' );
			$redirect_url = add_query_arg(
				[
					'page' => 'gf_entries',
					'view' => 'entry',
					'id' => $entry['form_id'],
					'lid' => $parent_id ?: $entry_id,
					'ur_applied' => '1',
				],
				admin_url( 'admin.php' )
			);

			wp_redirect( $redirect_url );
			exit;
		} else {
			// Failed - show error
			wp_die(
				'Failed to apply update request. Check error logs for details.',
				'Apply Failed',
				[ 'response' => 500, 'back_link' => true ]
			);
		}
	}

	/**
	 * Handle update request submission
	 */
	public function handle_update_request() {
		// Verify nonce
		check_ajax_referer( 'sfa_ur_submit', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
		}

		// Get POST data
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( $_POST['filename'] ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( $_POST['reason'] ) : '';

		if ( ! $entry_id || ! $form_id || ! $filename ) {
			wp_send_json_error( [ 'message' => 'Missing required data' ] );
		}

		// Get parent entry
		$parent_entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $parent_entry ) ) {
			wp_send_json_error( [ 'message' => 'Parent entry not found' ] );
		}

		// Check if current user is the entry creator
		if ( ! FormSettings::is_entry_creator( $parent_entry ) ) {
			wp_send_json_error( [ 'message' => 'Only the entry creator can submit update requests' ] );
		}

		// Check if cutoff step has been passed
		$form = \GFAPI::get_form( $form_id );
		if ( ! FormSettings::can_submit_update_request( $form, 0, $parent_entry ) ) {
			wp_send_json_error( [ 'message' => 'Drawing updates are no longer allowed. The cutoff step has been passed.' ] );
		}

		// Handle file uploads
		$drawing_file = null;
		$invoice_file = null;

		if ( isset( $_FILES['drawing_file'] ) && $_FILES['drawing_file']['error'] === UPLOAD_ERR_OK ) {
			$drawing_file = $this->handle_file_upload( $_FILES['drawing_file'], 'drawing' );
			if ( is_wp_error( $drawing_file ) ) {
				wp_send_json_error( [ 'message' => 'Drawing upload failed: ' . $drawing_file->get_error_message() ] );
			}
		} else {
			wp_send_json_error( [ 'message' => 'Drawing file is required' ] );
		}

		if ( isset( $_FILES['invoice_file'] ) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK ) {
			$invoice_file = $this->handle_file_upload( $_FILES['invoice_file'], 'invoice' );
			if ( is_wp_error( $invoice_file ) ) {
				wp_send_json_error( [ 'message' => 'Invoice upload failed: ' . $invoice_file->get_error_message() ] );
			}
		}

		// Create child entry for update request
		$child_entry_data = [
			'form_id' => $form_id,
			'created_by' => get_current_user_id(),
		];

		$child_entry_id = \GFAPI::add_entry( $child_entry_data );

		if ( is_wp_error( $child_entry_id ) ) {
			wp_send_json_error( [ 'message' => 'Failed to create update request entry' ] );
		}

		// Save update request metadata
		gform_update_meta( $child_entry_id, '_ur_mode', 'update_request' );
		gform_update_meta( $child_entry_id, '_ur_parent_id', $entry_id );
		gform_update_meta( $child_entry_id, '_ur_type', 'drawing_update' );
		gform_update_meta( $child_entry_id, '_ur_status', 'submitted' );
		gform_update_meta( $child_entry_id, '_ur_original_filename', $filename );
		gform_update_meta( $child_entry_id, '_ur_reason', $reason );
		gform_update_meta( $child_entry_id, '_ur_drawing_file', $drawing_file );

		if ( $invoice_file ) {
			gform_update_meta( $child_entry_id, '_ur_invoice_file', $invoice_file );
		}

		// Link to parent entry
		$this->link_to_parent( $entry_id, $child_entry_id, 'drawing_update' );

		// Add entry note
		\GFAPI::add_note(
			$child_entry_id,
			get_current_user_id(),
			wp_get_current_user()->display_name,
			sprintf(
				'Update request submitted for drawing: %s<br>Reason: %s',
				$filename,
				$reason
			)
		);

		// Trigger workflow (if GravityFlow is active)
		if ( class_exists( 'Gravity_Flow_API' ) ) {
			$api = new \Gravity_Flow_API( $form_id );
			$api->process_workflow( $child_entry_id );
		}

		wp_send_json_success( [
			'message' => 'Update request submitted successfully and sent for approval',
			'child_entry_id' => $child_entry_id,
		] );
	}

	/**
	 * Handle following invoice submission
	 */
	public function handle_following_invoice() {
		// Verify nonce
		check_ajax_referer( 'sfa_ur_submit', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
		}

		// Get POST data
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( $_POST['reason'] ) : '';

		if ( ! $entry_id || ! $form_id ) {
			wp_send_json_error( [ 'message' => 'Missing required data' ] );
		}

		// Get parent entry
		$parent_entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $parent_entry ) ) {
			wp_send_json_error( [ 'message' => 'Parent entry not found' ] );
		}

		// Check if current user is the entry creator
		if ( ! FormSettings::is_entry_creator( $parent_entry ) ) {
			wp_send_json_error( [ 'message' => 'Only the entry creator can submit following invoices' ] );
		}

		// Check if cutoff step has been passed
		$form = \GFAPI::get_form( $form_id );
		if ( ! FormSettings::can_submit_following_invoice( $form, 0, $parent_entry ) ) {
			wp_send_json_error( [ 'message' => 'Following invoices are no longer allowed. The cutoff step has been passed.' ] );
		}

		// Handle file upload
		$invoice_file = null;

		if ( isset( $_FILES['invoice_file'] ) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK ) {
			$invoice_file = $this->handle_file_upload( $_FILES['invoice_file'], 'invoice' );
			if ( is_wp_error( $invoice_file ) ) {
				wp_send_json_error( [ 'message' => 'Invoice upload failed: ' . $invoice_file->get_error_message() ] );
			}
		} else {
			wp_send_json_error( [ 'message' => 'Invoice file is required' ] );
		}

		// Create child entry for following invoice
		$child_entry_data = [
			'form_id' => $form_id,
			'created_by' => get_current_user_id(),
		];

		$child_entry_id = \GFAPI::add_entry( $child_entry_data );

		if ( is_wp_error( $child_entry_id ) ) {
			wp_send_json_error( [ 'message' => 'Failed to create following invoice entry' ] );
		}

		// Save following invoice metadata
		gform_update_meta( $child_entry_id, '_ur_mode', 'update_request' );
		gform_update_meta( $child_entry_id, '_ur_parent_id', $entry_id );
		gform_update_meta( $child_entry_id, '_ur_type', 'following_invoice' );
		gform_update_meta( $child_entry_id, '_ur_status', 'submitted' );
		gform_update_meta( $child_entry_id, '_ur_reason', $reason );
		gform_update_meta( $child_entry_id, '_ur_invoice_file', $invoice_file );

		// Link to parent entry
		$this->link_to_parent( $entry_id, $child_entry_id, 'following_invoice' );

		// Add entry note
		\GFAPI::add_note(
			$child_entry_id,
			get_current_user_id(),
			wp_get_current_user()->display_name,
			sprintf(
				'Following invoice submitted<br>Description: %s',
				$reason
			)
		);

		// Trigger workflow (if GravityFlow is active)
		if ( class_exists( 'Gravity_Flow_API' ) ) {
			$api = new \Gravity_Flow_API( $form_id );
			$api->process_workflow( $child_entry_id );
		}

		wp_send_json_success( [
			'message' => 'Following invoice submitted successfully and sent for approval',
			'child_entry_id' => $child_entry_id,
		] );
	}

	/**
	 * Handle file upload
	 *
	 * @param array  $file    $_FILES array element
	 * @param string $type    File type (drawing, invoice)
	 * @return string|WP_Error File URL or error
	 */
	private function handle_file_upload( $file, $type ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Validate file type
		$allowed_types = [
			'drawing' => [ 'pdf', 'dwg', 'dxf', 'jpg', 'jpeg', 'png' ],
			'invoice' => [ 'pdf' ],
		];

		$file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_ext, $allowed_types[ $type ], true ) ) {
			return new \WP_Error(
				'invalid_file_type',
				sprintf( 'Invalid file type. Allowed types: %s', implode( ', ', $allowed_types[ $type ] ) )
			);
		}

		// Upload file
		$upload_overrides = [ 'test_form' => false ];
		$uploaded_file = wp_handle_upload( $file, $upload_overrides );

		if ( isset( $uploaded_file['error'] ) ) {
			return new \WP_Error( 'upload_error', $uploaded_file['error'] );
		}

		return $uploaded_file['url'];
	}

	/**
	 * Link child entry to parent
	 *
	 * @param int    $parent_id  Parent entry ID
	 * @param int    $child_id   Child entry ID
	 * @param string $request_type Request type
	 */
	private function link_to_parent( $parent_id, $child_id, $request_type ) {
		// Get existing children
		$children_json = gform_get_meta( $parent_id, '_ur_children' );
		$children = $children_json ? json_decode( $children_json, true ) : [];

		if ( ! is_array( $children ) ) {
			$children = [];
		}

		// Add new child
		$children[] = [
			'entry_id' => $child_id,
			'request_type' => $request_type,
			'status' => 'submitted',
			'submitted_at' => current_time( 'mysql' ),
			'submitted_by' => get_current_user_id(),
		];

		// Update parent meta
		gform_update_meta( $parent_id, '_ur_children', wp_json_encode( $children ) );

		// Fire action
		do_action( 'sfa_update_request_linked', $child_id, $parent_id, $request_type );
	}
}
