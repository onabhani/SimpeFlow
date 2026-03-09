<?php
/**
 * Quality Gate — Audit, history, and fixed-log functions.
 *
 * Extracted from quality-gate.php for maintainability.
 * All functions are guarded with function_exists() where needed.
 *
 * @package SFA\QualityGate
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// --- Audit table installation ---------------------------------------------------

/**
 * Create the sfa_qg_audit table (runs on plugin activation).
 */
function sfa_qg_install_audit_table() {
	global $wpdb;
	$tbl     = $wpdb->prefix . 'sfa_qg_audit';
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE IF NOT EXISTS `$tbl` (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event_type VARCHAR(8) NOT NULL,           -- 'fail' | 'fix'
		form_id BIGINT UNSIGNED NOT NULL,
		entry_id BIGINT UNSIGNED NOT NULL,
		item_label VARCHAR(255) NULL,             -- human label (e.g., file name or QC metric label)
		metric_key VARCHAR(190) NULL,             -- stable key for grouping
		user_id BIGINT UNSIGNED NULL,
		note TEXT NULL,
		extra LONGTEXT NULL,                       -- JSON snapshot if you want
		event_utc DATETIME NOT NULL,               -- stored in UTC
		PRIMARY KEY (id),
		KEY k_time (event_utc),
		KEY k_form (form_id),
		KEY k_entry (entry_id),
		KEY k_type (event_type),
		KEY k_metric (metric_key),
		KEY k_exist (event_type, entry_id, metric_key)
	) $charset;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Ensure the audit table exists at runtime (no need to re-activate the plugin).
 * Uses an option flag to avoid SHOW TABLES on every request.
 */
function sfa_qg_maybe_install_audit_table() {
	// Fast path: option flag already confirmed the table exists.
	if ( get_option( 'sfa_qg_audit_table_ready' ) === '1' ) {
		return;
	}

	global $wpdb;
	$tbl = $wpdb->prefix . 'sfa_qg_audit';

	$pattern = $wpdb->esc_like( $tbl );
	$exists  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $pattern ) );

	if ( $exists !== $tbl ) {
		sfa_qg_install_audit_table();
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $pattern ) );
	}
	update_option( 'sfa_qg_audit_table_ready', $exists === $tbl ? '1' : '0', true );
}

// --- Audit row queries -----------------------------------------------------------

/** Check if a fail audit row exists for entry+metric_key (global scope). */
if ( ! function_exists( 'sfa_qg_audit_fail_exists' ) ) {
	function sfa_qg_audit_fail_exists( $entry_id, $metric_key ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'sfa_qg_audit';
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $tbl WHERE event_type = 'fail' AND entry_id = %d AND metric_key = %s LIMIT 1",
				(int) $entry_id,
				(string) $metric_key
			)
		);
	}
}

// --- Audit log insert ------------------------------------------------------------

function sfa_qg_audit_log( $type, $args ) {
	global $wpdb;
	$tbl = $wpdb->prefix . 'sfa_qg_audit';
	if ( ! in_array( $type, array( 'fail', 'fix' ), true ) ) {
		return false;
	}

	$now_utc = gmdate( 'Y-m-d H:i:s' );
	$ins = array(
		'event_type' => $type,
		'form_id'    => (int) ( $args['form_id'] ?? 0 ),
		'entry_id'   => (int) ( $args['entry_id'] ?? 0 ),
		'item_label' => (string) ( $args['item_label'] ?? '' ),
		'metric_key' => (string) ( $args['metric_key'] ?? '' ),
		'user_id'    => (int) ( $args['user_id'] ?? get_current_user_id() ),
		'note'       => (string) ( $args['note'] ?? '' ),
		'extra'      => isset( $args['extra'] ) ? wp_json_encode( $args['extra'] ) : null,
		'event_utc'  => (string) ( $args['event_utc'] ?? $now_utc ),
	);
	$fmt = array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' );

	$ok = $wpdb->insert( $tbl, $ins, $fmt );

	if ( false === $ok ) {
		return false;
	}

	return true;
}

function sfa_qg_audit_log_fail( $form_id, $entry_id, $metric_key, $item_label = '', $note = '', $extra = array() ) {
	return sfa_qg_audit_log( 'fail', compact( 'form_id', 'entry_id', 'metric_key', 'item_label', 'note', 'extra' ) );
}
function sfa_qg_audit_log_fix( $form_id, $entry_id, $metric_key, $item_label = '', $note = '', $extra = array() ) {
	return sfa_qg_audit_log( 'fix', compact( 'form_id', 'entry_id', 'metric_key', 'item_label', 'note', 'extra' ) );
}

// --- History & per-item metadata -------------------------------------------------

/** Append an event to the QC history meta. */
if ( ! function_exists( 'sfa_qg_history_push' ) ) {
	function sfa_qg_history_push( $entry_id, $event, $data = array() ) {
		$hist = json_decode( (string) gform_get_meta( $entry_id, '_qc_history' ), true );
		if ( ! is_array( $hist ) ) { $hist = array(); }
		$hist[] = array(
			'ts'    => current_time( 'mysql' ),
			'event' => (string) $event,
			'data'  => is_array( $data ) ? $data : array(),
		);
		gform_update_meta( $entry_id, '_qc_history', wp_json_encode( $hist ) );
	}
}

/** Key for per-item meta (safe, short). */
function sfa_qg_item_hash( $name ) {
	return substr( sha1( strtolower( remove_accents( (string) $name ) ) ), 0, 12 );
}

