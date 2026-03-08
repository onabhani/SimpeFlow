<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Entries;

use Gravity_Flow\Gravity_Flow\Ajax\Endpoint as Ajax_Endpoint;
use \Gravity_Flow_Inbox;
use \GFAPI;
use \Gravity_Flow_API;

/**
 * Inbox_Entries endpoint.
 *
 * @since 2.8
 */
class Endpoint extends Ajax_Endpoint {

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
		$form_ids = $request->get_param( Config::FORM_IDS );

		if ( is_array( $form_ids ) && count( $form_ids ) == 1 ) {
			$form_ids = $form_ids[0];
		}

		$args = array(
			'id_column'      => $request->get_param( config::ID_COLUMN ),
			'actions_column' => $request->get_param( config::ACTIONS_COLUMN ),
			'last_updated'   => $request->get_param( config::LAST_UPDATED ),
			'due_date'       => $request->get_param( config::DUE_DATE ),
			'form_id'        => $form_ids,
			'field_ids'      => GFAPI::current_user_can_any( 'gravityflow_status_view_all' ) ? $request->get_param( config::FIELDS ) : '',
		);

		$args = gravity_flow()->booleanize_shortcode_attributes( $args );
		$args = wp_parse_args( $args, Gravity_Flow_Inbox::get_defaults() );

		$entries     = Gravity_Flow_API::get_inbox_entries( $args, $total_count );
		$form_titles = array();
		$form_ids    = wp_list_pluck( $entries, 'form_id' );
		$forms       = \GFFormsModel::get_forms();

		foreach ( $forms as $form ) {
			if ( isset( $form_ids[ $form->id ] ) ) {
				$form_titles[ $form->id ] = $form->title;
			}
		}

		$columns            = Gravity_Flow_Inbox::get_columns( $args );
		$columns['form_id'] = __( 'Form ID', 'gravityforms' );
		$rows               = array();

		foreach ( $entries as $entry ) {
			$row  = array();
			$form = GFAPI::get_form( $entry['form_id'] );

			foreach ( $columns as $id => $label ) {
				$row[ $id ] = Gravity_Flow_Inbox::get_column_value( $id, $form, $entry, $columns );
			}

			$rows[] = $row;
		}

		// JavaScript doesn't guarantee the order of object keys so deliver as numeric array
		$columns_numeric_array = array();

		foreach ( $columns as $key => $value ) {
			$columns_numeric_array[] = array(
				'key'   => $key,
				'title' => $value,
			);
		}

		$data = array(
			'total_count' => $total_count,
			'rows'        => $rows,
			'columns'     => $columns_numeric_array,
			'form_titles' => $form_titles,
		);

		return $this->response_factory->create( $data, 200 );
	}

}