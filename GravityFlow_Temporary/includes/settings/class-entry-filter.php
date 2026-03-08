<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;
use GFFormsModel;
use GFCommon;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Entry_Filter extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'entry_filter';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {
        if ( ! empty( $this['filter_settings'] ) ) {
            $filter_settings = $this['filter_settings'];
        } else {
            $form            = ! empty( $this['form_id'] ) ? GFFormsModel::get_form_meta( $this['form_id'] ) : gravity_flow()->get_current_form();
            $filter_settings = GFCommon::get_field_filter_settings( $form );
        }

        $filter_settings_json = json_encode( $filter_settings );

        $value = gravity_flow()->get_setting( $this['name'] );
        if ( ! $value ) {
            $value = array(
                'mode' => 'all',
                'filters' => array(
                    array(
                        'field'    => 0,
                        'operator' => 'contains',
                        'value'    => '',
                    ),
                ),
            );

        }
        $value_json = json_encode( $value );
        $text = isset( $this['filter_text'] ) ? $this['filter_text'] : esc_html__( 'Match {0} of the following criteria:', 'gravityflow' );
        $text_json = json_encode( $text );
        $name = $this['name'];
        $html = "
            <div id='setting-entry-filter-{$name}' class='setting-entry-filter'>
            <!--placeholder-->
            </div>
            <script>
            gf_vars.filterAndAny = {$text_json};
            jQuery('#setting-entry-filter-{$name}').gfFilterUI({$filter_settings_json}, {$value_json});

            (function($){
            $(document).ready(function () {
                function setFilterValue(){
                    var filterSetting = $(this).closest('.setting-entry-filter').parent(),
                        filterRows = filterSetting.find('.gform-field-filter'),
                        filters = [];
                    filterRows.each( function( i )  {
                        var f = $(this).find('.gform-filter-field').val(),
                            o = $(this).find('.gform-filter-operator').val(),
                            v = $(this).find('.gform-filter-value').val();
                        filters.push({field : f, operator: o, value: v });
                    });
                    var input = filterSetting.find('input[type=hidden]'),
                        mode = filterSetting.find('select[name=mode]').val(),
                        val = {
                            mode : mode,
                            filters : filters
                        };
                    input.val(JSON.stringify(val));
                };
                $('#setting-entry-filter-{$name}').on('change', 'select[name=mode]', setFilterValue);
                $('#setting-entry-filter-{$name}').on('change', '.gform-filter-operator', setFilterValue);
                $('#setting-entry-filter-{$name}').on('change blur', '.gform-filter-value', setFilterValue);
                $('#setting-entry-filter-{$name}').on('DOMSubtreeModified', setFilterValue);
            });
            })(jQuery);
            </script>";
        $hidden_field = array( 'name' => $this['name'], 'type'=> 'hidden' );
        $html .= gravity_flow()->settings_hidden( $hidden_field, false );

        if ( rgar( $this, 'show_sorting_options' ) ) {
            $html               .= '<br />' . esc_html__( 'Sort by field' ) . '&nbsp;';
            $sort_field_choices = array();
            foreach ( $filter_settings as $filter_setting ) {
                if ( $filter_setting['key'] === '0' ) {
                    continue;
                }

                $filter_key = $filter_setting['key'] === 'entry_id' ? 'id' : $filter_setting['key'];

                $sort_field_choices[] = array(
                    'value' => $filter_key,
                    'label' => $filter_setting['text'],
                );
            }

            $sort_field = array(
                'name'          => $this['name'] . 'sort_key',
                'default_value' => 'entry_id',
                'choices'       => $sort_field_choices,
            );

            $html            .= gravity_flow()->settings_select( $sort_field, false );
            $html            .= '&nbsp;';
            $direction_field = array(
                'name'          => $this['name'] . 'sort_direction',
                'default_value' => 'DESC',
                'choices'       => array(
                    array(
                        'value' => 'ASC',
                        'label' => 'ASC',
                    ),
                    array(
                        'value' => 'DESC',
                        'label' => 'DESC',
                    ),
                ),
            );

            $html .= gravity_flow()->settings_select( $direction_field, false );
        }

        return $html;
	}

}

Fields::register( 'entry_filter', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Entry_Filter' );
