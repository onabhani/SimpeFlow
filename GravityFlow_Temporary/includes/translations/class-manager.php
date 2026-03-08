<?php

namespace Gravity_Flow\Gravity_Flow\Translations;

use GFCommon;

defined( 'ABSPATH' ) || die();

/**
 * Class Manager
 * @package Gravity_Flow\Gravity_Flow\Translations
 *
 * @since 2.7.5
 */
class Manager {

	const T15S_TRANSIENT_KEY = 't15s-registry-gflow';
	const T15S_API_URL = 'https://packages.translationspress.com/gravityflow/packages.json';

	/**
	 * The plugin slug.
	 *
	 * @since 2.7.5
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * The locales installed during the current request.
	 *
	 * @since 2.7.5
	 *
	 * @var array
	 */
	private $installed = array();

	/**
	 * Cached TranslationsPress data for all Gravity Flow plugins.
	 *
	 * @since 2.7.5
	 *
	 * @var null|object
	 */
	private static $all_translations;

	/**
	 * The current instances of this class with the slugs as the keys.
	 *
	 * @since 2.7.5
	 *
	 * @var Manager[]
	 */
	private static $_instances = array();

	/**
	 * Returns an instance of this class for the given slug.
	 *
	 * @since 2.7.5
	 *
	 * @param string $slug The plugin slug.
	 *
	 * @return Manager
	 */
	public static function get_instance( $slug ) {
		if ( empty( self::$_instances[ $slug ] ) ) {
			self::$_instances[ $slug ] = new self( $slug );
		}

		return self::$_instances[ $slug ];
	}

