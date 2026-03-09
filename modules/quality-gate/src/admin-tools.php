<?php
/**
 * Quality Gate — Admin debug/maintenance tools (backfill, cleanup, audit peek).
 *
 * Extracted from quality-gate.php for maintainability.
 * Each function is registered on admin_init at priority 99.
 *
 * @package SFA\QualityGate
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// === Admin backfill v2: build/repair audit "fail" rows from existing entries ===
function sfa_qg_admin_backfill() {
	if ( empty( $_GET['sfa_qg_backfill'] ) ) return;
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sfa_qg_backfill' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'simpleflow' ), 403 );
	}

	sfa_qg_install_audit_table();

	global $wpdb;
	$em    = $wpdb->prefix . 'gf_entry_meta';
	$e     = $wpdb->prefix . 'gf_entry';
	$limit = isset( $_GET['limit'] ) ? max( 1, min( 20000, (int) $_GET['limit'] ) ) : 2000;
	$force = ! empty( $_GET['force'] );

	// Pull entries that have saved failed items + join basic entry info.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT m.entry_id, m.meta_value, e.form_id, e.date_created
			 FROM $em m
			 INNER JOIN $e e ON e.id = m.entry_id
			 WHERE m.meta_key = %s
			 ORDER BY m.entry_id DESC
			 LIMIT %d",
			'_qc_failed_items',
			$limit
		),
		ARRAY_A
	);

	$scanned = 0; $added = 0; $skipped = 0;
	foreach ( (array) $rows as $r ) {
		$scanned++;
		$eid       = (int) $r['entry_id'];
		$form_id   = (int) $r['form_id'];
		$entry_when_local = (string) $r['date_created'];

		$list  = json_decode( (string) $r['meta_value'], true );
		$names = is_array( $list ) ? array_values( array_unique( array_filter( array_map( 'strval', $list ) ) ) ) : array();

		foreach ( $names as $name ) {
			$name = trim( (string) $name ); if ( $name === '' ) continue;

			// Prefer item fail-time meta; else fall back to entry date.
			$key         = '_qc_fail_time_' . sfa_qg_item_hash( $name );
			$when_local  = (string) gform_get_meta( $eid, $key );
			if ( ! $when_local ) $when_local = $entry_when_local ?: current_time( 'mysql', false );

			$mk     = 'item:' . sanitize_title( $name );
			$exists = sfa_qg_audit_fail_exists( $eid, $mk );

			if ( $force || ! $exists ) {
				sfa_qg_audit_log( 'fail', array(
					'form_id'    => $form_id,
					'entry_id'   => $eid,
					'metric_key' => $mk,
					'item_label' => $name,
					'event_utc'  => get_gmt_from_date( $when_local, 'Y-m-d H:i:s' ),
					'extra'      => array( 'source' => 'backfill', 'forced' => (int) $force ),
				) );
				$added++;
			} else {
				$skipped++;
			}
		}
	}

	wp_die(
		'<p>' . esc_html__( 'Backfill done.', 'simpleflow' ) . '</p>'
		. '<p>' . esc_html__( 'Scanned entries:', 'simpleflow' ) . ' <code>' . esc_html( (string) $scanned ) . '</code><br>'
		. esc_html__( 'New audit rows:', 'simpleflow' ) . ' <code>' . esc_html( (string) $added ) . '</code><br>'
		. esc_html__( 'Skipped (already existed):', 'simpleflow' ) . ' <code>' . esc_html( (string) $skipped ) . '</code></p>'
		. '<p>' . esc_html__( 'Tip: add &force=1 to re-create missing rows if needed.', 'simpleflow' ) . '</p>'
	);
}

// === Cleanup: remove QG meta from non-QG forms (admin only) ===
// Usage: /wp-admin/?sfa_qg_cleanup=1&_wpnonce=<nonce> (add &confirm=1 to actually delete)
function sfa_qg_admin_cleanup() {
	if ( empty( $_GET['sfa_qg_cleanup'] ) ) return;
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sfa_qg_cleanup' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'simpleflow' ), 403 );
	}

	global $wpdb;
	$em = $wpdb->prefix . 'gf_entry_meta';
	$e  = $wpdb->prefix . 'gf_entry';
	$confirm = ! empty( $_GET['confirm'] );

	// Get all form IDs that have any QC-related meta
	$meta_keys = array( '_qc_fixed_log', '_qc_recheck_items', '_qc_failed_items', '_qc_failed_metrics', '_qc_summary', '_qc_history' );
	$in_keys = "'" . implode( "','", $meta_keys ) . "'";
	// Also match per-item fail-time keys (_qc_fail_time_*)
	$forms_with_meta = $wpdb->get_col(
		"SELECT DISTINCT e.form_id FROM $e e INNER JOIN $em m ON m.entry_id = e.id WHERE m.meta_key IN ($in_keys) OR m.meta_key LIKE '_qc_fail_time_%'"
	);

	// Check which forms have quality_checklist fields
	$non_qc_forms = array();
	if ( class_exists( 'GFAPI' ) ) {
		foreach ( (array) $forms_with_meta as $fid ) {
			$fid = (int) $fid;
			$form = \GFAPI::get_form( $fid );
			$has_qc = false;
			if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
				foreach ( (array) $form['fields'] as $field ) {
					if ( rgar( (array) $field, 'type' ) === 'quality_checklist' ) {
						$has_qc = true;
						break;
					}
				}
			}
			if ( ! $has_qc ) {
				$non_qc_forms[] = $fid;
			}
		}
	}

	echo '<div class="wrap"><h1>' . esc_html__( 'QG Cleanup: Non-QC Form Meta', 'simpleflow' ) . '</h1>';

	if ( empty( $non_qc_forms ) ) {
		echo '<p>' . esc_html__( 'No polluted data found. All forms with QG meta have quality_checklist fields.', 'simpleflow' ) . '</p></div>';
		exit;
	}

	// Count entries with polluted data
	$in_forms = implode( ',', array_map( 'intval', $non_qc_forms ) );
	$count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT e.id) FROM $e e INNER JOIN $em m ON m.entry_id = e.id WHERE e.form_id IN ($in_forms) AND (m.meta_key IN ($in_keys) OR m.meta_key LIKE '_qc_fail_time_%')" );

	/* translators: %s: number of entries */
	echo '<p>' . sprintf( esc_html__( 'Found %s entries in forms without quality_checklist fields that have QG meta data.', 'simpleflow' ), '<strong>' . esc_html( $count ) . '</strong>' ) . '</p>';
	/* translators: %s: comma-separated form IDs */
	echo '<p>' . sprintf( esc_html__( 'Non-QC Form IDs: %s', 'simpleflow' ), '<code>' . esc_html( implode( ', ', $non_qc_forms ) ) . '</code>' ) . '</p>';

	if ( $confirm ) {
		// Delete the polluted meta
		$deleted = $wpdb->query( "DELETE m FROM $em m INNER JOIN $e e ON m.entry_id = e.id WHERE e.form_id IN ($in_forms) AND (m.meta_key IN ($in_keys) OR m.meta_key LIKE '_qc_fail_time_%')" );
		/* translators: %s: number of deleted rows */
		echo '<p style="color:green;"><strong>' . sprintf( esc_html__( 'Cleaned up %s meta rows.', 'simpleflow' ), esc_html( $deleted ) ) . '</strong></p>';
	} else {
		echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( 'confirm', '1' ) ) . '">' . esc_html__( 'Delete polluted meta', 'simpleflow' ) . '</a></p>';
		echo '<p><em>' . esc_html__( 'Add &confirm=1 to the URL to actually delete the data.', 'simpleflow' ) . '</em></p>';
	}

	echo '</div>';
	exit;
}

