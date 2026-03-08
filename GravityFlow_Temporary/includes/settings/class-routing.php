<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Routing extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'routing';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {
        $settings_prefix = version_compare( \GFForms::$version, '2.5-dev-1', '<' ) ? 'gaddon' : 'gform';
		$html 			 = '<div class="gravityflow-user-routing-description">' . rgar( $this, 'description' ) . '</div>';
        $html            .= '<div id="gform_routing_setting" class="gravityflow-routing" data-field_name="_' . $settings_prefix . '_setting_routing" data-field_id="routing" ></div>';
        $html 			 .= gravity_flow()->settings_hidden( $this, false );

        return $html;
	}

}

Fields::register( 'routing', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Routing' );
