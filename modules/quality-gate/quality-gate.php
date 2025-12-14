<?php
/**
 * SFA Quality Gate
 * Mode: Per-item from Upload field (Advanced tab)
 * Honors GF "Required" on QC field
 * Version: 0.1.9a7.6
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** ----------------------------------------------------------------
 *  Utils / constants
 * ----------------------------------------------------------------*/
if ( ! function_exists( 'sfa_qg_log' ) ) {
	function sfa_qg_log( $msg, $ctx = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[SFA QG] ' . $msg . ( $ctx ? ' | ' . wp_json_encode( $ctx ) : '' ) );
		}
	}
}

// Robust entry-id detection for GF / Gravity Flow screens.
if ( ! function_exists( 'sfa_qg_current_entry_id' ) ) {
	function sfa_qg_current_entry_id() {
		$keys = array('lid','entry_id','entryId','eid');
		foreach ( $keys as $k ) {
			if ( isset($_GET[$k]) && $_GET[$k] !== '' )  return absint($_GET[$k]);
			if ( isset($_POST[$k]) && $_POST[$k] !== '' ) return absint($_POST[$k]);
		}
		// GF helper if available
		if ( function_exists('rgget') ) {
			$lid = rgget('lid');
			if ( $lid ) return absint($lid);
		}
		return 0;
	}
}


if ( ! function_exists( 'sfa_qg_report_collect' ) ) {
	function sfa_qg_report_collect( $range = 'today', $form_id = 0, $ym = '' ) {
	    
	    if ( function_exists('sfa_qg_log') ) sfa_qg_log('REPORT v2: collector active', ['range'=>$range,'form_id'=>$form_id]);

		global $wpdb;

list( $start_local, $end_local ) = sfa_qg_report_range_bounds( $range, $ym );

// Convert to UTC for gf_entry.date_created filter
$start_utc = get_gmt_from_date( $start_local, 'Y-m-d H:i:s' );
$end_utc   = get_gmt_from_date( $end_local,   'Y-m-d H:i:s' );

$where  = "e.date_created BETWEEN %s AND %s";
$params = array( '_qc_summary', $start_utc, $end_utc );

$em = $wpdb->prefix . 'gf_entry_meta';
$e  = $wpdb->prefix . 'gf_entry';




		if ( $form_id ) {
			$where  .= " AND e.form_id = %d";
			$params[] = (int) $form_id;
		}

		$sql = $wpdb->prepare(
			"SELECT e.id, e.form_id, e.date_created, m.meta_value
			 FROM $e e
			 INNER JOIN $em m ON m.entry_id = e.id AND m.meta_key = %s
			 WHERE $where",
			$params
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$totals = array(
			'metrics_total'  => 0,
			'metrics_failed' => 0,
			'items_total'    => 0,
			'entries'        => 0,
		);

		$entry_ids          = array();
		$entry_forms_map    = array();
		$latest_failed      = array();
		$top_failed         = array();
		$top_failed_metrics = array();
		$failed_entries_map = array();

		foreach ( (array) $rows as $r ) {
			$entry_ids[] = (int) $r['id'];
            $entry_forms_map[ (int) $r['id'] ] = (int) $r['form_id']; // <-- add this


			$sum = json_decode( (string) $r['meta_value'], true );
			if ( is_array( $sum ) ) {
				$totals['metrics_total']  += (int) ( $sum['metrics_total']  ?? 0 );
				$totals['metrics_failed'] += (int) ( $sum['metrics_failed'] ?? 0 );
				$totals['items_total']    += (int) ( $sum['items_total']    ?? 0 );
				$totals['entries']++;

				if ( ! empty( $sum['metrics_failed'] ) ) {
					$latest_failed[] = array(
						'entry_id'       => (int) $r['id'],
						'form_id'        => (int) $r['form_id'],
						'date_created'   => get_date_from_gmt( (string) $r['date_created'], 'Y-m-d H:i:s' ),
						'metrics_failed' => (int) $sum['metrics_failed'],
					);
					$failed_entries_map[ (int) $r['id'] ] = array(
						'entry_id'       => (int) $r['id'],
						'form_id'        => (int) $r['form_id'],
						'date_created'   => get_date_from_gmt( (string) $r['date_created'], 'Y-m-d H:i:s' ),
						'metrics_failed' => (int) $sum['metrics_failed'],
						'items'          => array(),
						'metrics_labels' => array(),
					);
				}
			}
		}

		if ( $entry_ids ) {
			// Process entries in batches to avoid query length limits
			$batch_size = 1000;
			$batches = array_chunk( $entry_ids, $batch_size );

			foreach ( $batches as $batch ) {
				$in = implode( ',', array_map( 'intval', $batch ) );

				// Attach failed items to those entries
				$q2 = "SELECT entry_id, meta_value FROM $em WHERE meta_key = '_qc_failed_items' AND entry_id IN ($in)";
				foreach ( (array) $wpdb->get_results( $q2, ARRAY_A ) as $r2 ) {
					$list = json_decode( (string) $r2['meta_value'], true );
					if ( is_array( $list ) ) {
						$eid = (int) $r2['entry_id'];
						foreach ( $list as $name ) {
							$name = trim( (string) $name );
							if ( $name === '' ) continue;
							$top_failed[ $name ] = ( $top_failed[ $name ] ?? 0 ) + 1;
							if ( isset( $failed_entries_map[ $eid ] ) ) {
								$failed_entries_map[ $eid ]['items'][] = $name;
							}
						}
						if ( isset( $failed_entries_map[ $eid ] ) ) {
							$failed_entries_map[ $eid ]['items'] = array_values( array_unique( $failed_entries_map[ $eid ]['items'] ) );
						}
					}
				}

				// Attach failing metric labels
				$q3 = "SELECT entry_id, meta_value FROM $em WHERE meta_key = '_qc_failed_metrics' AND entry_id IN ($in)";
				foreach ( (array) $wpdb->get_results( $q3, ARRAY_A ) as $r3 ) {
					$list = json_decode( (string) $r3['meta_value'], true );
					if ( is_array( $list ) ) {
						$eid = (int) $r3['entry_id'];
						foreach ( $list as $label ) {
							$label = trim( (string) $label );
							if ( $label === '' ) continue;
							$top_failed_metrics[ $label ] = ( $top_failed_metrics[ $label ] ?? 0 ) + 1;
							if ( isset( $failed_entries_map[ $eid ] ) ) {
								$failed_entries_map[ $eid ]['metrics_labels'][] = $label;
							}
						}
						if ( isset( $failed_entries_map[ $eid ] ) ) {
							$failed_entries_map[ $eid ]['metrics_labels'] = array_values( array_unique( $failed_entries_map[ $eid ]['metrics_labels'] ) );
						}
					}
				}
			} // End batch processing loop
		}

		arsort( $top_failed );
		arsort( $top_failed_metrics );

		$failed_entries = array_values( $failed_entries_map );
		usort( $failed_entries, static function( $a, $b ) {
			return strcmp( (string) $b['date_created'], (string) $a['date_created'] );
		} );

		if ( ! empty( $latest_failed ) ) {
			usort( $latest_failed, static function( $a, $b ) {
				return strcmp( (string) $b['date_created'], (string) $a['date_created'] );
			} );
		}

// --- FULL rebuild from QC JSON when summary/meta didn't give us totals ---
$need_full = ((int) $totals['metrics_total'] === 0 && (int) $totals['items_total'] === 0);

if ( $need_full && class_exists('GFAPI') ) {
    // Reset panels and totals
    $totals = array(
        'metrics_total'  => 0,
        'metrics_failed' => 0,
        'items_total'    => 0,
        'entries'        => 0,
    );
    $top_failed           = array();
    $top_failed_metrics   = array();
    $latest_failed        = array();
    $failed_entries_map   = array();

    // 1) Pull entries by date/form (no meta join)
$where2  = "date_created BETWEEN %s AND %s";
$params2 = array( $start_utc, $end_utc );

    if ( $form_id ) { $where2 .= " AND form_id = %d"; $params2[] = (int) $form_id; }

    $rows2 = $wpdb->get_results(
        $wpdb->prepare("SELECT id, form_id, date_created FROM $e WHERE $where2", $params2),
        ARRAY_A
    );

    // 2) Cache QC field id per form
    $qc_field_by_form = array();

    foreach ( (array) $rows2 as $r2 ) {
        $eid0 = (int) $r2['id'];
        $fid0 = (int) $r2['form_id'];

        if ( ! isset( $qc_field_by_form[ $fid0 ] ) ) {
            $qc_field_by_form[ $fid0 ] = 0;
            $form0 = \GFAPI::get_form( $fid0 );
            if ( is_array( $form0 ) && ! empty( $form0['fields'] ) ) {
                foreach ( (array) $form0['fields'] as $f0 ) {
                    if ( rgar( (array) $f0, 'type' ) === 'quality_checklist' ) {
                        $qc_field_by_form[ $fid0 ] = (int) rgar( (array) $f0, 'id' );
                        break;
                    }
                }
            }
        }

        $entry0 = \GFAPI::get_entry( $eid0 );
        if ( is_wp_error( $entry0 ) || ! is_array( $entry0 ) ) { continue; }

        // 3) Read QC JSON (field id first, then auto-detect)
        $json = null;
        $qfid = (int) ( $qc_field_by_form[ $fid0 ] ?? 0 );
        if ( $qfid ) {
            $raw = rgar( $entry0, (string) $qfid );
            $tmp = json_decode( (string) $raw, true );
            if ( is_array( $tmp ) && isset( $tmp['items'] ) ) { $json = $tmp; }
        }
        if ( ! is_array( $json ) ) {
            foreach ( $entry0 as $v ) {
                if ( ! is_string( $v ) || $v === '' ) continue;
                $tmp = json_decode( $v, true );
                if ( is_array( $tmp ) && isset( $tmp['items'] ) && is_array( $tmp['items'] ) ) {
                    $json = $tmp; break;
                }
            }
        }
        if ( ! is_array( $json ) || empty( $json['items'] ) ) { continue; }

        // 4) Accumulate totals + panels
        $totals['entries']++;
        $totals['items_total'] += count( (array) $json['items'] );

        $entry_failed_count   = 0;
        $entry_items_failed   = array();
        $entry_metrics_labels = array();

        foreach ( (array) $json['items'] as $it ) {
            $name    = trim( (string) rgar( (array) $it, 'name', '' ) );
            $metrics = (array) rgar( (array) $it, 'metrics', array() );

            $totals['metrics_total'] += count( $metrics );

            $item_had_fail = false;
            foreach ( $metrics as $m ) {
                $res   = strtolower( (string) rgar( (array) $m, 'result', '' ) );
                $label = trim( (string) rgar( (array) $m, 'label',  '' ) );
                if ( $res === 'fail' ) {
                    $totals['metrics_failed']++;
                    $entry_failed_count++;
                    $item_had_fail = true;
                    if ( $label !== '' ) {
                        $top_failed_metrics[ $label ] = ( $top_failed_metrics[ $label ] ?? 0 ) + 1;
                        $entry_metrics_labels[] = $label;
                    }
                }
            }

            if ( $item_had_fail && $name !== '' ) {
                $top_failed[ $name ] = ( $top_failed[ $name ] ?? 0 ) + 1;
                $entry_items_failed[] = $name;
            }
        }

        if ( $entry_failed_count > 0 ) {
            $latest_failed[] = array(
                'entry_id'       => $eid0,
                'form_id'        => $fid0,
                'date_created'   => get_date_from_gmt( (string) $r2['date_created'], 'Y-m-d H:i:s' ),
                'metrics_failed' => $entry_failed_count,
            );
            $failed_entries_map[ $eid0 ] = array(
                'entry_id'       => $eid0,
                'form_id'        => $fid0,
                'date_created'   => get_date_from_gmt( (string) $r2['date_created'], 'Y-m-d H:i:s' ),
                'metrics_failed' => $entry_failed_count,
                'items'          => array_values( array_unique( $entry_items_failed ) ),
                'metrics_labels' => array_values( array_unique( $entry_metrics_labels ) ),
            );
        }
    }

    arsort( $top_failed );
    arsort( $top_failed_metrics );

    $failed_entries = array_values( $failed_entries_map );
    usort( $failed_entries, static function( $a, $b ) {
        return strcmp( (string) $b['date_created'], (string) $a['date_created'] );
    } );
    usort( $latest_failed, static function( $a, $b ) {
        return strcmp( (string) $b['date_created'], (string) $a['date_created'] );
    } );

    if ( function_exists('sfa_qg_log') ) {
        sfa_qg_log('REPORT JSON sweep used (no _qc_summary)', array(
            'range'   => $range,
            'form_id' => (int) $form_id,
            'totals'  => $totals,
        ));
    }
}




// --- Recompute KPI from QC JSON if summary didn't include it (robust) ---
if ( (!empty($entry_ids)) && class_exists('GFAPI') &&
     ( (int)$totals['metrics_total'] === 0 || (int)$totals['metrics_failed'] === 0 || (int)$totals['items_total'] === 0 ) ) {

    $qc_field_by_form = array();
    $calc_failed = 0;
    $calc_total_metrics = 0;
    $calc_items_total = 0;

    foreach ( (array) $entry_ids as $eid ) {
        $eid = (int) $eid;
        $fid = isset( $entry_forms_map[$eid] ) ? (int) $entry_forms_map[$eid] : 0;

        $entry = \GFAPI::get_entry( $eid );
        if ( is_wp_error( $entry ) || ! is_array( $entry ) ) { continue; }

        // Resolve QC field id once per form
        $json = null;
        if ( $fid ) {
            if ( ! isset( $qc_field_by_form[$fid] ) ) {
                $qc_field_by_form[$fid] = 0;
                $form = \GFAPI::get_form( $fid );
                if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
                    foreach ( (array) $form['fields'] as $f ) {
                        if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) {
                            $qc_field_by_form[$fid] = (int) rgar( (array) $f, 'id' );
                            break;
                        }
                    }
                }
            }
            $qfid = (int) $qc_field_by_form[$fid];
            if ( $qfid ) {
                $raw = rgar( $entry, (string) $qfid );
                $tmp = json_decode( (string) $raw, true );
                if ( is_array( $tmp ) && isset( $tmp['items'] ) ) { $json = $tmp; }
            }
        }

        // Auto-detect any QC-shaped JSON if field lookup failed
        if ( ! is_array( $json ) ) {
            foreach ( $entry as $v ) {
                if ( ! is_string( $v ) || $v === '' ) continue;
                $tmp = json_decode( $v, true );
                if ( is_array( $tmp ) && isset( $tmp['items'] ) && is_array( $tmp['items'] ) ) {
                    $json = $tmp; break;
                }
            }
        }
        if ( ! is_array( $json ) || empty( $json['items'] ) ) { continue; }

        $calc_items_total += count( (array) $json['items'] );

        foreach ( (array) $json['items'] as $it ) {
            $metrics = (array) rgar( (array) $it, 'metrics', array() );
            $calc_total_metrics += count( $metrics );
            foreach ( $metrics as $m ) {
                $res = strtolower( (string) rgar( (array) $m, 'result', '' ) );
                if ( $res === 'fail' ) { $calc_failed++; }
            }
        }
    }

    if ( $calc_items_total > 0 )     { $totals['items_total']    = (int) $calc_items_total; }
    if ( $calc_total_metrics > 0 )   { $totals['metrics_total']  = (int) $calc_total_metrics; }
    if ( $calc_failed > 0 )          { $totals['metrics_failed'] = (int) $calc_failed; }

    sfa_qg_log('REPORT KPI recompute (totals rebuilt from QC JSON)', array(
        'range'   => $range,
        'form_id' => (int) $form_id,
        'totals'  => $totals,
    ));
}







		$completion = $totals['metrics_total'] > 0
			? round( 100 * ( $totals['metrics_total'] - $totals['metrics_failed'] ) / $totals['metrics_total'], 1 )
			: 0;

		$result = array(
			'range'              => $range,
			'start'              => $start_local,
			'end'                => $end_local,
			'form_id'            => (int) $form_id,
			'totals'             => $totals,
			'completion'         => $completion,
			'top_failed'         => $top_failed,
			'top_failed_metrics' => $top_failed_metrics,
			'latest_failed'      => $latest_failed,
			'failed_entries'     => $failed_entries,
			'ym'                 => (string) $ym,
		);

		// ---- 2) Fallback by EVENT TIME via the Audit table (UTC) ----
		$no_failed_panels =
			( (int) $totals['metrics_failed'] === 0 ) &&
			empty( $latest_failed ) &&
			empty( $top_failed ) &&
			empty( $top_failed_metrics );

		if ( ! $no_failed_panels ) {
			return $result; // we have "failed" data already
		}

		// Convert the local range to UTC to query sfa_qg_audit(event_utc is UTC).
		$start_utc = get_gmt_from_date( $start_local, 'Y-m-d H:i:s' );
		$end_utc   = get_gmt_from_date( $end_local,   'Y-m-d H:i:s' );

		$tbl = $wpdb->prefix . 'sfa_qg_audit';
		$where = "event_type = 'fail' AND event_utc BETWEEN %s AND %s";
		$args  = array( $start_utc, $end_utc );
		if ( $form_id ) {
			$where .= " AND form_id = %d";
			$args[] = (int) $form_id;
		}

		$audit_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, form_id, item_label, metric_key, event_utc
				 FROM $tbl
				 WHERE $where
				 ORDER BY event_utc DESC",
				$args
			),
			ARRAY_A
		);

		if ( empty( $audit_rows ) ) {
			return $result; // still nothing to show
		}

		// Build the same shapes the renderer expects, but from audit events
		$top_failed_audit         = array();
		$top_failed_metrics_audit = array();
		$latest_failed_audit      = array(); // entry-level, most recent event first
		$failed_entries_audit     = array(); // entry_id => struct

		$seen_latest = array(); // entry_id => true (to keep most recent only)

