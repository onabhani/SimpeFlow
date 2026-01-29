<?php
namespace SFA\UpdateRequests\GravityForms;

use SFA\UpdateRequests\Admin\FormSettings;

/**
 * File Version Applier
 *
 * Applies approved drawing/invoice updates to parent entry
 * Adds new file versions without modifying field values
 */
class FileVersionApplier {

	public function __construct() {
		// Hook into approval workflow
		add_action( 'sfa_update_request_approved', [ $this, 'apply_approved_update' ], 10, 2 );
	}

	/**
	 * Apply approved update to parent entry
	 *
	 * @param int $child_entry_id Child (update request) entry ID
	 * @param int $user_id User who approved
	 */
	public function apply_approved_update( $child_entry_id, $user_id ) {
		// Get child entry
		$child_entry = \GFAPI::get_entry( $child_entry_id );

		if ( is_wp_error( $child_entry ) ) {
			error_log( sprintf(
				'Update Requests: Failed to load child entry %d for applying update',
				$child_entry_id
			) );
			return;
		}

		// Get parent entry ID
		$parent_id = gform_get_meta( $child_entry_id, '_ur_parent_id' );

		if ( ! $parent_id ) {
			error_log( sprintf(
				'Update Requests: No parent ID found for child entry %d',
				$child_entry_id
			) );
			return;
		}

		// Get parent entry
		$parent_entry = \GFAPI::get_entry( $parent_id );

		if ( is_wp_error( $parent_entry ) ) {
			error_log( sprintf(
				'Update Requests: Failed to load parent entry %d',
				$parent_id
			) );
			return;
		}

		// Get request type
		$request_type = gform_get_meta( $child_entry_id, '_ur_type' );

		// Apply based on type
		// Note: 'entry_updating' comes from URL-mode forms, 'drawing_update' comes from AJAX modal
		if ( $request_type === 'drawing_update' || $request_type === 'entry_updating' ) {
			$this->apply_drawing_update( $parent_entry, $child_entry, $user_id );
		} elseif ( $request_type === 'following_invoice' ) {
			$this->apply_following_invoice( $parent_entry, $child_entry, $user_id );
		}
	}

	/**
	 * Apply drawing update to parent
	 *
	 * @param array $parent_entry Parent entry
	 * @param array $child_entry Child entry
	 * @param int   $user_id Approving user ID
	 */
	private function apply_drawing_update( $parent_entry, $child_entry, $user_id ) {
		$parent_id = $parent_entry['id'];
		$child_id = $child_entry['id'];
		$form_id = $parent_entry['form_id'];

		// Get form and drawing field
		$form = \GFAPI::get_form( $form_id );

		if ( ! $form ) {
			error_log( sprintf(
				'Update Requests: Form %d not found',
				$form_id
			) );
			return;
		}

		$drawing_field_id = FormSettings::get_drawing_field_id( $form );

		if ( ! $drawing_field_id ) {
			error_log( sprintf(
				'Update Requests: No drawing field configured for form %d',
				$form_id
			) );
			return;
		}

		// Get update request metadata
		$original_filename = gform_get_meta( $child_id, '_ur_original_filename' );
		$new_drawing_url = gform_get_meta( $child_id, '_ur_drawing_file' );
		$invoice_url = gform_get_meta( $child_id, '_ur_invoice_file' );
		$reason = gform_get_meta( $child_id, '_ur_reason' );

		if ( ! $original_filename || ! $new_drawing_url ) {
			error_log( sprintf(
				'Update Requests: Missing required metadata for child entry %d',
				$child_id
			) );
			return;
		}

		// Add new version to version history
		$version_manager = new VersionManager();
		$new_version = $version_manager->add_version(
			$parent_id,
			$original_filename,
			$new_drawing_url,
			'approved',
			[
				'reason' => $reason,
				'child_entry_id' => $child_id,
				'invoice_url' => $invoice_url,
			]
		);

		// Update parent entry field to include new file
		$this->add_file_to_field( $parent_entry, $drawing_field_id, $new_drawing_url );

		// Add entry note to parent
		\GFAPI::add_note(
			$parent_id,
			$user_id,
			wp_get_current_user()->display_name,
			sprintf(
				'<strong>Drawing Update Applied (v%d)</strong><br>' .
				'Original: %s<br>' .
				'New file: <a href="%s" target="_blank">%s</a><br>' .
				'Reason: %s<br>' .
				'Update request: #%d',
				$new_version,
				$original_filename,
				$new_drawing_url,
				basename( $new_drawing_url ),
				$reason,
				$child_id
			)
		);

		// Update child entry metadata
		gform_update_meta( $child_id, '_ur_applied_at', current_time( 'mysql' ) );
		gform_update_meta( $child_id, '_ur_applied_by', $user_id );

		error_log( sprintf(
			'Update Requests: Applied drawing update from entry %d to parent %d (new version: v%d)',
			$child_id,
			$parent_id,
			$new_version
		) );

		// Fire action
		do_action( 'sfa_update_request_applied', $child_id, $parent_id, [
			'type' => 'drawing_update',
			'filename' => $original_filename,
			'version' => $new_version,
		] );
	}

