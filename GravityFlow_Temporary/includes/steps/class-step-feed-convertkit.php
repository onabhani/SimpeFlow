<?php
/**
 * Gravity Flow Step Feed Kit
 *
 * @package     GravityFlow
 * @subpackage  Classes/Gravity_Flow_Step_Feed_ConvertKit
 * @copyright   Copyright (c) 2016-2018, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.3.3-dev
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Class Gravity_Flow_Step_Feed_ConvertKit
 */
class Gravity_Flow_Step_Feed_ConvertKit extends Gravity_Flow_Step_Feed_Add_On {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public $_step_type = 'convertkit';

	/**
	 * The name of the class used by the add-on.
	 *
	 * @var string
	 */
	protected $_class_name = 'GFConvertKit';

	/**
	 * The slug used by the add-on.
	 *
	 * @var string
	 */
	protected $_slug = 'ckgf';

	/**
	 * Returns the step label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Kit';
	}

	/**
	 * Returns the feed name.
	 *
	 * @param array $feed The Kit feed properties.
	 *
	 * @return string
	 */
	public function get_feed_label( $feed ) {
		$label = $feed['meta']['feed_name'];

		return $label;
	}

	/**
	 * Returns the markup or URL for the step icon.
	 *
	 * @since 1.4
	 * @since 2.9.4 Updated to use the SVG icon or the icon font included with Gravity Forms, when available.
	 *
	 * @return string
	 */
	public function get_icon_url() {
		if ( gravity_flow()->is_gravityforms_supported( '2.7.8.1' ) ) {
			return '<i class="gform-icon gform-icon--convertkit"></i>';
		}

		return $this->get_base_url() . '/images/convertkit.svg';
	}

	/**
	 * Returns the class name for the add-on.
	 *
	 * @since 2.9.4
	 *
	 * @return string
	 */
	public function get_feed_add_on_class_name() {
		if ( class_exists( 'GF_ConvertKit' ) ) {
			$this->_class_name = 'GF_ConvertKit';
		}

		return parent::get_feed_add_on_class_name();
	}

	/**
	 * Returns the slug for the add-on associated with this step.
	 *
	 * @since 2.9.4
	 *
	 * @return string
	 */
	public function get_slug() {
		if ( class_exists( 'GF_ConvertKit' ) ) {
			$this->_slug = 'gravityformsconvertkit';
		}

		return parent::get_slug();
	}

	/**
	 * Updates the selected Kit feed IDs in the step meta when the Gravity Forms Kit add-on migrates the feeds from the third-party add-on.
	 *
	 * @since 2.9.4
	 *
	 * @param array $migration_map An array using the third-party feed IDs as the keys to the new feed IDs.
	 */
	public static function migrate( $migration_map ) {
		if ( empty( $migration_map ) ) {
			return;
		}

		$steps = gravity_flow()->get_steps();

		foreach ( $steps as $step ) {
			if ( $step->get_type() !== 'convertkit' ) {
				continue;
			}

			$to_migrate = array();
			$step_meta  = $step->get_feed_meta();

			foreach ( $migration_map as $old_id => $new_id ) {
				if ( isset( $step_meta[ 'feed_' . $old_id ] ) ) {
					$to_migrate[ $old_id ] = $step_meta[ 'feed_' . $old_id ] == '1' ? $new_id : false;
					unset( $step_meta[ 'feed_' . $old_id ] );
				}
			}

			if ( ! empty( $to_migrate ) ) {
				foreach ( $to_migrate as $new_id ) {
					if ( $new_id !== false ) {
						$step_meta[ 'feed_' . $new_id ] = '1';
					}
				}
				gravity_flow()->update_feed_meta( $step->get_id(), $step_meta );
			}

		}
	}

}

Gravity_Flow_Steps::register( new Gravity_Flow_Step_Feed_ConvertKit() );

add_action( 'gform_convertkit_post_migrate_feeds', array( 'Gravity_Flow_Step_Feed_ConvertKit', 'migrate' ) );
