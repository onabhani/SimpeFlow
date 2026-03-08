<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;
use GFFormsModel;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class WP_Dropdown_Pages extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'wp_dropdown_pages';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {
        $settings_prefix = version_compare( \GFForms::$version, '2.5-dev-1', '<' ) ? 'gaddon' : 'gform';

        $args = array(
            'selected'         => gravity_flow()->get_setting( $this['name'] ),
            'echo'             => false,
            'name'             => "_{$settings_prefix}_setting_" . esc_attr( $this['name'] ),
            'class'            => "{$settings_prefix}-setting gaddon-select",
            'show_option_none' => esc_html__( 'Select page', 'gravityflow' ),
        );

        $html = wp_dropdown_pages( $args );

        return $html;
    }
}

Fields::register( 'wp_dropdown_pages', '\Gravity_Flow\Gravity_Flow\Settings\Fields\WP_Dropdown_Pages' );
