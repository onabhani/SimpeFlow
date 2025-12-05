<?php namespace SFA\SCI;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AdminController {
	const SLUG  = 'sfa-simple-customer-info';
	const NONCE = 'sfa_sci_nonce';

	private $repo;
	private $view_file;

	public function __construct( $repo, $admin_view ) {
		$this->repo      = $repo;
		$this->view_file = $admin_view;
	}

	public function register() : void {

		// One tab in Form Settings (correct signature)
		add_filter( 'gform_form_settings_menu', function( $tabs ) {
			if ( ! Cap::can_manage() ) { return $tabs; }
			foreach ( (array) $tabs as $t ) {
				if ( ( isset( $t['name'] )  && $t['name']  === self::SLUG )
				  || ( isset( $t['label'] ) && $t['label'] === __( 'Customer Info', 'simple-flow-attachment' ) ) ) {
					return $tabs; // already there
				}
			}
			$tabs[] = array(
				'name'  => self::SLUG,
				'label' => __( 'Customer Info', 'simple-flow-attachment' ),
				'icon'  => 'gform-icon--person',
			);
			return $tabs;
		}, 20 );

		// Render our tab (no params)
		add_action( 'gform_form_settings_page_' . self::SLUG, array( $this, 'render_page' ) );

		// Handle submit from admin-post.php
		add_action( 'admin_post_sfa_sci_save', array( $this, 'save' ) );

		// Load assets on our tab so the “Add row” JS works there too
		add_action( 'admin_enqueue_scripts', function( $hook ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
			$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : ''; // phpcs:ignore
			if ( $page !== 'gf_edit_forms' || $view !== self::SLUG ) { return; }

			wp_enqueue_style(  'sfa-sci', plugins_url( '../assets/css/simple-customer-info.css', __FILE__ ), array(), defined('SFA_SCI_VER') ? SFA_SCI_VER : '0.0.0' );
			wp_enqueue_script( 'sfa-sci', plugins_url( '../assets/js/simple-customer-info.js',  __FILE__ ), array(), defined('SFA_SCI_VER') ? SFA_SCI_VER : '0.0.0', true );
		} );
	}

	public function render_page() : void {
		if ( ! Cap::can_manage() ) { wp_die( esc_html__( 'Unauthorized.', 'simple-flow-attachment' ) ); }

		// Resolve form ID robustly
		$form_id = 0;
		if ( function_exists( 'rgget' ) ) { $form_id = absint( rgget( 'id' ) ); }
		if ( ! $form_id && isset( $_GET['id'] ) ) { $form_id = absint( $_GET['id'] ); } // phpcs:ignore
		if ( ! $form_id && isset( $_REQUEST['id'] ) ) { $form_id = absint( $_REQUEST['id'] ); } // phpcs:ignore

		$map    = $form_id ? $this->repo->get( $form_id )            : array( 'preset'=>array(), 'extra'=>array(), 'options'=>array( 'collapse_mobile'=>1, 'hide_native'=>1 ,
				'optional_phone_ids' => isset( $_POST['options']['optional_phone_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['options']['optional_phone_ids'] ) ) : ''
			) );
		$fields = $form_id ? $this->repo->fields_indexed( $form_id ) : array();

		if ( isset( $_GET['updated'] ) && (int) $_GET['updated'] === 1 ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>', esc_html__( 'Saved.', 'simple-flow-attachment' ), '</p></div>';
		}

		$view_file = $this->view_file;
		if ( file_exists( $view_file ) ) {
			include $view_file; // expects $form_id, $map, $fields
		} else {
			echo '<div class="notice notice-error"><p>', esc_html__( 'View file missing.', 'simple-flow-attachment' ), '</p></div>';
		}
	}

	// Minimal & safe; includes log line and redirect fallback
	public function save() {
		if ( ! Cap::can_manage() ) { wp_die( esc_html__( 'Unauthorized.', 'simple-flow-attachment' ) ); }

		if ( defined('WP_DEBUG') && WP_DEBUG ) { @error_log( 'SCI: save() hit' ); }

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		check_admin_referer( 'sfa_sci_save_' . $form_id, self::NONCE );

		$data = array(
			'preset'  => array(),
			'extra'   => array(),
			'options' => array(
				'collapse_mobile' => ! empty( $_POST['options']['collapse_mobile'] ) ? 1 : 0,
				'hide_native'     => ! empty( $_POST['options']['hide_native'] ) ? 1 : 0,
				'badge_field_id'   => isset( $_POST['options']['badge_field_id'] ) ? intval( $_POST['options']['badge_field_id'] ) : 0,
				'badge_colors'     => isset( $_POST['options']['badge_colors'] ) ? sanitize_textarea_field( wp_unslash( $_POST['options']['badge_colors'] ) ) : '',
			),
		);

		if ( ! empty( $_POST['preset'] ) && is_array( $_POST['preset'] ) ) {
			foreach ( $_POST['preset'] as $key => $slot ) {
				$data['preset'][ sanitize_key( $key ) ] = array(
					'label'    => isset( $slot['label'] ) ? sanitize_text_field( $slot['label'] ) : '',
					'field_id' => isset( $slot['field_id'] ) ? (int) $slot['field_id'] : 0,
				);
			}
		}

		if ( ! empty( $_POST['extra'] ) && is_array( $_POST['extra'] ) ) {
			foreach ( $_POST['extra'] as $slot ) {
				$label = isset( $slot['label'] ) ? sanitize_text_field( $slot['label'] ) : '';
				$fid   = isset( $slot['field_id'] ) ? (int) $slot['field_id'] : 0;
				if ( $label && $fid ) {
					$data['extra'][] = array( 'label' => $label, 'field_id' => $fid );
				}
			}
		}

		$this->repo->save( $form_id, $data );

		$url = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . self::SLUG . '&id=' . $form_id . '&updated=1' );

		nocache_headers();
		if ( ! headers_sent() ) {
			wp_redirect( $url, 303 );
			exit;
		}

		echo '<script>location.href=' . json_encode( $url ) . ';</script>';
		echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_attr( $url ) . '"></noscript>';
		exit;
	}
}
