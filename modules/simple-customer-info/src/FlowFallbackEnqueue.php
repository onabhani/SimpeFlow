<?php
namespace SFA\SCI;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class FlowFallbackEnqueue {
    public static function init() : void {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front' ) );
    }

    public static function admin( $hook ) : void {
        if ( ! self::is_flow_context() ) {
            return;
        }
        self::enqueue();
    }

    public static function front() : void {
        if ( ! self::is_flow_context() ) {
            return;
        }
        self::enqueue();
    }

    private static function is_flow_context() : bool {
        if ( function_exists( 'gravity_flow' ) ) {
            if ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) && strpos( $_GET['page'], 'gravityflow' ) !== false ) {
                return true;
            }
            // Frontend Flow inbox (shortcodes) - allow
            return true;
        }
        return false;
    }

    private static function enqueue() : void {
        $main = dirname( __FILE__ ) . '/../simple-customer-info.php';
        $url  = plugin_dir_url( $main ) . 'assets/js/sfa-sci-flow-fallback.js';
        wp_enqueue_script(
            'sfa-sci-flow-fallback',
            $url,
            array( 'jquery' ),
            defined( 'SFA_SCI_VER' ) ? SFA_SCI_VER : '0.0.0',
            true
        );
    }
}
FlowFallbackEnqueue::init();
