<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Get_Preference;

use Gravity_Flow\Gravity_Flow\Ajax\Endpoint as Ajax_Endpoint;
use Gravity_Flow\Gravity_Flow\Inbox\Models\Preferences;

/**
 * Get_Preference endpoint.
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
		$key     = $request->get_param( Config::KEY );
		$type    = $request->get_param( Config::TYPE );
		$id      = $request->get_param( Config::USER_ID );
		$view    = $request->get_param( Config::VIEW );
		$default = $request->get_param( Config::DEFAULT_VALUE );

		$id_or_view = $type === 'user' ? $id : $view;
		$data       = $type === Config::DEFAULT_TYPE ? $this->model->get_setting( $key, $id, $view, $default ) : $this->model->get_setting_from( $type, $key, $id_or_view, $default );

		return $this->response_factory->create( $data, 200 );
	}

}
