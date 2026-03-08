<?php
/**
 * Gravity Flow Step Feed Salesforce
 *
 * @package     GravityFlow
 * @subpackage  Classes/Gravity_Flow_Step_Feed_Salesforce
 * @copyright   Copyright (c) 2016-2024 Rocketgenius
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.9.7
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Class Gravity_Flow_Step_Feed_Salesforce
 */
class Gravity_Flow_Step_Feed_Salesforce extends Gravity_Flow_Step_Feed_Add_On {

	use Response_Mapping;

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public $_step_type = 'salesforce';

	/**
	 * The name of the class used by the add-on.
	 *
	 * @var string
	 */
	protected $_class_name = 'GF_Salesforce';

	/**
	 * Returns the step label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Salesforce';
	}


	/**
	 * Returns the URL for the step icon.
	 *
	 * @return string
	 */
	public function get_icon_url() {
		if ( gravity_flow()->is_gravityforms_supported( '2.7.16.1' ) ) {
			return '<i class="gform-icon gform-icon--salesforce"></i>';
		}

		return $this->get_base_url() . '/images/salesforce-icon.svg';
	}

	/**
	 * Processes the feed.
	 *
	 * @sicne 2.9.9
	 *
	 * @param array $feed The feed currently being processed.
	 * @return true
	 */
	public function process_feed( $feed ) {
		add_action( 'gform_salesforce_post_request', array( $this, 'post_request_mapping' ), 10, 6 );
		parent::process_feed( $feed );
		remove_action( 'gform_salesforce_post_request', array( $this, 'post_request_mapping' ) );

		return true;
	}

	/**
	 * Returns the settings for this step.
	 *
	 * @since 2.9.10
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = parent::get_settings();

		$settings['fields'][] = array(
			'name'          => 'response_header',
			'label'         => esc_html__( 'Response Header Mapping', 'gravityflow' ),
			'type'          => 'radio',
			'default_value' => '',
			'horizontal'    => true,
			'tooltip'       => '<h6>' . esc_html__( 'Response Header Mapping', 'gravityflow' ) . '</h6>' . esc_html__( 'Whether to save webhook response headers to the Gravity Forms entry.', 'gravityflow' ),
			'choices'       => array(
				array(
					'label' => __( 'None', 'gravityflow' ),
					'value' => '',
				),
				array(
					'label' => __( 'Select Fields', 'gravityflow' ),
					'value' => 'select_fields',
				),
			),
		);

		$settings['fields'][] = array(
			'name'        => 'response_header_mappings',
			'label'       => esc_html__( 'Response Header Field Values', 'gravityflow' ),
			'type'        => 'generic_map',
			'key_field'   => array(
				'title' => esc_html__( 'Key', 'gravityflow' ),
			),
			'value_field' => array(
				'title'        => esc_html__( 'Field', 'gravityflow' ),
				'choices'      => $this->value_mappings(),
				'allow_custom' => false,
			),
			'tooltip'     => '<h6>' . esc_html__( 'Response Header Field Values', 'gravityflow' ) . '</h6>' . esc_html__( 'Map webhook response headers to form fields. Mapped response header values will be saved in the entry.', 'gravityflow' ),
			'dependency'  => array(
				'live'   => true,
				'fields' => array(
					array(
						'field'  => 'response_header',
						'values' => array( 'select_fields' ),
					),
				),
			),
		);

		$settings['fields'][] = array(
			'name'          => 'response_body',
			'label'         => esc_html__( 'Response Body Mapping', 'gravityflow' ),
			'type'          => 'radio',
			'default_value' => '',
			'horizontal'    => true,
			'tooltip'       => '<h6>' . esc_html__( 'Response Body Mapping', 'gravityflow' ) . '</h6>' . esc_html__( 'Whether to save webhook response body items to the Gravity Forms entry.', 'gravityflow' ),
			'choices'       => array(
				array(
					'label' => __( 'None', 'gravityflow' ),
					'value' => '',
				),
				array(
					'label' => __( 'Select Fields', 'gravityflow' ),
					'value' => 'select_fields',
				),
			),
		);

		$settings['fields'][] = array(
			'name'        => 'response_mappings',
			'label'       => esc_html__( 'Response Body Field Values', 'gravityflow' ),
			'type'        => 'generic_map',
			'key_field'   => array(
				'title' => esc_html__( 'Key', 'gravityflow' ),
			),
			'value_field' => array(
				'title'        => esc_html__( 'Field', 'gravityflow' ),
				'choices'      => $this->value_mappings(),
				'allow_custom' => false,
			),
			'tooltip'     => '<h6>' . esc_html__( 'Response Body Field Values', 'gravityflow' ) . '</h6>' . esc_html__( 'Map webhook response body items to form fields. Mapped response body items will be saved in the entry.', 'gravityflow' ),
			'dependency'  => array(
				'live'   => true,
				'fields' => array(
					array(
						'field'  => 'response_body',
						'values' => array( 'select_fields' ),
					),
				),
			),
		);

		return $settings;
	}

	/**
	 * Returns an array of statuses and their properties.
	 *
	 * @since 2.9.10
	 *
	 * @return array
	 */
	public function get_status_config() {
		return array(
			array(
				'status'                    => 'complete',
				'status_label'              => __( 'Success', 'gravityflow' ),
				'destination_setting_label' => esc_html__( 'Next Step if Success', 'gravityflow' ),
				'default_destination'       => 'next',
			),
			array(
				'status'                    => 'error_client',
				'status_label'              => __( 'Error - Client', 'gravityflow' ),
				'destination_setting_label' => esc_html__( 'Next Step if Client Error', 'gravityflow' ),
				'default_destination'       => 'complete',
			),
			array(
				'status'                    => 'error_server',
				'status_label'              => __( 'Error - Server', 'gravityflow' ),
				'destination_setting_label' => esc_html__( 'Next Step if Server Error', 'gravityflow' ),
				'default_destination'       => 'complete',
			),
			array(
				'status'                    => 'error',
				'status_label'              => __( 'Error - Other', 'gravityflow' ),
				'destination_setting_label' => esc_html__( 'Next step if Other Error', 'gravityflow' ),
				'default_destination'       => 'complete',
			),
		);
	}