// === TEMP: Peek at last 20 audit rows (admin only) ===
function sfa_qg_admin_auditpeek() {
	if ( empty( $_GET['sfa_qg_auditpeek'] ) ) return;
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sfa_qg_auditpeek' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'simpleflow' ), 403 );
	}

	global $wpdb;
	$tbl = $wpdb->prefix . 'sfa_qg_audit';
	$rows = $wpdb->get_results( "SELECT * FROM $tbl ORDER BY id DESC LIMIT 20", ARRAY_A );

	echo '<div class="wrap"><h1>' . esc_html__( 'SFA QG Audit (latest)', 'simpleflow' ) . '</h1><table class="widefat striped">';
	echo '<thead><tr><th>' . esc_html__( 'ID', 'simpleflow' ) . '</th><th>' . esc_html__( 'type', 'simpleflow' ) . '</th><th>' . esc_html__( 'form', 'simpleflow' ) . '</th><th>' . esc_html__( 'entry', 'simpleflow' ) . '</th><th>' . esc_html__( 'item', 'simpleflow' ) . '</th><th>' . esc_html__( 'metric_key', 'simpleflow' ) . '</th><th>' . esc_html__( 'user', 'simpleflow' ) . '</th><th>' . esc_html__( 'utc', 'simpleflow' ) . '</th></tr></thead><tbody>';
	foreach ( (array) $rows as $r ) {
		printf(
			'<tr><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
			(int) $r['id'],
			esc_html( $r['event_type'] ),
			(int) $r['form_id'],
			(int) $r['entry_id'],
			esc_html( $r['item_label'] ),
			esc_html( $r['metric_key'] ),
			(int) $r['user_id'],
			esc_html( $r['event_utc'] )
		);
	}
	echo '</tbody></table></div>';
	exit;
}
