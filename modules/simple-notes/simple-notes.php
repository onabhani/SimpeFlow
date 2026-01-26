<?php
/**
 * Simple Notes Module for SimpleFlow
 * Complete notes system with user mentions, email notifications, and automatic positioning
 * Version: 1.0.0
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Module constants
if ( ! defined( 'SFA_NOTES_VER' ) ) {
	define( 'SFA_NOTES_VER', '1.0.0' );
}
if ( ! defined( 'SFA_NOTES_DIR' ) ) {
	define( 'SFA_NOTES_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SFA_NOTES_URL' ) ) {
	define( 'SFA_NOTES_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Autoloader for module classes
 */
spl_autoload_register( function ( $class ) {
	// Only autoload our namespace
	if ( strpos( $class, 'SFA\\SimpleNotes\\' ) !== 0 ) {
		return;
	}

	$relative_class = str_replace( 'SFA\\SimpleNotes\\', '', $class );
	$file           = SFA_NOTES_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize module on plugins loaded
 */
add_action( 'plugins_loaded', function () {
	// Install database tables on first activation
	if ( get_option( 'sfa_notes_db_version' ) !== SFA_NOTES_VER ) {
		require_once SFA_NOTES_DIR . 'src/Database/Installer.php';
		SFA\SimpleNotes\Database\Installer::install();
		update_option( 'sfa_notes_db_version', SFA_NOTES_VER );
	}

	// Initialize admin pages
	if ( is_admin() ) {
		require_once SFA_NOTES_DIR . 'src/Admin/NotesPage.php';
		new SFA\SimpleNotes\Admin\NotesPage();

		require_once SFA_NOTES_DIR . 'src/Admin/SettingsPage.php';
		new SFA\SimpleNotes\Admin\SettingsPage();
	}

	// Initialize AJAX endpoints
	require_once SFA_NOTES_DIR . 'src/API/AjaxEndpoints.php';
	new SFA\SimpleNotes\API\AjaxEndpoints();

	// Initialize auto-positioning
	require_once SFA_NOTES_DIR . 'src/Frontend/AutoPositioning.php';
	new SFA\SimpleNotes\Frontend\AutoPositioning();

	error_log( 'Simple Notes Module: Initialized successfully' );
}, 20 );

/**
 * Enqueue scripts and styles
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	// Enqueue main JavaScript
	wp_enqueue_script(
		'sfa-simple-notes',
		SFA_NOTES_URL . 'assets/js/notes.js',
		array( 'jquery' ),
		SFA_NOTES_VER,
		true
	);

	// Localize script with config
	wp_localize_script( 'sfa-simple-notes', 'simpleNotesConfig', array(
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'simple_notes_nonce' ),
		'currentUser' => array(
			'id'   => get_current_user_id(),
			'name' => wp_get_current_user()->display_name,
		),
	) );

	// Add inline CSS for mention dropdown
	wp_add_inline_style( 'admin-bar', '
		.mention-dropdown .mention-item:hover,
		.mention-dropdown .mention-item.selected {
			background: #f0f0f1;
		}
		.mention-dropdown .mention-item:last-child {
			border-bottom: none;
		}
	' );
}, 10 );

/**
 * Also enqueue on frontend for workflow-inbox pages
 */
add_action( 'wp_enqueue_scripts', function () {
	// Only enqueue on workflow-inbox pages
	if ( strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 'workflow-inbox' ) !== false ) {
		wp_enqueue_script(
			'sfa-simple-notes-frontend',
			SFA_NOTES_URL . 'assets/js/notes.js',
			array( 'jquery' ),
			SFA_NOTES_VER,
			true
		);

		wp_localize_script( 'sfa-simple-notes-frontend', 'simpleNotesConfig', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'simple_notes_nonce' ),
			'currentUser' => array(
				'id'   => get_current_user_id(),
				'name' => wp_get_current_user()->display_name,
			),
		) );

		wp_add_inline_style( 'wp-admin', '
			.mention-dropdown .mention-item:hover,
			.mention-dropdown .mention-item.selected {
				background: #f0f0f1;
			}
			.mention-dropdown .mention-item:last-child {
				border-bottom: none;
			}
		' );
	}
}, 10 );
