<?php
/**
 * Customer Lookup Module for SimpleFlow
 * AJAX-based customer lookup by phone number for Gravity Forms order forms
 * Version: 2.0.1
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Module constants
if ( ! defined( 'SFA_CL_VER' ) ) {
	define( 'SFA_CL_VER', '2.0.1' );
}
if ( ! defined( 'SFA_CL_DB_VER' ) ) {
	define( 'SFA_CL_DB_VER', '1.2.0' );
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
	// Version-gated DB table install — uses SFA_CL_DB_VER so only schema changes trigger dbDelta
	$installed_ver = get_option( 'sfa_cl_db_version', '0' );
	if ( version_compare( $installed_ver, SFA_CL_DB_VER, '<' ) ) {
		\SFA\CustomerLookup\Database\CustomerTable::create_table();
		update_option( 'sfa_cl_db_version', SFA_CL_DB_VER );
	}

	new SFA\CustomerLookup\Ajax\LookupHandler();

	if ( is_admin() ) {
		new SFA\CustomerLookup\Admin\SettingsPage();
		new SFA\CustomerLookup\Admin\CustomersAdmin();
	}
}, 20 );