foreach ( $audit_rows as $r ) {
    $eid   = (int) $r['entry_id'];
    $fid   = (int) $r['form_id'];
    $item  = trim( (string) $r['item_label'] );
    $mkey  = trim( (string) $r['metric_key'] );
    $utc   = (string) $r['event_utc'];
    $local = get_date_from_gmt( $utc, 'Y-m-d H:i:s' ); // display in local

    // classify event
    $is_metric_event = ( $mkey !== '' && strpos( $mkey, 'metric:' ) === 0 );
    $is_item_event   = ( $mkey === '' || strpos( $mkey, 'item:' ) === 0 );

    // top failed items (only from item events)
    if ( $is_item_event && $item !== '' ) {
        $top_failed_audit[ $item ] = ( $top_failed_audit[ $item ] ?? 0 ) + 1;
    }

    // top failing metrics (only from metric events)
    if ( $is_metric_event ) {
        $label = trim( substr( $mkey, 7 ) );
        if ( $label !== '' ) {
            $top_failed_metrics_audit[ $label ] = ( $top_failed_metrics_audit[ $label ] ?? 0 ) + 1;
        }
    }

    // per-entry detail
    if ( ! isset( $failed_entries_audit[ $eid ] ) ) {
        $failed_entries_audit[ $eid ] = array(
            'entry_id'       => $eid,
            'form_id'        => $fid,
            'date_created'   => $local, // use most-recent audit time
            'metrics_failed' => 1,
            'items'          => array(),
            'metrics_labels' => array(),
        );
    } else {
        $failed_entries_audit[ $eid ]['metrics_failed']++;
        if ( strcmp( $local, (string) $failed_entries_audit[ $eid ]['date_created'] ) > 0 ) {
            $failed_entries_audit[ $eid ]['date_created'] = $local;
        }
    }

    if ( $is_item_event && $item !== '' ) {
        $failed_entries_audit[ $eid ]['items'][] = $item;
    }
    if ( $is_metric_event ) {
        $label = trim( substr( $mkey, 7 ) );
        if ( $label !== '' ) {
            $failed_entries_audit[ $eid ]['metrics_labels'][] = $label;
        }
    }

    // latest failed entries (keep one per entry)
    if ( ! isset( $seen_latest[ $eid ] ) ) {
        $latest_failed_audit[] = array(
            'entry_id'       => $eid,
            'form_id'        => $fid,
            'date_created'   => $local,
            'metrics_failed' => 1,
        );
        $seen_latest[ $eid ] = true;
    }
}


		// Normalize/unique & sort
		foreach ( $failed_entries_audit as &$fe ) {
			$fe['items']          = array_values( array_unique( array_map( 'strval', $fe['items'] ) ) );
			$fe['metrics_labels'] = array_values( array_unique( array_map( 'strval', $fe['metrics_labels'] ) ) );
		}
		unset( $fe );

		$failed_entries_audit = array_values( $failed_entries_audit );
		usort( $failed_entries_audit, static function( $a, $b ) {
			return strcmp( (string) $b['date_created'], (string) $a['date_created'] );
		} );

		usort( $latest_failed_audit, static function( $a, $b ) {
			return strcmp( (string) $b['date_created'], (string) $a['date_created'] );
		} );

		arsort( $top_failed_audit );
		arsort( $top_failed_metrics_audit );

		// Keep the original totals/completion cards (still entry-date based),
		// but fill the failed panels from audit events so the UI isn’t empty.
		$result['top_failed']         = $top_failed_audit;
		$result['top_failed_metrics'] = $top_failed_metrics_audit;
		$result['latest_failed']      = $latest_failed_audit;
		$result['failed_entries']     = $failed_entries_audit;

		if ( function_exists( 'sfa_qg_log' ) ) {
			sfa_qg_log( 'REPORT fallback to audit (fail panels filled by events)', array(
				'range'   => $range,
				'form_id' => (int) $form_id,
				'rows'    => count( $audit_rows ),
			) );
		}

		return $result;
	}
}


