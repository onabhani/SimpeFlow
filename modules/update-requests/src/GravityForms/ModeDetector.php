<?php
namespace SFA\UpdateRequests\GravityForms;

/**
 * Mode Detector
 *
 * Detects update request mode via hidden fields and URL parameters
 * Populates hidden fields: _ur_mode, _ur_parent_id, _ur_type
 */
class ModeDetector {

	public function __construct() {
		// Populate hidden fields on form display
		add_filter( 'gform_field_value__ur_mode', [ $this, 'populate_ur_mode' ] );
		add_filter( 'gform_field_value__ur_parent_id', [ $this, 'populate_ur_parent_id' ] );
		add_filter( 'gform_field_value__ur_type', [ $this, 'populate_ur_type' ] );
	}

	/**
	 * Populate _ur_mode field
	 * Value: 'update_request' if in update mode, empty otherwise
	 */
	public function populate_ur_mode( $value ) {
		return $this->is_update_request_mode() ? 'update_request' : '';
	}

	/**
	 * Populate _ur_parent_id field
	 * Value: Parent entry ID from URL parameter
	 */
	public function populate_ur_parent_id( $value ) {
		if ( $this->is_update_request_mode() ) {
			return $this->get_parent_id_from_url();
		}
		return '';
	}

	/**
	 * Populate _ur_type field
	 * Value: 'entry_updating' or 'following_invoice' from URL
	 */
	public function populate_ur_type( $value ) {
		if ( $this->is_update_request_mode() ) {
			return $this->get_request_type_from_url();
		}
		return '';
	}

	/**
	 * Check if form is in update request mode
	 *
	 * @return bool
	 */
	private function is_update_request_mode() {
		return isset( $_GET['update_request'] ) && $_GET['update_request'] === '1';
	}

	/**
	 * Get parent entry ID from URL
	 *
	 * @return int
	 */
	private function get_parent_id_from_url() {
		return isset( $_GET['parent_id'] ) ? absint( $_GET['parent_id'] ) : 0;
	}

	/**
	 * Get request type from URL
	 *
	 * @return string
	 */
	private function get_request_type_from_url() {
		$type = isset( $_GET['request_type'] ) ? sanitize_text_field( $_GET['request_type'] ) : 'entry_updating';

		// Only allow valid types
		$valid_types = [ 'entry_updating', 'following_invoice' ];
		return in_array( $type, $valid_types, true ) ? $type : 'entry_updating';
	}
}
