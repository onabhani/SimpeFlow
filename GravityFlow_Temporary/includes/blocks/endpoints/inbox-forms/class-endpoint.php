<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Forms;

use Gravity_Flow\Gravity_Flow\Ajax\Endpoint as Ajax_Endpoint;
use \GFFormsModel;
use \GFAPI;

/**
 * Inbox_Forms endpoint.
 *
 * @since 2.8
 */
class Endpoint extends Ajax_Endpoint {

	/**
	 * Permission callback.
	 *
	 * @since 2.8
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function permission_callback( $request ) {
		return GFAPI::current_user_can_any( array( 'gravityflow_status_view_all' ) );
	}

	/**
	 * Handle the request.
	 *
	 * @since 2.8
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_HTTP_Response
	 */
	public function handle( $request ) {
		$form_details = array();

		$form_ids = gravity_flow()->get_workflow_form_ids();
		$forms    = GFFormsModel::get_form_meta_by_id( $form_ids );

		$published_form_ids = gravity_flow()->get_published_form_ids();

		foreach ( $forms as $form ) {
			$field_data = array();

			foreach ( $form['fields'] as $field ) {
				/** @var GF_Field $field */
				$field_data[ $field->id ] = array(
					'id'     => $field->id,
					'label'  => $field->label,
					'inputs' => $field->get_entry_inputs(),
				);
			}

			$form_details[ $form['id'] ] = array(
				'id'          => $form['id'],
				'title'       => $form['title'],
				'fields'      => $field_data,
				'isPublished' => in_array( $form['id'], $published_form_ids ),
			);
		}

		// Sort by the form title.
		uasort( $form_details, array( $this, 'sort_by_form_title' ) );

		return $this->response_factory->create( $form_details, 200 );
	}

	/**
	 * Sort two arrays by the key of "title".
	 *
	 * @since 2.8
	 *
	 * @param array $a The first array.
	 * @param array $b The second array.
	 *
	 * @return int
	 */
	private function sort_by_form_title( $a, $b ) {
		return strcmp( $a['title'], $b['title'] );
	}

}