<?php

namespace Gravity_Flow\Gravity_Flow\Inbox;

use Gravity_Flow\Gravity_Flow\Ajax\Ajax_Service_Provider;
use Gravity_Flow\Gravity_Flow\Ajax\Response_Factory;

use Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Refresh_Inbox_Items\Config as Refresh_Items_Config;
use Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Refresh_Inbox_Items\Endpoint as Refresh_Items_Endpoint;

use Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Get_Preference\Config as Get_Prefs_Config;
use Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Get_Preference\Endpoint as Get_Prefs_Endpoint;

use Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Save_Preference\Config as Save_Prefs_Config;
use Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Save_Preference\Endpoint as Save_Prefs_Endpoint;

use Gravity_Flow\Gravity_Flow\Inbox\Models\Task;

use Gravity_Flow\Gravity_Flow\Inbox\Lang\Strings_I18n;

use Gravity_Forms\Gravity_Forms\GF_Service_Provider;
use Gravity_Forms\Gravity_Forms\GF_Service_Container;
use Gravity_Flow\Gravity_Flow\Util\Util_Service_Provider;
use Gravity_Flow\Gravity_Flow\Inbox\Models\Preferences;

use \Gravity_Flow_API;

/**
 * Inbox Service Provider
 *
 * Gathers and provides all the services for the Inbox to function.
 *
 * @since 2.8
 */
class Inbox_Service_Provider extends GF_Service_Provider {

	const AJAX_PREFIX = 'gflow_inbox_';

	const REFRESH_ITEMS_ENDPOINT   = 'refresh_items_endpoint';
	const GET_PREFERENCE_ENDPOINT  = 'get_prefs_endpoint';
	const SAVE_PREFERENCE_ENDPOINT = 'save_prefs_endpoint';

	const TASK_MODEL   = 'task_model';
	const DEFAULTS_MAP = 'defaults_map';
	const PREF_FETCHER = 'pref_fetcher';
	const STRINGS_I18N = 'strings_i18n';

	const FETCH_ENABLED       = 'fetch_enabled';
	const FETCH_INTERVAL      = 'fetch_interval';
	const PUSH_ENABLED        = 'push_enabled';
	const ITEMS_PER_PAGE      = 'items_per_page';
	const DEFAULT_SORT_COL    = 'default_sort_col';
	const DEFAULT_SORT_DIR    = 'default_sort_dir';
	const DEFAULT_DATE_FORMAT = 'default_date_format';

	/**
	 * The endpoints this provider provides.
	 *
	 * @since 2.8
	 *
	 * @var string[]
	 */
	protected $endpoints = array(
		self::REFRESH_ITEMS_ENDPOINT,
		self::GET_PREFERENCE_ENDPOINT,
		self::SAVE_PREFERENCE_ENDPOINT,
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
		$container->add( self::DEFAULTS_MAP, function () {
			/**
			 * Control the default rows per page for all inboxes.
			 *
			 * @since 2.8.3
			 */
			$page_count = apply_filters( 'gravityflow_inbox_items_per_page_default', 20 );

			return array(
				self::PUSH_ENABLED     => 0,
				self::FETCH_ENABLED    => 1,
				self::FETCH_INTERVAL   => 30,
				self::ITEMS_PER_PAGE   => $page_count,
				self::DEFAULT_SORT_COL => 'date_created',
				self::DEFAULT_SORT_DIR => 'desc',
			);
		} );

		$container->add( self::PREF_FETCHER, function () {
			return new Preferences();
		} );

		$container->add( self::TASK_MODEL, function () use ( $container ) {
			return new Task( $container->get( Util_Service_Provider::GFLOW_API ), $container->get( Util_Service_Provider::GF_API ) );
		} );


		$container->add( self::REFRESH_ITEMS_ENDPOINT, function () use ( $container ) {
			$config = new Refresh_Items_Config();

			return new Refresh_Items_Endpoint( $config, $container->get( Ajax_Service_Provider::RESPONSE_FACTORY ), $container->get( self::TASK_MODEL ) );
		} );

		$container->add( self::GET_PREFERENCE_ENDPOINT, function () use ( $container ) {
			$config = new Get_Prefs_Config();

			return new Get_Prefs_Endpoint( $config, $container->get( Ajax_Service_Provider::RESPONSE_FACTORY ), $container->get( self::PREF_FETCHER ) );
		} );

		$container->add( self::SAVE_PREFERENCE_ENDPOINT, function () use ( $container ) {
			$config = new Save_Prefs_Config();

			return new Save_Prefs_Endpoint( $config, $container->get( Ajax_Service_Provider::RESPONSE_FACTORY ), $container->get( self::PREF_FETCHER ) );
		} );

		$container->add( self::STRINGS_I18N, function() {
			return new Strings_I18n();
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
			if ( is_admin() && rgget( 'page' ) !== 'gravityflow-inbox' ) {
				return $config;
			}

			if ( gravity_flow()->is_workflow_detail_page() ) {
				return $config;
			}

			$config['site_url']                  = get_site_url();
			$config['plugin_url']                = gravity_flow()->get_base_url();
			$config[ self::DEFAULT_DATE_FORMAT ] = \GFCommon::get_default_date_format();
			$config['grids']                     = $this->get_config_for_grids( $container );
			$config['endpoints']                 = $this->get_endpoints( $container, $endpoints );

			return $config;
		}, 10, 1 );

		add_filter( 'gravityflow_js_config_shared', function ( $config ) use ( $container ) {
			$config['i18n'] = $container->get( self::STRINGS_I18N )->strings();

			return $config;
		}, 10, 1 );

		add_filter( 'gravityflow_inbox_field_value', function( $value, $form_id, $id, $entry ) use ( $container ) {
			if ( ! is_numeric( $id ) && strpos( $id, '_human_readable' ) === false ) {
				return $value;
			}

			return $container->get( self::TASK_MODEL )->get_date_field_values( $value, $form_id, $id, $entry );
		}, 10, 4 );
	}

