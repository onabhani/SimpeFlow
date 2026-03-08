<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Save_Preference;

use Gravity_Flow\Gravity_Flow\Ajax\Argument;
use Gravity_Flow\Gravity_Flow\Ajax\Config as Ajax_Config;

/**
 * Save_Preference AJAX Config
 *
 * @since 2.8
 */
class Config extends Ajax_Config {

	const KEY     = 'key';
	const VALUE   = 'value';
	const TYPE    = 'type';
	const USER_ID = 'user_id';
	const VIEW    = 'view';

	const DEFAULT_VIEW = 'inbox';
	const DEFAULT_TYPE = 'view';

	/**
	 * Name
	 *
	 * @since 2.8
	 *
	 * @var string
	 */
	protected $name = 'inbox/preferences';

	/**
	 * The method for this request.
	 *
	 * @since 2.8
	 *
	 * @return string
	 */
	public function method() {
		return 'PUT';
	}

	/**
	 * Arguments for the endpoint.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	public function args() {
		return array(
			$this->key(),
			$this->value(),
			$this->type(),
			$this->id(),
			$this->view(),
		);
	}

	/**
	 * The key argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function key() {
		return new Argument(
			self::KEY,
			true
		);
	}

	/**
	 * The value argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function value() {
		return new Argument(
			self::VALUE,
			true
		);
	}

	/**
	 * The type argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function type() {
		return new Argument(
			self::TYPE,
			false,
			self::DEFAULT_TYPE
		);
	}

	/**
	 * The id argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function id() {
		return new Argument(
			self::USER_ID,
			false,
			0
		);
	}

	/**
	 * The view argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function view() {
		return new Argument(
			self::VIEW,
			false,
			self::DEFAULT_VIEW
		);
	}
}