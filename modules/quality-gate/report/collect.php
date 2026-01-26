<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/utils.php';

if ( ! function_exists( 'sfa_qg_report_collect' ) ) {
    function sfa_qg_report_collect( $range = 'today', $form_id = 0, $ym = '' ) {
        global $wpdb;

// Local window for display
list( $start_local, $end_local ) = sfa_qg_report_range_bounds( $range, $ym );

// Convert to UTC for gf_entry.date_created (GF stores UTC in DB)
$start_utc = get_gmt_from_date( $start_local, 'Y-m-d H:i:s' );
$end_utc   = get_gmt_from_date( $end_local,   'Y-m-d H:i:s' );

$em = $wpdb->prefix . 'gf_entry_meta';
$e  = $wpdb->prefix . 'gf_entry';

$where  = "e.date_created BETWEEN %s AND %s";
$params = array( '_qc_summary', $start_utc, $end_utc );


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
        $failed_entries     = array();

        foreach ( (array) $rows as $r ) {
            $entry_ids[] = (int) $r['id'];
            $entry_forms_map[(int)$r['id']] = (int)$r['form_id'];

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
                    $failed_entries[ (int) $r['id'] ] = array(
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
            $em = $wpdb->prefix . 'gf_entry_meta';
            $in = implode( ',', array_map( 'intval', array_slice( $entry_ids, 0, 1000 ) ) );

            $q2 = "SELECT entry_id, meta_value FROM $em WHERE meta_key = '_qc_failed_items' AND entry_id IN ($in)";
            foreach ( (array) $wpdb->get_results( $q2, ARRAY_A ) as $r2 ) {
                $list = json_decode( (string) $r2['meta_value'], true );
                if ( is_array( $list ) ) {
                    $eid = (int) $r2['entry_id'];
                    foreach ( $list as $name ) {
                        $name = trim( (string) $name );
                        if ( $name === '' ) continue;
                        $top_failed[ $name ] = ($top_failed[ $name ] ?? 0) + 1;
                        if ( isset( $failed_entries[ $eid ] ) ) {
                            $failed_entries[ $eid ]['items'][] = $name;
                        }
                    }
                    if ( isset( $failed_entries[ $eid ] ) ) {
                        $failed_entries[ $eid ]['items'] = array_values( array_unique( $failed_entries[ $eid ]['items'] ) );
                    }
                }
            }

            $q3 = "SELECT entry_id, meta_value FROM $em WHERE meta_key = '_qc_failed_metrics' AND entry_id IN ($in)";
            foreach ( (array) $wpdb->get_results( $q3, ARRAY_A ) as $r3 ) {
                $list = json_decode( (string) $r3['meta_value'], true );
                if ( is_array( $list ) ) {
                    $eid = (int) $r3['entry_id'];
                    foreach ( $list as $label ) {
                        $label = trim( (string) $label );
                        if ( $label === '' ) continue;
                        $top_failed_metrics[ $label ] = ($top_failed_metrics[ $label ] ?? 0) + 1;
                        if ( isset( $failed_entries[ $eid ] ) ) {
                            $failed_entries[ $eid ]['metrics_labels'][] = $label;
                        }
                    }
                    if ( isset( $failed_entries[ $eid ] ) ) {
                        $failed_entries[ $eid ]['metrics_labels'] = array_values( array_unique( $failed_entries[ $eid ]['metrics_labels'] ) );
                    }
                }
            }
        }

// ---- Guard: if metrics look contaminated by item names, rebuild from audit (metric events only)
$maybe_mixed = false;
if ( $top_failed && $top_failed_metrics ) {
    $item_names = array_map( 'strval', array_keys( $top_failed ) );
    foreach ( array_keys( $top_failed_metrics ) as $lbl ) {
        if ( in_array( (string) $lbl, $item_names, true ) ) { $maybe_mixed = true; break; }
    }
}

if ( $maybe_mixed ) {
    $tbl = $wpdb->prefix . 'sfa_qg_audit';
    $where_a = "event_type = 'fail' AND event_utc BETWEEN %s AND %s AND metric_key LIKE 'metric:%'";
    $args_a  = array( $start_utc, $end_utc );
    if ( $form_id ) { $where_a .= " AND form_id = %d"; $args_a[] = (int) $form_id; }

    $rows_a = $wpdb->get_results(
        $wpdb->prepare("SELECT metric_key FROM $tbl WHERE $where_a", $args_a),
        ARRAY_A
    );

    $fixed_metrics = array();
    foreach ( (array) $rows_a as $ra ) {
        $key = (string) ( $ra['metric_key'] ?? '' );
        if ( strpos( $key, 'metric:' ) === 0 ) {
            $label = trim( substr( $key, 7 ) ); // strip "metric:"
            if ( $label !== '' ) $fixed_metrics[ $label ] = ( $fixed_metrics[ $label ] ?? 0 ) + 1;
        }
    }
    if ( $fixed_metrics ) {
        $top_failed_metrics = $fixed_metrics;
    }
}


        arsort( $top_failed );
        arsort( $top_failed_metrics );

        $failed_entries = array_values( $failed_entries );
        usort( $failed_entries, static function( $a, $b ) {
            return strcmp( (string) $b['date_created'], (string) $a['date_created'] );
        } );

        if ( ! empty( $latest_failed ) ) {
            usort( $latest_failed, static function( $a, $b ) {
                return strcmp( (string) $b['date_created'], (string) $a['date_created'] );
            } );
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



// If still zero, count distinct metric fail events in the audit window (robust fallback)
if ( (int) $totals['metrics_failed'] === 0 ) {
    $tbl = $wpdb->prefix . 'sfa_qg_audit';
    $where_audit = "event_type = 'fail' AND event_utc BETWEEN %s AND %s AND metric_key LIKE 'metric:%'";
    $args_audit  = array( $start_utc, $end_utc );
    if ( $form_id ) { $where_audit .= " AND form_id = %d"; $args_audit[] = (int) $form_id; }

    $audit_failed = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT CONCAT(entry_id,'|',COALESCE(metric_key,''),'|',COALESCE(item_label,''))) FROM $tbl WHERE $where_audit",
            $args_audit
        )
    );
    if ( $audit_failed > 0 ) {
        $totals['metrics_failed'] = $audit_failed;
    }
}
// OPTIONAL: keep KPI 'Failed metrics' non-zero if there were fail events in the window (even if fixed now)
if ( (int) $totals['metrics_failed'] === 0 ) {
    $tbl = $wpdb->prefix . 'sfa_qg_audit';

    // Count distinct metric occurrences per entry+item+metric in the window.
    // Works even if metric_key is empty in your audit rows.
    $where_audit = "event_type = 'fail' AND event_utc BETWEEN %s AND %s";
    $args_audit  = array( $start_utc, $end_utc );
    if ( $form_id ) {
        $where_audit .= " AND form_id = %d";
        $args_audit[] = (int) $form_id;
    }

    $sql = "SELECT COUNT(DISTINCT CONCAT(entry_id,'|',COALESCE(metric_key,''),'|',COALESCE(item_label,'')))
            FROM $tbl WHERE $where_audit";

    $audit_failed = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args_audit ) );
    if ( $audit_failed > 0 ) {
        $totals['metrics_failed'] = $audit_failed;
    }
}




        $completion = $totals['metrics_total'] > 0
            ? round( 100 * ( $totals['metrics_total'] - $totals['metrics_failed'] ) / $totals['metrics_total'], 1 )
            : 0;

        return array(
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
    }
}

