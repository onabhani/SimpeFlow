<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;
use GFAPI;
use GFFormsModel;
use GFCommon;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Schedule extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'schedule';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {

        $form = GFAPI::get_form( $_GET['id'] );

        $checkbox_label = isset( $this['checkbox_label'] ) ? $this['checkbox_label'] : esc_html__( 'Schedule this step', 'gravityflow' );

        $scheduled = array(
            'name'    => 'scheduled',
            'type'    => 'checkbox',
            'choices' => array(
                array(
                    'label' => $checkbox_label,
                    'name'  => 'scheduled',
                ),
            ),
        );

        $schedule_type = array(
            'name'          => 'schedule_type',
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
            $schedule_type['choices'][] = array(
                'label' => esc_html__( 'Date Field', 'gravityflow' ),
                'value' => 'date_field',
            );


            foreach ( $date_fields  as $date_field ) {
                $date_field_choices[] = array( 'value' => $date_field->id, 'label' => GFFormsModel::get_label( $date_field ) );
            }
        }

        $schedule_date_fields = array(
            'name'    => 'schedule_date_field',
            'label'   => esc_html__( 'Schedule Date Field', 'gravityflow' ),
            'style'   => 'width:auto',
            'choices' => $date_field_choices,
        );

        $schedule_date = array(
            'id'          => 'schedule_date',
            'name'        => 'schedule_date',
            'placeholder' => 'yyyy-mm-dd',
            'class'       => 'datepicker gform-datepicker datepicker_with_icon ymd_dash',
            'label'       => esc_html__( 'Schedule', 'gravityflow' ),
            'type'        => 'text',
        );

        $delay_offset_field = array(
            'name'  => 'schedule_delay_offset',
            'class' => 'small-text',
            'style' => 'width:auto',
            'label' => esc_html__( 'Schedule', 'gravityflow' ),
            'type'  => 'text',
        );

        $unit_field = array(
            'name'          => 'schedule_delay_unit',
            'label'         => esc_html__( 'Schedule', 'gravityflow' ),
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

        $html = gravity_flow()->settings_checkbox( $scheduled, false );

        $enabled                    = gravity_flow()->get_setting( 'scheduled', false );
        $schedule_type_setting      = gravity_flow()->get_setting( 'schedule_type', 'delay' );
        $schedule_style             = $enabled ? '' : 'style="display:none;"';
        $schedule_date_style        = ( $schedule_type_setting == 'date' ) ? '' : 'style="display:none;"';
        $schedule_delay_style       = ( $schedule_type_setting == 'delay' ) ? '' : 'style="display:none;"';
        $schedule_date_fields_style = ( $schedule_type_setting == 'date_field' ) ? '' : 'style="display:none;"';
        ob_start();
        ?>
        <div class="gravityflow-schedule-settings" <?php echo $schedule_style ?> >
            <div class="gravityflow-schedule-type-container">
                <?php gravity_flow()->settings_radio( $schedule_type ); ?>
            </div>
            <div class="gravityflow-schedule-date-container" <?php echo $schedule_date_style ?> >
                <?php
                $delay_label = isset( $this['date_label'] ) ?  $this['date_label'] : esc_html__( 'Start this step on %s', 'gravityflow' );
                printf( $delay_label, gravity_flow()->settings_text( $schedule_date, false ) );
                ?>
                <input type="hidden" id="gforms_calendar_icon_schedule_date" class="gform_hidden" value="<?php echo GFCommon::get_base_url() . '/images/calendar.png'; ?>" />
            </div>
            <div class="gravityflow-schedule-delay-container" <?php echo $schedule_delay_style ?>>
                <?php
                /* translators: 1. textbox input for the number of days/weeks etc. 2. select input with options for minutes/hours/days/weeks  */
                $delay_label = isset( $this['delay_label'] ) ?  $this['delay_label'] : esc_html__( 'Start this step %1$s %2$s after the workflow step is triggered.', 'gravityflow' );
                printf( $delay_label, gravity_flow()->settings_text( $delay_offset_field, false ), gravity_flow()->settings_select( $unit_field, false ) );
                ?>
            </div>
            <div class="gravityflow-schedule-date-field-container" <?php echo $schedule_date_fields_style ?>>
                <?php
                /* translators: 1. textbox input for the number of days/weeks etc. 2. select input with options for minutes/hours/days/weeks 3. select input before/after 4. select input with list of date fields */
                $date_field_label = isset( $this['date_field_label'] ) ?  $this['delay_label'] : esc_html__( 'Start this step %1$s %2$s %3$s %4$s', 'gravityflow' );

                $delay_offset_field['name']          = 'schedule_date_field_offset';
                $delay_offset_field['default_value'] = '0';

                $unit_field['name'] = 'schedule_date_field_offset_unit';
                echo '&nbsp;';
                $before_after_field = array(
                    'name'          => 'schedule_date_field_before_after',
                    'label'         => esc_html__( 'Schedule', 'gravityflow' ),
                    'default_value' => 'after',
                    'style'         => 'width:auto',
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

                printf( $date_field_label, gravity_flow()->settings_text( $delay_offset_field, false ), gravity_flow()->settings_select( $unit_field, false ), gravity_flow()->settings_select( $before_after_field, false ), gravity_flow()->settings_select( $schedule_date_fields, false ) );

                ?>
            </div>
        </div>
        <script>
            (function($) {
                $( '#scheduled' ).click(function(){
                    $('.gravityflow-schedule-settings').slideToggle();
                });
                $( '#schedule_type0' ).click(function(){
                    $('.gravityflow-schedule-delay-container').show();
                    $('.gravityflow-schedule-date-container').hide();
                    $('.gravityflow-schedule-date-field-container').hide();
                });
                $( '#schedule_type1' ).click(function(){
                    $('.gravityflow-schedule-delay-container').hide();
                    $('.gravityflow-schedule-date-container').show();
                    $('.gravityflow-schedule-date-field-container').hide();
                });
                $( '#schedule_type2' ).click(function(){
                    $('.gravityflow-schedule-delay-container').hide();
                    $('.gravityflow-schedule-date-container').hide();
                    $('.gravityflow-schedule-date-field-container').show();
                });
            })(jQuery);
        </script>
        <?php

        $html .= trim( ob_get_clean() );

        return $html;
    }
}

Fields::register( 'schedule', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Schedule' );
