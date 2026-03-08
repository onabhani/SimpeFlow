<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class User_Routing extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'user_routing';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {
        $name = $this['name'];

        $settings_prefix = version_compare( \GFForms::$version, '2.5-dev-1', '<' ) ? 'gaddon' : 'gform';

        $id = ! empty( $this['id'] ) ?  $this['id'] : 'gform_user_routing_setting_' . $name;

		$html = '<div class="gravityflow-user-routing-description">' . rgar( $this, 'description' ) . '</div>';
        $html .= '<div class="gravityflow-user-routing" id="' . $id . '" data-field_name="_' . $settings_prefix . '_setting_' . $name . 'user_routing" data-field_id="' . $name . '" ></div>';
        $html .= gravity_flow()->settings_hidden( $this, false );

        return $html;
	}

}

Fields::register( 'user_routing', '\Gravity_Flow\Gravity_Flow\Settings\Fields\User_Routing' );
