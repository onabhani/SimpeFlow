<?php

namespace Gravity_Flow\Gravity_Flow\Ajax;

use Gravity_Flow\Gravity_Flow\Models\Model;
use \WP_REST_Controller;

abstract class Endpoint extends WP_REST_Controller {

	const REST_NAMESPACE = 'gravityflow/internal';

	protected $response_factory;

	protected $config;

	protected $model;

	protected $data          = array();
	protected $required_args = array();
	protected $optional_args = array();

	public function __construct( Config $config, Response_Factory $factory, Model $model ) {
		$this->response_factory = $factory;
		$this->config           = $config;
		$this->model            = $model;
	}

	public function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/' . $this->config->name(), array(
			array(
				'methods'             => $this->config->method(),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $this->get_args(),
				'show_in_index'       => true,
			),
		) );
	}

	public function get_rest_param_string() {
		return '';
	}

	public function get_base_route() {
		return sprintf( '/%1$s/%2$s/%3$s', rest_get_url_prefix(), self::REST_NAMESPACE, $this->config->name() );
	}

	public function get_name() {
		return $this->config->name();
	}

	public function permission_callback( $request ) {
		return true;
	}

	public function get_args() {
		$args = array();

		if ( empty( $this->config->args() ) ) {
			return $args;
		}

		foreach ( $this->config->args() as $arg ) {
			/**
			 * @var Argument $arg .
			 */
			$args[ $arg->name() ] = $arg->to_array();
		}

		return $args;
	}

	abstract public function handle( $request );

}
