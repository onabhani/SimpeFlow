<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Form_Steps;

use Gravity_Flow\Gravity_Flow\Ajax\Config as Ajax_Config;

/**
 * Inbox_Form_Steps config
 *
 * @since 2.8
 */
class Config extends Ajax_Config {

	protected $name = 'workflow/forms/(?P<id>[\d]+)/steps';

	/**
	 * Args for the endpoint.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	public function args() {
		return array();
	}
}
