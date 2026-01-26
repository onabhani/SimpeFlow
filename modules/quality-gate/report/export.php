<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/collect.php';

if ( ! function_exists( 'sfa_qg_handle_export' ) ) {
function sfa_qg_handle_export() {
    // Permissions (same cap as the page)
    $cap = current_user_can( 'gravityflow_workflow' ) ? 'gravityflow_workflow' : 'gravityforms_view_entries';
    if ( ! current_user_can( $cap ) ) {
        wp_die( esc_html__( 'You do not have permission to export this report.', 'sfa-quality-gate' ) );
    }

    // Params
    $range   = isset( $_GET['range'] )   ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : 'today';
    $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
    $ym      = isset( $_GET['ym'] )  ? preg_replace( '/[^0-9\-]/', '', (string) $_GET['ym'] )  : '';
    $ym2     = isset( $_GET['ym2'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $_GET['ym2'] ) : '';
    $tables_ym = ( isset( $_GET['tables_ym'] ) && $_GET['tables_ym'] === 'ym2' ) ? 'ym2' : 'ym1';

    $has_compare = in_array( $range, array( 'month','month_custom' ), true ) && $ym2 !== '';
    $ymA = ( $range === 'month' )
        ? date_i18n( 'Y-m', current_time( 'timestamp' ) )
        : ( $ym ?: date_i18n( 'Y-m', current_time( 'timestamp' ) ) );

    if ( $has_compare ) { $collect_range = 'month_custom'; $collect_ym = ( $tables_ym === 'ym2' ) ? $ym2 : $ymA; }
    elseif ( $range === 'month' ) {   $collect_range = 'month_custom'; $collect_ym = $ymA; }
    else {                            $collect_range = $range;         $collect_ym = $ym; }

    $data = sfa_qg_report_collect( $collect_range, $form_id, $collect_ym );
    $fx   = sfa_qg_fixed_report_collect( $collect_range, $form_id, $collect_ym );

    $type = sanitize_key( $_GET['qg_export'] );
    $rows = array();
    $fn   = 'quality-gate.csv';

    if ( $type === 'top_failed' ) {
        $rows[] = array( 'Item', 'Failures' );
        foreach ( $data['top_failed'] as $name => $count ) $rows[] = array( $name, (int) $count );
        $fn = 'qg-top-failed-items.csv';

    } elseif ( $type === 'failed_entries' ) {
        $rows[] = array( 'Entry ID', 'Form ID', 'Date', 'Failed items', 'Failed metrics (count)', 'Link' );
        foreach ( (array) $data['failed_entries'] as $r ) {
            $link = admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $r['form_id'] . '&lid=' . (int) $r['entry_id'] );
            $rows[] = array( (int)$r['entry_id'], (int)$r['form_id'], (string)$r['date_created'],
                             implode(', ', (array)$r['items']), (int)$r['metrics_failed'], $link );
        }
        $fn = 'qg-failed-entries.csv';

    } elseif ( $type === 'top_failed_metrics' ) {
        $rows[] = array( 'Metric label', 'Failures' );
        foreach ( $data['top_failed_metrics'] as $label => $count ) $rows[] = array( $label, (int)$count );
        $fn = 'qg-top-failed-metrics.csv';

    } elseif ( $type === 'latest_failed' ) {
        $rows[] = array( 'Entry ID', 'Form ID', 'Date', 'Failed metrics', 'Link' );
        foreach ( (array) $data['latest_failed'] as $r ) {
            $link = admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int)$r['form_id'] . '&lid=' . (int)$r['entry_id'] );
            $rows[] = array( (int)$r['entry_id'], (int)$r['form_id'], (string)$r['date_created'], (int)$r['metrics_failed'], $link );
        }
        $fn = 'qg-latest-failed.csv';

    } elseif ( $type === 'fixed_monthly' ) {
        $rows[] = array( 'Month', 'Fixed count', 'Avg time to fix' );
        foreach ( $fx['monthly'] as $ym_k => $cnt ) {
            $avg = isset( $fx['avg'][ $ym_k ] ) ? sfa_qg_human_dur( (int)$fx['avg'][ $ym_k ] ) : '—';
            $rows[] = array( $ym_k, (int)$cnt, $avg );
        }
        $fn = 'qg-fixed-monthly.csv';

    } elseif ( $type === 'fixed_details' ) {
        $rows[] = array( 'Entry ID', 'Form ID', 'Item', 'Failed at', 'Fixed at', 'Time to fix (sec)', 'Link' );
        foreach ( (array) $fx['details'] as $r ) {
            $link = admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $r['form_id'] . '&lid=' . (int) $r['entry_id'] );
            $rows[] = array(
                (int)$r['entry_id'], (int)$r['form_id'], (string)$r['item'],
                (string)( $r['failed_at'] ?? '' ),
                (string)( $r['fixed_at'] ?? '' ),
                (int)( $r['duration_seconds'] ?? 0 ),
                $link
            );
        }
        $fn = 'qg-fixed-details.csv';
    }

    // Stream CSV
    while ( ob_get_level() ) { ob_end_clean(); }
    if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
    if ( function_exists( 'ini_get' ) && ini_get( 'zlib.output_compression' ) ) {
        @ini_set( 'zlib.output_compression', 'Off' );
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $fn );
    header( 'X-Content-Type-Options: nosniff' );

    echo "\xEF\xBB\xBF"; // Excel BOM

    $csv_safe = static function ( $v ) {
        if ( is_array( $v ) || is_object( $v ) ) {
            $v = wp_json_encode( $v );
        }
        $v = (string) $v;
        $v = str_replace( array("\r\n", "\r"), "\n", $v );
        if ( $v !== '' && in_array( $v[0], array( '=', '+', '-', '@' ), true ) ) {
            $v = "'" . $v;
        }
        return $v;
    };

    $out = fopen( 'php://output', 'w' );
    foreach ( $rows as $row ) {
        fputcsv( $out, array_map( $csv_safe, (array) $row ) );
    }
    fflush( $out );
    fclose( $out );
    exit;
}}

add_action('admin_init', function () {
	if ( ! is_admin() ) return;
	if ( ! isset($_GET['page']) || $_GET['page'] !== 'sfa-qg-report' ) return;
	if ( ! isset($_GET['qg_export']) ) return;
	sfa_qg_handle_export();
}, 1);




