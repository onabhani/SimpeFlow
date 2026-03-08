<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Forms;

use Gravity_Flow\Gravity_Flow\Ajax\Config as Ajax_Config;

/**
 * Inbox_Forms Config
 *
 * @since 2.8
 */
class Config extends Ajax_Config {

	protected $name = 'workflow/forms';

	/**
	 * The args for the endpoint.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	public function args() {
		return array();
	}
}