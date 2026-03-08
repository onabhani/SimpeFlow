<?php

namespace Gravity_Flow\Gravity_Flow\Blocks;

use Gravity_Flow\Gravity_Flow\Ajax\Ajax_Service_Provider;
use Gravity_Flow\Gravity_Flow\Blocks\Registrees\Inbox;
use Gravity_Flow\Gravity_Flow\Blocks\Registrees\Reports;
use Gravity_Flow\Gravity_Flow\Blocks\Registrees\Status;
use Gravity_Flow\Gravity_Flow\Blocks\Registrees\Submit;
use Gravity_Flow\Gravity_Flow\Inbox\Inbox_Service_Provider;
use Gravity_Forms\Gravity_Forms\GF_Service_Provider;
use Gravity_Forms\Gravity_Forms\GF_Service_Container;

use Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Entries\Config as Inbox_Entries_Config;
use Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Entries\Endpoint as Inbox_Entries_Endpoint;

use Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Forms\Config as Inbox_Forms_Config;
use Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Forms\Endpoint as Inbox_Forms_Endpoint;

use Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Form_Steps\Config as Inbox_Form_Steps_Config;
use Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Inbox_Form_Steps\Endpoint as Inbox_Form_Steps_Endpoint;

use Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Reports\Config as Reports_Config;
use Gravity_Flow\Gravity_Flow\Blocks\Endpoints\Reports\Endpoint as Reports_Endpoint;

/**
 * Block Service Provider
 *
 * Provides the services to enable the Gravity Flow Blocks.
 *
 * @since 2.8
 */
class Blocks_Service_Provider extends GF_Service_Provider {


	const INBOX_ENTRIES_ENDPOINT    = 'inbox_entries_endpoint';
	const INBOX_FORMS_ENDPOINT      = 'inbox_forms_endpoint';
	const INBOX_FORM_STEPS_ENDPOINT = 'inbox_form_steps_endpoint';
	const REPORTS_ENDPOINT          = 'reports_endpoint';

	const INBOX_BLOCK   = 'inbox_block';
	const REPORTS_BLOCK = 'reports_block';
	const STATUS_BLOCK  = 'status_block';
	const SUBMIT_BLOCK  = 'submit_block';

	/**
	 * The endpoints this provides.
	 *
	 * @since 2.8
	 *
	 * @var string[]
	 */
	protected $endpoints = array(
		self::INBOX_ENTRIES_ENDPOINT,
		self::INBOX_FORMS_ENDPOINT,
		self::INBOX_FORM_STEPS_ENDPOINT,
		self::REPORTS_ENDPOINT,
	);

	/**
	 * The blocks this provides.
	 *
	 * @since 2.8
	 *
	 * @var string[]
	 */
	protected $blocks = array(
		self::INBOX_BLOCK,
		self::REPORTS_BLOCK,
		self::STATUS_BLOCK,
		self::SUBMIT_BLOCK,
	);

	/**
	 * Register the services.
	 *
	 * @since 2.8
	 *
	 * @param GF_Service_Container $container
	 *
	 * @return void
	 */
	public function register( GF_Service_Container $container ) {
		$container->add( self::INBOX_ENTRIES_ENDPOINT, function () use ( $container ) {
			$config = new Inbox_Entries_Config();

			return new Inbox_Entries_Endpoint( $config, $container->get( Ajax_Service_Provider::RESPONSE_FACTORY ), $container->get( Inbox_Service_Provider::TASK_MODEL ) );
		} );

		$container->add( self::INBOX_FORMS_ENDPOINT, function () use ( $container ) {
			$config = new Inbox_Forms_Config();

			return new Inbox_Forms_Endpoint( $config, $container->get( Ajax_Service_Provider::RESPONSE_FACTORY ), $container->get( Inbox_Service_Provider::TASK_MODEL ) );
		} );

		$container->add( self::INBOX_FORM_STEPS_ENDPOINT, function () use ( $container ) {
			$config = new Inbox_Form_Steps_Config();

			return new Inbox_Form_Steps_Endpoint( $config, $container->get( Ajax_Service_Provider::RESPONSE_FACTORY ), $container->get( Inbox_Service_Provider::TASK_MODEL ) );
		} );

		$container->add( self::REPORTS_ENDPOINT, function () use ( $container ) {
			$config = new Reports_Config();

			return new Reports_Endpoint( $config, $container->get( Ajax_Service_Provider::RESPONSE_FACTORY ), $container->get( Inbox_Service_Provider::TASK_MODEL ) );
		} );

		$container->add( self::INBOX_BLOCK, function () use ( $container ) {
			return new Inbox();
		} );

		$container->add( self::REPORTS_BLOCK, function () use ( $container ) {
			return new Reports();
		} );

		$container->add( self::STATUS_BLOCK, function () use ( $container ) {
			return new Status();
		} );

		$container->add( self::SUBMIT_BLOCK, function () use ( $container ) {
			return new Submit();
		} );
	}