require_once __DIR__ . '/report/admin-page.php';
require_once __DIR__ . '/report/export.php';

if ( ! defined( 'SFA_QG_VER' ) ) define( 'SFA_QG_VER', '0.2.7.0');
if ( ! defined( 'SFA_QG_DIR' ) ) define( 'SFA_QG_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'SFA_QG_URL' ) ) define( 'SFA_QG_URL', plugin_dir_url( __FILE__ ) );




// === QG Audit Log (persist failures & fixes) ===============================
register_activation_hook( __FILE__, 'sfa_qg_install_audit_table' );
function sfa_qg_install_audit_table() {
	global $wpdb;
	$tbl  = $wpdb->prefix . 'sfa_qg_audit';
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
 * Runs early on every request; if the table is missing, it will be created.
 */
add_action( 'init', 'sfa_qg_maybe_install_audit_table', 1 );
function sfa_qg_maybe_install_audit_table() {
	global $wpdb;
	$tbl = $wpdb->prefix . 'sfa_qg_audit';

	$pattern = $wpdb->esc_like( $tbl ); // escape _ and %
	$exists  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $pattern ) );

	if ( $exists !== $tbl ) {
		sfa_qg_install_audit_table();
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $pattern ) );
		update_option( 'sfa_qg_audit_table_ready', $exists === $tbl ? '1' : '0' );
	} else {
		update_option( 'sfa_qg_audit_table_ready', '1' );
	}
}

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


function sfa_qg_audit_log( $type, $args ) {
	global $wpdb;
	$tbl = $wpdb->prefix . 'sfa_qg_audit';
	if ( ! in_array( $type, array( 'fail','fix' ), true ) ) {
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
	$fmt = array( '%s','%d','%d','%s','%s','%d','%s','%s','%s' );

	$ok = $wpdb->insert( $tbl, $ins, $fmt );

	if ( false === $ok ) {
		// Surface the actual DB error in debug.log
		if ( function_exists( 'sfa_qg_log' ) ) {
			sfa_qg_log( 'AUDIT insert failed', array(
				'error' => $wpdb->last_error,
				'sql'   => $wpdb->last_query,
				'data'  => $ins,
			) );
		}
		return false;
	}

	return true;
}


function sfa_qg_audit_log_fail( $form_id, $entry_id, $metric_key, $item_label = '', $note = '', $extra = array() ) {
	return sfa_qg_audit_log( 'fail', compact( 'form_id','entry_id','metric_key','item_label','note','extra' ) );
}
function sfa_qg_audit_log_fix( $form_id, $entry_id, $metric_key, $item_label = '', $note = '', $extra = array() ) {
	return sfa_qg_audit_log( 'fix', compact( 'form_id','entry_id','metric_key','item_label','note','extra' ) );
}


/** ----------------------------------------------------------------
 *  Assets (registered once; enqueued where needed)
 * ----------------------------------------------------------------*/
add_action( 'init', function () {
	wp_register_script( 'sfa-qg', SFA_QG_URL . 'assets/js/quality.js', array( 'jquery' ), SFA_QG_VER, true );
	wp_register_style( 'sfa-qg', SFA_QG_URL . 'assets/css/quality.css', array(), SFA_QG_VER );
	sfa_qg_log( 'Assets registered' );
}, 5);

/**
 * Localize AJAX on both front and admin when script is enqueued.
 */
function sfa_qg_localize_ajax() {
	if ( wp_script_is( 'sfa-qg', 'enqueued' ) ) {
		wp_localize_script( 'sfa-qg', 'SFA_QG_AJAX', array(
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sfa_qg' ),
		) );
	}
}

/**
 * Enqueue on front where GF forms load.
 */
add_action( 'gform_enqueue_scripts', function( $form, $is_ajax ) {
	wp_enqueue_script( 'sfa-qg' );
	wp_enqueue_style( 'sfa-qg' );
	sfa_qg_localize_ajax();

	// Footer-localize collected per-field configs (set by the field renderer).
	add_action( 'wp_footer', function() {
		if ( empty( $GLOBALS['sfa_qg_cfg'] ) ) return;
		wp_localize_script( 'sfa-qg', 'SFA_QG_CFG', array(
			'fields' => array_values( (array) $GLOBALS['sfa_qg_cfg'] ),
		) );
	}, 100 );
}, 10, 2 );

/**
 * Enqueue on Gravity Flow admin screens (inbox, entry, etc.).
 */
add_action( 'admin_enqueue_scripts', function () {
	if ( ! function_exists( 'get_current_screen' ) ) return;
	$screen = get_current_screen();
	if ( ! $screen ) return;

	$match = (
		strpos( $screen->id, 'gravityflow' ) !== false
		|| $screen->id === 'forms_page_gf_entries'
		|| $screen->id === 'toplevel_page_gravityflow-inbox'
	);
	if ( $match ) {
		wp_enqueue_script( 'sfa-qg' );
		wp_enqueue_style( 'sfa-qg' );
		sfa_qg_localize_ajax();
		sfa_qg_log( 'Admin enqueue on screen', array( 'screen' => $screen->id ) );
	}
}, 20 );

/** ----------------------------------------------------------------
 *  Field & Step registration
 * ----------------------------------------------------------------*/

add_action( 'gform_loaded', function () {
	// Bail early if GF core classes aren’t ready.
	if ( ! class_exists( '\GF_Fields' ) ) {
		sfa_qg_log( 'GF_Fields not available on gform_loaded' );
		return;
	}

	// If the type is already registered, don’t register again.
	if ( method_exists( '\GF_Fields', 'get' ) ) {
		$existing = \GF_Fields::get( 'quality_checklist' );
		if ( $existing ) {
			sfa_qg_log( 'GF Field already registered, skipping.' );
			return;
		}
	}

	$file = SFA_QG_DIR . 'src/Field_Quality_Checklist.php';
	if ( ! file_exists( $file ) ) {
		sfa_qg_log( 'GF Field file not found', array( 'path' => $file ) );
		return;
	}

	require_once $file;

	if ( ! class_exists( '\SFA\QualityGate\Field_Quality_Checklist' ) ) {
		sfa_qg_log( 'GF Field class missing after include' );
		return;
	}

	// Double-check again in case something registered during include.
	if ( method_exists( '\GF_Fields', 'get' ) && \GF_Fields::get( 'quality_checklist' ) ) {
		sfa_qg_log( 'GF Field present after include, skipping register.' );
		return;
	}

	\GF_Fields::register( new \SFA\QualityGate\Field_Quality_Checklist() );
	sfa_qg_log( 'GF Field registered' );
}, 5 );


add_action( 'gravityflow_loaded', function () {
	$file = SFA_QG_DIR . 'src/Step_Quality_Gate.php';
	if ( class_exists( 'Gravity_Flow_Step' ) && file_exists( $file ) ) {
		require_once $file;
		sfa_qg_log( 'Step class included' );
	}
}, 1 );

/** ----------------------------------------------------------------
 *  Editor: Add field button + settings UI
 * ----------------------------------------------------------------*/

// QG-206 — show ONE “Quality Checklist” button, under Advanced
add_filter( 'gform_add_field_buttons', function ( $groups ) {
	// Ensure Advanced exists
	$adv_i = null;
	foreach ( $groups as $i => $g ) {
		if ( isset( $g['name'] ) && $g['name'] === 'advanced_fields' ) { $adv_i = $i; break; }
	}
	if ( $adv_i === null ) {
		$groups[] = array( 'name' => 'advanced_fields', 'label' => esc_html__( 'Advanced Fields', 'gravityforms' ), 'fields' => array() );
		$adv_i = count( $groups ) - 1;
	}

	// Remove any existing “quality_checklist” buttons from ALL groups
	foreach ( $groups as $i => $g ) {
		if ( empty( $g['fields'] ) || ! is_array( $g['fields'] ) ) continue;
		$kept = array();
		foreach ( $g['fields'] as $f ) {
			$type = $f['data-type'] ?? ( $f['type'] ?? '' );
			if ( $type === 'quality_checklist' ) { continue; } // drop duplicates
			$kept[] = $f;
		}
		$groups[$i]['fields'] = $kept;
	}

	// Add exactly one back to Advanced
	$groups[$adv_i]['fields'][] = array(
		'class'     => 'button',
		'value'     => esc_html__( 'Quality Checklist', 'sfa-quality-gate' ),
		'data-type' => 'quality_checklist',
	);

	return $groups;
}, PHP_INT_MAX ); // run absolutely last


add_filter('gform_validation', function($result){
	$entry_id = function_exists('sfa_qg_current_entry_id') ? sfa_qg_current_entry_id() : 0;
	if ( $entry_id ) {
		sfa_qg_log('HOOK gform_validation', ['entry_id'=>$entry_id, 'is_valid'=>$result['is_valid']]);
		sfa_qg_save_recheck_items_from_post($result['form'], (int)$entry_id);
	}
	return $result;
}, 9999);


add_action( 'gform_field_advanced_settings', function ( $position, $form_id ) {
	if ( (int) $position !== 200 ) return; ?>
	<li class="sfa_qg_setting_source_upload field_setting">
		<label for="sfa_qg_source_upload_field" class="section_label">
			<?php esc_html_e( 'QC Source Upload field', 'sfa-quality-gate' ); ?>
		</label>
		<select id="sfa_qg_source_upload_field" onchange="SetFieldProperty('sfa_qg_source_upload_field', this.value);"></select>
		<p class="description"><?php esc_html_e( 'Choose a File Upload field; filenames will become QC items.', 'sfa-quality-gate' ); ?></p>
	</li>
	<li class="sfa_qg_setting_metrics field_setting">
	<label for="sfa_qg_metric_labels" class="section_label">
		<?php esc_html_e( 'Metric labels (one per line)', 'sfa-quality-gate' ); ?>
	</label>
	<textarea id="sfa_qg_metric_labels" class="fieldwidth-3" rows="6"
	          oninput="SetFieldProperty('sfa_qg_metric_labels', this.value);"
	          placeholder="<?php echo esc_attr__( 'e.g.'."\n".'Dimensions'."\n".'Finish'."\n".'Holes'."\n"."Packaging", 'sfa-quality-gate' ); ?>"></textarea>
	<p class="description"><?php esc_html_e( 'Up to 10 metrics. Leave blank to use a single “Overall” check.', 'sfa-quality-gate' ); ?></p>
</li>
<?php }, 10, 2 );

add_action( 'gform_field_standard_settings', function ( $position, $form_id ) {
	if ( (int) $position !== 150 ) return; ?>
	<li class="sfa_qg_setting_require_note field_setting">
		<input type="checkbox" id="sfa_qg_require_note_on_fail"
			onclick="SetFieldProperty('sfa_qg_require_note_on_fail', this.checked ? 1 : 0);" />
		<label for="sfa_qg_require_note_on_fail" class="inline">
			<?php esc_html_e( 'Require note when a QC metric fails', 'sfa-quality-gate' ); ?>
		</label>
	</li>
<?php }, 10, 2 );

add_action( 'gform_editor_js', function () { ?>
<script>
( function( $ ) {
	fieldSettings.quality_checklist = ( fieldSettings.quality_checklist || '' )
		+ ', .sfa_qg_setting_require_note'
		+ ', .sfa_qg_setting_source_upload'
		+ ', .sfa_qg_setting_metrics'
        + ', .sfa_qg_setting_metric_labels';

	function sfaQGPopulateUploadSelect( field ) {
		var $sel = $( '#sfa_qg_source_upload_field' );
		if ( !$sel.length ) return;
		$sel.empty();
		$sel.append( $('<option/>').val('').text('<?php echo esc_js( __( '-- Select upload field --', 'sfa-quality-gate' ) ); ?>') );

		if ( typeof GetFieldsByType === 'function' ) {
			var uploads = GetFieldsByType( ['fileupload'] ) || [];
			for ( var i = 0; i < uploads.length; i++ ) {
				var f = uploads[i];
				var label = ( f.label || ('Field ' + f.id) ) + ' (ID ' + f.id + ')';
				$sel.append( $('<option/>').val( f.id ).text( label ) );
			}
		}

		if ( field && field.sfa_qg_source_upload_field ) {
			$sel.val( String( field.sfa_qg_source_upload_field ) );
		}
	}

	$( document ).on( 'gform_load_field_settings', function( e, field ) {
		if ( field.type !== 'quality_checklist' ) return;
		$('#sfa_qg_require_note_on_fail').prop('checked', !!( field.sfa_qg_require_note_on_fail ));
		$('#sfa_qg_metric_labels').val(field.sfa_qg_metric_labels ? field.sfa_qg_metric_labels : '');
		sfaQGPopulateUploadSelect(field);
	} );
} )( jQuery );
</script>
<?php } );

/** ----------------------------------------------------------------
 *  AJAX: item discovery from an entry's Upload field
 * ----------------------------------------------------------------*/
function sfa_qg_normalize_files( $raw ) {
	if ( empty( $raw ) ) return array();

	$push = function( $src, &$out ) {
		if ( ! is_string( $src ) || $src === '' ) return;
		$out[] = $src;
	};

	$urls = array();

	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			foreach ( (array) $decoded as $item ) {
				if ( is_string( $item ) )           $push( $item, $urls );
				elseif ( is_array( $item ) ) {
					foreach ( array( 'uploaded_filename','uploaded_file','url','temp_filename','file','name','filename' ) as $k ) {
						if ( ! empty( $item[ $k ] ) ) { $push( (string) $item[ $k ], $urls ); break; }
					}
				}
			}
		} else {
			$raw = str_replace( '|', ',', $raw );
			foreach ( array_map( 'trim', explode( ',', $raw ) ) as $p ) {
				if ( $p !== '' ) $push( $p, $urls );
			}
		}
	} elseif ( is_array( $raw ) ) {
		foreach ( $raw as $item ) {
			if ( is_string( $item ) ) $push( $item, $urls );
			elseif ( is_array( $item ) ) {
				foreach ( array( 'uploaded_filename','uploaded_file','url','temp_filename','file','name','filename' ) as $k ) {
					if ( ! empty( $item[ $k ] ) ) { $push( (string) $item[ $k ], $urls ); break; }
				}
			}
		}
	}

	$items = array();
	foreach ( $urls as $s ) {
		$b = wp_basename( $s );
		if ( $b === '' ) continue;
		$n = preg_replace( '/\.[^.]+$/', '', $b );
		if ( $n !== '' ) $items[] = array( 'name' => $n );
	}
	return $items;
}