	/**
	 * Adds a new project to load translations for.
	 *
	 * @since 2.7.5
	 *
	 * @param string $slug The plugin slug.
	 */
	private function __construct( $slug ) {
		$this->slug = $slug;

		if ( 'gravityflow' === $slug ) {
			// Translations data for all Gravity Flow plugins is stored together so we only need to add this hook once.
			add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'refresh_all_translations' ) );
		}

		add_action( 'update_option_WPLANG', array( $this, 'install_on_wplang_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'upgrader_process_complete' ), 10, 2 );

		add_filter( 'translations_api', array( $this, 'translations_api' ), 10, 3 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'site_transient_update_plugins' ) );
	}

	/**
	 * Short-circuits translations API requests for private projects.
	 *
	 * @since 2.7.5
	 *
	 * @param bool|array $result         The result object. Default false.
	 * @param string     $requested_type The type of translations being requested.
	 * @param object     $args           Translation API arguments.
	 *
	 * @return bool|array
	 */
	public function translations_api( $result, $requested_type, $args ) {
		if ( 'plugins' !== $requested_type || $this->slug !== $args['slug'] ) {
			return $result;
		}

		return $this->get_plugin_translations();
	}

	/**
	 * Filters the translations transients to include the current plugin.
	 *
	 * @since 2.7.5
	 *
	 * @param mixed $value The transient value.
	 *
	 * @return object
	 * @see   wp_get_translation_updates()
	 *
	 */
	public function site_transient_update_plugins( $value ) {
		if ( ! is_object( $value ) ) {
			$value = new \stdClass();
		}

		if ( ! isset( $value->translations ) ) {
			$value->translations = array();
		}

		$translations = $this->get_plugin_translations();

		if ( empty( $translations['translations'] ) ) {
			return $value;
		}

		foreach ( $translations['translations'] as $translation ) {
			if ( ! $this->should_install( $translation ) ) {
				continue;
			}

			$translation['type'] = 'plugin';
			$translation['slug'] = $this->slug;

			$value->translations[] = $translation;
		}

		return $value;
	}

	/**
	 * Gets the TranslationsPress data for the current plugin.
	 *
	 * @since 2.7.5
	 *
	 * @return array
	 */
	private function get_plugin_translations() {
		self::set_all_translations();

		return (array) rgar( self::$all_translations->projects, $this->slug );
	}

	/**
	 * Refreshes the cached TranslationsPress data, if expired.
	 *
	 * @since 2.7.5
	 */
	public static function refresh_all_translations() {
		static $done;

		if ( $done ) {
			return;
		}

		self::$all_translations = null;
		self::set_all_translations();
		$done = true;
	}

	/**
	 * Determines if the cached TranslationsPress data needs refreshing.
	 *
	 * @since 2.7.5
	 *
	 * @return bool
	 */
	private static function is_transient_expired() {
		$cache_lifespan = 12 * HOUR_IN_SECONDS;

		return ! isset( self::$all_translations->_last_checked ) || ( time() - self::$all_translations->_last_checked ) > $cache_lifespan;
	}

	/**
	 * Gets the translations data from the TranslationsPress API.
	 *
	 * @since 2.7.5
	 *
	 * @return array
	 */
	private static function get_remote_translations_data() {
		$result = json_decode( wp_remote_retrieve_body( wp_remote_get( self::T15S_API_URL, array( 'timeout' => 3 ) ) ), true );

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Caches the TranslationsPress data, if not already cached.
	 *
	 * @since 2.7.5
	 */
	private static function set_all_translations() {
		if ( is_object( self::$all_translations ) ) {
			return;
		}

		self::$all_translations = get_site_transient( self::T15S_TRANSIENT_KEY );
		if ( is_object( self::$all_translations ) && ! self::is_transient_expired() ) {
			return;
		}

		self::$all_translations                = new \stdClass();
		self::$all_translations->projects      = self::get_remote_translations_data();
		self::$all_translations->_last_checked = time();
		set_site_transient( self::T15S_TRANSIENT_KEY, self::$all_translations );
	}

	/**
	 * Triggers translation installation when the add-on version changes, for older GF versions.
	 *
	 * @since 2.7.5
	 *
	 * @param \GFAddOn $add_on The add-on instance.
	 */
	public function legacy_install_on_setup( $add_on ) {
		if ( $add_on->is_gravityforms_supported( '2.5.6' ) ) {
			return;
		}

		$installed_version = get_option( 'gravityformsaddon_' . $add_on->get_slug() . '_version' );

		if ( $installed_version != $add_on->get_version() ) {
			$installed_version = \GFForms::get_wp_option( 'gravityformsaddon_' . $add_on->get_slug() . '_version' );
		}

		if ( $installed_version == $add_on->get_version() ) {
			return;
		}

		$this->install();
	}

	/**
	 * Triggers translation installation when a user updates the site language setting, for older GF versions.
	 *
	 * @since 2.7.5
	 *
	 * @param string $old_language The language before the user changed the site language setting.
	 * @param string $new_language The new language after the user changed the site language setting.
	 */
	public function install_on_wplang_update( $old_language, $new_language ) {
		if ( gravity_flow()->is_gravityforms_supported( '2.5.6' ) || empty( $new_language ) || ! current_user_can( 'install_languages' ) ) {
			return;
		}

		$this->install( $new_language );
	}

	/**
	 * Triggers translation installation, if required.
	 *
	 * @since 2.7.5
	 *
	 * @param string $locale The locale when the site locale is changed or an empty string to install all the user available locales.
	 */
	public function install( $locale = '' ) {
		if ( $locale && in_array( $locale, $this->installed ) ) {
			return;
		}

		$translations = $this->get_plugin_translations();

		if ( empty( $translations['translations'] ) ) {
			gravity_flow()->log_error( __METHOD__ . sprintf( '(): Aborting; No translations list for %s.', $this->slug ) );

			return;
		}

		foreach ( $translations['translations'] as $translation ) {
			if ( ! $this->should_install( $translation, $locale ) ) {
				continue;
			}

			$this->install_translation( $translation );

			if ( $locale ) {
				return;
			}
		}
	}

	/**
	 * Downloads and installs the given translation.
	 *
	 * @since 2.7.5
	 *
	 * @param array $translation The translation data.
	 */
	private function install_translation( $translation ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . '/wp-admin/includes/admin.php';

			// Same approach as in WP Core: https://github.com/WordPress/wordpress-develop/blob/6.2/src/wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php#L853-L869.
			ob_start();
			$filesystem_credentials_available = request_filesystem_credentials( self_admin_url() );
			ob_end_clean();

			if ( ! $filesystem_credentials_available ) {
				gravity_flow()->log_error( __METHOD__ . '(): Aborting; filesystem credentials required.' );

				return;
			}

			if ( ! \WP_Filesystem() ) {
				gravity_flow()->log_error( __METHOD__ . '(): Aborting; unable to init WP_Filesystem.' );

				return;
			}
		}

		$lang_dir = $this->get_path();
		if ( ! $wp_filesystem->is_dir( $lang_dir ) ) {
			$wp_filesystem->mkdir( $lang_dir, FS_CHMOD_DIR );
		}

		gravity_flow()->log_debug( __METHOD__ . '(): Downloading: ' . $translation['package'] );
		$temp_file = download_url( $translation['package'] );

		if ( is_wp_error( $temp_file ) ) {
			gravity_flow()->log_error( __METHOD__ . '(): Error downloading package. Code: ' . $temp_file->get_error_code() . '; Message: ' . $temp_file->get_error_message() );

			return;
		}

		$zip_path    = $lang_dir . $this->slug . '-' . $translation['language'] . '.zip';
		$copy_result = $wp_filesystem->copy( $temp_file, $zip_path, true, FS_CHMOD_FILE );
		$wp_filesystem->delete( $temp_file );

		if ( ! $copy_result ) {
			gravity_flow()->log_error( __METHOD__ . '(): Unable to move package to: ' . $lang_dir );

			return;
		}

		$result = unzip_file( $zip_path, $lang_dir );
		@unlink( $zip_path );

		if ( is_wp_error( $result ) ) {
			gravity_flow()->log_error( __METHOD__ . '(): Error extracting package. Code: ' . $result->get_error_code() . '; Message: ' . $result->get_error_message() );

			return;
		}

		gravity_flow()->log_debug( __METHOD__ . sprintf( '(): Installed %s translation for %s.', $translation['language'], $this->slug ) );
		$this->installed[] = $translation['language'];
	}

	/**
	 * Logs which locales WordPress installs translations for.
	 *
	 * @since 2.7.5
	 *
	 * @param object $upgrader_object WP_Upgrader Instance.
	 * @param array  $hook_extra      Item update data.
	 */
	public function upgrader_process_complete( $upgrader_object, $hook_extra ) {
		if ( rgar( $hook_extra, 'type' ) !== 'translation' || empty( $hook_extra['translations'] ) || empty( $upgrader_object->result ) || is_wp_error( $upgrader_object->result ) ) {
			return;
		}

		$locales = array();

		foreach ( $hook_extra['translations'] as $translation ) {
			if ( rgar( $translation, 'type' ) !== 'plugin' || rgar( $translation, 'slug' ) !== $this->slug ) {
				continue;
			}

			$locales[] = $translation['language'];
		}

		if ( empty( $locales ) ) {
			return;
		}

		$this->installed = $locales;
		gravity_flow()->log_debug( __METHOD__ . sprintf( '(): WordPress installed %s translation(s) for %s.', implode( ', ', $locales ), $this->slug ) );
	}

	/**
	 * Returns an array of locales the site has installed.
	 *
	 * @since 2.7.5
	 *
	 * @return array
	 */
	private function get_available_languages() {
		static $languages = array();

		if ( empty( $languages ) ) {
			$languages = get_available_languages();
		}

		return $languages;
	}

	/**
	 * Returns the header data from the installed translations for the current plugin.
	 *
	 * @since 2.7.5
	 *
	 * @return array
	 */
	private function get_installed_translations_data() {
		static $data = array();

		if ( isset( $data[ $this->slug ] ) ) {
			return $data[ $this->slug ];
		}

		$data[ $this->slug ] = array();
		$translations        = $this->get_installed_translations( true );

		foreach ( $translations as $locale => $mo_file ) {
			$po_file = str_replace( '.mo', '.po', $mo_file );
			if ( ! file_exists( $po_file ) ) {
				continue;
			}
			$data[ $this->slug ][ $locale ] = wp_get_pomo_file_data( $po_file );
		}

		return $data[ $this->slug ];
	}

	/**
	 * Returns an array of locales or mo translation files found in the WP_LANG_DIR/plugins directory.
	 *
	 * @since 2.7.5
	 *
	 * @param bool $return_files Indicates if the mo files should be returned using the locales as the keys.
	 *
	 * @return array
	 */
	public function get_installed_translations( $return_files = false ) {
		if ( method_exists( 'GFCommon', 'get_installed_translations' ) ) {
			return GFCommon::get_installed_translations( $this->slug, $return_files );
		}

		$files = GFCommon::glob( $this->slug . '-*.mo', WP_LANG_DIR . '/plugins/' );

		if ( ! is_array( $files ) ) {
			return array();
		}

		$translations = array();

		foreach ( $files as $file ) {
			$translations[ str_replace( $this->slug . '-', '', basename( $file, '.mo' ) ) ] = $file;
		}

		return $return_files ? $translations : array_keys( $translations );
	}

	/**
	 * Returns the path to where plugin translations are stored.
	 *
	 * @since 2.7.5
	 *
	 * @return string
	 */
	private function get_path() {
		return WP_LANG_DIR . '/plugins/';
	}

	/**
	 * Determines if a translation should be installed.
	 *
	 * @since 2.7.5
	 *
	 * @param array  $translation The translation data.
	 * @param string $locale      The locale when the site locale is changed or an empty string to check all the user available locales.
	 *
	 * @return bool
	 */
	private function should_install( $translation, $locale = '' ) {
		if ( ( $locale && $locale !== $translation['language'] ) || ! in_array( $translation['language'], $this->get_available_languages() ) ) {
			return false;
		}

		if ( empty( $translation['updated'] ) ) {
			return true;
		}

		$installed = $this->get_installed_translations_data();

		if ( ! isset( $installed[ $translation['language'] ] ) ) {
			return true;
		}

		$local  = date_create( $installed[ $translation['language'] ]['PO-Revision-Date'] );
		$remote = date_create( $translation['updated'] );

		return $remote > $local;
	}

}