	/**
	 * Initialize hooks and filters.
	 *
	 * @since 2.8
	 *
	 * @param GF_Service_Container $container
	 *
	 * @return void
	 */
	public function init( GF_Service_Container $container ) {
		$endpoints = $this->endpoints;
		$blocks    = $this->blocks;

		add_action( 'rest_api_init', function () use ( $container, $endpoints ) {
			foreach ( $endpoints as $ep_name ) {
				/**
				 * @var \Gravity_Flow\Gravity_Flow\Ajax\Endpoint $endpoint
				 */
				$endpoint = $container->get( $ep_name );

				$endpoint->register_routes();
			}
		} );

		add_filter( 'gravityflow_js_config_shared', function ( $config ) use ( $container, $endpoints ) {
			if ( ! isset( $config['endpoints'] ) ) {
				$config['endpoints'] = array();
			}

			$config['endpoints'] = array_merge( $config['endpoints'], $this->get_endpoints( $container, $endpoints ) );

			return $config;
		}, 15, 1 );

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );

		add_action( 'init', function () use ( $container, $blocks ) {
			if ( class_exists( 'Gravity_Flow_Blocks_Bootstrap' ) ) {
				return;
			}

			foreach ( $blocks as $block ) {
				/**
				 * @var Block_Registree $registree
				 */
				$registree = $container->get( $block );
				$registree->register();
				$registree->register_fields();
			}
		}, 10, 0 );

		add_action( 'admin_notices', function() {
			if ( ! class_exists( 'Gravity_Flow_Blocks_Bootstrap' ) ) {
				return;
			}

			$message = __( 'It looks like you\'ve got the Gravity Flow Blocks plugin enabled. In the latest version of Gravity Flow, the Editor Blocks are included, and you no longer need the Blocks Plugin enabled.', 'gravityflow' );

			printf( '<div class="notice below-h1 notice-error gf-notice"><p>%1$s</p></div>', esc_html( $message ) );
		});
	}

	/**
	 * Get the endpoints for this provider.
	 *
	 * @since 2.8
	 *
	 * @param $container
	 * @param $endpoints
	 *
	 * @return array
	 */
	private function get_endpoints( $container, $endpoints ) {
		$response = array();

		foreach ( $endpoints as $ep_name ) {
			/**
			 * @var \Gravity_Flow\Gravity_Flow\Ajax\Endpoint $endpoint
			 */
			$endpoint = $container->get( $ep_name );

			$response[ $endpoint->get_name() ] = array(
				'path'        => $endpoint->get_base_route(),
				'rest_params' => $endpoint->get_rest_param_string(),
				'nonce'       => null,
			);
		}

		return $response;
	}

	/**
	 * Enqueue the assets for this provider.
	 *
	 * @since 2.8
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';

		$block_path = "/assets/js/dist/editor.blocks{$min}.js";
		$style_path = '/css/blocks.editor.css';

		// Enqueue the bundled block JS file with necessary dependencies
		$deps = array(
			'wp-i18n',
			'wp-element',
			'wp-blocks',
			'wp-components'
		);

		global $pagenow;
		if( $pagenow !== 'widgets.php' ) {
			$deps[] = 'wp-editor';
		}
		wp_enqueue_script(
			'gravityflow-blocks-js',
			gravity_flow()->get_base_url() . $block_path,
			$deps,
			filemtime( gravity_flow()->get_base_path() . $block_path )
		);

		// Enqueue optional editor only styles
		wp_enqueue_style(
			'gravityflow-blocks-editor-css',
			gravity_flow()->get_base_url() . $style_path,
			array(),
			filemtime( gravity_flow()->get_base_path() . $style_path )
		);

		// Enqueue scripts for Reports.
		wp_enqueue_script( 'google_charts', 'https://www.gstatic.com/charts/loader.js', array(), gravity_flow()->_version );

		wp_enqueue_script( 'gravityflow_reports', gravity_flow()->get_base_url() . "/js/reports{$min}.js", array(
			'jquery',
			'google_charts'
		), gravity_flow()->_version );
	}
}
