<?php

namespace Gravity_Flow\Gravity_Flow\Rest_API\V2;

use Gravity_Flow\Gravity_Flow\Rest_API\V2\Controllers\Gravity_Flow_REST_Controller;
use Gravity_Flow\Gravity_Flow\Rest_API\V2\Controllers\Steps_Controller;
use Gravity_flow\Gravity_Flow\Rest_API\V2\Controllers\Workflows_Controller;
use Gravity_Forms\Gravity_Forms\GF_Service_Provider;
use Gravity_Forms\Gravity_Forms\GF_Service_Container;

/**
 * REST API V2 Service Provider
 *
 * Gathers and provides all the services for the REST API V2.
 *
 * @since  3.0.0
 */
class REST_API_Service_Provider extends GF_Service_Provider {

	const STEPS_END_POINT           = 'steps';
	const WORKFLOWS_END_POINT       = 'workflows';

	/**
	 * The endpoints this provider provides.
	 *
	 * @since  3.0.0
	 *
	 * @var string[]
	 */
	protected $endpoints = array(
		self::STEPS_END_POINT,
		self::WORKFLOWS_END_POINT,
	);

	/**
	 * Register the services.
	 *
	 * @since  3.0.0
	 *
	 * @param GF_Service_Container $container
	 *
	 * @return void
	 */
	public function register( GF_Service_Container $container ) {
		require_once plugin_dir_path( __FILE__ ) . 'controllers/class-gravityflow-rest-controller.php';
		require_once plugin_dir_path( __FILE__ ) . 'controllers/class-controller-steps.php';
		require_once plugin_dir_path( __FILE__ ) . 'controllers/class-controller-workflows.php';

		$container->add(
			self::STEPS_END_POINT,
			function () use ( $container ) {
				return new Steps_Controller();
			}
		);

		$container->add(
			self::WORKFLOWS_END_POINT,
			function () use ( $container ) {
				return new Workflows_Controller();
			}
		);
	}

	/**
	 * Initialize hooks and filters.
	 *
	 * @since  3.0.0
	 *
	 * @param GF_Service_Container $container
	 *
	 * @return void
	 */
	public function init( GF_Service_Container $container ) {
		$endpoints = $this->endpoints;

		add_action(
			'rest_api_init',
			function () use ( $container, $endpoints ) {
				foreach ( $endpoints as $ep_name ) {
					/**
					 * @var Gravity_Flow_REST_Controller $endpoint
					 */
					$endpoint = $container->get( $ep_name );

					$endpoint->register_routes();
				}
			}
		);
	}
}
