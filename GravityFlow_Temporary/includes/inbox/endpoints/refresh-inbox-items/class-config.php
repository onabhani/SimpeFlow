<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Refresh_Inbox_Items;

use Gravity_Flow\Gravity_Flow\Ajax\Argument;
use Gravity_Flow\Gravity_Flow\Ajax\Config as Ajax_Config;

/**
 * Refresh_Inbox_Items ajax Config
 *
 * @since 2.8
 */
class Config extends Ajax_Config {

	const SEARCH_ARGS = 'search_args';
	const CURRENT_IDS = 'current_ids';
	const TOKEN       = 'gflow_access_token';

	/**
	 * Name
	 *
	 * @since 2.8
	 *
	 * @var string
	 */
	protected $name = 'inbox/changes';

	/**
	 * The method for the request.
	 *
	 * @since 2.8
	 *
	 * @return string
	 */
	public function method() {
		return 'POST';
	}

	/**
	 * The args for the endpoint.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	public function args() {
		return array(
			$this->search_args(),
			$this->current_ids(),
			$this->token_args(),
		);
	}

	/**
	 * The search_args argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function search_args() {
		return new Argument(
			self::SEARCH_ARGS,
			false,
			array()
		);
	}

	/**
	 * The current_ids argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function current_ids() {
		return new Argument(
			self::CURRENT_IDS,
			false,
			array()
		);
	}

	/**
	 * The token_args argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function token_args() {
		return new Argument(
			self::TOKEN,
			true
		);
	}
}