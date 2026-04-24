<?php
namespace SFA\EntryCreator\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'sfa_entry_creator_log';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT(20) UNSIGNED NOT NULL,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			old_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			new_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			changed_by BIGINT(20) UNSIGNED NOT NULL,
			changed_at DATETIME NOT NULL,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			reason TEXT NULL,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY changed_by (changed_by),
			KEY changed_at (changed_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function uninstall() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'sfa_entry_creator_log';
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		delete_option( 'sfa_ec_db_version' );
	}
}
