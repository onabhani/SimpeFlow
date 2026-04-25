<?php
/**
 * Entry Creator Module for SimpleFlow
 * Allows authorized admins to reassign the created_by property on Gravity Forms entries.
 * Version: 1.2.0
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SFA_EC_VER' ) ) {
	define( 'SFA_EC_VER', '1.2.0' );
}
if ( ! defined( 'SFA_EC_DIR' ) ) {
	define( 'SFA_EC_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SFA_EC_URL' ) ) {
	define( 'SFA_EC_URL', plugin_dir_url( __FILE__ ) );
}

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'SFA\\EntryCreator\\' ) !== 0 ) {
		return;
	}

	$relative_class = str_replace( 'SFA\\EntryCreator\\', '', $class );
	$file           = SFA_EC_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'GFAPI' ) ) {
		return;
	}

	if ( get_option( 'sfa_ec_db_version' ) !== SFA_EC_VER ) {
		require_once SFA_EC_DIR . 'src/Database/Installer.php';
		SFA\EntryCreator\Database\Installer::install();
		update_option( 'sfa_ec_db_version', SFA_EC_VER );
	}

	if ( is_admin() ) {
		require_once SFA_EC_DIR . 'src/Admin/MetaBoxRenderer.php';
		new SFA\EntryCreator\Admin\MetaBoxRenderer();

		require_once SFA_EC_DIR . 'src/Admin/SaveHandler.php';
		new SFA\EntryCreator\Admin\SaveHandler();
	}
}, 20 );
