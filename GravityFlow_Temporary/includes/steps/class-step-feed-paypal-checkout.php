<?php
/**
 * Gravity Flow Step Feed PayPal Checkout
 *
 * @package     GravityFlow
 * @subpackage  Classes/Gravity_Flow_Step_Feed_PayPal_Checkout
 * @copyright   Copyright (c) 2016-2024 Rocketgenius
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.9.8
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}


/**
 * Class Gravity_Flow_Step_Feed_PayPal_Checkout
 */
class Gravity_Flow_Step_Feed_PayPal_Checkout extends Gravity_Flow_Step {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public $_step_type = 'paypal_checkout_capture';

	/**
	 * The name of the class used by the add-on.
	 *
	 * @since 2.9.8
	 *
	 * @var string
	 */
	protected $_class_name = 'GF_PPCP';

	/**
	 * The feed transaction type(s) the step supports.
	 *
	 * @since 2.9.8
	 *
	 * @var array
	 */
	protected $_transaction_types = array( 'product' );

	/**
	 * Returns the step label.
	 *
	 * @since 2.9.8
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Capture Payment', 'gravityflow' );
	}

	/**
	 * Returns the URL for the step icon.
	 *
	 * @since 2.9.8
	 *
	 * @return string
	 */
	public function get_icon_url() {
		if ( gravity_flow()->is_gravityforms_supported( '2.7.16.1' ) ) {
			return '<i class="gform-icon gform-icon--paypal"></i>';
		}

		return $this->get_base_url() . '/images/paypal.svg';
	}

	/**
	 * Checks if the step is combinable with the installed version of PayPal Checkout.
	 *
	 * @since 2.9.8
	 *
	 * @return bool
	 */
	public function is_supported() {
		$ppcp = $this->get_add_on_instance();
		if ( ! $ppcp ) {
			return false;
		}
		return parent::is_supported() && version_compare( $ppcp->get_version(), '3.5.0', '>=' );
	}

	/**
	 * Returns the current instance of the PayPal Checkout add-on.
	 *
	 * @return GF_PPCP|null
	 */
	public function get_add_on_instance() {
		if ( ! class_exists( $this->_class_name ) ) {
			return null;
		}
		return call_user_func( array( $this->_class_name, 'get_instance' ) );
	}

	/**
	 * Captures the authorized payment.
	 *
	 * @since 2.9.8
	 *
	 * @return string The step status.
	 */
	public function process() {
		/** @var GF_PPCP $ppcp */
		$ppcp = $this->get_add_on_instance();
		if ( ! $ppcp ) {
			$this->log_debug( __METHOD__ . '(): Aborting; PayPal Checkout add-on not found.' );
			$this->update_step_status( 'failed' );

			return true;
		}

		$entry = $this->get_entry();
		if ( ! in_array( rgar( $entry, 'payment_status' ), array( 'Authorized', 'Pending' ) ) ) {
			$this->log_debug( __METHOD__ . '(): Payment is not in authorization status, skipping capture.' );
			$this->update_step_status( 'failed' );

			return true;
		}
		if ( ! $ppcp->is_payment_gateway( rgar( $entry, 'id' ) ) ) {
			$this->log_debug( __METHOD__ . '(): Aborting; not payment gateway.' );
			$this->update_step_status( 'failed' );

			return true;
		}

		$feed = $ppcp->get_payment_feed( $entry );
		if ( ! $this->is_valid_feed( $feed ) ) {
			$this->log_debug( __METHOD__ . '(): Aborting; feed is not valid.' );
			$this->update_step_status( 'failed' );

			return true;
		}

		$form = GFAPI::get_form( $entry['form_id'] );

		// Remove authorization only filter so the payment is captured.
		remove_filter( 'gform_ppcp_authorization_only', 'filter_ppcp_authorization_only' );
		$capture = $ppcp->capture_authorized( $entry );
		if ( is_wp_error( $capture ) ) {
			$status = 'failed';
		} else {
			$status = 'captured';
		}

		$authorization = array(
			'captured_payment' => array(
				'is_success'     => $status === 'captured',
				'is_delayed'     => true,
				'amount'         => rgar( $entry, 'payment_amount' ),
				'transaction_id' => rgar( $entry, 'transaction_id' ),
			),
			'is_authorized'    => true,
			'amount'           => rgar( $entry, 'payment_amount' ),
		);

		$ppcp->process_capture( $authorization, $feed, array(), $form, $entry );

		$this->update_step_status( $status );
		$this->refresh_entry();
		parent::process();

		return true;
	}

