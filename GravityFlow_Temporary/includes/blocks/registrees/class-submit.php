<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Registrees;

use Gravity_Flow\Gravity_Flow\Blocks\Block_Registree;
use \GFAPI;

/**
 * Submit Block Registree
 *
 * @since 2.8
 */
class Submit extends Block_Registree {

	protected $type = 'gravityflow/submit';

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
		$saved_form_ids_json = get_post_meta( get_the_ID(), '_gravityflow_submit_forms_json', true );
		$saved_form_ids      = json_decode( $saved_form_ids_json, true );
		$form_ids            = is_array( $saved_form_ids ) ? wp_list_pluck( $saved_form_ids, 'value' ) : array();

		ob_start();
		gravity_flow()->submit_page( false, $form_ids );
		$html = ob_get_clean();

		return $html;
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

		register_meta( 'post', '_gravityflow_submit_forms_json', array(
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'string',
			'auth_callback' => function () {
				return GFAPI::current_user_can_any( 'gravityflow_status_view_all' );
			},
		) );
	}
}