function sfa_qg_ajax_items() {
	check_ajax_referer( 'sfa_qg', 'nonce' );

	$eid       = isset( $_POST['eid'] )      ? absint( $_POST['eid'] )      : 0;
	$source_id = isset( $_POST['sourceId'] ) ? absint( $_POST['sourceId'] ) : 0;

	if ( ! $eid || ! $source_id || ! class_exists( 'GFAPI' ) ) {
		wp_send_json_error( array( 'message' => 'missing params' ) );
	}

	$entry = \GFAPI::get_entry( $eid );
	if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
		wp_send_json_error( array( 'message' => 'entry not found' ) );
	}

	$raw = rgar( $entry, (string) $source_id );
	if ( ! $raw && isset( $entry[ $source_id ] ) ) {
		$raw = $entry[ $source_id ];
	}

	$items = sfa_qg_normalize_files( $raw );

	sfa_qg_log( 'AJAX items', array( 'eid' => $eid, 'source_id' => $source_id, 'count' => count( $items ) ) );
	wp_send_json_success( array( 'items' => $items ) );
}
add_action( 'wp_ajax_sfa_qg_items',        'sfa_qg_ajax_items' );
add_action( 'wp_ajax_nopriv_sfa_qg_items', 'sfa_qg_ajax_items' );

/**
 * ----------------------------------------------------------------
 * Tiny Reporting (shortcode + admin page)
 * ----------------------------------------------------------------
 */
if ( ! function_exists( 'sfa_qg_report_range_bounds' ) ) {
	function sfa_qg_report_range_bounds( $range, $ym = '' ) {
		$range = strtolower( (string) $range );
		$now   = current_time( 'timestamp' );

		switch ( $range ) {
			case 'year':
				$start = date_i18n( 'Y-01-01 00:00:00', $now );
				$end   = date_i18n( 'Y-12-31 23:59:59', $now );
				break;

			case 'month':
				$start = date_i18n( 'Y-m-01 00:00:00', $now );
				$end   = date_i18n( 'Y-m-t 23:59:59', $now );
				break;

			case 'month_custom':
				$ym = preg_replace( '/[^0-9\-]/', '', (string) $ym );
				if ( ! preg_match( '/^\d{4}\-\d{2}$/', $ym ) ) {
					$ym = date_i18n( 'Y-m', $now );
				}
				$ts    = strtotime( $ym . '-01 00:00:00' );
				$start = date_i18n( 'Y-m-01 00:00:00', $ts );
				$end   = date_i18n( 'Y-m-t 23:59:59', $ts );
				break;

			case 'today':
			default:
				$start = date_i18n( 'Y-m-d 00:00:00', $now );
				$end   = date_i18n( 'Y-m-d 23:59:59', $now );
				break;
		}
		return array( $start, $end );
	}
}














if ( ! function_exists( 'sfa_qg_report_shortcode' ) ) {
  function sfa_qg_report_shortcode( $atts = array() ) {
    $a = shortcode_atts( array(
      'range'   => 'today',
      'form_id' => 0,
      'ym'      => '',
      'ym2'     => '',
    ), $atts, 'sfa_qg_report' );

    return sfa_qg_report_render_html( $a['range'], (int)$a['form_id'], $a['ym'], $a['ym2'] );
  }
  add_shortcode( 'sfa_qg_report', 'sfa_qg_report_shortcode' );
}


if ( ! function_exists( 'sfa_qg_report_admin_menu' ) ) {
	function sfa_qg_report_admin_menu() {
		$cap = current_user_can( 'gravityflow_workflow' ) ? 'gravityflow_workflow' : 'gravityforms_view_entries';
		add_submenu_page(
			'gravityflow-inbox',
			__( 'Quality Gate Report', 'sfa-quality-gate' ),
			__( 'Quality Gate Report', 'sfa-quality-gate' ),
			$cap,
			'sfa-qg-report',
			'sfa_qg_report_admin_page'
		);
	}
	add_action( 'admin_menu', 'sfa_qg_report_admin_menu', 200 );
}





// QG-203 — hide the raw checkbox bullets for the Recheck field in read-only renders.
// Works in Gravity Flow entry detail and GF admin/front-end renders.
add_filter( 'gform_entry_field_value', function ( $value, $field, $entry, $form ) {
    // Only checkboxes
    if ( ! is_object( $field ) || $field->type !== 'checkbox' ) {
        return $value;
    }

    // A. Prefer CSS markers (Admin CSS Class on the checkbox field):
    //    qg-rework or qg-rework-field
    $classes   = ' ' . strtolower( trim( (string) rgar( (array) $field, 'cssClass' ) ) ) . ' ';
    $has_marker = ( strpos( $classes, ' qg-rework ' ) !== false ) || ( strpos( $classes, ' qg-rework-field ' ) !== false );

    // B. Fallback: resolve by field id (heuristic finder)
    $is_target = $has_marker;
    if ( ! $is_target && function_exists( 'sfa_qg_find_fixed_checkbox_field_id' ) ) {
        $target_id = (int) sfa_qg_find_fixed_checkbox_field_id( $form );
        $is_target = $target_id && ( (int) $field->id === $target_id );
    }
    if ( ! $is_target ) {
        return $value;
    }

    // Skip when this field is actually editable on a User Input step
    if (
        is_array( $entry )
        && function_exists( 'sfa_qg_is_field_editable_on_user_input' )
        && sfa_qg_is_field_editable_on_user_input( $form, $entry, $field )
    ) {
        return $value;
    }

    // Idempotent: already wrapped?
    if ( is_string( $value ) && strpos( $value, 'qg-rework-value' ) !== false ) {
        return $value;
    }

    // Wrap so we can target just this value with CSS
    return ( is_string( $value ) && $value !== '' )
        ? '<div class="qg-rework-value">' . $value . '</div>'
        : $value;
}, 9999, 4 );



/* ======================================================================
 * QG-102 & QG-103 — Portable rework UX (no hard-coded IDs)
 * ====================================================================== */

/** Find the Quality Gate step for this form (future use / filters can rely on it). */
function sfa_qg_find_quality_gate_step_id( $form ) {
	$step_id = 0;
	if ( function_exists( 'gravity_flow' ) && ! empty( $form['id'] ) ) {
		$steps = gravity_flow()->get_steps( (int) $form['id'] );
		foreach ( (array) $steps as $step ) {
			$matches_type  = ( property_exists( $step, '_step_type' ) && $step->_step_type === 'quality_gate' );
			$matches_class = ( $step instanceof \SFA\QualityGate\Step_Quality_Gate );
			if ( $matches_type || $matches_class ) {
				$step_id = (int) ( method_exists( $step, 'get_id' ) ? $step->get_id() : $step->id );
				break;
			}
		}
	}
	return (int) apply_filters( 'sfa_qg/quality_gate_step_id', $step_id, $form );
}