	/**
	 * Determines the current status of the step.
	 *
	 * @since 2.9.10
	 *
	 * @return string
	 */
	public function status_evaluation() {
		$step_status = $this->get_status();

		return $step_status;
	}

	/**
	 * Determines if the current step has been completed.
	 *
	 * @since 2.9.10
	 *
	 * @return bool
	 */
	public function is_complete() {
		$status = $this->evaluate_status();

		return ! in_array( $status, array( 'pending', 'queued' ) );
	}


	/**
	 * Retrieves the salesforce step and processes the response mapping.
	 *
	 * @since 2.9.10
	 *
	 * @param WP_Error|array $response     The response array returned by wp_remote_request().
	 * @param array          $request_url  The request URL.
	 * @param array          $request_args The request arguments.
	 * @param array          $entry        The entry currently being processed.
	 * @param array          $form         The form currently being processed.
	 * @param array          $feed         The feed currently being processed.
	 *
	 * @return array The updated entry.
	 */
	public function post_request_mapping( $response, $request_url, $request_args, $entry, $form, $feed ) {

		if ( is_wp_error( $response ) ) {
			$step_status = 'error';
		} else {
			$http_response_code = wp_remote_retrieve_response_code( $response );
			switch ( true ) {
				case in_array( $http_response_code, range( 200, 299 ) ):
					$step_status = 'complete';
					break;
				case in_array( $http_response_code, range( 400, 499 ) ):
					$step_status = 'error_client';
					break;
				case in_array( $http_response_code, range( 500, 599 ) ):
					$step_status = 'error_server';
					break;
				default:
					$step_status = 'error';
			}
		}
		$this->update_step_status( $step_status );
		$entry = $this->refresh_entry();
		return $this->process_response_mapping( $response, $entry );
	}
}

Gravity_Flow_Steps::register( new Gravity_Flow_Step_Feed_Salesforce() );
