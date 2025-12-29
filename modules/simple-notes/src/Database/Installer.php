<?php
namespace SFA\SimpleNotes\Database;

/**
 * Database Installer for Simple Notes
 */
class Installer {

	public static function install() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'simple_notes';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			entity_type varchar(50) NOT NULL,
			entity_id varchar(50) NOT NULL,
			content text NOT NULL,
			author_id bigint(20) NOT NULL,
			author_name varchar(100) NOT NULL,
			mentioned_users text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY entity_lookup (entity_type, entity_id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		// Set default settings
		if ( ! get_option( 'simple_notes_mention_roles' ) ) {
			update_option( 'simple_notes_mention_roles', array( 'administrator', 'editor', 'author' ) );
		}
		if ( ! get_option( 'simple_notes_email_notifications' ) ) {
			update_option( 'simple_notes_email_notifications', 1 );
		}
		if ( ! get_option( 'simple_notes_email_from_name' ) ) {
			update_option( 'simple_notes_email_from_name', get_bloginfo( 'name' ) );
		}
		if ( ! get_option( 'simple_notes_email_from_email' ) ) {
			update_option( 'simple_notes_email_from_email', get_option( 'admin_email' ) );
		}

		error_log( 'Simple Notes Module: Database installed successfully' );
	}
}