// Locate the “Fixed items” checkbox reliably (no hard-coded IDs).
if ( ! function_exists( 'sfa_qg_find_fixed_checkbox_field_id' ) ) {
	function sfa_qg_find_fixed_checkbox_field_id( $form ) {
		$single_fix = 0;   // a checkbox that has exactly one choice called "Fix"
		$empty      = 0;   // a checkbox with no choices yet
		$first      = 0;   // first checkbox id (for the 1-checkbox legacy case)
		$count      = 0;   // number of checkbox fields

		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			$fa = (array) $f;
			if ( rgar( $fa, 'type' ) !== 'checkbox' ) {
				continue;
			}
			$count++;
			$first = $first ?: (int) rgar( $fa, 'id' );

			$adm = strtolower( (string) rgar( $fa, 'adminLabel' ) );
			$lbl = strtolower( (string) rgar( $fa, 'label' ) );
			$css = strtolower( (string) rgar( $fa, 'cssClass' ) );

			// 1) Explicit markers (preferred).
			if (
				$adm === 'qg_fixed' || $adm === 'qc_fixed' ||
				strpos( $css, 'qg-fixed' ) !== false ||
				strpos( $lbl, 'fixed' ) !== false || strpos( $lbl, 'rework' ) !== false || strpos( $lbl, 'fix' ) !== false ||
				strpos( $lbl, 'إصلاح' ) !== false || strpos( $lbl, 'تصحيح' ) !== false
			) {
				return (int) rgar( $fa, 'id' );
			}

			// 2) Heuristic: exactly one choice named "Fix".
			$choices = (array) rgar( $fa, 'choices', array() );
			if ( count( $choices ) === 1 ) {
				$txt = strtolower( trim( (string) rgar( $choices[0], 'text' ) ) );
				if ( $txt === 'fix' ) {
					$single_fix = (int) rgar( $fa, 'id' );
				}
			}

			// 3) Heuristic: empty checkbox (no choices yet).
			if ( empty( $choices ) && ! $empty ) {
				$empty = (int) rgar( $fa, 'id' );
			}
		}

		// 4) Back-compat: if there is exactly one checkbox in the form, use it.
		if ( $count === 1 && $first ) {
			return $first;
		}

		// 5) Prefer our heuristics; do NOT pick a random checkbox.
		if ( $single_fix ) return $single_fix;
		if ( $empty )      return $empty;

		// Ambiguous → do nothing; avoids touching the wrong field.
		return 0;
	}
}

/**
 * Collect ONLY the selected values from the rework checkbox field (supports input_X_Y and input_X[]).
 *
 * SECURITY NOTE: This function accesses $_POST data but is designed to be called only within
 * Gravity Forms hooks (gform_pre_render, gform_validation, etc.) which already perform
 * nonce verification. Do not call this function outside of validated GF contexts.
 */
if ( ! function_exists( 'sfa_qg_collect_rework_values_from_post' ) ) {
	function sfa_qg_collect_rework_values_from_post( $form, $field_id ) {
		// Defensive check: ensure we're in a Gravity Forms context
		if ( ! is_array( $form ) || ! isset( $form['fields'] ) ) {
			sfa_qg_log( 'sfa_qg_collect_rework_values_from_post called with invalid form', array( 'field_id' => $field_id ) );
			return array();
		}

		$selected = array();

		// Standard GF style: input_5_1, input_5_2, ...
		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			if ( (int) rgar( (array) $f, 'id' ) !== (int) $field_id || $f->type !== 'checkbox' || empty( $f->inputs ) ) {
				continue;
			}
			foreach ( $f->inputs as $inp ) {
				$key = 'input_' . str_replace( '.', '_', $inp['id'] );
				$val = isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
				if ( $val !== '' ) {
					$selected[] = $val;
				}
			}
			break;
		}

		// Array style: input_5[]
		if ( empty( $selected ) ) {
			$key = 'input_' . $field_id;
			if ( isset( $_POST[ $key ] ) ) {
				$vals = (array) wp_unslash( $_POST[ $key ] );
				foreach ( $vals as $v ) {
					$v = (string) $v;
					if ( $v !== '' ) {
						$selected[] = $v;
					}
				}
			}
		}
		return array_values( array_unique( $selected ) );
	}
}


// Detect if we are on a Gravity Flow **User Input** step (i.e., the rework step).
if ( ! function_exists( 'sfa_qg_is_rework_context' ) ) {
	function sfa_qg_is_rework_context( $form ) {
		if ( ! function_exists( 'gravity_flow' ) ) {
			return false;
		}

		// --- Try 1: explicit step id from the request ---
		$step_id = sfa_qg_current_step_id();
		if ( $step_id ) {
			$step = gravity_flow()->get_step( $step_id );
			if ( $step ) {
				// Not the Quality Gate itself
				if (
					( property_exists( $step, '_step_type' ) && $step->_step_type === 'quality_gate' ) ||
					( class_exists( '\SFA\QualityGate\Step_Quality_Gate' ) && $step instanceof \SFA\QualityGate\Step_Quality_Gate )
				) {
					return false;
				}
				// User Input => editable
				if (
					( property_exists( $step, '_step_type' ) && $step->_step_type === 'user_input' ) ||
					( class_exists( '\Gravity_Flow_Step_User_Input' ) && $step instanceof \Gravity_Flow_Step_User_Input )
				) {
					return true;
				}
			}
		}

		// --- Try 2: resolve the entry's *current* step (works when no step param is present) ---
		$entry_id = sfa_qg_current_entry_id();
		if ( $entry_id && class_exists( 'GFAPI' ) ) {
			$entry = \GFAPI::get_entry( $entry_id );
			if ( ! is_wp_error( $entry ) ) {
				$curr = gravity_flow()->get_current_step( $form, $entry );
				if ( $curr ) {
					if (
						( property_exists( $curr, '_step_type' ) && $curr->_step_type === 'user_input' ) ||
						( class_exists( '\Gravity_Flow_Step_User_Input' ) && $curr instanceof \Gravity_Flow_Step_User_Input )
					) {
						return true;
					}
				}
			}
		}

		return false;
	}
}




if ( ! function_exists( 'sfa_qg_is_field_editable_on_user_input' ) ) {
	function sfa_qg_is_field_editable_on_user_input( $form, $entry, $field ) {
		if ( ! function_exists( 'gravity_flow' ) ) {
			return false;
		}

		// Resolve the step: explicit ?step=… first, else entry’s current step
		$step_id = function_exists( 'sfa_qg_current_step_id' ) ? sfa_qg_current_step_id() : 0;
		$step    = $step_id ? gravity_flow()->get_step( $step_id ) : gravity_flow()->get_current_step( $form, $entry );
		if ( ! $step ) {
			return false;
		}

		// Must be a User Input step
		$is_user_input = (
			( property_exists( $step, '_step_type' ) && $step->_step_type === 'user_input' ) ||
			( class_exists( '\Gravity_Flow_Step_User_Input' ) && $step instanceof \Gravity_Flow_Step_User_Input )
		);
		if ( ! $is_user_input ) {
			return false;
		}

		// Prefer the step API if available
		if ( method_exists( $step, 'is_editable_field' ) ) {
			return (bool) $step->is_editable_field( $field, $form, $entry );
		}

		// Fallback to editable fields array + documented filter
		$editable_ids = method_exists( $step, 'get_editable_fields' ) ? (array) $step->get_editable_fields() : array();
		// Allow site-level overrides (per docs: gravityflow_editable_fields)
		$editable_ids = apply_filters( 'gravityflow_editable_fields', $editable_ids, $step, $form, $entry );

		// Normalize to ints/strings, check membership
		$field_id = (int) ( is_object( $field ) ? $field->id : rgar( (array) $field, 'id', 0 ) );
		$norm = array();
		foreach ( $editable_ids as $id ) { $norm[] = (int) $id; }

		return in_array( $field_id, $norm, true );
	}
}


/** Populate the checkbox with failed items in entry context. */
add_filter( 'gform_pre_render',       'sfa_qg_populate_rework_choices', 9999 );
add_filter( 'gform_pre_validation',   'sfa_qg_populate_rework_choices', 9999 );
add_filter( 'gform_admin_pre_render', 'sfa_qg_populate_rework_choices', 9999 );
// Helpers: build map Item => [failed metric labels] from the saved QC JSON/meta
if ( ! function_exists( 'sfa_qg_failed_metric_map' ) ) {
	function sfa_qg_failed_metric_map( $entry_id, $form ) {
		$map = array();

		// 1) Try to compute from the QC field value (JSON)
		$qc_field_id = 0;
		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) { $qc_field_id = (int) rgar( (array) $f, 'id' ); break; }
		}
		if ( $qc_field_id && class_exists( 'GFAPI' ) ) {
			$entry = \GFAPI::get_entry( $entry_id );
			if ( ! is_wp_error( $entry ) ) {
				$raw = rgar( $entry, (string) $qc_field_id );
				$val = json_decode( (string) $raw, true );
				if ( is_array( $val ) && ! empty( $val['items'] ) ) {
					foreach ( (array) $val['items'] as $it ) {
						$name  = (string) rgar( $it, 'name' );
						if ( $name === '' ) { continue; }
						$fails = array();
						foreach ( (array) rgar( $it, 'metrics' ) as $m ) {
							$label = trim( (string) rgar( $m, 'label' ) );
							if ( rgar( $m, 'result' ) === 'fail' && $label !== '' ) {
								$fails[] = $label;
							}
						}
						if ( $fails ) { $map[ $name ] = array_values( array_unique( $fails ) ); }
					}
				}
			}
		}

		// 2) Ensure every failed item exists as a key (even if no labels)
		$failed_items = json_decode( (string) gform_get_meta( $entry_id, '_qc_failed_items' ), true );
		if ( is_array( $failed_items ) ) {
			foreach ( $failed_items as $name ) {
				$name = trim( (string) $name );
				if ( $name !== '' && ! isset( $map[ $name ] ) ) { $map[ $name ] = array(); }
			}
		}

		return $map;
	}
}

if ( ! function_exists( 'sfa_qg_render_failed_table' ) ) {
	function sfa_qg_render_failed_table( $map, $fixed_list = null, $entry_id = 0, $editable = false, $field_id = 0 ) {
		if ( empty( $map ) ) { return ''; }

		// Prefer an explicit list (live POST), otherwise read saved meta.
		if ( ! is_array( $fixed_list ) ) {
			$fixed_list = array();
			if ( $entry_id ) {
				$fixed_list = json_decode( (string) gform_get_meta( $entry_id, '_qc_recheck_items' ), true );
				$fixed_list = is_array( $fixed_list ) ? array_map( 'strval', $fixed_list ) : array();
			}
		} else {
			$fixed_list = array_map( 'strval', $fixed_list );
		}

$out  = '<table class="qg-rework-table widefat striped">';
		$out .= '<thead><tr><th>' . esc_html__( 'Item', 'sfa-quality-gate' ) . '</th><th>' . esc_html__( 'Failed metrics', 'sfa-quality-gate' ) . '</th></tr></thead><tbody>';

		foreach ( $map as $name => $labels ) {
			$is_fixed = in_array( (string) $name, $fixed_list, true );
			$badge = $is_fixed
				? ' <span class="sfa-qg-badge is-fixed">' . esc_html__( 'Fixed', 'sfa-quality-gate' ) . '</span>'
				: '';
// in sfa_qg_render_failed_table()
$chk = '';
if ( $editable ) {
    $chk = sprintf(
        '<span class="qg-row-slot" data-field-id="%d" data-value="%s"></span> ',
        (int) $field_id,
        esc_attr( $name )
    );
}

$out .= '<tr><td>' . $chk . esc_html( $name ) . $badge . '</td><td>' . ( $labels ? esc_html( implode( ', ', $labels ) ) : '&ndash;' ) . '</td></tr>';

		}

		$out .= '</tbody></table>';
		return $out;
	}
}



