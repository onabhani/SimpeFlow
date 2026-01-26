<?php
/**
 * Plugin Name:       Simple Flow Attachment
 * Description:       Grouped file attachments for Gravity Forms/Gravity Flow (admin + inbox). Collapsible UI, badges, Download All/Selected (ZIP), remote ZIP with host controls.
 * Version:           1.6.9.1
 * Author:            Omar Alnabhani
 * Author URI:        https://hdqah.com
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPLv2 or later
 * Text Domain:       simple-flow-attachment
 */
defined( 'ABSPATH' ) || exit;

if ( defined( 'SFA_LOADED' ) ) { return; }
define( 'SFA_LOADED', true );

define( 'SFA_VER', '1.6.9.1' );
define( 'SFA_FILE', __FILE__ );
define( 'SFA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFA_URL', plugin_dir_url( __FILE__ ) );

// ZIP controls
if ( ! defined( 'SFA_REMOTE_ZIP' ) ) define( 'SFA_REMOTE_ZIP', true );
if ( ! defined( 'SFA_ZIP_ALLOWED_HOSTS' ) ) define( 'SFA_ZIP_ALLOWED_HOSTS', 'auto' );
if ( ! defined( 'SFA_ZIP_MAX_TOTAL_MB' ) ) define( 'SFA_ZIP_MAX_TOTAL_MB', 50 );

// UI default
if ( ! defined( 'SFA_DEFAULT_OPEN' ) ) define( 'SFA_DEFAULT_OPEN', 'auto' );

// Include required files
require_once SFA_DIR . 'includes/functions-helpers.php';
require_once SFA_DIR . 'includes/functions-collect.php';
require_once SFA_DIR . 'includes/functions-render.php';
require_once SFA_DIR . 'includes/class-sfa-logger.php';
require_once SFA_DIR . 'includes/hooks.php';

// Activation hook (only works when this was a standalone plugin)
// This won't fire when loaded as a module - consider removing or handling differently
register_activation_hook( __FILE__, function () {
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'Simple Flow Attachment requires PHP 7.4 or newer.', 'simple-flow-attachment' ) );
    }
} );

// REMOVED: The nested module loading that was causing the issue
// simple-customer-info is now a separate sibling module managed by SimpleFlow