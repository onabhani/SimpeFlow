<?php
/**
 * SFA Production Scheduling
 * Schedule factory production capacity and calculate installation dates
 * Version: 1.1.0
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Module constants
if ( ! defined( 'SFA_PROD_VER' ) ) define( 'SFA_PROD_VER', '1.1.0' );
if ( ! defined( 'SFA_PROD_DIR' ) ) define( 'SFA_PROD_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'SFA_PROD_URL' ) ) define( 'SFA_PROD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for module classes
 */
spl_autoload_register( function ( $class ) {
	// Only autoload our namespace
	if ( strpos( $class, 'SFA\\ProductionScheduling\\' ) !== 0 ) {
		return;
	}

	$relative_class = str_replace( 'SFA\\ProductionScheduling\\', '', $class );
	$file = SFA_PROD_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize module on plugins loaded
 */
add_action( 'plugins_loaded', function () {
	// Check dependencies
	if ( ! class_exists( 'GFForms' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Production Scheduling:</strong> Requires Gravity Forms to be installed and activated.';
			echo '</p></div>';
		} );
		return;
	}

	// Install database tables on first activation
	if ( get_option( 'sfa_prod_db_version' ) !== SFA_PROD_VER ) {
		require_once SFA_PROD_DIR . 'src/Database/Installer.php';
		SFA\ProductionScheduling\Database\Installer::install();
		update_option( 'sfa_prod_db_version', SFA_PROD_VER );
	}

	// Initialize admin pages
	if ( is_admin() ) {
		require_once SFA_PROD_DIR . 'src/Admin/SettingsPage.php';
		new SFA\ProductionScheduling\Admin\SettingsPage();

		require_once SFA_PROD_DIR . 'src/Admin/FormSettings.php';
		new SFA\ProductionScheduling\Admin\FormSettings();

		require_once SFA_PROD_DIR . 'src/Admin/ScheduleView.php';
		new SFA\ProductionScheduling\Admin\ScheduleView();
	}

	// Initialize GF integration
	require_once SFA_PROD_DIR . 'src/GravityForms/BillingStepPreview.php';
	require_once SFA_PROD_DIR . 'src/GravityForms/ValidationHandler.php';
	require_once SFA_PROD_DIR . 'src/GravityForms/BookingHandler.php';

	new SFA\ProductionScheduling\GravityForms\BillingStepPreview();
	new SFA\ProductionScheduling\GravityForms\ValidationHandler();
	new SFA\ProductionScheduling\GravityForms\BookingHandler();

	// Initialize AJAX endpoints
	require_once SFA_PROD_DIR . 'src/API/AjaxEndpoints.php';
	new SFA\ProductionScheduling\API\AjaxEndpoints();
}, 20 );

/**
 * Register assets
 */
add_action( 'init', function () {
	$version = SFA_PROD_VER;
	$timestamp = time(); // Cache bust during development

	wp_register_script(
		'sfa-prod-billing',
		SFA_PROD_URL . 'assets/js/billing-step.js?v=' . $timestamp,
		array( 'jquery' ),
		$version,
		true
	);

	wp_register_script(
		'sfa-prod-calendar',
		SFA_PROD_URL . 'assets/js/calendar-view.js?v=' . $timestamp,
		array( 'jquery' ),
		$version,
		true
	);

	wp_register_style(
		'sfa-prod-styles',
		SFA_PROD_URL . 'assets/css/production-schedule.css?v=' . $timestamp,
		array(),
		$version
	);
}, 5 );