/**
 * Populate the Rework checkbox with failed items, show the mini table,
 * and render the "Mark all fixed" button. (QG-102 / QG-103)
 */
// Run in front-end, during validation, and in admin entry screens.
function sfa_qg_populate_rework_choices( $form ) {
	$entry_id = sfa_qg_current_entry_id();
	if ( ! $entry_id ) { return $form; }
$entry = null;
if ( class_exists( 'GFAPI' ) ) {
	$entry = \GFAPI::get_entry( $entry_id );
	if ( is_wp_error( $entry ) ) {
		$entry = null;
	}
}

	$target_id = sfa_qg_find_fixed_checkbox_field_id( $form );
	if ( ! $target_id ) { return $form; }

	// Don't show on the Quality Gate step itself.
	$curr_step = sfa_qg_current_step_id();
	$qg_step   = sfa_qg_find_quality_gate_step_id( $form );
	if ( $curr_step && $qg_step && $curr_step === $qg_step ) {
		return $form;
	}

	// ✅ Canonical list of failed item NAMES (works in all contexts)
	$failed = json_decode( (string) gform_get_meta( $entry_id, '_qc_failed_items' ), true );
	$failed = is_array( $failed )
		? array_values( array_filter( array_map( static function( $v ){ return trim( (string) $v ); }, $failed ) ) )
		: array();
		
// Stamp first-seen fail time for each failed item (once).
if ( $entry_id && $failed ) {
	sfa_qg_stamp_fail_times_if_missing( $entry_id, $failed );
}



	// Map item => failed metric labels (for the table only)
	$failed_map = sfa_qg_failed_metric_map( $entry_id, $form );

	// Already fixed (saved) + currently ticked (live POST) = union
	$fixed_saved = json_decode( (string) gform_get_meta( $entry_id, '_qc_recheck_items' ), true );
	$fixed_saved = is_array( $fixed_saved ) ? array_map( 'strval', $fixed_saved ) : array();

	$fixed_now   = sfa_qg_collect_rework_values_from_post( $form, $target_id );
	$fixed_union = array_values( array_unique( array_filter( array_map( 'strval', array_merge( $fixed_saved, $fixed_now ) ) ) ) );
	
sfa_qg_log('READ _qc_recheck_items', [
  'entry_id' => $entry_id,
  'raw'      => gform_get_meta($entry_id, '_qc_recheck_items'),
  'saved'    => $fixed_saved,
]);
	// Are we on the rework (User Input) step?
	$editable = sfa_qg_is_rework_context( $form );

	// Helper table (adds row checkboxes only if $editable is true)
	$table_html = sfa_qg_render_failed_table( $failed_map, $fixed_union, $entry_id, $editable, $target_id );

foreach ( $form['fields'] as &$field ) {
	if ( (int) $field->id !== (int) $target_id || $field->type !== 'checkbox' ) { continue; }

	/* 1) Replace choices with the canonical failed list (keeps GF happy + POST fallback) */
	$field->choices = array();
	foreach ( $failed as $name ) {
		if ( $name === '' ) { continue; }
		$field->choices[] = array(
			'text'       => $name,
			'value'      => $name,
			'isSelected' => in_array( $name, $fixed_union, true ),
		);
	}
	
	
			if ( function_exists('sfa_qg_log') ) {
    sfa_qg_log('QG rework populate',
        array(
            'entry_id'         => sfa_qg_current_entry_id(),
            'target_field_id'  => $target_id,
            'is_user_input'    => sfa_qg_is_rework_context($form),
            'failed_items'     => $failed,
            'failed_map'       => $failed_map,
            'fixed_saved_meta' => $fixed_saved,
            'fixed_now_post'   => $fixed_now,
            'fixed_union_used' => $fixed_union,
        )
    );
}

	/* 1b) Rebuild inputs to match choices */
	$field->inputs = array();
	$idx = 1;
	foreach ( $field->choices as $c ) {
		$field->inputs[] = array(
			'id'    => $field->id . '.' . $idx,
			'label' => $c['text'],
			'name'  => '',
		);
		$idx++;
	}

	/* Resolve entry for editability checks */
	$entry = null;
	if ( class_exists( 'GFAPI' ) ) {
		$_entry = \GFAPI::get_entry( $entry_id );
		if ( ! is_wp_error( $_entry ) ) {
			$entry = $_entry;
		}
	}

/* Determine per-field editability on the current User Input step (robust) */
$editable_field = false;
$step_type      = '';

if ( function_exists('gravity_flow') && $entry ) {
    $sid  = sfa_qg_current_step_id(); // may be 0 on first render after transition
    $step = $sid ? gravity_flow()->get_step($sid) : gravity_flow()->get_current_step($form, $entry);

    if ( $step ) {
        $step_type = property_exists($step, '_step_type') ? (string) $step->_step_type : '';

        $is_user_input = (
            $step_type === 'user_input' ||
            ( class_exists('\Gravity_Flow_Step_User_Input') && $step instanceof \Gravity_Flow_Step_User_Input )
        );

        $is_quality_gate = (
            $step_type === 'quality_gate' ||
            ( class_exists('\SFA\QualityGate\Step_Quality_Gate') && $step instanceof \SFA\QualityGate\Step_Quality_Gate )
        );

        if ( $is_user_input && ! $is_quality_gate ) {
            if ( function_exists('sfa_qg_is_field_editable_on_user_input') ) {
                $editable_field = sfa_qg_is_field_editable_on_user_input($form, $entry, $field);
            } elseif ( method_exists($step, 'is_editable_field') ) {
                $editable_field = (bool) $step->is_editable_field($field, $form, $entry);
            } else {
                $ids = method_exists($step, 'get_editable_fields') ? (array) $step->get_editable_fields() : array();
                $ids = apply_filters('gravityflow_editable_fields', $ids, $step, $form, $entry);
                $editable_field = in_array((int)$field->id, array_map('intval', $ids), true);
            }
        }
    }
}

/* No extra guards here. $editable_field is final. */
sfa_qg_log('REWORK controls state', ['entry_id'=>$entry_id,'step_type'=>$step_type,'editable_field'=>$editable_field]);




	/* Build the helper table (row checkboxes appear only when $editable_field is true) */
	$table_html_local = sfa_qg_render_failed_table( $failed_map, $fixed_union, $entry_id, $editable_field, $target_id );

	/* 2) Description block above the (hidden) GF checkbox list */
	$field->descriptionPlacement = 'above';

$desc  = '<div class="qg-rework-help"'
      . ' data-field-id="' . esc_attr( $target_id ) . '"'
      . ' data-editable="' . ( $editable_field ? '1' : '0' ) . '"'
      . ' data-items="' . count( $failed ) . '"'
      . ' data-fixed="' . esc_attr( wp_json_encode( $fixed_union ) ) . '"'
      . '>';

	// HIDE native GF checkbox UI and any read-only bullet lists in ALL cases (editable or not).
	$desc .= '<style>
		/* Hide the native checkbox container (front/admin) */
		.gfield:has(.qg-rework-help) .ginput_container_checkbox,
		.gfield:has(.qg-rework-help) .gfield_checkbox { display:none!important; }

		/* Hide any UL/OL GF prints under the description (admin single entry, detail views) */
		.qg-rework-help + ul,
		.qg-rework-help ~ ul,
		.qg-rework-help + ol,
		.qg-rework-help ~ ol { display:none!important; }

		/* Common pattern: description followed by UL */
		.gfield:has(.qg-rework-help) .gfield_description + ul { display:none!important; }

		/* Belt-and-suspenders: if something still renders controls when not editable, hide them */
		.qg-rework-help[data-editable="0"] .qg-rework-controls { display:none!important; }
	</style>';

	// Show controls ONLY when the field is editable on this step and there are failed items.
	if ( $editable_field && ! empty( $failed ) ) {
		$desc .= '<div class="qg-rework-controls" style="margin:0 0 8px 0;">'
		       .   '<p class="qg-instruction" style="display:inline-block;margin:0 10px 0 0;">'
		       .       esc_html__( 'Tick the items that have been fixed.', 'sfa-quality-gate' )
		       .   '</p>'
		       .   '<button type="button" class="button qg-select-all-fixed" data-field-id="' . esc_attr( $target_id ) . '">'
		       .       esc_html__( 'Mark all fixed', 'sfa-quality-gate' )
		       .   '</button>'
		       . '</div>';
	}

	// Table always visible for context (row checkboxes only if editable)
	$desc .= $table_html_local ?: '<p class="description" style="margin:0;">' . esc_html__( 'No failed items for this entry.', 'sfa-quality-gate' ) . '</p>';

	$desc .= '</div>';
	$field->description = $desc;



// Stable CSS hook + ensure 'qg-rework' for entry-value filter
$existing_css = isset( $field->cssClass ) ? trim( (string) $field->cssClass ) : '';
$classes = ' ' . $existing_css . ' ';

if ( strpos( $classes, ' qg-rework-field ' ) === false ) {
    $existing_css .= ' qg-rework-field';
    $classes      .= ' qg-rework-field ';
}
if ( strpos( $classes, ' qg-rework ' ) === false ) {
    $existing_css .= ' qg-rework';
}
$field->cssClass = trim( $existing_css );

	
	
	

	break;
}

return $form;

}





// QG-106 — Require all failed items to be ticked as fixed before submit.
// Require all failed items (the rework field choices) to be ticked before submit.
add_filter( 'gform_validation', function( $result ) {
	$form     = $result['form'];

	// Only enforce on the actual rework (User Input) step.
	if ( ! sfa_qg_is_rework_context( $form ) ) {
		return $result;
	}

	$field_id = sfa_qg_find_fixed_checkbox_field_id( $form );
	if ( ! $field_id ) { return $result; }

	// The required list = the choices we injected for the rework field (i.e., failed items).
	$required = array();
	foreach ( $form['fields'] as $f ) {
		if ( (int) $f->id !== (int) $field_id || $f->type !== 'checkbox' ) { continue; }
		foreach ( (array) $f->choices as $c ) {
			$val = trim( (string) rgar( (array) $c, 'value', rgar( (array) $c, 'text', '' ) ) );
			if ( $val !== '' ) { $required[] = $val; }
		}
		break;
	}

	// If somehow choices are not present (edge case), fall back to meta.
	if ( ! $required ) {
		$entry_id = sfa_qg_current_entry_id();
		if ( $entry_id ) {
			$tmp = json_decode( (string) gform_get_meta( $entry_id, '_qc_failed_items' ), true );
			if ( is_array( $tmp ) ) {
				$required = array_values( array_unique( array_filter( array_map( 'strval', $tmp ) ) ) );
			}
		}
	}
	if ( ! $required ) { return $result; }

	// What the user ticked now (supports input_X_Y and input_X[]).
	$selected = sfa_qg_collect_rework_values_from_post( $form, $field_id );

	$missing = array_values( array_diff( $required, $selected ) );
	if ( $missing ) {
		$result['is_valid'] = false;
		foreach ( $form['fields'] as &$fld ) {
			if ( (int) $fld->id === (int) $field_id ) {
				$fld->failed_validation  = true;
				$fld->validation_message = sprintf(
					esc_html__( 'You must mark all failed items as fixed before submitting. Missing: %s', 'sfa-quality-gate' ),
					esc_html( implode( ', ', $missing ) )
				);
			}
		}
		$result['form'] = $form;
	}
	return $result;
}, 9999 );