if ( ! function_exists( 'sfa_qg_fixed_report_collect' ) ) {
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

        $monthly  = array();
        $avg_map  = array();
        $details  = array();

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
    }}
    
    // === History collector (uses the audit table if present) ====================
if ( ! function_exists( 'sfa_qg_report_collect_history' ) ) {
	function sfa_qg_report_collect_history( $range = 'month', $form_id = 0, $ym = '' ) {
		global $wpdb;

		$out = array(
			'failed_entries'      => array(),
			'top_failed_metrics'  => array(),
			'latest_failed'       => array(),
			'_has_history'        => false,
		);

		$tbl = $wpdb->prefix . 'sfa_qg_audit';
		$has = $wpdb->get_var( $wpdb->prepare(
			"SHOW TABLES LIKE %s", $wpdb->esc_like( $tbl )
		) );
		if ( $has !== $tbl ) {
			return $out; // table not present (fresh install) — graceful no-op
		}

		// Date window in UTC (audit timestamps are UTC)
		$now = current_time( 'timestamp', true ); // UTC ts
		$start = $end = '';
		switch ( $range ) {
			case 'today':
				$start = gmdate( 'Y-m-d 00:00:00', $now );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
				break;
			case 'month':
				$start = gmdate( 'Y-m-01 00:00:00', $now );
				$end   = gmdate( 'Y-m-t 23:59:59', $now );
				break;
			case 'year':
				$start = gmdate( 'Y-01-01 00:00:00', $now );
				$end   = gmdate( 'Y-12-31 23:59:59', $now );
				break;
			case 'month_custom':
				// $ym = YYYY-MM
				if ( preg_match( '/^\d{4}\-\d{2}$/', (string) $ym ) ) {
					$ts    = strtotime( $ym . '-01 00:00:00' );
					$start = gmdate( 'Y-m-01 00:00:00', $ts );
					$end   = gmdate( 'Y-m-t 23:59:59', $ts );
				}
				break;
			case 'year_custom':
				// $ym = YYYY
				if ( preg_match( '/^\d{4}$/', (string) $ym ) ) {
					$ts    = strtotime( $ym . '-01-01 00:00:00' );
					$start = gmdate( 'Y-01-01 00:00:00', $ts );
					$end   = gmdate( 'Y-12-31 23:59:59', $ts );
				}
				break;
			default:
				$start = gmdate( 'Y-m-01 00:00:00', $now );
				$end   = gmdate( 'Y-m-t 23:59:59', $now );
				break;
		}
		if ( ! $start || ! $end ) {
			return $out;
		}

		$where = $wpdb->prepare( "event_utc BETWEEN %s AND %s AND event_type = 'fail'", $start, $end );
		if ( $form_id ) {
			$where .= $wpdb->prepare( " AND form_id = %d", $form_id );
		}

		// 1) Failed entries (group entry_id within the window)
$sql = "SELECT entry_id, form_id, MAX(event_utc) AS last_ts,
        GROUP_CONCAT(DISTINCT item_label ORDER BY event_utc SEPARATOR '||') AS items
        FROM $tbl
        WHERE $where AND (metric_key = '' OR metric_key LIKE 'item:%')
        GROUP BY form_id, entry_id
        ORDER BY last_ts DESC";
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		foreach ( (array) $rows as $r ) {
			$out['failed_entries'][] = array(
				'entry_id'     => (int) $r['entry_id'],
				'form_id'      => (int) $r['form_id'],
				'date_created' => gmdate( 'Y-m-d H:i', strtotime( $r['last_ts'] ) ),
				'items'        => array_filter( array_map( 'trim', explode( '||', (string) $r['items'] ) ) ),
			);
		}
		
		// Attach metric labels per entry (from metric events only)
$sql4 = "SELECT entry_id,
         GROUP_CONCAT(DISTINCT COALESCE(NULLIF(item_label,''), SUBSTRING(metric_key, 8))
                      ORDER BY event_utc SEPARATOR '||') AS labels
         FROM $tbl
         WHERE $where AND metric_key LIKE 'metric:%'
         GROUP BY entry_id";
$rows4 = $wpdb->get_results( $sql4, ARRAY_A );

$labels_by_entry = array();
foreach ( (array) $rows4 as $r4 ) {
    $labels_by_entry[ (int) $r4['entry_id'] ] = array_filter(
        array_map( 'trim', explode( '||', (string) $r4['labels'] ) )
    );
}

// merge into failed_entries
foreach ( $out['failed_entries'] as &$fe ) {
    $eid = (int) $fe['entry_id'];
    $fe['metrics_labels'] = isset( $labels_by_entry[$eid] ) ? $labels_by_entry[$eid] : array();
}
unset( $fe );


		// 2) Top failing metrics (count occurrences in window)
$sql2 = "SELECT COALESCE(NULLIF(item_label,''), SUBSTRING(metric_key, 8)) AS label, COUNT(*) AS c
         FROM $tbl
         WHERE $where AND metric_key LIKE 'metric:%'
         GROUP BY label
         ORDER BY c DESC, label ASC";

		$rows2 = $wpdb->get_results( $sql2, ARRAY_A );
		$out['top_failed_metrics'] = array();
		foreach ( (array) $rows2 as $r ) {
			$out['top_failed_metrics'][ (string) $r['label'] ] = (int) $r['c'];
		}

		// 3) Latest failed entries (with a count of metrics_failed per entry in window)
$sql3 = "SELECT form_id, entry_id, MAX(event_utc) AS last_ts,
         COUNT(DISTINCT metric_key) AS metrics_failed
         FROM $tbl
         WHERE $where AND metric_key LIKE 'metric:%'
         GROUP BY form_id, entry_id
         ORDER BY last_ts DESC";

		$rows3 = $wpdb->get_results( $sql3, ARRAY_A );
		foreach ( (array) $rows3 as $r ) {
			$out['latest_failed'][] = array(
				'entry_id'       => (int) $r['entry_id'],
				'form_id'        => (int) $r['form_id'],
				'date_created'   => gmdate( 'Y-m-d H:i', strtotime( $r['last_ts'] ) ),
				'metrics_failed' => (int) $r['metrics_failed'],
			);
		}

		$out['_has_history'] = ( ! empty( $rows ) || ! empty( $rows2 ) || ! empty( $rows3 ) );
		return $out;
	}
}

