<?php
/**
 * Code Validation Module for SimpleFlow
 * Validates confirmation codes entered by salesmen against existing entries
 * Version: 1.0.3
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Module constants
if ( ! defined( 'SFA_CV_VER' ) ) {
	define( 'SFA_CV_VER', '1.0.3' );
}
if ( ! defined( 'SFA_CV_DIR' ) ) {
	define( 'SFA_CV_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SFA_CV_URL' ) ) {
	define( 'SFA_CV_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Autoloader for module classes
 */
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'SFA\\CodeValidation\\' ) !== 0 ) {
		return;
	}

	$relative_class = str_replace( 'SFA\\CodeValidation\\', '', $class );
	$file           = SFA_CV_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize module on plugins loaded
 */
add_action( 'plugins_loaded', function () {
	// Load validation rules from settings
	require_once SFA_CV_DIR . 'src/GravityForms/Validator.php';
	new SFA\CodeValidation\GravityForms\Validator();

	// Load admin settings
	if ( is_admin() ) {
		require_once SFA_CV_DIR . 'src/Admin/SettingsPage.php';
		new SFA\CodeValidation\Admin\SettingsPage();
	}
}, 20 );
