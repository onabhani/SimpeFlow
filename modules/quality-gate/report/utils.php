<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'sfa_qg_report_range_bounds' ) ) {
    function sfa_qg_report_range_bounds( $range, $ym = '' ) {
        $range = strtolower( (string) $range );
        $now   = current_time( 'timestamp' );

        switch ( $range ) {
            case 'year':
                $start = date_i18n( 'Y-01-01 00:00:00', $now );
                $end   = date_i18n( 'Y-12-31 23:59:59', $now );
                break;

            case 'last_year':
                $last_year = (int) date_i18n( 'Y', $now ) - 1;
                $start = $last_year . '-01-01 00:00:00';
                $end   = $last_year . '-12-31 23:59:59';
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

            case 'year_custom':
                $yy = preg_replace('/[^0-9]/', '', (string) $ym );
                if ( ! preg_match('/^\d{4}$/', $yy ) ) {
                    $yy = date_i18n( 'Y', $now );
                }
                $ts    = strtotime( $yy . '-01-01 00:00:00' );
                $start = date_i18n( 'Y-01-01 00:00:00', $ts );
                $end   = date_i18n( 'Y-12-31 23:59:59', $ts );
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

if ( ! function_exists( 'sfa_qg_human_dur' ) ) {
    function sfa_qg_human_dur( $sec ) {
        if ( ! $sec ) return '—';
        $d = floor( $sec / 86400 ); $sec %= 86400;
        $h = floor( $sec / 3600 );  $sec %= 3600;
        $m = floor( $sec / 60 );
        if ( $d > 0 ) return sprintf( '%dd %dh %dm', $d, $h, $m );
        if ( $h > 0 ) return sprintf( '%dh %dm', $h, $m );
        return sprintf( '%dm', $m );
    }
}