	/**
	 * Get the endpoints to register.
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
	 * Get the configuration for a given grid.
	 *
	 * @since 2.8
	 *
	 * @param $container
	 *
	 * @return array[]
	 */
	private function get_config_for_grids( $container ) {
		/**
		 * @var Task $tasks
		 */
		$tasks = $container->get( self::TASK_MODEL );

		$shortcodes_on_page = $tasks->get_all_stored_shortcodes();
		$configs            = array();

		// Add the default admin shortcode values for display
		if ( rgget( 'page' ) == 'gravityflow-inbox' && empty( $shortcodes_on_page ) ) {
			$sc_args = $this->get_default_sc_args( $tasks );

			$shortcodes_on_page[] = $sc_args;
		}

		foreach ( $shortcodes_on_page as $id => $sc_args ) {
			$grid_id = $tasks->get_unique_grid_id_from_args( $sc_args );

			$grid_config['grid_options'] = array(
				'columnDefs'         => $tasks->get_table_header_defs( $sc_args ),
				'rowData'            => $tasks->get_inbox_tasks( $sc_args ),
				'pagination'         => true,
				'paginationPageSize' => (int) $this->get_pref( $container, self::ITEMS_PER_PAGE, $grid_id ),
				'searchArgs'         => $sc_args,
			);

			$grid_config['current_user_token']     = $this->get_user_token( $grid_id, $tasks );
			$grid_config[ self::PUSH_ENABLED ]     = (bool) $this->get_pref( $container, self::PUSH_ENABLED, $grid_id );
			$grid_config[ self::FETCH_ENABLED ]    = (bool) $this->get_pref( $container, self::FETCH_ENABLED, $grid_id );
			$grid_config[ self::FETCH_INTERVAL ]   = (int) $this->get_pref( $container, self::FETCH_INTERVAL, $grid_id );
			$grid_config[ self::DEFAULT_SORT_COL ] = $this->get_pref( $container, self::DEFAULT_SORT_COL, $grid_id );
			$grid_config[ self::DEFAULT_SORT_DIR ] = $this->get_pref( $container, self::DEFAULT_SORT_DIR, $grid_id );

			$configs[ $grid_id ] = $grid_config;
		}

		return $configs;
	}

	/**
	 * Get the default SC args for admin display.
	 *
	 * @since 2.8.3
	 *
	 * @param Task $tasks
	 *
	 * @return mixed|void
	 */
	private function get_default_sc_args( Task $tasks ) {
		$defaults = array(
			'display_empty_fields' => true,
			'check_permissions'    => true,
			'show_header'          => true,
			'timeline'             => true,
			'step_highlight'       => true,
			'due_date'             => false,
			'context_key'          => 'wp-admin',
			'back_link'            => false,
			'back_link_text'       => __( 'Return to list', 'gravityflow' ),
			'back_link_url'        => null,
		);

		$args = array_merge( $defaults, array() );
		$args = array_merge( \Gravity_Flow_Inbox::get_defaults(), $args );

		/**
		 * Allow the inbox page arguments to be overridden.
		 *
		 * @param array $args The inbox page arguments.
		 */
		$args = apply_filters( 'gravityflow_inbox_args', $args );

		return $args;
	}

	/**
	 * Get a preference by name and grid ID.
	 *
	 * @since 2.8
	 *
	 * @param $container
	 * @param $pref_name
	 * @param $grid_id
	 *
	 * @return bool
	 */
	protected function get_pref( $container, $pref_name, $grid_id ) {
		$user_id   = get_current_user_id();
		$view      = $grid_id;

		/**
		 * @var array $defaults_map
		 */
		$defaults_map = $container->get( self::DEFAULTS_MAP );

		/**
		 * @var Preferences $prefs
		 */
		$prefs = $container->get( self::PREF_FETCHER );

		return $prefs->get_setting(
			$pref_name,
			$user_id,
			$defaults_map[ $pref_name ],
			$view
		);
	}

	/**
	 * Get a user token from a filter key.
	 *
	 * @since 2.8
	 *
	 * @param $filter_key
	 * @param $tasks
	 *
	 * @return string
	 */
	protected function get_user_token( $filter_key, $tasks ) {
		$assignee = $tasks->get_assignee_from_filter_key( $filter_key );

		return gravity_flow()->generate_access_token( $assignee );
	}
}
