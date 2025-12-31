<?php
/**
 * Update Requests Module for SimpleFlow
 * Allows submitting update requests for existing job entries
 * Version: 1.0.0
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Module constants
if ( ! defined( 'SFA_UR_VER' ) ) {
	define( 'SFA_UR_VER', '1.0.0' );
}
if ( ! defined( 'SFA_UR_DIR' ) ) {
	define( 'SFA_UR_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SFA_UR_URL' ) ) {
	define( 'SFA_UR_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Autoloader for module classes
 */
spl_autoload_register( function ( $class ) {
	// Only autoload our namespace
	if ( strpos( $class, 'SFA\\UpdateRequests\\' ) !== 0 ) {
		return;
	}

	$relative_class = str_replace( 'SFA\\UpdateRequests\\', '', $class );
	$file           = SFA_UR_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize module on plugins loaded
 */
add_action( 'plugins_loaded', function () {
	// Initialize mode detector (detects update request mode via hidden fields)
	require_once SFA_UR_DIR . 'src/GravityForms/ModeDetector.php';
	new SFA\UpdateRequests\GravityForms\ModeDetector();

	// Initialize child linking (links child entries to parent)
	require_once SFA_UR_DIR . 'src/GravityForms/ChildLinking.php';
	new SFA\UpdateRequests\GravityForms\ChildLinking();

	// Initialize drawing population (populates checkbox from parent field 45)
	require_once SFA_UR_DIR . 'src/GravityForms/DrawingPopulation.php';
	new SFA\UpdateRequests\GravityForms\DrawingPopulation();

	// Initialize approval guards (prevents skipping approval step) - v0.2.0
	if ( class_exists( 'Gravity_Flow_API' ) ) {
		require_once SFA_UR_DIR . 'src/GravityForms/ApprovalGuards.php';
		new SFA\UpdateRequests\GravityForms\ApprovalGuards();
	}

	// Initialize file attachments (controls file uploads after approval) - v0.3.0
	require_once SFA_UR_DIR . 'src/GravityForms/FileAttachments.php';
	new SFA\UpdateRequests\GravityForms\FileAttachments();

	// Initialize entry updating (applies approved changes to parent) - v0.4.0
	require_once SFA_UR_DIR . 'src/GravityForms/EntryUpdating.php';
	new SFA\UpdateRequests\GravityForms\EntryUpdating();

	// Initialize admin panel (shows all update requests in parent entry)
	if ( is_admin() || strpos( $_SERVER['REQUEST_URI'], 'workflow-inbox' ) !== false ) {
		require_once SFA_UR_DIR . 'src/Admin/ParentPanel.php';
		new SFA\UpdateRequests\Admin\ParentPanel();
	}

	error_log( 'Update Requests Module v' . SFA_UR_VER . ': Initialized successfully' );
}, 20 );
