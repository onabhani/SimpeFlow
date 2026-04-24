<?php
namespace SFA\EntryCreator\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogRepository {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sfa_entry_creator_log';
	}

	/**
	 * Insert an audit log row.
	 *
	 * @param array{entry_id:int,form_id:int,old_user_id:int,new_user_id:int,changed_by:int,ip_address:string,reason:?string} $data
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$row = array(
			'entry_id'    => absint( $data['entry_id'] ?? 0 ),
			'form_id'     => absint( $data['form_id'] ?? 0 ),
			'old_user_id' => absint( $data['old_user_id'] ?? 0 ),
			'new_user_id' => absint( $data['new_user_id'] ?? 0 ),
			'changed_by'  => absint( $data['changed_by'] ?? 0 ),
			'changed_at'  => current_time( 'mysql' ),
			'ip_address'  => substr( (string) ( $data['ip_address'] ?? '' ), 0, 45 ),
			'reason'      => isset( $data['reason'] ) ? (string) $data['reason'] : null,
		);

		$formats = array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' );

		$result = $wpdb->insert( self::table(), $row, $formats );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetch audit log rows for an entry, newest first.
	 *
	 * @return array<int,object>
	 */
	public static function get_for_entry( int $entry_id, int $limit = 50 ) {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, entry_id, form_id, old_user_id, new_user_id, changed_by, changed_at, ip_address, reason
				 FROM {$table}
				 WHERE entry_id = %d
				 ORDER BY changed_at DESC, id DESC
				 LIMIT %d",
				$entry_id,
				$limit
			)
		);
	}
}
