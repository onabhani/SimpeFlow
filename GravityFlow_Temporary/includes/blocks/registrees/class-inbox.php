<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Registrees;

use Gravity_Flow\Gravity_Flow\Blocks\Block_Registree;
use \GFAPI;

/**
 * Inbox Registree
 *
 * @since 2.8
 */
class Inbox extends Block_Registree {

	protected $type = 'gravityflow/inbox';

	/**
	 * Render the block.
	 *
	 * @since 2.8
	 *
	 * @param $attributes
	 * @param $content
	 *
	 * @return string
	 */
	public function render( $attributes, $content ) {
		$saved_form_ids_json  = get_post_meta( get_the_ID(), '_gravityflow_inbox_forms_json', true );
		$saved_form_ids       = json_decode( $saved_form_ids_json, true );
		$form_ids             = is_array( $saved_form_ids ) ? wp_list_pluck( $saved_form_ids, 'value' ) : array();
		$saved_field_ids_json = get_post_meta( get_the_ID(), '_gravityflow_inbox_fields_json', true );
		$saved_field_ids      = json_decode( $saved_field_ids_json, true );
		$fields               = is_array( $saved_field_ids ) ? wp_list_pluck( $saved_field_ids, 'value' ) : array();

		$attributes['fields'] = join( ',', $fields );
		$attributes['form']   = join( ',', $form_ids );

		$shortcode_atts = array();
		foreach ( $attributes as $key => $value ) {
			// Convert camel to snake
			$snake_key                    = strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $key ) );
			$shortcode_atts[ $snake_key ] = $value;
		}

		$shortcode_atts['is_block'] = true;

		$shortcode_atts = shortcode_atts( gravity_flow()->get_shortcode_defaults(), $shortcode_atts );

		$markup = gravity_flow()->get_shortcode_inbox_page( $shortcode_atts );

		return $markup;
	}

	/**
	 * Register the Fields.
	 *
	 * @since 2.8
	 *
	 * @return void
	 */
	public function register_fields() {
		if ( ! GFAPI::current_user_can_any( 'gravityflow_status_view_all' ) ) {
			return;
		}

		register_meta( 'post', '_gravityflow_inbox_fields_json', array(
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'string',
			'auth_callback' => function () {
				return GFAPI::current_user_can_any( 'gravityflow_status_view_all' );
			},
		) );
		register_meta( 'post', '_gravityflow_inbox_forms_json', array(
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'string',
			'auth_callback' => function () {
				return GFAPI::current_user_can_any( 'gravityflow_status_view_all' );
			},
		) );
	}
}