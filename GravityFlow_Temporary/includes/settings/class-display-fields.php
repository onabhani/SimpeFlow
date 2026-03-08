<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;
use GFAPI;
use GFFormsModel;
use GFCommon;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Display_Fields extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'display_fields';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {
        $mode_field = array(
            'name'          => 'display_fields_mode',
            'label'         => '',
            'type'          => 'select',
            'default_value' => 'all_fields',
            'onchange'      => 'jQuery(this).parent().parent().find(".gravityflow_display_fields_selected_container").toggle(this.value != "all_fields");',
            'choices'       => array(
                array(
                    'label' => __( 'Display all fields', 'gravityflow' ),
                    'value' => 'all_fields',
                ),
                array(
                    'label' => __( 'Display all fields except selected', 'gravityflow' ),
                    'value' => 'all_fields_except',
                ),
                array(
                    'label' => __( 'Hide all fields except selected', 'gravityflow' ),
                    'value' => 'selected_fields',
                ),
            ),
        );

        $form = GFAPI::get_form( $_GET['id'] );

        $fields = ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) ? $form['fields'] : array();

        $fields_as_choices = array();

        $has_product_field = false;

        foreach ( $fields as $field ) {
            /* @var GF_Field $field */
            if ( in_array( $field->type, array( 'page', 'section', 'captcha' ) ) ) {
                continue;
            }
            $fields_as_choices[] = array(
                'label' => $field->get_field_label( false, null ),
                'value' => $field->id,
            );
            $has_product_field   = GFCommon::is_product_field( $field->type ) ? true : $has_product_field;
        }

        /**
         * Allow the display fields to be filtered
         *
         * @param array       $fields_as_choices The Gravity Forms fields to be shown in the Display Fields settings
         * @param array       $form              The current Gravity Forms object
         * @param array|false $feed              The current feed being processed. If $feed is false, use the $_POST data.
         *
         * @since 2.0.1
         */
        $feed              = GFAPI::get_form( $_GET['fid'] );
        $fields_as_choices = apply_filters( 'gravityflow_display_field_choices', $fields_as_choices, $form, $feed );

        $mode_value = gravity_flow()->get_setting( 'display_fields_mode', 'all_fields' );

        $multiselect_field = array(
            'name'     => 'display_fields_selected[]',
            'label'    => __( 'Except', 'gravityflow' ),
            'type'     => 'select',
            'multiple' => 'multiple',
            'class'    => 'gravityflow-multiselect-ui',
            'choices'  => $fields_as_choices,
        );

        $html  = gravity_flow()->settings_select( $mode_field, false );
        $style = $mode_value == 'all_fields' ? 'style="display:none;"' : '';
        $html  .= '<div class="gravityflow_display_fields_selected_container" ' . $style . '>';
        $html  .= gravity_flow()->settings_select( $multiselect_field, false );
        $html  .= '</div>';

        if ( $has_product_field ) {
            $display_summary_field = array(
                'name'    => 'display_order_summary',
                'type'    => 'checkbox',
                'choices' => array(
                    array(
                        'label'         => esc_html__( 'Order Summary', 'gravityflow' ),
                        'name'          => 'display_order_summary',
                        'default_value' => '1',
                    ),
                ),
            );

            $html .= '<div style="margin-top:5px;">';
            $html .= gravity_flow()->settings_checkbox( $display_summary_field, false );
            $html .= '</div>';
        }

        return $html;
	}

}

Fields::register( 'display_fields', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Display_Fields' );
