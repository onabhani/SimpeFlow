<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Registrees;

use Gravity_Flow\Gravity_Flow\Blocks\Block_Registree;

/**
 * Reports Registree
 *
 * @since 2.8
 */
class Reports extends Block_Registree {

	protected $type = 'gravityflow/reports';

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
		if ( is_admin() ) {
			return;
		}

		if ( isset( $attributes['selectedFormJson'] ) ) {
			$form = json_decode( $attributes['selectedFormJson'], true );
			if ( rgar( $form, 'value' ) ) {
				$attributes['form'] = $form['value'];
			}
		}

		if ( ! rgar( $attributes, 'displayFilter' ) ) {
			$attributes['display_filter'] = false;
		}

		$shortcode_atts = array();
		foreach ( $attributes as $key => $value ) {
			// Convert camel to snake.
			$snake_key                    = strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $key ) );
			$shortcode_atts[ $snake_key ] = $value;
		}

		$shortcode_atts = shortcode_atts( gravity_flow()->get_shortcode_defaults(), $shortcode_atts );


		return gravity_flow()->get_shortcode_reports_page( $shortcode_atts );
	}

	/**
	 * Register the Fields.
	 *
	 * @since 2.8
	 *
	 * @return void
	 */
	public function register_fields() {
		return;
	}
}