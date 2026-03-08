<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;
use GFAPI;
use GFFormsModel;
use GFCommon;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

abstract class Schedule_Custom extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {


	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {

        $form = GFAPI::get_form( $_GET['id'] );

        $schedule_custom = array(
            'name'    => 'due_date',
            'type'    => 'checkbox',
            'choices' => array(
                array(
                    'label' => esc_html__( 'Schedule due date', 'gravityflow' ),
                    'name'  => 'due_date',
                ),
            ),
        );

        $due_date_type = array(
            'name'          => 'due_date_type',
            'type'          => 'radio',
            'horizontal'    => true,
            'default_value' => 'delay',
            'required'      => true,
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
            $due_date_type['choices'][] = array(
                'label' => esc_html__( 'Date Field', 'gravityflow' ),
                'value' => 'date_field',
            );

            foreach ( $date_fields  as $date_field ) {
                $date_field_choices[] = array( 'value' => $date_field->id, 'label' => GFFormsModel::get_label( $date_field ) );
            }
        }

        $due_date_date_fields = array(
            'name'     => 'due_date_date_field',
            'label'    => esc_html__( 'Due Date Field', 'gravityflow' ),
            'choices'  => $date_field_choices,
        );

        $due_date_date = array(
            'id'          => 'due_date_date',
            'name'        => 'due_date_date',
            'placeholder' => 'yyyy-mm-dd',
            'class'       => 'datepicker datepicker_with_icon ymd_dash',
            'label'       => esc_html__( 'Due Date', 'gravityflow' ),
            'type'        => 'text',
            'required'    => true,

        );

        $delay_offset_field = array(
            'name'      => 'due_date_delay_offset',
            'style'     => 'width:auto',
            'required'  => true,
            'label'     => esc_html__( 'Due Date', 'gravityflow' ),
            'type'      => 'text',
        );

        $unit_field = array(
            'name'          => 'due_date_delay_unit',
            'label'         => esc_html__( 'Due Date', 'gravityflow' ),
            'default_value' => 'hours',
            'style'         => 'width:auto',
            'required'      => true,
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

        $due_date_highlight_type = array(
            'name'           => 'due_date_highlight_type',
            'type'           => 'hidden',
            'default_value'  => 'color',
            'required'       => true,
        );

        $due_date_highlight_color = array(
            'name'          => 'due_date_highlight_color',
            'id'            => 'due_date_highlight_color',
            'class'         => 'small-text',
            'label'         => esc_html__( 'Color', 'gravityflow' ),
            'type'          => 'text',
            'default_value' => '#dd3333',
            'required'      => true,
        );

        $html = gravity_flow()->settings_checkbox( $due_date, false );

        $enabled                    = gravity_flow()->get_setting( 'due_date', false );
        $due_date_type_setting      = gravity_flow()->get_setting( 'due_date_type', 'delay' );
        $due_date_style             = $enabled ? '' : 'style="display:none;"';
        $due_date_date_style        = ( $due_date_type_setting == 'date' ) ? '' : 'style="display:none;"';
        $due_date_delay_style       = ( $due_date_type_setting == 'delay' ) ? '' : 'style="display:none;"';
        $due_date_date_fields_style = ( $due_date_type_setting == 'date_field' ) ? '' : 'style="display:none;"';

        ob_start();
        ?>
        <div class="gravityflow-due-date-settings" <?php echo $due_date_style; ?> >
            <div class="gravityflow-due-date-type-container" class="gravityflow-sub-setting">
                <?php gravity_flow()->settings_radio( $due_date_type ); ?>
            </div>
            <div class="gravityflow-due-date-date-container" <?php echo $due_date_date_style; ?> >
                <?php
                esc_html_e( 'This step has a due date on', 'gravityflow' );
                echo '&nbsp;';
                gravity_flow()->settings_text( $due_date_date );
                ?>
                <input type="hidden" id="gforms_calendar_icon_expiration_date" class="gform_hidden" value="<?php echo GFCommon::get_base_url() . '/images/calendar.png'; ?>" />
            </div>
            <div class="gravityflow-due-date-delay-container" <?php echo $due_date_delay_style; ?> class="gravityflow-sub-setting">
                <?php
                esc_html_e( 'This step has a due date on', 'gravityflow' );
                echo '&nbsp;';
                gravity_flow()->settings_text( $delay_offset_field );
                gravity_flow()->settings_select( $unit_field );
                echo '&nbsp;';
                esc_html_e( 'after the workflow step has started.' );
                ?>
            </div>
            <div class="gravityflow-due-date-date-field-container" <?php echo $due_date_date_fields_style; ?>>
                <?php
                esc_html_e( 'Due date for this step', 'gravityflow' );
                echo '&nbsp;';
                $delay_offset_field['name']          = 'due_date_date_field_offset';
                $delay_offset_field['default_value'] = '0';
                gravity_flow()->settings_text( $delay_offset_field );
                $unit_field['name'] = 'due_date_date_field_offset_unit';
                gravity_flow()->settings_select( $unit_field );
                echo '&nbsp;';
                $before_after_field = array(
                    'name'          => 'due_date_date_field_before_after',
                    'label'         => esc_html__( 'Due Date', 'gravityflow' ),
                    'style'         => 'width:auto',
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

                gravity_flow()->settings_select( $due_date_date_fields );

                ?>
            </div>
            <div class="gravityflow-due-date-highlight-field-container">
                <?php

                $due_date_highlight_type_setting = gravity_flow()->get_setting( 'due_date_highlight_type', 'color' );
                $due_date_highlight_color_style  = ( $due_date_highlight_type_setting == 'color' ) ? '' : 'style="display:none;"';
                ?>
                <div class="gravityflow-due-date-highlight-type-container">
                    <?php gravity_flow()->settings_hidden( $due_date_highlight_type ); ?>
                </div>
                <div class="gravityflow-due-date-highlight-color-container" <?php echo $due_date_highlight_color_style; ?> >
                    <?php
                        gravity_flow()->settings_text( $due_date_highlight_color );
                    ?>
                </div>
            </div>
        </div>
        <script>
            (function($) {
                $( '#due_date' ).click(function(){
                    $('.gravityflow-due-date-settings').slideToggle();
                });
                $( '#due_date_type0' ).click(function(){
                    $('.gravityflow-due-date-date-container').hide();
                    $('.gravityflow-due-date-delay-container').show();
                    $('.gravityflow-due-date-date-field-container').hide();
                });
                $( '#due_date_type1' ).click(function(){
                    $('.gravityflow-due-date-date-container').show();
                    $('.gravityflow-due-date-delay-container').hide();
                    $('.gravityflow-due-date-date-field-container').hide();
                });
                $( '#due_date_type2' ).click(function(){
                    $('.gravityflow-due-date-delay-container').hide();
                    $('.gravityflow-due-date-date-container').hide();
                    $('.gravityflow-due-date-date-field-container').show();
                });
                $(document).ready(function () {
                    $("#due_date_highlight_color").wpColorPicker();
                });
            })(jQuery);
        </script>
        <?php

        $html .= trim( ob_get_clean() );

        return $html;
    }
}

