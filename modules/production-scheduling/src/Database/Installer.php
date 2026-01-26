<?php
namespace SFA\ProductionScheduling\Database;

/**
 * Database Installer
 *
 * Creates necessary database tables for production scheduling
 */
class Installer {

	/**
	 * Install database tables
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name = $wpdb->prefix . 'sfa_prod_capacity_overrides';

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			date DATE NOT NULL,
			custom_capacity INT NOT NULL,
			reason VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY date (date),
			KEY created_by (created_by),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Set default settings if not exist
		if ( get_option( 'sfa_prod_daily_capacity' ) === false ) {
			add_option( 'sfa_prod_daily_capacity', 10 );
		}

		if ( get_option( 'sfa_prod_working_days' ) === false ) {
			// Default: All days except Friday (5 = Friday in PHP's w format)
			add_option( 'sfa_prod_working_days', wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ) );
		}

		if ( get_option( 'sfa_prod_installation_buffer' ) === false ) {
			add_option( 'sfa_prod_installation_buffer', 0 ); // Same day as default
		}

		if ( get_option( 'sfa_prod_holidays' ) === false ) {
			add_option( 'sfa_prod_holidays', wp_json_encode( [] ) );
		}

		if ( get_option( 'sfa_prod_earliest_start_date' ) === false ) {
			add_option( 'sfa_prod_earliest_start_date', '' ); // Empty = use current date
		}
	}

	/**
	 * Uninstall (optional - for development)
	 */
	public static function uninstall() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'sfa_prod_capacity_overrides';
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		delete_option( 'sfa_prod_db_version' );
		delete_option( 'sfa_prod_daily_capacity' );
		delete_option( 'sfa_prod_working_days' );
		delete_option( 'sfa_prod_installation_buffer' );
		delete_option( 'sfa_prod_holidays' );
		delete_option( 'sfa_prod_earliest_start_date' );
	}
}
