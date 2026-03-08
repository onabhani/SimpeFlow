<?php
/**
 * Gravity Flow Step Feed Brevo
 *
 * @package     GravityFlow
 * @subpackage  Classes/Gravity_Flow_Step_Feed_Brevo
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.9.13
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Class Gravity_Flow_Step_Feed_Brevo
 */
class Gravity_Flow_Step_Feed_Brevo extends Gravity_Flow_Step_Feed_Add_On {

	/**
	 *  The step type.
	 *
	 * @var string
	 */
	public $_step_type = 'brevo';

	/**
	 * The name of the class used by the add-on.
	 *
	 * @var string
	 */
	protected $_class_name = 'GF_Brevo';

	/**
	 * Returns the step label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Brevo';
	}

	/**
	 * Returns the feed name.
	 *
	 * @param array $feed The Brevo feed properties.
	 *
	 * @return string
	 */
	public function get_feed_label( $feed ) {
		$label = $feed['meta']['feedName'];

		return $label;
	}

	/**
	 * Returns the URL for the step icon.
	 *
	 * @returns string
	 */
	public function get_icon_url() {

		return $this->get_base_url() . '/images/brevo-icon.svg';
	}

}

Gravity_Flow_Steps::register( new Gravity_Flow_Step_Feed_Brevo() );