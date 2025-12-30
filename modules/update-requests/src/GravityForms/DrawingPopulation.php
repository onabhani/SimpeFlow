<?php
namespace SFA\UpdateRequests\GravityForms;

/**
 * Drawing Population
 *
 * Populates drawing selection checkbox from parent entry field 45
 * Only applies when in update_request mode
 */
class DrawingPopulation {

	public function __construct() {
		// Dynamically populate drawing checkbox field choices
		add_filter( 'gform_pre_render', [ $this, 'populate_drawing_choices' ] );
		add_filter( 'gform_pre_validation', [ $this, 'populate_drawing_choices' ] );
		add_filter( 'gform_pre_submission_filter', [ $this, 'populate_drawing_choices' ] );
		add_filter( 'gform_admin_pre_render', [ $this, 'populate_drawing_choices' ] );
	}

	/**
	 * Populate drawing checkbox field with choices from parent field 45
	 *
	 * @param array $form
	 * @return array
	 */
	public function populate_drawing_choices( $form ) {
		// Check if in update request mode
		if ( ! $this->is_update_request_mode() ) {
			return $form;
		}

		$parent_id = $this->get_parent_id_from_url();
		if ( ! $parent_id ) {
			return $form;
		}

		// Get parent entry
		$parent_entry = \GFAPI::get_entry( $parent_id );
		if ( is_wp_error( $parent_entry ) || ! $parent_entry ) {
			return $form;
		}

		// Get field 45 value from parent entry
		$parent_drawings = isset( $parent_entry[45] ) ? $parent_entry[45] : '';
		if ( empty( $parent_drawings ) ) {
			return $form;
		}

		// Parse drawings (assuming comma-separated or JSON array)
		$drawings_array = $this->parse_drawings( $parent_drawings );
		if ( empty( $drawings_array ) ) {
			return $form;
		}

		// Find the drawing selection checkbox field (by admin label)
		foreach ( $form['fields'] as &$field ) {
			// Look for checkbox field with admin label '_ur_drawing_selection'
			if ( $field->type === 'checkbox' && $field->adminLabel === '_ur_drawing_selection' ) {
				// Populate choices from parent drawings
				$choices = [];
				foreach ( $drawings_array as $index => $drawing ) {
					$choices[] = [
						'text'  => esc_html( $drawing ),
						'value' => esc_attr( $drawing ),
					];
				}

				$field->choices = $choices;

				error_log( sprintf(
					'Update Requests: Populated %d drawing choices from parent entry %d',
					count( $choices ),
					$parent_id
				) );

				break;
			}
		}

		return $form;
	}

	/**
	 * Parse drawings string into array
	 *
	 * @param string $drawings
	 * @return array
	 */
	private function parse_drawings( $drawings ) {
		// Try JSON decode first
		$decoded = json_decode( $drawings, true );
		if ( is_array( $decoded ) ) {
			return array_filter( $decoded ); // Remove empty values
		}

		// Try comma-separated
		if ( strpos( $drawings, ',' ) !== false ) {
			$array = explode( ',', $drawings );
			return array_filter( array_map( 'trim', $array ) );
		}

		// Try newline-separated
		if ( strpos( $drawings, "\n" ) !== false ) {
			$array = explode( "\n", $drawings );
			return array_filter( array_map( 'trim', $array ) );
		}

		// Single value
		return [ trim( $drawings ) ];
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
}
