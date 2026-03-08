<?php
namespace Gravity_Flow\Gravity_Flow\Locking;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

use GFCommon;
use GFLocking;

require_once GFCommon::get_base_path() . '/includes/locking/class-gf-locking.php';

/**
 * Class Locking
 *
 * @package Gravity_Flow\Gravity_Flow\Locking
 *
 * @since 2.8
 */
class Locking extends GFLocking {

	/**
	 * Null or the current instance of this class.
	 *
	 * @since 2.9.7
	 *
	 * @var null|Locking
	 */
	private static $_instance = null;

	/**
	 * Returns the current instance of this class.
	 *
	 * @since 2.9.7
	 *
	 * @return null|Locking
	 */
	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 *  Handles all tasks related to Gravity Flow Entry Locking - extends Gravity Forms Feed Add-On Object Locking pattern
	 *
	 * @since 2.8
	 */
	public function __construct() {
		$capabilities = array( 'gravityforms_edit_entries' );
		$redirect_url = admin_url( 'admin.php?page=gravityflow-inbox' );
		$entry_id     = $this->get_object_id();
		$form_id      = rgget( 'id' );
		$edit_url     = admin_url( sprintf( '/admin.php?page=gravityflow-inbox&view=entry&id=%d&lid=%d', $form_id, $entry_id ) );
		parent::__construct( 'flow', $redirect_url, $edit_url, $capabilities );
	}

	/**
	 *  Get the default text strings for display in various locking scenarios.
	 *
	 * @since 2.8
	 */
	public function get_strings() {
		$strings = array(
			/* translators: %s is a username */
			'currently_locked'  => __( 'This entry is currently locked. Click on the "Request Control" button to let %s know you\'d like to take over.', 'gravityflow' ),
			/* translators: %s is a username */
			'currently_editing' => __( '%s is currently editing this entry', 'gravityflow' ),
			/* translators: %s is a username */
			'taken_over'        => __( '%s has taken over and is currently editing this entry.', 'gravityflow' ),
			/* translators: %s is a username */
			'lock_requested'    => __( '%s has requested permission to take over control of this entry.', 'gravityflow' ),
		);

		return array_merge( parent::get_strings(), $strings );
	}

	/**
	 * Check the condition for the edit page.
	 *
	 * @since 2.8
	 *
	 * @return boolean
	 */
	protected function is_edit_page() {
		$is_edit_page = rgget( 'page' ) == 'gravityflow-inbox' && rgget( 'view' ) == 'entry' && ! empty( rgget( 'id' ) ) && ! empty( rgget( 'lid' ) );
		return $is_edit_page;
	}

	/**
	 * Check the condition for the detail page.
	 *
	 * @since 2.8
	 *
	 * @return boolean
	 */
	protected function is_detail_page() {
		return $this->is_edit_page();
	}

	/**
	 * Check the condition for the list page. No active implementation yet - will be used when displaying lock status on inbox/status.
	 *
	 * @since 2.8
	 *
	 * @return boolean
	 */
	protected function is_list_page() {
		$is_list_page = ( ( rgget( 'page' ) == 'gravityflow-inbox' ) || ( rgget( 'page' ) == 'gravityflow-status' ) ) && empty( rgget( 'view' ) );		
		return $is_list_page;
	}

	/**
	 * Get the entry ID
	 *
	 * @since 2.8
	 *
	 * @return int
	 */
	protected function get_object_id() {
		$id = rgget( 'lid' );
		$id = absint( $id );
		return $id;
	}

}

Locking::get_instance();
