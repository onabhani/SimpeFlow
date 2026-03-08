<?php

namespace Gravity_Flow\Gravity_Flow\Util;

use Gravity_Flow\Gravity_Flow\Config\JS_Config;
use Gravity_Forms\Gravity_Forms\GF_Service_Provider;
use Gravity_Forms\Gravity_Forms\GF_Service_Container;
use \GFAPI;
use \Gravity_Flow_API;

class Util_Service_Provider extends GF_Service_Provider {

	const GF_API    = 'gf_api';
	const GFLOW_API = 'gflow_api';
	const JS_CONFIG = 'js_config';

	public function register( GF_Service_Container $container ) {
		$container->add( self::GFLOW_API, function () {
			return new Gravity_Flow_API( false );
		} );

		$container->add( self::GF_API, function () {
			return new GFAPI();
		} );

		$container->add( self::JS_CONFIG, function () {
			return new JS_Config();
		} );
	}

	public function init( GF_Service_Container $container ) {
		add_action( 'gravityflow_enqueue_admin_scripts', function () use ( $container ) {
			$container->get( self::JS_CONFIG )->localize_admin_config();
		}, -10 );

		add_action( 'wp_print_footer_scripts', function () use ( $container ) {
			$container->get( self::JS_CONFIG )->localize_theme_config();
		}, -10 );
	}
}