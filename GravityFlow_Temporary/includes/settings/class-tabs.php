<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Tabs extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'tabs';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {
		$settings_prefix = version_compare( \GFForms::$version, '2.5-dev-1', '<' ) ? 'gaddon' : 'gform';
		ob_start();
		printf( '<div id="tabs-%s">', $this['name'] );
		echo '<ul>';
		foreach ( $this['tabs'] as $i => $tab ) {
			$id = isset( $tab['id'] ) ? $tab['id'] : $tab['name'];
			printf( '<li id="%s-setting-tab-%s">', $settings_prefix, $id );
			printf( '<a href="#tabs-%d"><span style="display:inline-block;width:10px;margin-right:5px"><i class="fa fa-check-square-o gravityflow-tab-checked" style="display:none;"></i><i class="fa fa-square-o gravityflow-tab-unchecked"></i></span>%s</a>', $i, $tab['label'] );
			echo '</li>';
		}
		echo '</ul>';
		foreach ( $this['tabs'] as $i => $tab ) {
			printf( '<div id="tabs-%d">', $i );
			foreach ( $tab['fields'] as $field ) {
				$id      = isset( $field['id'] ) ? $field['id'] : $field['name'];
				$tooltip = '';
				if ( isset( $field['tooltip'] ) ) {
					$tooltip_class = isset( $field['tooltip_class'] ) ? $field['tooltip_class'] : '';
					$tooltip       = gform_tooltip( $field['tooltip'], $tooltip_class, true );
				}
				printf( '<div id="%s-setting-tab-field-%s" class="gform-settings-field gform-settings-field__%s gravityflow-tab-field"><div class="gravityflow-tab-field-label">%s %s</div>', $settings_prefix, $id, $field['type'], $field['label'], $tooltip );

				$field_object = \Gravity_Forms\Gravity_Forms\Settings\Fields::create(
					$field,
					gravity_flow()->get_settings_renderer()
				);
				if ( ! is_wp_error( $field_object ) ) {
					echo $field_object->markup();
				} else {
					$func = array( gravity_flow(), 'settings_' . $field['type'] );
				    if ( is_callable( $func ) ) {
					    call_user_func( $func, $field );
				    }
                }
				echo '</div>';
			}
			echo '</div>';
		}
		?>
		</div>
		<script>
			(function($) {
				$( "#tabs-<?php echo $this['name'] ?>" ).tabs();
			})(jQuery);
		</script>
		<?php

		$html = trim( ob_get_clean() );

		return $html;
	}

}

Fields::register( 'tabs', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Tabs' );
