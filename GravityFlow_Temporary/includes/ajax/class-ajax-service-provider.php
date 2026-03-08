<?php

namespace Gravity_Flow\Gravity_Flow\Ajax;

use Gravity_Forms\Gravity_Forms\GF_Service_Provider;
use Gravity_Forms\Gravity_Forms\GF_Service_Container;

class Ajax_Service_Provider extends GF_Service_Provider {

	const STRATEGY            = 'ajax_response_strategy';
	const RESPONSE_FACTORY    = 'ajax_response_factory';
	const PERMISSIONS_HANDLER = 'ajax_permissions_handler';

	public function register( GF_Service_Container $container ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-ajax-permissions-handler.php';

		$container->add( self::STRATEGY, function () {
			return new WP_Ajax_Return_Strategy();
		} );

		$container->add( self::RESPONSE_FACTORY, function () use ( $container ) {
			return new Response_Factory( $container->get( self::STRATEGY ) );
		} );

		$container->add( self::PERMISSIONS_HANDLER, function() {
			return new Ajax_Permissions_Handler();
		} );
	}

	public function init( GF_Service_Container $container ) {
		// Bail early to avoid running filter more than necessary.
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX || $_REQUEST['action'] !== 'rg_delete_file' ) {
			return;
		}

		add_filter( 'user_has_cap', function ( $all_user_caps, $requested_caps ) use ( $container ) {
			if ( ! in_array( 'gravityforms_delete_entries', $requested_caps ) ) {
				return $all_user_caps;
			}

			return $container->get( self::PERMISSIONS_HANDLER )->override_file_delete_perms( $all_user_caps, $requested_caps );
		}, 10, 2 );
	}

}