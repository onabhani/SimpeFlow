<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Get_Preference;

use Gravity_Flow\Gravity_Flow\Ajax\Argument;
use Gravity_Flow\Gravity_Flow\Ajax\Config as Ajax_Config;

/**
 * Get_Preference Config
 *
 * @since 2.8
 */
class Config extends Ajax_Config {

	// Argument names.
	const KEY           = 'key';
	const TYPE          = 'type';
	const USER_ID       = 'user_id';
	const VIEW          = 'view';
	const DEFAULT_VALUE = 'default';

	// Default value for arguments.
	const DEFAULT_VIEW  = 'inbox';
	const DEFAULT_TYPE  = 'cascade';

	/**
	 * Name
	 *
	 * @since 2.8
	 *
	 * @var string
	 */
	protected $name = 'inbox/preferences';

	/**
	 * The arguments for this endpoint.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	public function args() {
		return array(
			$this->key(),
			$this->type(),
			$this->id(),
			$this->view(),
			$this->default_value(),
		);
	}

	/**
	 * The Key argument.
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

	/**
	 * The default_value argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function default_value() {
		return new Argument(
			self::DEFAULT_VALUE,
			false,
			''
		);
	}
}
