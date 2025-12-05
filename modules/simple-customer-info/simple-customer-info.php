<?php
/**
 * Module: Simple Customer Information (SFA module)
 * Version: 0.2.7e-topfix2-statusbadge-ui8-complete-fixed3e
 * Author: Omar Alnabhani (hdqah.com)
 * Text Domain: simple-flow-attachment
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'SFA_SCI_VER', '0.2.7e-topfix2-statusbadge-ui8-complete-fixed3e' );
define( 'SFA_SCI_DIR', __DIR__ . '/' );
define( 'SFA_SCI_URL', plugin_dir_url( __FILE__ ) );

require_once SFA_SCI_DIR . 'src/Cap.php';
require_once SFA_SCI_DIR . 'src/MapRepository.php';
require_once SFA_SCI_DIR . 'src/Renderer.php';
require_once SFA_SCI_DIR . 'src/Assets.php';
require_once SFA_SCI_DIR . 'src/AdminController.php';
require_once SFA_SCI_DIR . 'src/Integrations/GFEntryView.php';
require_once SFA_SCI_DIR . 'src/Integrations/GravityFlowView.php';

add_action( 'gform_loaded', function () {
    $repo     = new \SFA\SCI\MapRepository('_sci_map');
    $renderer = new \SFA\SCI\Renderer( SFA_SCI_DIR . 'views/card.php' );
    
    (new \SFA\SCI\Assets( SFA_SCI_URL . 'assets/' ))->register();
    (new \SFA\SCI\AdminController( $repo, SFA_SCI_DIR . 'views/admin-page.php' ))->register();
    (new \SFA\SCI\Integrations\GFEntryView( $repo, $renderer ))->register();
    (new \SFA\SCI\Integrations\GravityFlowView( $repo, $renderer ))->register();
}, 10);

// Bridge admin options to badge status filter
add_filter('sfa_sci_badge_status', function($config, $form, $entry, $map){
    if (is_array($map) && isset($map['options'])) {
        $opts = $map['options'];
        $field_id = isset($opts['badge_field_id']) ? (int)$opts['badge_field_id'] : 0;
        $raw = isset($opts['badge_colors']) ? (string)$opts['badge_colors'] : '';
        
        if ($field_id) {
            $colors = array();
            if ($raw !== '') {
                $lines = preg_split('/\r\n|\r|\n/', $raw);
                foreach ($lines as $ln) {
                    $ln = trim($ln);
                    if ($ln === '') continue;
                    $parts = preg_split('/\s*[\|:]\s*/', $ln, 2);
                    if (count($parts) === 2) { 
                        $colors[$parts[0]] = $parts[1]; 
                    }
                }
            }
            return array('field_id'=>$field_id, 'colors'=>$colors);
        }
    }
    return $config;
}, 10, 4);

// Check if FlowFallbackEnqueue file exists before requiring
if ( file_exists( __DIR__ . '/src/FlowFallbackEnqueue.php' ) ) {
    require_once __DIR__ . '/src/FlowFallbackEnqueue.php';
}