<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;
use GFAPI;
use GFFormsModel;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Editable_Fields_Setting extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'editable_fields';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {
        $form    = GFAPI::get_form( $_GET['id'] );
        $choices = array();
        if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $form_field ) {
                if ( $form_field->displayOnly ) {
                    continue;
                }
                $choices[] = array( 'label' => GFFormsModel::get_label( $form_field ), 'value' => $form_field->id );
            }
        }
        $this['choices'] = $choices;

        $html = gravity_flow()->settings_select( $this, false );

        return $html;
	}

}

Fields::register( 'editable_fields', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Editable_Fields_Setting' );