/**
 * Get current Gravity Flow step ID from request parameters.
 *
 * SECURITY NOTE: Uses absint() to sanitize all input. This is safe for reading
 * step IDs which are always integers. Gravity Flow performs its own authorization
 * checks to ensure users can only access steps they have permission for.
 */
if ( ! function_exists( 'sfa_qg_current_step_id' ) ) {
	function sfa_qg_current_step_id() {
		// Gravity Flow sometimes uses different keys or none at all.
		$keys = array( 'step', 'step_id', 'gflow_step', 'workflow_step', 'current_step' );
		foreach ( $keys as $k ) {
			// Using absint() ensures we only get positive integers, preventing injection
			if ( isset( $_GET[ $k ] ) && $_GET[ $k ] !== '' ) return absint( $_GET[ $k ] );
			if ( isset( $_POST[ $k ] ) && $_POST[ $k ] !== '' ) return absint( $_POST[ $k ] );
		}
		return 0;
	}
}





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



/** Key for per-item meta (safe, short) */
function sfa_qg_item_hash( $name ) {
	return substr( sha1( strtolower( remove_accents( (string) $name ) ) ), 0, 12 );
}

/** Stamp fail time once for every failed item AND guarantee an audit row exists. */
function sfa_qg_stamp_fail_times_if_missing( $entry_id, array $failed_names ) {
	$entry   = class_exists('GFAPI') ? \GFAPI::get_entry( (int) $entry_id ) : null;
	$form_id = ( is_array($entry) && isset($entry['form_id']) ) ? (int) $entry['form_id'] : 0;

	foreach ( $failed_names as $n ) {
		$name = trim( (string) $n );
		if ( $name === '' ) continue;

		$key         = '_qg_fail_time_' . sfa_qg_item_hash( $name );
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


/** Read / write the per-entry fixed log (array of events). */
function sfa_qg_fixed_log_get( $entry_id ) {
	$log = json_decode( (string) gform_get_meta( (int) $entry_id, '_qc_fixed_log' ), true );
	return is_array( $log ) ? $log : array();
}
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
		$seen[ strtolower( (string) ( $ev['item'] ?? '' ) ) ] = true;
	}

	$added = array();
	foreach ( $items as $name ) {
		$name = trim( (string) $name );
		if ( $name === '' ) continue;

		$norm = strtolower( $name );
		if ( isset( $seen[ $norm ] ) ) continue; // already logged for this item

		$failed_at = gform_get_meta( (int) $entry_id, '_qg_fail_time_' . sfa_qg_item_hash( $name ) );
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

/** Collect Fixed analytics for report range (monthly + details). */
function sfa_qg_fixed_report_collect( $range = 'today', $form_id = 0, $ym = '' ) {
	global $wpdb;

	list( $start, $end ) = sfa_qg_report_range_bounds( $range, $ym );
	$start_ts = strtotime( $start );
	$end_ts   = strtotime( $end );

	$em = $wpdb->prefix . 'gf_entry_meta';
	$e  = $wpdb->prefix . 'gf_entry';

	$args = array( '_qc_fixed_log' );
	$sql  = "SELECT e.id AS entry_id, e.form_id, m.meta_value
	         FROM $e e
	         INNER JOIN $em m ON m.entry_id = e.id AND m.meta_key = %s";
	if ( $form_id ) {
		$sql  .= " WHERE e.form_id = %d";
		$args[] = (int) $form_id;
	}

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

	$monthly  = array();   // ym => count
	$avg_map  = array();   // ym => ['sum'=>sec, 'cnt'=>n]
	$details  = array();   // flat rows

	foreach ( (array) $rows as $r ) {
		$log = json_decode( (string) $r['meta_value'], true );
		if ( ! is_array( $log ) ) continue;

		foreach ( $log as $ev ) {
			$fx = isset( $ev['fixed_at'] ) ? strtotime( (string) $ev['fixed_at'] ) : 0;
			if ( ! $fx ) continue;
			if ( $fx < $start_ts || $fx > $end_ts ) continue;

			$ym = gmdate( 'Y-m', $fx );
			$monthly[ $ym ] = ( $monthly[ $ym ] ?? 0 ) + 1;

			$dur = isset( $ev['duration_seconds'] ) ? (int) $ev['duration_seconds'] : null;
			if ( $dur !== null ) {
				if ( ! isset( $avg_map[ $ym ] ) ) $avg_map[ $ym ] = array( 'sum' => 0, 'cnt' => 0 );
				$avg_map[ $ym ]['sum'] += $dur;
				$avg_map[ $ym ]['cnt'] += 1;
			}

			$details[] = array(
				'entry_id'         => (int) $r['entry_id'],
				'form_id'          => (int) $r['form_id'],
				'item'             => (string) ( $ev['item'] ?? '' ),
				'failed_at'        => (string) ( $ev['failed_at'] ?? '' ),
				'fixed_at'         => (string) ( $ev['fixed_at'] ?? '' ),
				'duration_seconds' => $dur,
				'fixed_by'         => (int) ( $ev['fixed_by'] ?? 0 ),
			);
		}
	}

	ksort( $monthly );
	$avg = array();
	foreach ( $avg_map as $ym => $v ) {
		$avg[ $ym ] = (int) round( $v['sum'] / max( 1, $v['cnt'] ) );
	}

	return array(
		'range'   => $range,
		'start'   => $start,
		'end'     => $end,
		'form_id' => (int) $form_id,
		'monthly' => $monthly,
		'avg'     => $avg,
		'details' => $details,
	);
}

/** Humanize seconds */
function sfa_qg_human_dur( $sec ) {
	if ( ! $sec ) return '—';
	$d = floor( $sec / 86400 ); $sec %= 86400;
	$h = floor( $sec / 3600 );  $sec %= 3600;
	$m = floor( $sec / 60 );
	if ( $d > 0 ) return sprintf( '%dd %dh %dm', $d, $h, $m );
	if ( $h > 0 ) return sprintf( '%dh %dm', $h, $m );
	return sprintf( '%dm', $m );
}

	// QG-010 — Entry Detail: compact read-only QC summary box
add_action( 'gform_entry_detail_sidebar_middle', 'sfa_qg_entry_qc_summary_box', 10, 2 );
function sfa_qg_entry_qc_summary_box( $form, $entry ) {
	$sum = json_decode( (string) gform_get_meta( $entry['id'], '_qc_summary' ), true );
	if ( ! is_array( $sum ) ) return;

	$failed_items   = json_decode( (string) gform_get_meta( $entry['id'], '_qc_failed_items' ), true );
	$failed_metrics = json_decode( (string) gform_get_meta( $entry['id'], '_qc_failed_metrics' ), true );
	$failed_items   = is_array( $failed_items )   ? $failed_items   : array();
	$failed_metrics = is_array( $failed_metrics ) ? $failed_metrics : array();

	?>
	<div class="sfa-qg-report" style="margin-top:10px;">
		<h3 style="margin:0 0 6px;"><?php esc_html_e( 'Quality Gate', 'sfa-quality-gate' ); ?></h3>
		<div class="qg-row">
			<div class="qg-name"><?php esc_html_e('Totals','sfa-quality-gate'); ?></div>
			<div class="qg-meta">
				<?php
				printf(
					esc_html__( '%d metrics (%d failed) across %d items', 'sfa-quality-gate' ),
					(int) ($sum['metrics_total'] ?? 0),
					(int) ($sum['metrics_failed'] ?? 0),
					(int) ($sum['items_total'] ?? 0)
				);
				?>
			</div>
		</div>
		<?php if ( $failed_items ) : ?>
			<div class="qg-row">
				<div class="qg-name"><?php esc_html_e('Failed items','sfa-quality-gate'); ?></div>
				<div class="qg-meta"><?php echo esc_html( implode( ', ', $failed_items ) ); ?></div>
			</div>
		<?php endif; ?>
		<?php if ( $failed_metrics ) : ?>
			<div class="qg-row">
				<div class="qg-name"><?php esc_html_e('Failing metrics','sfa-quality-gate'); ?></div>
				<div class="qg-meta"><?php echo esc_html( implode( ', ', $failed_metrics ) ); ?></div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

function sfa_qg_save_recheck_items_from_post( $form, $entry_id ) {
	$field_id = sfa_qg_find_fixed_checkbox_field_id( $form );
if ( ! $field_id && class_exists('\GFAPI') && ! empty($form['id']) ) {
    // Fallback: load the full form (some hooks pass a trimmed form array)
    $full = \GFAPI::get_form( (int) $form['id'] );
    if ( is_array( $full ) ) {
        $field_id = sfa_qg_find_fixed_checkbox_field_id( $full );
        sfa_qg_log('SAVE fallback resolved rework field', [
            'entry_id' => (int)$entry_id,
            'form_id'  => (int)$form['id'],
            'field_id' => (int)$field_id,
        ]);
    }
}
if ( ! $field_id ) {
    sfa_qg_log('SAVE abort: no rework field (after fallback)', [
        'entry_id'=>(int)$entry_id,
        'form_id' => isset($form['id']) ? (int)$form['id'] : 0,
    ]);
    return;
}


	// 1) Try POST (supports input_X_Y and input_X[])
	$selected = sfa_qg_collect_rework_values_from_post( $form, $field_id );
	sfa_qg_log('SAVE collect POST', [
		'entry_id'=>(int)$entry_id,
		'field_id'=>(int)$field_id,
		'selected'=>$selected,
		'post_keys'=>array_keys($_POST),
	]);

	// 2) Fallback: read labels from UPDATED entry
	if ( empty($selected) && class_exists('\GFAPI') ) {
		$entry = \GFAPI::get_entry( $entry_id );
		if ( ! is_wp_error($entry) && is_array($entry) ) {
			$gf_field = class_exists('\GFFormsModel') ? \GFFormsModel::get_field( $form, $field_id ) : null;
			if ( $gf_field && $gf_field->type === 'checkbox' ) {
				$subs = is_callable([$gf_field,'get_entry_inputs']) ? $gf_field->get_entry_inputs() : null;
				if ( is_array($subs) && $subs ) {
					foreach ( $subs as $sub ) {
						$key = isset($sub['id']) ? (string)$sub['id'] : '';
						if ( $key === '' ) continue;
						$val = rgar($entry, $key);
						if ( is_string($val) && $val !== '' ) $selected[] = $val;
					}
				} else {
					$raw = rgar($entry, (string)$field_id);
					if ( is_array($raw) ) {
						foreach ($raw as $v) if (is_string($v) && $v!=='') $selected[]=$v;
					} elseif ( is_string($raw) && $raw!=='') {
						foreach (explode(',', $raw) as $v) { $v=trim($v); if($v!=='') $selected[]=$v; }
					}
				}
			}
		}
		sfa_qg_log('SAVE collect ENTRY fallback', [
			'entry_id'=>(int)$entry_id,
			'field_id'=>(int)$field_id,
			'selected'=>$selected,
		]);
	}

	$selected = array_values( array_unique( array_filter( array_map('strval', $selected) ) ) );
	gform_update_meta( $entry_id, '_qc_recheck_items', wp_json_encode( $selected ) );
	sfa_qg_log('SAVE wrote _qc_recheck_items', ['entry_id'=>(int)$entry_id,'count'=>count($selected),'items'=>$selected]);

	sfa_qg_history_push( $entry_id, 'REWORK_MARKED', ['items'=>$selected,'user'=>get_current_user_id()] );
	
	
	// === Ensure fixed events are logged whenever we persist the selection ===
try {
    $form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

    // Already logged (case-insensitive) → avoid duplicates
    $already = array();
    foreach ( sfa_qg_fixed_log_get( (int) $entry_id ) as $ev ) {
        $already[ strtolower( (string) ( $ev['item'] ?? '' ) ) ] = true;
    }

    // Only add newly-marked items
    $to_add = array();
    foreach ( (array) $selected as $name ) {
        $name = trim( (string) $name );
        if ( $name === '' ) continue;
        if ( ! isset( $already[ strtolower( $name ) ] ) ) {
            $to_add[] = $name;
        }
    }

    if ( $to_add ) {
        $step_id = function_exists( 'sfa_qg_current_step_id' ) ? sfa_qg_current_step_id() : 0;
        $added   = sfa_qg_fixed_log_append_items( $form_id, (int) $entry_id, $to_add, $step_id );
        sfa_qg_log( 'FIXED appended (save handler)', array(
            'entry_id' => (int) $entry_id,
            'added'    => wp_list_pluck( $added, 'item' ),
        ) );
    }
} catch ( \Throwable $t ) {
    sfa_qg_log( 'FIXED append error (save handler): ' . $t->getMessage(), array( 'entry_id' => (int) $entry_id ) );
}

}





// Fires when a Gravity Flow User Input step is saved (rework screen)
add_action('gravityflow_post_update_user_input', function( $step, $entry_id, $form ) {
	sfa_qg_log('HOOK gravityflow_post_update_user_input', [
		'entry_id' => (int)$entry_id,
		'step_id'  => method_exists($step,'get_id') ? (int)$step->get_id() : 0,
		'step_type'=> property_exists($step,'_step_type') ? $step->_step_type : '',
	]);
	// persist ticks
	if ( function_exists('sfa_qg_collect_rework_values_from_post') ) {
		$field_id = sfa_qg_find_fixed_checkbox_field_id($form);
		$vals = sfa_qg_collect_rework_values_from_post($form, $field_id);
		gform_update_meta( (int)$entry_id, '_qc_recheck_items', wp_json_encode(array_values(array_unique(array_filter(array_map('strval',$vals))))) );
		sfa_qg_log('SAVE_RECHECK via GF User Input', ['entry_id'=>(int)$entry_id,'saved'=>$vals]);
		
		// === Fixed logging: add new events for items that weren't logged before ===
try {
	$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;
	$field_id = sfa_qg_find_fixed_checkbox_field_id( $form );
	$now_vals = sfa_qg_collect_rework_values_from_post( $form, $field_id );

	// Dedup against already logged items
	$already = array();
	foreach ( sfa_qg_fixed_log_get( (int) $entry_id ) as $ev ) {
		$already[ strtolower( (string) ( $ev['item'] ?? '' ) ) ] = true;
	}
	$new = array();
	foreach ( (array) $now_vals as $v ) {
		$v = trim( (string) $v );
		if ( $v === '' ) continue;
		if ( ! isset( $already[ strtolower( $v ) ] ) ) $new[] = $v;
	}

	if ( $new ) {
		$step_id = method_exists( $step, 'get_id' ) ? (int) $step->get_id() : 0;
		$added   = sfa_qg_fixed_log_append_items( $form_id, (int) $entry_id, $new, $step_id );
		sfa_qg_log( 'FIXED appended', array( 'entry_id' => (int) $entry_id, 'added' => wp_list_pluck( $added, 'item' ) ) );
	}
} catch ( \Throwable $t ) {
	sfa_qg_log( 'FIXED append error: ' . $t->getMessage(), array( 'entry_id' => (int) $entry_id ) );
}

	}
}, 10, 3);

// --- Persist failed items/metrics from the QC field JSON (robust) ---
if ( ! function_exists( 'sfa_qg_persist_fails_from_qc' ) ) {
	function sfa_qg_persist_fails_from_qc( $form, $entry_id ) {
		if ( ! class_exists( 'GFAPI' ) ) return;

		$entry = \GFAPI::get_entry( (int) $entry_id );
		if ( is_wp_error( $entry ) || ! is_array( $entry ) ) return;

		// Find the QC field id (try passed $form first, then full form).
		$qc_field_id = 0;
		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) {
				$qc_field_id = (int) rgar( (array) $f, 'id' );
				break;
			}
		}
		if ( ! $qc_field_id && ! empty( $entry['form_id'] ) && class_exists( 'GFAPI' ) ) {
			$full = \GFAPI::get_form( (int) $entry['form_id'] );
			if ( is_array( $full ) ) {
				foreach ( (array) rgar( $full, 'fields', array() ) as $f ) {
					if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) {
						$qc_field_id = (int) rgar( (array) $f, 'id' );
						break;
					}
				}
			}
		}
		if ( ! $qc_field_id ) return;

		$raw = rgar( $entry, (string) $qc_field_id );
		if ( ! is_string( $raw ) || $raw === '' ) return;

		$val = json_decode( (string) $raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $val ) ) return;

		$failed_items   = array();
		$failed_metrics = array();

		foreach ( (array) rgar( $val, 'items', array() ) as $it ) {
			$name = trim( (string) rgar( $it, 'name' ) );
			if ( $name === '' ) continue;

			$item_failed = false;
			foreach ( (array) rgar( $it, 'metrics', array() ) as $m ) {
				$res   = strtolower( (string) rgar( $m, 'result', '' ) );
				$label = trim( (string) rgar( $m, 'label', '' ) );
				if ( $res === 'fail' ) {
					$item_failed = true;
					if ( $label !== '' ) $failed_metrics[] = $label;
				}
			}
			if ( $item_failed ) $failed_items[] = $name;
		}

		$failed_items   = array_values( array_unique( array_filter( array_map( 'strval', $failed_items ) ) ) );
		$failed_metrics = array_values( array_unique( array_filter( array_map( 'strval', $failed_metrics ) ) ) );

		// Persist meta so reporting can read it.
		gform_update_meta( (int) $entry_id, '_qc_failed_items',   wp_json_encode( $failed_items ) );
		gform_update_meta( (int) $entry_id, '_qc_failed_metrics', wp_json_encode( $failed_metrics ) );

		// Ensure per-item fail timestamps + audit FAIL rows (idempotent).
		if ( $failed_items ) {
			if ( function_exists( 'sfa_qg_stamp_fail_times_if_missing' ) ) {
				sfa_qg_stamp_fail_times_if_missing( (int) $entry_id, $failed_items );
			}
		}

		// Optional: also log metric-level fail events for the "Top failing metrics" panel.
		if ( function_exists( 'sfa_qg_audit_log_fail' ) ) {
			$form_id = isset( $form['id'] ) ? (int) $form['id'] : (int) rgar( $entry, 'form_id', 0 );
			foreach ( $failed_metrics as $label ) {
				sfa_qg_audit_log_fail( $form_id, (int) $entry_id, 'metric:' . sanitize_title( $label ), $label );
			}
		}

		if ( function_exists( 'sfa_qg_log' ) ) {
			sfa_qg_log( 'SNAPSHOT: qc fails persisted', array(
				'entry_id' => (int) $entry_id,
				'items'    => $failed_items,
				'metrics'  => $failed_metrics,
			) );
		}
	}
}

