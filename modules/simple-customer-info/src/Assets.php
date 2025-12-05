<?php namespace SFA\SCI;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Assets {
    private string $base_url;

    public function __construct( string $base_url ) {
        $this->base_url = rtrim( $base_url, '/' ) . '/';
    }

    public function register() : void {
        // Admin (GF entries + Gravity Flow pages)
        add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin' ) );
        // Our GF Form Settings subtab
        add_action( 'gform_form_settings_page_sfa_sci', array( $this, 'enqueue' ) );
        // Front-end (Gravity Flow inbox shortcode, etc.)
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend' ) );
    }

    public function maybe_enqueue_admin( $hook ) : void {
        $load = false;
        if ( strpos( (string) $hook, 'gf_entries' ) !== false ) {
            $load = true;
        }
        if ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) && strpos( $_GET['page'], 'gravityflow' ) !== false ) {
            $load = true;
        }
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'gravityflow-inbox' ) {
            $load = true;
        }
        if ( $load ) {
            $this->enqueue();
        }
    }

    public function maybe_enqueue_frontend() : void {
        if ( function_exists( 'gravity_flow' ) ) {
            $this->enqueue();
        }
    }

    private function enqueue() : void {
        wp_enqueue_style(
            'sfa-sci',
            $this->base_url . 'css/simple-customer-info.css',
            array(),
            defined( 'SFA_SCI_VER' ) ? SFA_SCI_VER : '0.0.0'
        );
        wp_enqueue_script(
            'sfa-sci',
            $this->base_url . 'js/simple-customer-info.js',
            array( 'jquery' ),
            defined( 'SFA_SCI_VER' ) ? SFA_SCI_VER : '0.0.0',
            true
        );
    }
}
