<?php

namespace Gravity_Flow\Gravity_Flow\Ajax;

abstract class Config {

	protected $name = '';

	protected $method = 'GET';

	public function __construct() {
		if ( empty( $this->name ) ) {
			throw new \LogicException( 'AJAX Endpoint Configs must have a unique $name property.' );
		}
	}

	public function name() {
		return $this->name;
	}

	public function method() {
		return array( $this->method );
	}

	/**
	 * @return Argument[]
	 */
	abstract public function args();

}
