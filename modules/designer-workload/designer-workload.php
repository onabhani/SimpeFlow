<?php
/**
 * Designer Workload Module for SimpleFlow
 * Read-only page listing each designer's pending DOFS / Projects tasks
 * Version: 1.0.1
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SFA_DW_VER' ) ) {
	define( 'SFA_DW_VER', '1.0.1' );
}
if ( ! defined( 'SFA_DW_DIR' ) ) {
	define( 'SFA_DW_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SFA_DW_URL' ) ) {
	define( 'SFA_DW_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Autoloader for module classes
 */
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'SFA\\DesignerWorkload\\' ) !== 0 ) {
		return;
	}

	$relative_class = str_replace( 'SFA\\DesignerWorkload\\', '', $class );
	$file           = SFA_DW_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize module on plugins loaded
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'gravity_flow' ) ) {
		return;
	}

	new SFA\DesignerWorkload\Frontend\Shortcode();

	if ( is_admin() ) {
		new SFA\DesignerWorkload\Admin\SettingsPage();
	}
}, 20 );
