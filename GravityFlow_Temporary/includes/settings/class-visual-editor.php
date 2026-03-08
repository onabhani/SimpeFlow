<?php

namespace Gravity_Flow\Gravity_Flow\Settings\Fields;

use Gravity_Forms\Gravity_Forms\Settings\Fields;

defined( 'ABSPATH' ) || die();

// Load base class.
require_once \GFCommon::get_base_path() . '/includes/settings/class-fields.php';

class Visual_Editor extends \Gravity_Forms\Gravity_Forms\Settings\Fields\Base {

	/**
	 * Field type.
	 *
	 * @since 2.9
	 *
	 * @var string
	 */
	public $type = 'visual_editor';

	/**
	 * Render field.
	 *
	 * @since 2.9
	 *
	 * @return string
	 */
	public function markup() {
		$value = $this->get_value();
		$settings_prefix = version_compare( \GFForms::$version, '2.5-dev-1', '<' ) ? 'gaddon' : 'gform';
		$id            = "_{$settings_prefix}_setting_" . $this['name'];

		ob_start();

		echo "<span class='mt-{$id}'></span>";
		wp_editor( $value, $id, array(
			'autop'        => false,
			'editor_class' => 'merge-tag-support mt-wp_editor mt-manual_position mt-position-right',
		) );

		$html = trim( ob_get_clean() );

		return $html;
	}

}

Fields::register( 'visual_editor', '\Gravity_Flow\Gravity_Flow\Settings\Fields\Visual_Editor' );
