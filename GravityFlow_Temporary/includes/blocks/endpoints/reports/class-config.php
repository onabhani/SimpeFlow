<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Reports;

use Gravity_Flow\Gravity_Flow\Ajax\Argument;
use Gravity_Flow\Gravity_Flow\Ajax\Config as Ajax_Config;

/**
 * Reports AJAX config.
 *
 * @since 2.8
 */
class Config extends Ajax_Config {

	const ASSIGNEE       = 'assignee';
	const RANGE          = 'range';
	const FORM           = 'form';
	const CATEGORY       = 'category';
	const STEP_ID        = 'step-id';
	const DISPLAY_FILTER = 'display_filter';

	protected $name = 'workflow/reports';

	/**
	 * The arguments for the endpoint.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	public function args() {
		return array(
			$this->assignee(),
			$this->range(),
			$this->form(),
			$this->category(),
			$this->step_id(),
			$this->display_filter(),
		);
	}

	/**
	 * The assignee argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function assignee() {
		return new Argument(
			self::ASSIGNEE,
			false,
			''
		);
	}

	/**
	 * The range argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function range() {
		return new Argument(
			self::RANGE,
			false,
			''
		);
	}

	/**
	 * The form argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function form() {
		return new Argument(
			self::FORM,
			false,
			''
		);
	}

	/**
	 * The category argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function category() {
		return new Argument(
			self::CATEGORY,
			false,
			''
		);
	}

	/**
	 * The step_id argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function step_id() {
		return new Argument(
			self::STEP_ID,
			false,
			''
		);
	}

	/**
	 * The display_filter argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function display_filter() {
		return new Argument(
			self::DISPLAY_FILTER,
			false,
			''
		);
	}
}