// Call it whenever an entry is created/updated.
add_action( 'gform_after_submission', function( $entry, $form ) {
	if ( isset( $entry['id'] ) ) {
		sfa_qg_persist_fails_from_qc( $form, (int) $entry['id'] );
	}
}, 9, 2 ); // run before your existing handler if possible

add_action( 'gform_after_update_entry', function( $form, $entry_id ) {
	sfa_qg_persist_fails_from_qc( $form, (int) $entry_id );
}, 9, 2 );


add_action('gform_after_submission', function($entry, $form){
	sfa_qg_log('HOOK gform_after_submission', ['entry_id'=>(int)$entry['id']]);
	sfa_qg_save_recheck_items_from_post($form, (int)$entry['id']);
}, 10, 2);



add_action('gform_after_update_entry', function($form, $entry_id){
	sfa_qg_log('HOOK gform_after_update_entry', ['entry_id'=>(int)$entry_id,'form_id'=>(int)$form['id']]);
	sfa_qg_save_recheck_items_from_post($form, (int)$entry_id);
}, 10, 2);





// === Admin backfill v2: build/repair audit "fail" rows from existing entries ===
add_action( 'admin_init', function () {
	if ( empty( $_GET['sfa_qg_backfill'] ) ) return;
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;

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
			$key         = '_qg_fail_time_' . sfa_qg_item_hash( $name );
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
		'<p>Backfill done.</p>'
		. '<p>Scanned entries: <code>' . esc_html( (string) $scanned ) . '</code><br>'
		. 'New audit rows: <code>' . esc_html( (string) $added ) . '</code><br>'
		. 'Skipped (already existed): <code>' . esc_html( (string) $skipped ) . '</code></p>'
		. '<p>Tip: add <code>&force=1</code> to re-create missing rows if needed.</p>'
	);
}, 99 );



// === TEMP: Peek at last 20 audit rows (admin only) ===
add_action( 'admin_init', function () {
	if ( empty( $_GET['sfa_qg_auditpeek'] ) ) return;
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;

	global $wpdb;
	$tbl = $wpdb->prefix . 'sfa_qg_audit';
	$rows = $wpdb->get_results( "SELECT * FROM $tbl ORDER BY id DESC LIMIT 20", ARRAY_A );

	echo '<div class="wrap"><h1>SFA QG Audit (latest)</h1><table class="widefat striped">';
	echo '<thead><tr><th>ID</th><th>type</th><th>form</th><th>entry</th><th>item</th><th>metric_key</th><th>user</th><th>utc</th></tr></thead><tbody>';
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
}, 99 );