	/**
	 * Determines if the entry has an active product and service type PayPal Checkout feed associated with it.
	 *
	 * @since 2.9.8
	 *
	 * @param array|false $feed The feed to be checked.
	 *
	 * @return bool
	 */
	public function is_valid_feed( $feed ) {
		if ( ! $feed ) {
			$this->log_debug( __METHOD__ . '(): Aborting; no payment feed.' );

			return false;
		}

		if ( ! rgar( $feed, 'is_active' ) ) {
			$this->log_debug( __METHOD__ . '(): Aborting; feed not active.' );

			return false;
		}

		if ( ! in_array( rgars( $feed, 'meta/transactionType' ), $this->_transaction_types ) ) {
			$this->log_debug( __METHOD__ . '(): Aborting; wrong transaction type.' );

			return false;
		}

		return true;
	}

	/**
	 * Prevent the feeds assigned to the current step from being processed by the associated add-on.
	 *
	 * @since 2.9.8
	 */
	public function intercept_submission() {
		if ( ! class_exists( 'GF_PPCP' ) ) {
			return;
		}

		add_filter( 'gform_ppcp_authorization_only', '__return_true' );
	}

	/**
	 * Adds an alert to the step settings area.
	 *
	 * @since 2.9.8
	 *
	 * @return string
	 */
	public function get_settings_alert() {
		return sprintf( '<div class="delete-alert alert_yellow"><i class="fa fa-exclamation-triangle gf_invalid"></i> %s</div>', esc_html__( 'PayPal automatically cancels (expires) authorized Payments which are not captured within 29 days.', 'gravityflow' ) );
	}

	/**
	 * Override the step settings to add the alert description.
	 *
	 * @since 2.9.8
	 *
	 * @return string[]
	 */
	public function get_settings() {
		return array(
			'description' => $this->get_settings_alert(),
		);
	}

	/**
	 * Returns an array of the configuration of the status options for this step.
	 *
	 * @since 2.9.8
	 *
	 * @return array[]
	 */
	public function get_status_config() {
		return array(
			array(
				'status'                    => 'captured',
				'status_label'              => __( 'Captured', 'gravityflow' ),
				'destination_setting_label' => __( 'Next Step if Captured', 'gravityflow' ),
				'default_destination'       => 'next',
			),
			array(
				'status'                    => 'failed',
				'status_label'              => __( 'Failed', 'gravityflow' ),
				'destination_setting_label' => __( 'Next step if Failed', 'gravityflow' ),
				'default_destination'       => 'complete',
			),
		);
	}

}

Gravity_Flow_Steps::register( new Gravity_Flow_Step_Feed_PayPal_Checkout() );

/**
 * Prevents the PayPal Checkout Add-On capturing payment during the initial form submission if there is an active step.
 *
 * @since  2.9.8
 *
 * @param bool  $authorization_only The feed configurations' meta value that indicates if it captures or only authorized the payment.
 * @param array $form               The current form.
 *
 * @return bool
 */
function filter_ppcp_authorization_only( $authorization_only, $feed ) {
	$form_id = absint( rgar( $feed, 'form_id' ) );
	if ( empty( $form_id ) ) {
		return $authorization_only;
	}

	static $has_active_step = array();
	if ( isset( $has_active_step[ $form_id ] ) ) {
		return $has_active_step[ $form_id ] ? true : $authorization_only;
	}

	$steps = gravity_flow()->get_steps( $form_id );
	if ( empty( $steps ) || ! gravity_flow()->has_active_step( 'paypal_checkout_capture', $steps ) ) {
		$has_active_step[ $form_id ] = false;

		return $authorization_only;
	}

	$has_active_step[ $form_id ] = true;
	gravity_flow()->log_debug( __METHOD__ . sprintf( '(): form (#%d) has a PayPal Checkout step; preventing automatic capture.', $form_id ) );

	return true;
}
// Prevent PayPal capture during initial form submission if there is an active step.
add_filter( 'gform_ppcp_authorization_only', 'filter_ppcp_authorization_only', 10, 2 );
