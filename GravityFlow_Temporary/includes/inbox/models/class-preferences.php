<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Models;

use Gravity_Flow\Gravity_Flow\Models\Model;

/**
 * Model for getting and storing Preferences in a cascading manner.
 *
 * @since 2.8
 */
class Preferences implements Model {

	/**
	 * Get a setting.
	 *
	 * @since 2.8
	 *
	 * @param        $key
	 * @param        $id
	 * @param        $default
	 * @param string $view
	 *
	 * @return bool
	 */
	public function get_setting( $key, $id, $default, $view = 'inbox' ) {
		$value = $this->get_setting_from_user( $key, $id, $view );

		if ( $value !== 0 && empty( $value ) ) {
			$value = $this->get_setting_from_view( $key, $view );
		}

		if ( $value === false ) {
			return $default;
		}

		return $this->parse_saved_val( $value );
	}

	/**
	 * Parse the saved value.
	 *
	 * @since 2.8
	 *
	 * @param $value
	 *
	 * @return bool
	 */
	private function parse_saved_val( $value ) {
		if ( $value === 'true' ) {
			return true;
		}

		if ( $value === 'false' ) {
			return false;
		}

		return $value;
	}

	/**
	 * Save a setting.
	 *
	 * @since 2.8
	 *
	 * @param        $key
	 * @param        $id
	 * @param        $value
	 * @param string $type
	 * @param string $view
	 *
	 * @return bool|int
	 */
	public function save_setting( $key, $id, $value, $type = 'view', $view = 'inbox' ) {
		switch ( $type ) {
			case 'user':
				return $this->save_setting_for_user( $key, $id, $view, $value );
			case 'view':
			default:
				return $this->save_setting_for_view( $key, $view, $value );
		}
	}

	/**
	 * Get a setting from the specified location.
	 *
	 * @since 2.8
	 *
	 * @param        $type
	 * @param        $key
	 * @param        $id_or_view
	 * @param string $default
	 *
	 * @return int|mixed|string|void
	 */
	public function get_setting_from( $type, $key, $id_or_view, $default = '' ) {
		switch ( $type ) {
			case 'user':
				$value = $this->get_setting_from_user( $key, $id_or_view );
				break;
			case 'view':
			default:
				$value = $this->get_setting_from_view( $key, $id_or_view );
				break;
		}

		if ( $value !== false && $value !== 0 && empty( $value ) ) {
			return $default;
		}

		if ( $value === false ) {
			return $default;
		}

		return $value;
	}

	/**
	 * Get setting from a user's meta.
	 *
	 * @since 2.8
	 *
	 * @param        $key
	 * @param        $id
	 * @param string $view
	 *
	 * @return mixed
	 */
	private function get_setting_from_user( $key, $id, $view = 'inbox' ) {
		$setting_key = $this->get_setting_key( $key, $view );

		return get_user_meta( $id, $setting_key, true );
	}

	/**
	 * Get setting from stored values for a view.
	 *
	 * @since 2.8
	 *
	 * @param $key
	 * @param $view
	 *
	 * @return false|mixed|void
	 */
	private function get_setting_from_view( $key, $view ) {
		$setting_key = $this->get_setting_key( $key, $view );

		return get_option( $setting_key );
	}

	/**
	 * Save settings to a user's meta.
	 *
	 * @since 2.8
	 *
	 * @param $key
	 * @param $id
	 * @param $view
	 * @param $value
	 *
	 * @return bool|int
	 */
	private function save_setting_for_user( $key, $id, $view, $value ) {
		$setting_key = $this->get_setting_key( $key, $view );

		return update_user_meta( $id, $setting_key, $value );
	}

	/**
	 * Save settings for a specific view.
	 *
	 * @since 2.8
	 *
	 * @param $key
	 * @param $view
	 * @param $value
	 *
	 * @return bool
	 */
	private function save_setting_for_view( $key, $view, $value ) {
		$setting_key = $this->get_setting_key( $key, $view );

		return update_option( $setting_key, $value );
	}

	/**
	 * Get the key for a given setting.
	 *
	 * @since 2.8
	 *
	 * @param       $key
	 * @param false $view
	 *
	 * @return string
	 */
	private function get_setting_key( $key, $view = false ) {
		if ( $view === false ) {
			return sprintf( 'gflow_setting_%s', $key );
		}

		return sprintf( 'gflow_setting_%s_%s', $key, $view );
	}

}