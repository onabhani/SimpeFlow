<?php
/**
 * SFA Production Scheduling
 * Schedule factory production capacity and calculate installation dates
 * Version: 1.1.9
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Module constants
if ( ! defined( 'SFA_PROD_VER' ) ) define( 'SFA_PROD_VER', '1.1.9' );
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

	// Initialize frontend calendar shortcode
	require_once SFA_PROD_DIR . 'src/Frontend/FrontendCalendar.php';
	new SFA\ProductionScheduling\Frontend\FrontendCalendar();
}, 20 );

/**
 * Register assets
 */
add_action( 'init', function () {
	$version = SFA_PROD_VER;

	wp_register_script(
		'sfa-prod-billing',
		SFA_PROD_URL . 'assets/js/billing-step.js',
		array( 'jquery' ),
		$version,
		true
	);

	wp_register_script(
		'sfa-prod-calendar',
		SFA_PROD_URL . 'assets/js/calendar-view.js',
		array( 'jquery' ),
		$version,
		true
	);

	wp_register_script(
		'sfa-prod-admin-entry-edit',
		SFA_PROD_URL . 'assets/js/admin-entry-edit.js',
		array( 'jquery' ),
		$version,
		true
	);

	wp_register_style(
		'sfa-prod-styles',
		SFA_PROD_URL . 'assets/css/production-schedule.css',
		array(),
		$version
	);
}, 5 );

/**
 * Enqueue admin entry edit script on Gravity Forms entry detail page
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
	// Check if we're on the Gravity Forms entries page
	if ( $hook !== 'toplevel_page_gf_entries' && $hook !== 'forms_page_gf_entries' ) {
		return;
	}

	// Check if we're viewing/editing a specific entry (view=entry and lid parameter)
	if ( ! isset( $_GET['view'] ) || $_GET['view'] !== 'entry' ) {
		return;
	}

	if ( ! isset( $_GET['lid'] ) || ! absint( $_GET['lid'] ) ) {
		return;
	}

	// Get form ID from URL
	$form_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	if ( ! $form_id ) {
		return;
	}

	// Get form and check if production scheduling is enabled
	$form = \GFAPI::get_form( $form_id );
	if ( ! $form || is_wp_error( $form ) ) {
		return;
	}

	// Check if production scheduling is enabled for this form
	if ( ! SFA\ProductionScheduling\Admin\FormSettings::is_enabled( $form ) ) {
		return;
	}

	// Get the configured installation date field ID
	$install_field_id = SFA\ProductionScheduling\Admin\FormSettings::get_install_field_id( $form );
	if ( ! $install_field_id ) {
		return;
	}

	// Enqueue the script
	wp_enqueue_script( 'sfa-prod-admin-entry-edit' );

	// Get production field IDs so JS can read current values from the DOM
	$production_fields = SFA\ProductionScheduling\Admin\FormSettings::get_production_fields( $form );
	$prod_field_configs = [];
	if ( ! empty( $production_fields ) ) {
		foreach ( $production_fields as $pf ) {
			$prod_field_configs[] = [
				'field_id'   => $pf['field_id'],
				'field_type' => $pf['field_type'],
			];
		}
	}
	$lm_field_id = SFA\ProductionScheduling\Admin\FormSettings::get_lm_field_id( $form );

	// Localize script with config
	wp_localize_script( 'sfa-prod-admin-entry-edit', 'sfaProdAdmin', [
		'nonce' => wp_create_nonce( 'sfa_prod_admin_capacity_check' ),
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'formId' => $form_id,
		'installFieldId' => $install_field_id,
		'entryId' => absint( $_GET['lid'] ),
		'productionFields' => $prod_field_configs,
		'lmFieldId' => $lm_field_id,
	] );
} );
