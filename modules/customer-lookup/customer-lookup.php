<?php
/**
 * Customer Lookup Module for SimpleFlow
 * AJAX-based customer lookup by phone number for Gravity Forms order forms
 * Version: 1.0.0
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Module constants
if ( ! defined( 'SFA_CL_VER' ) ) {
	define( 'SFA_CL_VER', '1.0.0' );
}
if ( ! defined( 'SFA_CL_DIR' ) ) {
	define( 'SFA_CL_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SFA_CL_URL' ) ) {
	define( 'SFA_CL_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Autoloader for module classes
 */
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'SFA\\CustomerLookup\\' ) !== 0 ) {
		return;
	}

	$relative_class = str_replace( 'SFA\\CustomerLookup\\', '', $class );
	$file           = SFA_CL_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize module on plugins loaded
 */
add_action( 'plugins_loaded', function () {
	new SFA\CustomerLookup\Ajax\LookupHandler();

	if ( is_admin() ) {
		new SFA\CustomerLookup\Admin\SettingsPage();
	}
}, 20 );
