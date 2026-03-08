<?php

namespace Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Entries;

use Gravity_Flow\Gravity_Flow\Ajax\Argument;
use Gravity_Flow\Gravity_Flow\Ajax\Config as Ajax_Config;

/**
 * Inbox_Entries AJAX Config
 *
 * @since 2.8
 */
class Config extends Ajax_Config {

	const FORM_IDS       = 'form-ids';
	const ID_COLUMN      = 'id-column';
	const ACTIONS_COLUMN = 'actions-column';
	const LAST_UPDATED   = 'last-updated';
	const DUE_DATE       = 'due-date';
	const FIELDS         = 'fields';

	/**
	 * Name
	 *
	 * @var string
	 */
	protected $name = 'inbox/entries';

	/**
	 * The arguments for the endpoint.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	public function args() {
		return array(
			$this->form_ids(),
			$this->id_column(),
			$this->actions_column(),
			$this->last_updated(),
			$this->due_date(),
			$this->fields(),
		);
	}

	/**
	 * The form_ids argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function form_ids() {
		return new Argument(
			self::FORM_IDS,
			false,
			''
		);
	}

	/**
	 * The id_column argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function id_column() {
		return new Argument(
			self::ID_COLUMN,
			false,
			''
		);
	}

	/**
	 * The actions_column argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function actions_column() {
		return new Argument(
			self::ACTIONS_COLUMN,
			false,
			''
		);
	}

	/**
	 * The last_updated argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function last_updated() {
		return new Argument(
			self::LAST_UPDATED,
			false,
			''
		);
	}

	/**
	 * The due_date argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function due_date() {
		return new Argument(
			self::DUE_DATE,
			false,
			''
		);
	}

	/**
	 * The fields argument.
	 *
	 * @since 2.8
	 *
	 * @return Argument
	 */
	public function fields() {
		return new Argument(
			self::FIELDS,
			false,
			array()
		);
	}
}
