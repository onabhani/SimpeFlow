<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;
use GFAPI;
use GFFormsModel;
use GFCommon;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Expiration extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'expiration';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {

        $form = GFAPI::get_form( $_GET['id'] );

        $checkbox_label = esc_html__( 'Schedule expiration', 'gravityflow' );

        $expiration = array(
            'name'    => 'expiration',
            'type'    => 'checkbox',
            'choices' => array(
                array(
                    'label' => $checkbox_label,
                    'name'  => 'expiration',
                ),
            ),
        );

        $expiration_type = array(
            'name'          => 'expiration_type',
            'type'          => 'radio',
            'horizontal'    => true,
            'default_value' => 'delay',
            'choices'       => array(
                array(
                    'label' => esc_html__( 'Delay', 'gravityflow' ),
                    'value' => 'delay',
                ),
                array(
                    'label' => esc_html__( 'Date', 'gravityflow' ),
                    'value' => 'date',
                ),
            ),
        );

        $date_fields = GFFormsModel::get_fields_by_type( $form, 'date' );

        $date_field_choices = array();

        if ( ! empty( $date_fields ) ) {
            $expiration_type['choices'][] = array(
                'label' => esc_html__( 'Date Field', 'gravityflow' ),
                'value' => 'date_field',
            );


            foreach ( $date_fields  as $date_field ) {
                $date_field_choices[] = array( 'value' => $date_field->id, 'label' => GFFormsModel::get_label( $date_field ) );
            }
        }

        $expiration_date_fields = array(
            'name'    => 'expiration_date_field',
            'label'   => esc_html__( 'Expiration Date Field', 'gravityflow' ),
            'choices' => $date_field_choices,
        );

        $expiration_date = array(
            'id'          => 'expiration_date',
            'name'        => 'expiration_date',
            'placeholder' => 'yyyy-mm-dd',
            'class'       => 'datepicker gform-datepicker datepicker_with_icon ymd_dash',
            'label'       => esc_html__( 'Expiration', 'gravityflow' ),
            'type'        => 'text',
        );

        $delay_offset_field = array(
            'name'  => 'expiration_delay_offset',
            'class' => 'small-text',
            'style' => 'width:auto',
            'label' => esc_html__( 'Expiration', 'gravityflow' ),
            'type'  => 'text',
        );

        $unit_field = array(
            'name'          => 'expiration_delay_unit',
            'label'         => esc_html__( 'Expiration', 'gravityflow' ),
            'default_value' => 'hours',
            'style'         => 'width:auto',
            'choices'       => array(
                array(
                    'label' => esc_html__( 'Minute(s)', 'gravityflow' ),
                    'value' => 'minutes',
                ),
                array(
                    'label' => esc_html__( 'Hour(s)', 'gravityflow' ),
                    'value' => 'hours',
                ),
                array(
                    'label' => esc_html__( 'Day(s)', 'gravityflow' ),
                    'value' => 'days',
                ),
                array(
                    'label' => esc_html__( 'Week(s)', 'gravityflow' ),
                    'value' => 'weeks',
                ),
            ),
        );

        $html = gravity_flow()->settings_checkbox( $expiration, false );

        $enabled                      = gravity_flow()->get_setting( 'expiration', false );
        $expiration_type_setting      = gravity_flow()->get_setting( 'expiration_type', 'delay' );
        $expiration_style             = $enabled ? '' : 'style="display:none;"';
        $expiration_date_style        = ( $expiration_type_setting == 'date' ) ? '' : 'style="display:none;"';
        $expiration_delay_style       = ( $expiration_type_setting == 'delay' ) ? '' : 'style="display:none;"';
        $expiration_date_fields_style = ( $expiration_type_setting == 'date_field' ) ? '' : 'style="display:none;"';

        ob_start();
        ?>
        <div class="gravityflow-expiration-settings" <?php echo $expiration_style ?> >
            <div class="gravityflow-expiration-type-container" class="gravityflow-sub-setting">
                <?php gravity_flow()->settings_radio( $expiration_type ); ?>
            </div>
            <div class="gravityflow-expiration-date-container" <?php echo $expiration_date_style ?> >
                <?php
                esc_html_e( 'This step expires on', 'gravityflow' );
                echo '&nbsp;';
                gravity_flow()->settings_text( $expiration_date );
                ?>
                <input type="hidden" id="gforms_calendar_icon_expiration_date" class="gform_hidden" value="<?php echo GFCommon::get_base_url() . '/images/calendar.png'; ?>" />
            </div>
            <div class="gravityflow-expiration-delay-container" <?php echo $expiration_delay_style ?> class="gravityflow-sub-setting">
                <?php
                esc_html_e( 'This step will expire', 'gravityflow' );
                echo '&nbsp;';
                gravity_flow()->settings_text( $delay_offset_field );
                gravity_flow()->settings_select( $unit_field );
                echo '&nbsp;';
                esc_html_e( 'after the workflow step has started.' );
                ?>
            </div>
            <div class="gravityflow-expiration-date-field-container" <?php echo $expiration_date_fields_style ?>>
                <?php
                esc_html_e( 'Expire this step', 'gravityflow' );
                echo '&nbsp;';
                $delay_offset_field['name']          = 'expiration_date_field_offset';
                $delay_offset_field['default_value'] = '0';
                gravity_flow()->settings_text( $delay_offset_field );
                $unit_field['name'] = 'expiration_date_field_offset_unit';
                gravity_flow()->settings_select( $unit_field );
                echo '&nbsp;';
                $before_after_field = array(
                    'name'          => 'expiration_date_field_before_after',
                    'label'         => esc_html__( 'Expiration', 'gravityflow' ),
                    'default_value' => 'after',
                    'choices'       => array(
                        array(
                            'label' => esc_html__( 'after', 'gravityflow' ),
                            'value' => 'after',
                        ),
                        array(
                            'label' => esc_html__( 'before', 'gravityflow' ),
                            'value' => 'before',
                        ),
                    ),
                );
                gravity_flow()->settings_select( $before_after_field );

                gravity_flow()->settings_select( $expiration_date_fields );
                ?>
            </div>
            <div class="gravityflow-sub-setting">
                <?php
                $status_choices = rgar( $this, 'status_choices' );
                if ( is_array( $status_choices ) && ! empty( $status_choices ) ) {
                    esc_html_e( 'Status after expiration', 'gravityflow' );
                    echo ': ';
                    $status_choices_field = array(
                        'name'    => 'status_expiration',
                        'label'   => esc_html__( 'Expiration Status', 'gravityflow' ),
                        'type'    => 'select',
                        'choices' => $status_choices,
                    );
                    gravity_flow()->settings_select( $status_choices_field );
                }
                ?>
            </div>
            <div id="expiration_sub_setting_destination_expired" class="gravityflow-sub-setting">
                <?php
                esc_html_e( 'Next Step if Expired', 'gravityflow' );
                echo ': ';
                $next_step_field = array(
                    'name'          => 'destination_expired',
                    'label'         => esc_html__( 'Next Step if Expired', 'gravityflow' ),
                    'type'          => 'step_selector',
                    'default_value' => 'next',
                );
                gravity_flow()->settings_step_selector( $next_step_field );
                ?>
            </div>
        </div>
        <script>
            (function($) {
                $( '#expiration' ).click(function(){
                    $('.gravityflow-expiration-settings').slideToggle();
                });
                $( '#expiration_type0' ).click(function(){
                    $('.gravityflow-expiration-date-container').hide();
                    $('.gravityflow-expiration-delay-container').show();
                    $('.gravityflow-expiration-date-field-container').hide();
                });
                $( '#expiration_type1' ).click(function(){
                    $('.gravityflow-expiration-date-container').show();
                    $('.gravityflow-expiration-delay-container').hide();
                    $('.gravityflow-expiration-date-field-container').hide();
                });
                $( '#expiration_type2' ).click(function(){
                    $('.gravityflow-expiration-delay-container').hide();
                    $('.gravityflow-expiration-date-container').hide();
                    $('.gravityflow-expiration-date-field-container').show();
                });
            })(jQuery);
        </script>
        <?php

        $html .= trim( ob_get_clean() );

        return $html;
    }
}

Fields::register( 'expiration', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Expiration' );
