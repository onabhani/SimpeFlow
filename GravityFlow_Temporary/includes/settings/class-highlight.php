<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Highlight extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'highlight';

	/**
	 * Child inputs.
	 *
	 * @since 2.5
	 *
	 * @var Base[]
	 */
	public $inputs = array();
	public $settings_fields = array();

	private $field;

	/**
	 * Initialize Field Select field.
	 *
	 * @since 2.9
	 *
	 * @param array                                $props    Field properties.
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Settings $settings Settings instance.
	 */
	public function __construct( $props, $settings ) {

		parent::__construct( $props, $settings );

		$this->settings_fields  = array();

		$step_highlight             = array(
			'name'    => 'step_highlight',
			'type'    => 'checkbox',
			'choices' => array(
				array(
					'label' => esc_html__( 'Highlight this step', 'gravityflow' ),
					'name'  => 'step_highlight',
				),
			),
		);
		$this->settings_fields['step_highlight'] = $step_highlight;

		$step_highlight_type             = array(
			'name'          => 'step_highlight_type',
			'type'          => 'hidden',
			'default_value' => 'color',
			'required'      => true,
		);
		$this->settings_fields['step_highlight_type'] = $step_highlight_type;

		$step_highlight_color             = array(
			'name'          => 'step_highlight_color',
			'id'            => 'step_highlight_color',
			'class'         => 'small-text',
			'label'         => esc_html__( 'Color', 'gravityflow' ),
			'type'          => 'text',
			'default_value' => '#dd3333',
		);
		$this->settings_fields['step_highlight_color'] = $step_highlight_color;
		$this->field['settings']                      = $settings;			

		
	}

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {

		$flow = gravity_flow();

        $html = $flow->settings_checkbox( $this->settings_fields['step_highlight'], false );

        $enabled                     = $flow->get_setting( 'step_highlight', false );
        $step_highlight_style        = $enabled ? '' : 'style="display:none;"';
        $step_highlight_type_setting = $flow->get_setting( 'step_highlight_type', 'color' );
        $step_highlight_color_style  = ( $step_highlight_type_setting == 'color' ) ? '' : 'style="display:none;"';

        ob_start();
        ?>
        <div class="gravityflow-step-highlight-settings" <?php echo $step_highlight_style; ?> >
            <div class="gravityflow-step-highlight-type-container">
                <?php $flow->settings_hidden( $this->settings_fields['step_highlight_type'] ); ?>
            </div>
            <div class="gravityflow-step-highlight-color-container" <?php echo $step_highlight_color_style; ?> >
                <?php
                $flow->settings_text( $this->settings_fields['step_highlight_color'] );
                ?>
            </div>
        </div>
        <script>
            (function($) {
                $( '#step_highlight' ).click(function(){
                    $('.gravityflow-step-highlight-settings').toggle();
                });
                $(document).ready(function () {
                    $("#step_highlight_color").wpColorPicker();
                });
            })(jQuery);
        </script>
        <?php

        $html .= trim( ob_get_clean() );

        return $html;

	}

}

Fields::register( 'highlight', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Highlight' );