	/**
	 * Apply following invoice to parent
	 *
	 * @param array $parent_entry Parent entry
	 * @param array $child_entry Child entry
	 * @param int   $user_id Approving user ID
	 */
	private function apply_following_invoice( $parent_entry, $child_entry, $user_id ) {
		$parent_id = $parent_entry['id'];
		$child_id = $child_entry['id'];
		$form_id = $parent_entry['form_id'];

		// Get form and invoice field
		$form = \GFAPI::get_form( $form_id );

		if ( ! $form ) {
			error_log( sprintf(
				'Update Requests: Form %d not found',
				$form_id
			) );
			return;
		}

		$invoice_field_id = FormSettings::get_invoice_field_id( $form );

		if ( ! $invoice_field_id ) {
			error_log( sprintf(
				'Update Requests: No invoice field configured for form %d',
				$form_id
			) );
			return;
		}

		// Get invoice metadata
		$invoice_url = gform_get_meta( $child_id, '_ur_invoice_file' );
		$reason = gform_get_meta( $child_id, '_ur_reason' );

		if ( ! $invoice_url ) {
			error_log( sprintf(
				'Update Requests: Missing invoice file for child entry %d',
				$child_id
			) );
			return;
		}

		// Add invoice to parent entry field
		$this->add_file_to_field( $parent_entry, $invoice_field_id, $invoice_url );

		// Add entry note to parent
		\GFAPI::add_note(
			$parent_id,
			$user_id,
			wp_get_current_user()->display_name,
			sprintf(
				'<strong>Following Invoice Added</strong><br>' .
				'Invoice: <a href="%s" target="_blank">%s</a><br>' .
				'Description: %s<br>' .
				'Request: #%d',
				$invoice_url,
				basename( $invoice_url ),
				$reason,
				$child_id
			)
		);

		// Update child entry metadata
		gform_update_meta( $child_id, '_ur_applied_at', current_time( 'mysql' ) );
		gform_update_meta( $child_id, '_ur_applied_by', $user_id );

		error_log( sprintf(
			'Update Requests: Applied following invoice from entry %d to parent %d',
			$child_id,
			$parent_id
		) );

		// Fire action
		do_action( 'sfa_update_request_applied', $child_id, $parent_id, [
			'type' => 'following_invoice',
			'invoice' => basename( $invoice_url ),
		] );
	}

	/**
	 * Add file URL to entry field
	 *
	 * Handles multi-file fields by adding to existing array
	 *
	 * @param array $entry Entry array
	 * @param int   $field_id Field ID
	 * @param string $file_url File URL to add
	 */
	private function add_file_to_field( $entry, $field_id, $file_url ) {
		$entry_id = $entry['id'];
		$current_value = isset( $entry[ $field_id ] ) ? $entry[ $field_id ] : '';

		// Handle JSON array (multi-file)
		if ( $this->is_json( $current_value ) ) {
			$files = json_decode( $current_value, true );
			if ( ! is_array( $files ) ) {
				$files = [];
			}
			$files[] = $file_url;
			$new_value = wp_json_encode( $files );
		}
		// Handle comma-separated
		elseif ( strpos( $current_value, ',' ) !== false ) {
			$files = array_map( 'trim', explode( ',', $current_value ) );
			$files[] = $file_url;
			$new_value = implode( ',', $files );
		}
		// Handle single file or empty
		else {
			if ( empty( $current_value ) ) {
				$new_value = $file_url;
			} else {
				// Convert to comma-separated
				$new_value = $current_value . ',' . $file_url;
			}
		}

		// Update entry field
		$entry[ $field_id ] = $new_value;
		\GFAPI::update_entry( $entry );

		error_log( sprintf(
			'Update Requests: Added file to entry %d field %d: %s',
			$entry_id,
			$field_id,
			basename( $file_url )
		) );
	}

	/**
	 * Check if string is JSON
	 *
	 * @param string $string
	 * @return bool
	 */
	private function is_json( $string ) {
		json_decode( $string );
		return json_last_error() === JSON_ERROR_NONE;
	}
}
