<?php
namespace Gravity_Flow\Gravity_Flow\Rest_API\V2\Controllers;

use WP_REST_Request;

defined( 'ABSPATH' ) || die();

/**
 * Abstract base class for Gravity Flow REST API v2 controllers.
 *
 * Provides common properties and methods for v2 controllers.
 *
 * @since 3.0.0
 */
abstract class Gravity_Flow_REST_Controller extends \WP_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @since 3.0.0
	 *
	 * @var string
	 */
	protected $namespace = 'gf/v2';

	/**
	 * Indicates if the capability validation request has been logged.
	 *
	 * Without this the other registered methods for the route will also be logged when rest_send_allow_header() in WP rest-api.php runs.
	 *
	 * @since 3.0.0
	 *
	 * @var bool
	 */
	protected $_validate_caps_logged = false;

	/**
	 * Validates that the current user has the specified capability.
	 *
	 * @since 3.0.0
	 *
	 * @param string|array    $capability The required capability.
	 * @param WP_REST_Request $request    Full data about the request.
	 *
	 * @return bool
	 */
	public function current_user_can_any( $capability, $request ) {
		$result = \GFAPI::current_user_can_any( $capability );

		if ( ! $this->_validate_caps_logged ) {
			$this->log_debug( sprintf( '%s(): method: %s; route: %s; capability: %s; result: %s.', __METHOD__, $request->get_method(), $request->get_route(), json_encode( $capability ), json_encode( $result ) ) );
			$this->_validate_caps_logged = true;
		}

		return $result;
	}

	/**
	 * Writes a message to the log.
	 *
	 * @since 3.0.0
	 *
	 * @param string $message
	 */
	public function log_debug( $message ) {
		\GFAPI::log_debug( $message );
	}
}