/** Stamp fail time once for every failed item AND guarantee an audit row exists. */
function sfa_qg_stamp_fail_times_if_missing( $entry_id, array $failed_names ) {
	$entry   = class_exists( 'GFAPI' ) ? \GFAPI::get_entry( (int) $entry_id ) : null;
	$form_id = ( is_array( $entry ) && isset( $entry['form_id'] ) ) ? (int) $entry['form_id'] : 0;

	foreach ( $failed_names as $n ) {
		$name = trim( (string) $n );
		if ( $name === '' ) continue;

		$key         = '_qc_fail_time_' . sfa_qg_item_hash( $name );
		$when_local  = (string) gform_get_meta( (int) $entry_id, $key );
		if ( ! $when_local ) {
			$when_local = current_time( 'mysql', false );
			gform_update_meta( (int) $entry_id, $key, $when_local );
		}

		// Ensure there is a corresponding audit "fail" event (idempotent).
		$mk = 'item:' . sanitize_title( $name );
		if ( ! sfa_qg_audit_fail_exists( (int) $entry_id, $mk ) ) {
			sfa_qg_audit_log( 'fail', array(
				'form_id'    => $form_id,
				'entry_id'   => (int) $entry_id,
				'metric_key' => $mk,
				'item_label' => $name,
				'event_utc'  => get_gmt_from_date( $when_local, 'Y-m-d H:i:s' ),
				'extra'      => array( 'source' => 'ensure/migrate' ),
			) );
		}
	}
}

// --- Fixed-log management --------------------------------------------------------

/** Read per-entry fixed events log. */
function sfa_qg_fixed_log_get( $entry_id ) {
	$log = json_decode( (string) gform_get_meta( (int) $entry_id, '_qc_fixed_log' ), true );
	return is_array( $log ) ? $log : array();
}

/** Write per-entry fixed events log. */
function sfa_qg_fixed_log_set( $entry_id, array $log ) {
	gform_update_meta( (int) $entry_id, '_qc_fixed_log', wp_json_encode( array_values( $log ) ) );
}

/**
 * Append fixed events for NEW items (ignore items already logged).
 * Returns the events actually added.
 */
function sfa_qg_fixed_log_append_items( $form_id, $entry_id, array $items, $step_id = 0 ) {
	$now   = current_time( 'mysql', false );
	$log   = sfa_qg_fixed_log_get( $entry_id );
	$seen  = array();
	foreach ( $log as $ev ) {
		// Dedupe key includes step_id so same item fixed in different steps is preserved
		$ev_step = (int) ( $ev['step_id'] ?? 0 );
		$seen[ strtolower( (string) ( $ev['item'] ?? '' ) ) . '|step:' . $ev_step ] = true;
	}

	$added = array();
	foreach ( $items as $name ) {
		$name = trim( (string) $name );
		if ( $name === '' ) continue;

		$norm = strtolower( $name ) . '|step:' . (int) $step_id;
		if ( isset( $seen[ $norm ] ) ) continue; // already logged for this item+step

		$failed_at = gform_get_meta( (int) $entry_id, '_qc_fail_time_' . sfa_qg_item_hash( $name ) );
		$duration  = null;
		if ( $failed_at ) {
			$duration = max( 0, strtotime( $now ) - strtotime( $failed_at ) );
		}

		$event = array(
			'form_id'          => (int) $form_id,
			'entry_id'         => (int) $entry_id,
			'item'             => $name,
			'failed_at'        => $failed_at ?: null,
			'fixed_at'         => $now,
			'fixed_by'         => get_current_user_id() ?: 0,
			'step_id'          => (int) $step_id,
			'duration_seconds' => $duration,
		);
		$log[]   = $event;
		$added[] = $event;

		sfa_qg_audit_log_fix( $form_id, (int) $entry_id, 'item:' . sanitize_title( $name ), $name );
	}

	if ( $added ) {
		sfa_qg_fixed_log_set( $entry_id, $log );
		sfa_qg_history_push( $entry_id, 'FIXED_LOGGED', array(
			'count' => count( $added ),
			'items' => wp_list_pluck( $added, 'item' ),
		) );
	}

	return $added;
}

// --- Entry deletion cleanup ------------------------------------------------------

/**
 * Remove all QC-related entry meta when an entry is deleted.
 * Covers both explicit keys and per-item fail-time keys.
 */
function sfa_qg_cleanup_entry_meta( $entry_id ) {
	$entry_id = (int) $entry_id;
	if ( ! $entry_id ) return;

	// Explicit QC meta keys
	$qc_keys = array(
		'_qc_summary',
		'_qc_failed_items',
		'_qc_failed_metrics',
		'_qc_recheck_items',
		'_qc_fixed_log',
		'_qc_history',
		'_qc_nodata',
		'_qc_pending_noted',
	);
	foreach ( $qc_keys as $key ) {
		gform_delete_meta( $entry_id, $key );
	}

	// Per-item fail-time keys (_qc_fail_time_*)
	global $wpdb;
	$tbl = $wpdb->prefix . 'gf_entry_meta';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM $tbl WHERE entry_id = %d AND meta_key LIKE %s",
			$entry_id,
			'_qc_fail_time_%'
		)
	);
}
add_action( 'gform_delete_entry', 'sfa_qg_cleanup_entry_meta', 10, 1 );
