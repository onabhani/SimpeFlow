<?php
/**
 * Update Requests Module for SimpleFlow
 * Allows submitting update requests for existing job entries
 * Version: 1.2.0
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Module constants
if ( ! defined( 'SFA_UR_VER' ) ) {
	define( 'SFA_UR_VER', '1.2.0' );
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
	// Initialize form settings (per-form configuration)
	require_once SFA_UR_DIR . 'src/Admin/FormSettings.php';
	new SFA\UpdateRequests\Admin\FormSettings();

	// Initialize mode detector (detects update request mode via URL parameters)
	require_once SFA_UR_DIR . 'src/GravityForms/ModeDetector.php';
	new SFA\UpdateRequests\GravityForms\ModeDetector();

	// Initialize child linking (links child entries to parent)
	require_once SFA_UR_DIR . 'src/GravityForms/ChildLinking.php';
	new SFA\UpdateRequests\GravityForms\ChildLinking();

	// Initialize approval guards (prevents skipping approval step)
	if ( class_exists( 'Gravity_Flow_API' ) ) {
		require_once SFA_UR_DIR . 'src/GravityForms/ApprovalGuards.php';
		new SFA\UpdateRequests\GravityForms\ApprovalGuards();
	}

	// Initialize file version applier (applies approved updates to parent)
	require_once SFA_UR_DIR . 'src/GravityForms/FileVersionApplier.php';
	new SFA\UpdateRequests\GravityForms\FileVersionApplier();

	// Initialize file attachments (controls file upload visibility based on approval status)
	require_once SFA_UR_DIR . 'src/GravityForms/FileAttachments.php';
	new SFA\UpdateRequests\GravityForms\FileAttachments();

	// Initialize admin components
	if ( is_admin() || strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 'workflow-inbox' ) !== false ) {
		// Parent panel (shows all update requests in sidebar)
		require_once SFA_UR_DIR . 'src/Admin/ParentPanel.php';
		new SFA\UpdateRequests\Admin\ParentPanel();

		// File version widget (shows files table with update buttons)
		require_once SFA_UR_DIR . 'src/Admin/FileVersionWidget.php';
		new SFA\UpdateRequests\Admin\FileVersionWidget();

		// AJAX modal handlers (processes update request submissions)
		require_once SFA_UR_DIR . 'src/Admin/UpdateRequestModal.php';
		new SFA\UpdateRequests\Admin\UpdateRequestModal();
	}
}, 20 );
