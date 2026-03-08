<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Save_Preference;

use Gravity_Flow\Gravity_Flow\Ajax\Endpoint as Ajax_Endpoint;
use Gravity_Flow\Gravity_Flow\Inbox\Models\Preferences;

/**
 * Save_Preferences endpoint.
 *
 * @since 2.8
 */
class Endpoint extends Ajax_Endpoint {

	/**
	 * Permission callback.
	 *
	 * @since 2.8
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function permission_callback( $request ) {
		return is_user_logged_in();
	}

	/**
	 * Handle the request.
	 *
	 * @since 2.8
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_HTTP_Response
	 */
	public function handle( $request ) {
		$key   = $request->get_param( Config::KEY );
		$value = $request->get_param( Config::VALUE );
		$type  = $request->get_param( Config::TYPE );
		$id    = $request->get_param( Config::USER_ID );
		$view  = $request->get_param( Config::VIEW );

		$save = $this->model->save_setting( $key, $id, $value, $type, $view );
		$data = array( 'success' => true );

		return $this->response_factory->create( $data, 200 );
	}

}
