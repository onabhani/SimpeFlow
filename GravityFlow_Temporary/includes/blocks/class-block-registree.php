<?php

namespace Gravity_Flow\Gravity_Flow\Blocks;

/**
 * Block Registree Abstract
 *
 * @since 2.8
 */
abstract class Block_Registree {

	protected $type = '';

	/**
	 * Registers the block with Gutenberg.
	 *
	 * @since 2.8
	 */
	public function register() {
		// Only load if Gutenberg is available.
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type( $this->type, array(
			'render_callback' => array( $this, 'get_rendered_markup' ),
		) );
	}

	/**
	 * Get the rendered markup from the render() method, and prevent any shortcodes inside
	 * from being parsed.
	 *
	 * @since 2.8.4
	 *
	 * @param $attributes
	 * @param $content
	 *
	 * @return string
	 */
	public function get_rendered_markup( $attributes, $content ) {
		$markup = $this->render( $attributes, $content );

		// Any valid shortcodes have already been parsed. Any remaining shortcodes should not be parsed e.g. in field values.
		add_filter( 'pre_do_shortcode_tag', array( $this, 'filter_pre_do_shortcode_tag' ), 1, 4 );

		$markup = do_shortcode( $markup );

		remove_filter( 'pre_do_shortcode_tag', array( $this, 'filter_pre_do_shortcode_tag' ), 1 );

		return $markup;
	}

	/**
	 * Escapes all shortcodes in the markup that should remain untouched when WordPress parses all shortcodes in the content.
	 *
	 * @since 2.8.4
	 *
	 * @param $return
	 * @param $tag
	 * @param $attr
	 * @param $m
	 *
	 * @return string
	 */
	public function filter_pre_do_shortcode_tag( $return, $tag, $attr, $m ) {

		// Bail early if for some reason the regex array doesn't have the value we need.
		if ( ! isset( $m[0] ) ) {
			return $return;
		}

		// GFCommon::encode_shortcodes( $m[0] ) won't work here because do_shortcode() unescapes shortcodes using unescape_invalid_shortcodes()
		$return = '[' . $m[0]. ']';

		if ( current_theme_supports( 'block-templates' ) ) {
			// FSE themes need double escaping
			$return = '[' . $return . ']';
		}

		return $return;
	}

	/**
	 * Register the Fields.
	 *
	 * @since 2.8
	 *
	 * @return void
	 */
	abstract public function register_fields();

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
	abstract public function render( $attributes, $content );

}