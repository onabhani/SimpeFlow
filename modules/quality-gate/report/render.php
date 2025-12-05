<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/collect.php';

if ( ! function_exists( 'sfa_qg_report_render_html' ) ) {
    function sfa_qg_report_render_html( $range = 'today', $form_id = 0, $ym = '', $ym2 = '' ) {
        $data  = sfa_qg_report_collect( $range, $form_id, $ym );
        $fx    = sfa_qg_fixed_report_collect( $range, $form_id, $ym );
// Use audit history only for the tables; keep KPI cards and top_failed_metrics from live/meta data
$hist = function_exists( 'sfa_qg_report_collect_history' )
    ? sfa_qg_report_collect_history( $range, $form_id, $ym )
    : array();

if ( ! empty( $hist['_has_history'] ) ) {
    $data['failed_entries'] = $hist['failed_entries'];
    $data['latest_failed']  = $hist['latest_failed'];
    // DO NOT set $data['top_failed_metrics'] here.
}




        // ===== Comparison prep (month↔month and year↔year) =====
        $is_month_cmp = in_array( $range, array( 'month', 'month_custom' ), true ) && $ym2 !== '';
        $is_year_cmp  = ( $range === 'year_custom' ) && preg_match( '/^\d{4}$/', (string) $ym ) && preg_match( '/^\d{4}$/', (string) $ym2 );
        $has_compare  = $is_month_cmp || $is_year_cmp;

        $cmpA = $cmpB = null;
        $labelA = $labelB = '';   // shown in headers/legend
        $winMetrics = $winItems = '';
        $impMetrics = $impItems = 0;

        if ( $is_month_cmp ) {
            // Left side (this month when range=month; otherwise specific)
            $labelA = ( $range === 'month' )
                ? date_i18n( 'Y-m', current_time( 'timestamp' ) )
                : ( $ym ?: date_i18n( 'Y-m', current_time( 'timestamp' ) ) );
            $labelB = (string) $ym2;

            $cmpA = sfa_qg_report_collect( 'month_custom', $form_id, $labelA );
            $cmpB = sfa_qg_report_collect( 'month_custom', $form_id, $labelB );
        } elseif ( $is_year_cmp ) {
            $labelA = preg_match( '/^\d{4}$/', (string) $ym ) ? (string) $ym : date_i18n( 'Y', current_time( 'timestamp' ) );
            $labelB = (string) $ym2;

            $cmpA = sfa_qg_report_collect( 'year_custom', $form_id, $labelA );
            $cmpB = sfa_qg_report_collect( 'year_custom', $form_id, $labelB );
        }

        if ( $has_compare ) {
            $metA = (int) ( $cmpA['totals']['metrics_failed'] ?? 0 );
            $metB = (int) ( $cmpB['totals']['metrics_failed'] ?? 0 );
            $itmA = array_sum( array_map( 'intval', (array) ( $cmpA['top_failed'] ?? array() ) ) );
            $itmB = array_sum( array_map( 'intval', (array) ( $cmpB['top_failed'] ?? array() ) ) );

            if ( $metA !== $metB ) {
                $winMetrics = ( $metA < $metB ) ? $labelA : $labelB;
                $impMetrics = round( 100 * abs( $metA - $metB ) / max( $metA, $metB ), 1 );
            }
            if ( $itmA !== $itmB ) {
                $winItems = ( $itmA < $itmB ) ? $labelA : $labelB;
                $impItems = round( 100 * abs( $itmA - $itmB ) / max( $itmA, $itmB ), 1 );
            }
        }

        // ===== KPI “vs previous month” deltas (always shown in cards) =====
        $refYM = ( $range === 'month' )
            ? date_i18n( 'Y-m', current_time( 'timestamp' ) )
            : ( ( $range === 'month_custom' && preg_match( '/^\d{4}\-\d{2}$/', (string) $ym ) ) ? $ym : date_i18n( 'Y-m', current_time( 'timestamp' ) ) );
        $prevYM   = gmdate( 'Y-m', strtotime( $refYM . '-01 -1 month' ) );
        $prevData = sfa_qg_report_collect( 'month_custom', $form_id, $prevYM );

        $qg_delta = function( $now, $old, $polarity = 'neutral', $is_percent = false ) use ( $prevYM ) {
            $now  = (float) $now; $old  = (float) $old;
            $diff = $now - $old;
            $sign = $diff > 0 ? '+' : ( $diff < 0 ? '−' : '±' );
            $cls  = 'qg-delta ' . ( $diff > 0 ? 'up' : ( $diff < 0 ? 'down' : 'zero' ) );
            if ( $polarity === 'less_is_good' ) {
                $cls .= ( $diff < 0 ? ' good' : ( $diff > 0 ? ' bad' : '' ) );
            } elseif ( $polarity === 'more_is_good' ) {
                $cls .= ( $diff > 0 ? ' good' : ( $diff < 0 ? ' bad' : '' ) );
            }
            $val = $is_percent ? sprintf( '%.1fpp', abs( $diff ) ) : (int) abs( $diff );
            $pct = ( ! $is_percent && $old > 0 ) ? ' <small>(' . round( 100 * abs( $diff ) / $old, 1 ) . '%)</small>' : '';
            return '<div class="' . esc_attr( $cls ) . '">' . esc_html__( 'vs', 'sfa-quality-gate' ) . ' ' . esc_html( $prevYM ) . ' ' . esc_html( $sign . $val ) . $pct . '</div>';
        };

        ob_start(); ?>
        <div class="sfa-qg-report" id="sfa-qg-report-root">
<style>
/* Layout & base */
.sfa-qg-report .cards{display:flex;gap:12px;flex-wrap:wrap;margin:10px 0 16px}
.sfa-qg-report .card{border:1px solid #e2e2e2;border-radius:8px;padding:12px 14px;background:#fff;min-width:180px;box-shadow:0 1px 0 rgba(0,0,0,.02)}
.sfa-qg-report h2{margin:0 0 8px;font-size:18px}
.sfa-qg-report h3{margin:16px 0 8px;font-size:16px}

/* Sticky subnav */
.sfa-qg-subnav{position:sticky;top:32px;z-index:5;background:#f6f7f7;border:1px solid #e2e2e2;border-radius:8px;padding:8px 12px;margin:8px 0 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.sfa-qg-subnav a{text-decoration:none;border:1px solid #d1d5db;background:#fff;border-radius:999px;padding:4px 10px;font-size:12px}
.sfa-qg-subnav .sep{opacity:.35;margin:0 2px}
.sfa-qg-subnav .share{margin-left:auto}

/* Table */
.sfa-qg-report .qg-table{border-collapse:separate;border-spacing:0;width:100%}
.sfa-qg-report .qg-table th,.sfa-qg-report .qg-table td{border:1px solid #eee;padding:8px 10px;text-align:left}
.sfa-qg-report .qg-table thead th{background:#f9fafb;font-weight:600}
.sfa-qg-report .qg-table tr:nth-child(even) td{background:#fafafa}
.sfa-qg-mono{font-family:Menlo,Consolas,monospace}

/* Sortable headers */
.qg-sort{cursor:pointer;user-select:none;position:relative}
.qg-sort:after{content:"⇵";font-size:11px;opacity:.45;margin-left:6px}
.qg-sort.asc:after{content:"↑";opacity:.8}
.qg-sort.desc:after{content:"↓";opacity:.8}

/* Section chrome */
.qg-section{border:1px solid #e5e7eb;border-radius:10px;background:#fff;margin:14px 0;padding:0}
.qg-section summary{list-style:none;cursor:pointer;padding:12px 14px;border-bottom:1px solid #eff2f4;display:flex;align-items:center;gap:8px}
.qg-section summary::-webkit-details-marker{display:none}
.qg-section[open] summary{background:#fafafa}
.qg-section .qg-inner{padding:10px 14px 14px}
.qg-badge{display:inline-block;padding:1px 8px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;font-size:12px}

/* Search / filter */
.qg-tools{display:flex;align-items:center;gap:10px;margin:0 0 8px}
.qg-search{max-width:220px}
.qg-search input{width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px}
.qg-count{font-size:12px;color:#6b7280}

/* Comparison block */
.sfa-qg-compare{margin:8px 0 18px;padding:10px 12px;border:1px solid #e2e2e2;border-radius:6px;background:#fff}
.sfa-qg-compare svg{display:block;width:100%;max-width:700px;height:auto}

/* Delta coloring */
.qg-delta{font-size:12px;margin-top:4px;color:#6b7280}
.qg-delta.up.bad,.qg-delta.down.bad{color:#b91c1c}
.qg-delta.up.good,.qg-delta.down.good{color:#15803d}
.qg-delta.zero{opacity:.75}
.qg-delta small{opacity:.8}
.qg-section{scroll-margin-top: 90px;}
.qg-section.qg-hit{box-shadow:0 0 0 3px #c7d2fe inset; transition: box-shadow .3s;}
</style>

            <h2><?php echo esc_html( sprintf( __( 'Quality Gate Report — %s', 'sfa-quality-gate' ), ucfirst( $data['range'] ) ) ); ?></h2>
            <p><small><?php echo esc_html( $data['start'] ); ?> → <?php echo esc_html( $data['end'] ); ?></small></p>

<!-- Sticky subnav (single instance) -->
<div class="sfa-qg-subnav" role="navigation" aria-label="<?php esc_attr_e('Report sections','sfa-quality-gate'); ?>">
  <a href="#sec-fe"><?php esc_html_e('Failed entries','sfa-quality-gate'); ?></a>
  <a href="#sec-fm"><?php esc_html_e('Top failing metrics','sfa-quality-gate'); ?></a>
  <a href="#sec-lf"><?php esc_html_e('Latest failed entries','sfa-quality-gate'); ?></a>
  <span class="sep">·</span>
  <a href="#sec-fxm"><?php esc_html_e('Fixed — Monthly','sfa-quality-gate'); ?></a>
  <a href="#sec-fxd"><?php esc_html_e('Fixed — Details','sfa-quality-gate'); ?></a>

  <!-- right-aligned actions -->
  <a class="share" href="#" id="qg-share-link" style="margin-left:auto;"><?php esc_html_e('Share this view','sfa-quality-gate'); ?></a>
  <a class="share" href="#" id="qg-reset-layout" style="margin-left:8px;"><?php esc_html_e('Reset layout','sfa-quality-gate'); ?></a>
</div>


            <!-- KPI Cards -->
            <div class="cards">
                <div class="card">
                    <div><?php esc_html_e('Total metrics','sfa-quality-gate'); ?></div>
                    <div class="sfa-qg-mono"><strong><?php echo (int) $data['totals']['metrics_total']; ?></strong></div>
                    <?php echo $qg_delta( (int) $data['totals']['metrics_total'], (int) ( $prevData['totals']['metrics_total'] ?? 0 ) ); ?>
                </div>
                <div class="card">
                    <div><?php esc_html_e('Failed metrics','sfa-quality-gate'); ?></div>
                    <div class="sfa-qg-mono"><strong><?php echo (int) $data['totals']['metrics_failed']; ?></strong></div>
                    <?php echo $qg_delta( (int) $data['totals']['metrics_failed'], (int) ( $prevData['totals']['metrics_failed'] ?? 0 ), 'less_is_good' ); ?>
                </div>
                <div class="card">
                    <div><?php esc_html_e('Completion %','sfa-quality-gate'); ?></div>
                    <div class="sfa-qg-mono"><strong><?php echo esc_html( $data['completion'] ); ?>%</strong></div>
                    <?php echo $qg_delta( (float) $data['completion'], (float) ( $prevData['completion'] ?? 0 ), 'more_is_good', true ); ?>
                </div>
                <div class="card">
                    <div><?php esc_html_e('Items checked','sfa-quality-gate'); ?></div>
                    <div class="sfa-qg-mono"><strong><?php echo (int) $data['totals']['items_total']; ?></strong></div>
                    <?php echo $qg_delta( (int) $data['totals']['items_total'], (int) ( $prevData['totals']['items_total'] ?? 0 ) ); ?>
                </div>
                <div class="card">
                    <div><?php esc_html_e('Entries included','sfa-quality-gate'); ?></div>
                    <div class="sfa-qg-mono"><strong><?php echo (int) $data['totals']['entries']; ?></strong></div>
                    <?php echo $qg_delta( (int) $data['totals']['entries'], (int) ( $prevData['totals']['entries'] ?? 0 ) ); ?>
                </div>
            </div>

            <?php if ( $has_compare ) : ?>
                <style>.sfa-qg-report .qg-table td.num,.sfa-qg-report .qg-table th.num{text-align:right;white-space:nowrap}</style>
                <h3 style="margin-top:6px;"><?php esc_html_e('Comparison','sfa-quality-gate'); ?></h3>
                <p><small><?php echo esc_html( $labelA ); ?> vs <?php echo esc_html( $labelB ); ?></small></p>

                <table class="qg-table" aria-label="<?php esc_attr_e('Period comparison','sfa-quality-gate'); ?>">
                  <thead>
                    <tr>
                      <th><?php esc_html_e('Metric','sfa-quality-gate'); ?></th>
                      <th class="num"><?php echo esc_html( $labelA ); ?></th>
                      <th class="num"><?php echo esc_html( $labelB ); ?></th>
                      <th class="num"><?php esc_html_e('Δ','sfa-quality-gate'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td><?php esc_html_e('Failed metrics','sfa-quality-gate'); ?></td>
                      <td class="num"><?php echo (int) $metA; ?></td>
                      <td class="num"><?php echo (int) $metB; ?></td>
                      <td class="num"><?php echo (int) ( $metB - $metA ); ?></td>
                    </tr>
                    <tr>
                      <td><?php esc_html_e('Failed item occurrences','sfa-quality-gate'); ?></td>
                      <td class="num"><?php echo (int) $itmA; ?></td>
                      <td class="num"><?php echo (int) $itmB; ?></td>
                      <td class="num"><?php echo (int) ( $itmB - $itmA ); ?></td>
                    </tr>
                    <tr>
                      <td><?php esc_html_e('Items checked','sfa-quality-gate'); ?></td>
                      <td class="num"><?php echo (int) ( $cmpA['totals']['items_total'] ?? 0 ); ?></td>
                      <td class="num"><?php echo (int) ( $cmpB['totals']['items_total'] ?? 0 ); ?></td>
                      <td class="num"><?php echo (int) ( ( $cmpB['totals']['items_total'] ?? 0 ) - ( $cmpA['totals']['items_total'] ?? 0 ) ); ?></td>
                    </tr>
                    <tr>
                      <td><?php esc_html_e('Completion %','sfa-quality-gate'); ?></td>
                      <td class="num"><?php echo esc_html( $cmpA['completion'] ); ?>%</td>
                      <td class="num"><?php echo esc_html( $cmpB['completion'] ); ?>%</td>
                      <td class="num"><?php echo esc_html( round( $cmpB['completion'] - $cmpA['completion'], 1 ) ); ?>%</td>
                    </tr>
                  </tbody>
                </table>

                <?php
                  // Tiny SVG chart: use $labelA/$labelB (do NOT overwrite them)
                  $vals = array(
                      __( 'Failed metrics', 'sfa-quality-gate' ) => array( (int)$metA, (int)$metB ),
                      __( 'Failed items',   'sfa-quality-gate' ) => array( (int)$itmA, (int)$itmB ),
                  );
                  $maxv = max( 1, (int)$metA, (int)$metB, (int)$itmA, (int)$itmB );
                  $w=680;$h=220;$pad=40;$barw=60;$gap=40;$group_gap=140;
                  $x0=$pad+40;$y0=$h-$pad;$scale=($h-2*$pad)/$maxv;$groups=array_keys($vals);
                ?>
                <div class="sfa-qg-compare">
                  <svg viewBox="0 0 <?php echo (int)$w; ?> <?php echo (int)$h; ?>" role="img" aria-label="<?php echo esc_attr__('Comparison chart','sfa-quality-gate'); ?>">
                    <line x1="<?php echo (int)$pad; ?>" y1="<?php echo (int)$y0; ?>" x2="<?php echo (int)($w-$pad); ?>" y2="<?php echo (int)$y0; ?>" stroke="#999" stroke-width="1"/>
                    <line x1="<?php echo (int)$pad; ?>" y1="<?php echo (int)$pad; ?>" x2="<?php echo (int)$pad; ?>" y2="<?php echo (int)$y0; ?>" stroke="#999" stroke-width="1"/>
                    <?php
                      $gx=$x0;
                      foreach($groups as $glabel){
                        $vA=$vals[$glabel][0];$vB=$vals[$glabel][1];
                        $hA=$vA*$scale;$hB=$vB*$scale;
                        $xA=$gx;$xB=$gx+$barw+$gap;
                        $yA=$y0-$hA;$yB=$y0-$hB;
                        echo '<rect x="'.(int)$xA.'" y="'.(int)$yA.'" width="'.(int)$barw.'" height="'.(int)$hA.'" fill="#888"></rect>';
                        echo '<rect x="'.(int)$xB.'" y="'.(int)$yB.'" width="'.(int)$barw.'" height="'.(int)$hB.'" fill="#bbb"></rect>';
                        echo '<text x="'.(int)($xA+$barw/2).'" y="'.(int)($yA-6).'" text-anchor="middle" font-size="12">'.(int)$vA.'</text>';
                        echo '<text x="'.(int)($xB+$barw/2).'" y="'.(int)($yB-6).'" text-anchor="middle" font-size="12">'.(int)$vB.'</text>';
                        echo '<text x="'.(int)(($xA+$xB+$barw)/2).'" y="'.(int)($y0+16).'" text-anchor="middle" font-size="12">'.esc_html($glabel).'</text>';
                        $gx += $barw+$gap+$barw+$group_gap;
                      }
                      $ly=$pad-10;
                      echo '<rect x="'.(int)($w-$pad-180).'" y="'.(int)($ly-10).'" width="10" height="10" fill="#888"></rect>';
                      echo '<text x="'.(int)($w-$pad-165).'" y="'.(int)$ly.'" font-size="12">'.esc_html($labelA).'</text>';
                      echo '<rect x="'.(int)($w-$pad-100).'" y="'.(int)($ly-10).'" width="10" height="10" fill="#bbb"></rect>';
                      echo '<text x="'.(int)($w-$pad-85).'" y="'.(int)$ly.'" font-size="12">'.esc_html($labelB).'</text>';
                    ?>
                  </svg>
                </div>
            <?php endif; ?>

<?php
// -- Tables period switcher (works for month or year compare)
if ( $has_compare ) {
    $tables_view = ( isset($_GET['tables_ym']) && $_GET['tables_ym'] === 'ym2' ) ? 'ym2' : 'ym1';

    if ( $is_month_cmp ) {
        if ( $tables_view === 'ym2' ) {
            $data = sfa_qg_report_collect( 'month_custom', $form_id, $labelB );
            $fx   = sfa_qg_fixed_report_collect( 'month_custom', $form_id, $labelB );
        } else {
            $data = sfa_qg_report_collect( 'month_custom', $form_id, $labelA );
            $fx   = sfa_qg_fixed_report_collect( 'month_custom', $form_id, $labelA );
        }
    } else { // year compare
        if ( $tables_view === 'ym2' ) {
            $data = sfa_qg_report_collect( 'year_custom', $form_id, $labelB );
            $fx   = sfa_qg_fixed_report_collect( 'year_custom', $form_id, $labelB );
        } else {
            $data = sfa_qg_report_collect( 'year_custom', $form_id, $labelA );
            $fx   = sfa_qg_fixed_report_collect( 'year_custom', $form_id, $labelA );
        }
    }

// Re-apply history override for the selected tables period (tables only)
if ( function_exists( 'sfa_qg_report_collect_history' ) ) {
    $hist_tables = sfa_qg_report_collect_history(
        $is_month_cmp ? 'month_custom' : 'year_custom',
        $form_id,
        ( $tables_view === 'ym2' ? $labelB : $labelA )
    );
    if ( ! empty( $hist_tables['_has_history'] ) ) {
        $data['failed_entries'] = $hist_tables['failed_entries'];
        $data['latest_failed']  = $hist_tables['latest_failed'];
        // Do NOT override $data['top_failed_metrics'] here either.
    }
}



    echo '<p style="margin:6px 0;"><strong>'
       . esc_html__( 'Tables show period:', 'sfa-quality-gate' ) . ' '
       . esc_html( $tables_view === 'ym2' ? $labelB : $labelA )
       . '</strong> <a class="button button-small" style="margin-left:8px;" href="'
       . esc_url( add_query_arg( array_merge( $_GET, array( 'tables_ym' => ( $tables_view === 'ym2' ? 'ym1' : 'ym2' ) ) ) ) )
       . '">'. sprintf( esc_html__( 'Show tables for %s', 'sfa-quality-gate' ), esc_html( $tables_view === 'ym2' ? $labelA : $labelB ) ) .'</a></p>';
}
$qg_tables_ym = ( $has_compare && isset($_GET['tables_ym']) && $_GET['tables_ym'] === 'ym2' ) ? 'ym2' : 'ym1';
?>

<!-- SECTION: Entries with failed items -->
<details id="sec-fe" class="qg-section" open>
  <summary>
    <strong><?php esc_html_e('Entries with failed items','sfa-quality-gate'); ?></strong>
    <span class="qg-badge"><?php echo (int) count( (array) ($data['failed_entries'] ?? array()) ); ?></span>
    <a class="button button-small" style="margin-left:auto"
       href="<?php echo esc_url( add_query_arg( array(
           'qg_export' => 'failed_entries',
           'range'     => $data['range'],
           'form_id'   => $data['form_id'],
           'ym'        => (string) $ym,
           'ym2'       => (string) $ym2,
           'tables_ym' => $qg_tables_ym,
       ) ) ); ?>"><?php esc_html_e('Export CSV','sfa-quality-gate'); ?></a>
  </summary>
  <div class="qg-inner">
<?php if ( ! empty( $data['failed_entries'] ) ) : ?>
  <?php
    $pfe = isset( $_GET['pfe'] ) ? max( 1, absint( $_GET['pfe'] ) ) : 1; $per_fe = 25;
    $all_fe = (array) ( $data['failed_entries'] ?? array() ); $total_fe = count( $all_fe );
    $pages_fe = max( 1, (int) ceil( $total_fe / $per_fe ) ); $pfe = min( $pfe, $pages_fe );
    $offset_fe = ( $pfe - 1 ) * $per_fe; $fe_page = array_slice( $all_fe, $offset_fe, $per_fe, false );
    $page_url_fe = function( $n ){ $args = $_GET; $args['pfe']=max(1,(int)$n); return esc_url( add_query_arg( $args ) ); };
  ?>
  <div class="qg-tools">
    <div class="qg-search"><input type="search" placeholder="<?php echo esc_attr__('Filter this page…','sfa-quality-gate'); ?>" data-filter="#tbl-fe"></div>
    <div class="qg-count" data-count-for="#tbl-fe"></div>
  </div>
  <table id="tbl-fe" class="qg-table latest" aria-label="<?php esc_attr_e('Entries with failed items','sfa-quality-gate'); ?>">
    <colgroup>
      <col style="width:110px"/>
      <col style="width:90px"/>
      <col/>
      <col/>
      <col/>
      <col style="width:80px"/>
    </colgroup>
    <thead><tr>
      <th class="qg-sort" data-sort="num"><?php esc_html_e('Entry ID','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="num"><?php esc_html_e('Form ID','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Date','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Failed items','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Failing metrics','sfa-quality-gate'); ?></th>
      <th><?php esc_html_e('Link','sfa-quality-gate'); ?></th>
    </tr></thead>
<tbody>
<?php
// Cache forms (by form_id) to avoid repeated GFAPI calls.
$form_cache = array();

foreach ( $fe_page as $row ) :
    $eid = (int) $row['entry_id'];
    $fid = (int) $row['form_id'];

    // Get the form once per form_id (needed by sfa_qg_failed_metric_map()).
    if ( ! array_key_exists( $fid, $form_cache ) && class_exists( 'GFAPI' ) ) {
        $f = \GFAPI::get_form( $fid );
        $form_cache[ $fid ] = is_array( $f ) ? $f : null;
    }
    $form_for_map = $form_cache[ $fid ] ?? array();

    // Build per-item map from the QC field JSON when available.
    $map = function_exists( 'sfa_qg_failed_metric_map' )
        ? sfa_qg_failed_metric_map( $eid, $form_for_map )
        : array();

    // Items to render (prefer map keys; else fall back to $row['items']).
    $items = array_keys( (array) $map );
    if ( empty( $items ) ) {
        $items = isset( $row['items'] ) && is_array( $row['items'] )
            ? array_values( array_unique( array_filter( array_map( 'strval', $row['items'] ) ) ) )
            : array();
        foreach ( $items as $it ) {
            if ( ! isset( $map[ $it ] ) ) { $map[ $it ] = array(); } // ensure index exists
        }
    }
    
    /* ADD THIS: strip metrics & clean item keys */
$items = array_values( array_filter( array_map( static function( $x ) {
    $x = (string) $x;
    if ( strpos( $x, 'metric:' ) === 0 ) return '';           // drop metric tokens
    if ( strpos( $x, 'item:' )   === 0 ) $x = substr( $x, 5 ); // remove 'item:' prefix
    return trim( $x );
}, $items ) ) );

    // Entry-level labels we can fall back to if a specific item has no labels.
    $entry_level_labels = array();
    if ( ! empty( $row['metrics_labels'] ) && is_array( $row['metrics_labels'] ) ) {
        $entry_level_labels = array_values( array_unique( array_filter( array_map( 'strval', $row['metrics_labels'] ) ) ) );
    } else {
        // Last-chance fallback from meta.
        $tmp = json_decode( (string) gform_get_meta( $eid, '_qc_failed_metrics' ), true );
        if ( is_array( $tmp ) ) {
            $entry_level_labels = array_values( array_unique( array_filter( array_map( 'strval', $tmp ) ) ) );
        }
    }

    $link = admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $fid . '&lid=' . $eid );

    // If there are truly no items, show one minimal row so the entry is visible.
    if ( empty( $items ) ) : ?>
        <tr>
            <td class="sfa-qg-mono"><?php echo $eid; ?></td>
            <td class="sfa-qg-mono"><?php echo $fid; ?></td>
            <td><?php echo esc_html( (string) ( $row['date_created'] ?? '' ) ); ?></td>
            <td>&ndash;</td>
            <td>&ndash;</td>
            <td><a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Open', 'sfa-quality-gate' ); ?></a></td>
        </tr>
    <?php
        continue;
    endif;

    // One row per failed item.
    foreach ( $items as $item ) :
        $metrics = isset( $map[ $item ] ) && is_array( $map[ $item ] )
            ? array_values( array_unique( array_filter( array_map( 'strval', $map[ $item ] ) ) ) )
            : array();

        if ( empty( $metrics ) && $entry_level_labels ) {
            // We don't know per-item labels; show entry-level failing metrics instead.
            $metrics = $entry_level_labels;
        }
        ?>
        <tr>
            <td class="sfa-qg-mono"><?php echo $eid; ?></td>
            <td class="sfa-qg-mono"><?php echo $fid; ?></td>
            <td><?php echo esc_html( (string) ( $row['date_created'] ?? '' ) ); ?></td>
            <td><?php echo esc_html( $item ); ?></td>
            <td><?php echo $metrics ? esc_html( implode( ', ', $metrics ) ) : '—'; ?></td>
            <td><a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Open', 'sfa-quality-gate' ); ?></a></td>
        </tr>
    <?php endforeach;
endforeach; ?>
</tbody>



  </table>
  <?php if ( $pages_fe > 1 ) :
        $from = $offset_fe + 1; $to = min( $offset_fe + $per_fe, $total_fe ); ?>
    <div class="tablenav" style="margin-top:8px;display:flex;align-items:center;gap:10px;">
      <span><?php printf( esc_html__( 'Showing %1$d–%2$d of %3$d', 'sfa-quality-gate' ), (int)$from, (int)$to, (int)$total_fe ); ?></span>
      <div class="tablenav-pages">
        <?php if ( $pfe > 1 ) : ?>
          <a class="button button-small" href="<?php echo $page_url_fe($pfe-1); ?>">&laquo; <?php esc_html_e('Prev','sfa-quality-gate'); ?></a>
        <?php else : ?>
          <span class="button button-small disabled" aria-disabled="true">&laquo; <?php esc_html_e('Prev','sfa-quality-gate'); ?></span>
        <?php endif; ?>
        <span style="margin:0 6px;"><?php printf( esc_html__( 'Page %1$d of %2$d', 'sfa-quality-gate' ), (int)$pfe, (int)$pages_fe ); ?></span>
        <?php if ( $pfe < $pages_fe ) : ?>
          <a class="button button-small" href="<?php echo $page_url_fe($pfe+1); ?>"><?php esc_html_e('Next','sfa-quality-gate'); ?> &raquo;</a>
        <?php else : ?>
          <span class="button button-small disabled" aria-disabled="true"><?php esc_html_e('Next','sfa-quality-gate'); ?> &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <p><em><?php esc_html_e('No failed entries in this range.','sfa-quality-gate'); ?></em></p>
<?php endif; ?>
  </div>
</details>

<!-- SECTION: Top failing metrics -->
<details id="sec-fm" class="qg-section" open>
  <summary>
    <strong><?php esc_html_e('Top failing metrics','sfa-quality-gate'); ?></strong>
    <span class="qg-badge"><?php echo (int) count( (array) ($data['top_failed_metrics'] ?? array()) ); ?></span>
    <a class="button button-small" style="margin-left:auto"
       href="<?php echo esc_url( add_query_arg( array(
         'qg_export'=>'top_failed_metrics','range'=>$data['range'],'form_id'=>$data['form_id'],
         'ym'=>(string)$ym,'ym2'=>(string)$ym2,'tables_ym'=>$qg_tables_ym,
       ) ) ); ?>"><?php esc_html_e('Export CSV','sfa-quality-gate'); ?></a>
  </summary>
  <div class="qg-inner">
<?php if ( ! empty( $data['top_failed_metrics'] ) ) : ?>
  <?php
    $pfm = isset($_GET['pfm']) ? max(1,absint($_GET['pfm'])) : 1; $per_fm=25;
    $all_fm = (array)($data['top_failed_metrics'] ?? array()); $total_fm=count($all_fm);
    $pages_fm=max(1,(int)ceil($total_fm/$per_fm)); $pfm=min($pfm,$pages_fm);
    $offset_fm=($pfm-1)*$per_fm; $fm_page=array_slice($all_fm,$offset_fm,$per_fm,true);
    $page_url=function($n){$args=$_GET;$args['pfm']=max(1,(int)$n);return esc_url(add_query_arg($args));};
  ?>
  <div class="qg-tools">
    <div class="qg-search"><input type="search" placeholder="<?php echo esc_attr__('Filter this page…','sfa-quality-gate'); ?>" data-filter="#tbl-fm"></div>
    <div class="qg-count" data-count-for="#tbl-fm"></div>
  </div>
  <table id="tbl-fm" class="qg-table two-col" aria-label="<?php esc_attr_e('Top failing metrics','sfa-quality-gate'); ?>">
    <colgroup><col/><col style="width:140px"/></colgroup>
    <thead>
      <tr>
        <th class="qg-sort" data-sort="text"><?php esc_html_e('Metric label','sfa-quality-gate'); ?></th>
        <th class="qg-sort" data-sort="num"><?php esc_html_e('Failures','sfa-quality-gate'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ( $fm_page as $label => $count ) : ?>
        <tr>
          <td><?php echo esc_html($label); ?></td>
          <td class="sfa-qg-mono" style="text-align:right"><?php echo (int)$count; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ( $pages_fm > 1 ) :
        $from=$offset_fm+1; $to=min($offset_fm+$per_fm,$total_fm); ?>
    <div class="tablenav" style="margin-top:8px;display:flex;align-items:center;gap:10px;">
      <span><?php printf( esc_html__( 'Showing %1$d–%2$d of %3$d', 'sfa-quality-gate' ), (int)$from, (int)$to, (int)$total_fm ); ?></span>
      <div class="tablenav-pages">
        <?php if ( $pfm > 1 ) : ?>
          <a class="button button-small" href="<?php echo $page_url($pfm-1); ?>">&laquo; <?php esc_html_e('Prev','sfa-quality-gate'); ?></a>
        <?php else : ?>
          <span class="button button-small disabled" aria-disabled="true">&laquo; <?php esc_html_e('Prev','sfa-quality-gate'); ?></span>
        <?php endif; ?>
        <span style="margin:0 6px;"><?php printf( esc_html__( 'Page %1$d of %2$d', 'sfa-quality-gate' ), (int)$pfm, (int)$pages_fm ); ?></span>
        <?php if ( $pfm < $pages_fm ) : ?>
          <a class="button button-small" href="<?php echo $page_url($pfm+1); ?>"><?php esc_html_e('Next','sfa-quality-gate'); ?> &raquo;</a>
        <?php else : ?>
          <span class="button button-small disabled" aria-disabled="true"><?php esc_html_e('Next','sfa-quality-gate'); ?> &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <p><em><?php esc_html_e('No metric failures recorded in this range.','sfa-quality-gate'); ?></em></p>
<?php endif; ?>
  </div>
</details>

<!-- SECTION: Latest failed entries -->
<details id="sec-lf" class="qg-section">
  <summary>
    <strong><?php esc_html_e('Latest failed entries','sfa-quality-gate'); ?></strong>
    <span class="qg-badge"><?php echo (int) count( (array) ($data['latest_failed'] ?? array()) ); ?></span>
    <a class="button button-small" style="margin-left:auto"
       href="<?php echo esc_url( add_query_arg( array(
         'qg_export'=>'latest_failed','range'=>$data['range'],'form_id'=>$data['form_id'],
         'ym'=>(string)$ym,'ym2'=>(string)$ym2,'tables_ym'=>$qg_tables_ym,
       ) ) ); ?>"><?php esc_html_e('Export CSV','sfa-quality-gate'); ?></a>
  </summary>
  <div class="qg-inner">
<?php if ( ! empty( $data['latest_failed'] ) ) : ?>
  <?php
    $plf = isset($_GET['plf']) ? max(1,absint($_GET['plf'])) : 1; $per_lf=25;
    $all_lf=(array)($data['latest_failed'] ?? array()); $total_lf=count($all_lf);
    $pages_lf=max(1,(int)ceil($total_lf/$per_lf)); $plf=min($plf,$pages_lf);
    $offset_lf=($plf-1)*$per_lf; $lf_page=array_slice($all_lf,$offset_lf,$per_lf,false);
    $page_url_lf=function($n){$args=$_GET;$args['plf']=max(1,(int)$n);return esc_url(add_query_arg($args));};
  ?>
  <div class="qg-tools">
    <div class="qg-search"><input type="search" placeholder="<?php echo esc_attr__('Filter this page…','sfa-quality-gate'); ?>" data-filter="#tbl-lf"></div>
    <div class="qg-count" data-count-for="#tbl-lf"></div>
  </div>
  <table id="tbl-lf" class="qg-table latest" aria-label="<?php esc_attr_e('Latest failed entries','sfa-quality-gate'); ?>">
    <colgroup><col style="width:110px"/><col style="width:90px"/><col/><col style="width:130px"/><col style="width:80px"/></colgroup>
    <thead><tr>
      <th class="qg-sort" data-sort="num"><?php esc_html_e('Entry ID','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="num"><?php esc_html_e('Form ID','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Date','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="num" style="text-align:right"><?php esc_html_e('Failed metrics','sfa-quality-gate'); ?></th>
      <th><?php esc_html_e('Link','sfa-quality-gate'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $lf_page as $row ) :
      $link = admin_url( 'admin.php?page=gf_entries&view=entry&id='.(int)$row['form_id'].'&lid='.(int)$row['entry_id'] ); ?>
      <tr>
        <td class="sfa-qg-mono"><?php echo (int)$row['entry_id']; ?></td>
        <td class="sfa-qg-mono"><?php echo (int)$row['form_id']; ?></td>
        <td><?php echo esc_html( $row['date_created'] ); ?></td>
        <td class="sfa-qg-mono" style="text-align:right"><?php echo (int)$row['metrics_failed']; ?></td>
        <td><a href="<?php echo esc_url($link); ?>"><?php esc_html_e('Open','sfa-quality-gate'); ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ( $pages_lf > 1 ) :
        $from=$offset_lf+1; $to=min($offset_lf+$per_lf,$total_lf); ?>
    <div class="tablenav" style="margin-top:8px;display:flex;align-items:center;gap:10px;">
      <span><?php printf( esc_html__( 'Showing %1$d–%2$d of %3$d', 'sfa-quality-gate' ), (int)$from, (int)$to, (int)$total_lf ); ?></span>
      <div class="tablenav-pages">
        <?php if ( $plf > 1 ) : ?>
          <a class="button button-small" href="<?php echo $page_url_lf($plf-1); ?>">&laquo; <?php esc_html_e('Prev','sfa-quality-gate'); ?></a>
        <?php else : ?>
          <span class="button button-small disabled" aria-disabled="true">&laquo; <?php esc_html_e('Prev','sfa-quality-gate'); ?></span>
        <?php endif; ?>
        <span style="margin:0 6px;"><?php printf( esc_html__( 'Page %1$d of %2$d', 'sfa-quality-gate' ), (int)$plf, (int)$pages_lf ); ?></span>
        <?php if ( $plf < $pages_lf ) : ?>
          <a class="button button-small" href="<?php echo $page_url_lf($plf+1); ?>"><?php esc_html_e('Next','sfa-quality-gate'); ?> &raquo;</a>
        <?php else : ?>
          <span class="button button-small disabled" aria-disabled="true"><?php esc_html_e('Next','sfa-quality-gate'); ?> &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <p><em><?php esc_html_e('No failed entries in this range.','sfa-quality-gate'); ?></em></p>
<?php endif; ?>
  </div>
</details>

<!-- SECTION: Fixed — Monthly -->
<details id="sec-fxm" class="qg-section">
  <summary>
    <strong><?php esc_html_e('Fixed — Monthly','sfa-quality-gate'); ?></strong>
    <span class="qg-badge"><?php echo (int) count( (array) ($fx['monthly'] ?? array()) ); ?></span>
    <a class="button button-small" style="margin-left:auto"
       href="<?php echo esc_url( add_query_arg( array(
         'qg_export'=>'fixed_monthly','range'=>$data['range'],'form_id'=>$data['form_id'],
         'ym'=>(string)$ym,'ym2'=>(string)$ym2,'tables_ym'=>$qg_tables_ym,
       ) ) ); ?>"><?php esc_html_e('Export CSV','sfa-quality-gate'); ?></a>
  </summary>
  <div class="qg-inner">
<?php if ( ! empty( $fx['monthly'] ) ) : ?>
  <table class="qg-table two-col" aria-label="<?php esc_attr_e('Fixed per month','sfa-quality-gate'); ?>">
    <colgroup><col/><col style="width:140px"/></colgroup>
    <thead><tr>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Month','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="num"><?php esc_html_e('Fixed count','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Avg time to fix','sfa-quality-gate'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $fx['monthly'] as $ym_k => $cnt ) : ?>
      <tr>
        <td><?php echo esc_html( $ym_k ); ?></td>
        <td class="sfa-qg-mono"><?php echo (int) $cnt; ?></td>
        <td class="sfa-qg-mono"><?php echo isset( $fx['avg'][ $ym_k ] ) ? esc_html( sfa_qg_human_dur( (int)$fx['avg'][ $ym_k ] ) ) : '—'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <p><em><?php esc_html_e('No fixed items in this range.','sfa-quality-gate'); ?></em></p>
<?php endif; ?>
  </div>
</details>

<!-- SECTION: Fixed — Details -->
<details id="sec-fxd" class="qg-section">
  <summary>
    <strong><?php esc_html_e('Fixed — Details','sfa-quality-gate'); ?></strong>
    <span class="qg-badge"><?php echo (int) count( (array) ($fx['details'] ?? array()) ); ?></span>
    <a class="button button-small" style="margin-left:auto"
       href="<?php echo esc_url( add_query_arg( array(
         'qg_export'=>'fixed_details','range'=>$data['range'],'form_id'=>$data['form_id'],
         'ym'=>(string)$ym,'ym2'=>(string)$ym2,'tables_ym'=>$qg_tables_ym,
       ) ) ); ?>"><?php esc_html_e('Export CSV','sfa-quality-gate'); ?></a>
  </summary>
  <div class="qg-inner">
<?php if ( ! empty( $fx['details'] ) ) : ?>
  <?php
    $pfxd = isset($_GET['pfxd']) ? max(1,absint($_GET['pfxd'])) : 1; $per_fxd=25;
    $all_fxd=(array)($fx['details'] ?? array()); $total_fxd=count($all_fxd);
    $pages_fxd=max(1,(int)ceil($total_fxd/$per_fxd)); $pfxd=min($pfxd,$pages_fxd);
    $offset_fxd=($pfxd-1)*$per_fxd; $fxd_page=array_slice($all_fxd,$offset_fxd,$per_fxd,false);
    $page_url_fxd=function($n){$args=$_GET;$args['pfxd']=max(1,(int)$n);return esc_url(add_query_arg($args));};
  ?>
  <div class="qg-tools">
    <div class="qg-search"><input type="search" placeholder="<?php echo esc_attr__('Filter this page…','sfa-quality-gate'); ?>" data-filter="#tbl-fxd"></div>
    <div class="qg-count" data-count-for="#tbl-fxd"></div>
  </div>
  <table id="tbl-fxd" class="qg-table latest" aria-label="<?php esc_attr_e('Fixed details','sfa-quality-gate'); ?>">
    <colgroup><col style="width:110px"/><col style="width:90px"/><col/><col/><col style="width:110px"/><col style="width:80px"/></colgroup>
    <thead><tr>
      <th class="qg-sort" data-sort="num"><?php esc_html_e('Entry ID','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="num"><?php esc_html_e('Form ID','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Item','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Fixed at','sfa-quality-gate'); ?></th>
      <th class="qg-sort" data-sort="text"><?php esc_html_e('Time to fix','sfa-quality-gate'); ?></th>
      <th><?php esc_html_e('Link','sfa-quality-gate'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $fxd_page as $r ) :
      $link = admin_url( 'admin.php?page=gf_entries&view=entry&id='.(int)$r['form_id'].'&lid='.(int)$r['entry_id'] ); ?>
      <tr>
        <td class="sfa-qg-mono"><?php echo (int)$r['entry_id']; ?></td>
        <td class="sfa-qg-mono"><?php echo (int)$r['form_id']; ?></td>
        <td><?php echo esc_html($r['item']); ?></td>
        <td><?php echo esc_html($r['fixed_at']); ?></td>
        <td class="sfa-qg-mono"><?php echo esc_html( sfa_qg_human_dur( (int)($r['duration_seconds'] ?? 0) ) ); ?></td>
        <td><a href="<?php echo esc_url($link); ?>"><?php esc_html_e('Open','sfa-quality-gate'); ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ( $pages_fxd > 1 ) :
        $from=$offset_fxd+1; $to=min($offset_fxd+$per_fxd,$total_fxd); ?>
    <div class="tablenav" style="margin-top:8px;display:flex;align-items:center;gap:10px;">
      <span><?php printf( esc_html__( 'Showing %1$d–%2$d of %3$d', 'sfa-quality-gate' ), (int)$from, (int)$to, (int)$total_fxd ); ?></span>
      <div class="tablenav-pages">
        <?php if ( $pfxd > 1 ) : ?>
          <a class="button button-small" href="<?php echo $page_url_fxd($pfxd-1); ?>">&laquo; <?php esc_html_e('Prev','sfa-quality-gate'); ?></a>
        <?php else : ?>
          <span class="button button-small disabled" aria-disabled="true">&laquo; <?php esc_html_e('Prev','sfa-quality-gate'); ?></span>
        <?php endif; ?>
        <span style="margin:0 6px;"><?php printf( esc_html__( 'Page %1$d of %2$d', 'sfa-quality-gate' ), (int)$pfxd, (int)$pages_fxd ); ?></span>
        <?php if ( $pfxd < $pages_fxd ) : ?>
          <a class="button button-small" href="<?php echo $page_url_fxd($pfxd+1); ?>"><?php esc_html_e('Next','sfa-quality-gate'); ?> &raquo;</a>
        <?php else : ?>
          <span class="button button-small disabled" aria-disabled="true"><?php esc_html_e('Next','sfa-quality-gate'); ?> &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <p><em><?php esc_html_e('No fixed items in this range.','sfa-quality-gate'); ?></em></p>
<?php endif; ?>
  </div>
</details>

<script>
(function(){
  // ---- Persist open/closed state for sections
  var sections=document.querySelectorAll('.qg-section');
  sections.forEach(function(sec){
    var id=sec.id;
    if(!id) return;
    try{
      var saved=localStorage.getItem('qg_sec_'+id);
      if(saved==='0') sec.removeAttribute('open');
      if(saved==='1') sec.setAttribute('open','open');
    }catch(e){}
    sec.addEventListener('toggle',function(){
      try{ localStorage.setItem('qg_sec_'+id, sec.open? '1':'0'); }catch(e){}
    });
  });


  // ---- Share this view
  var share=document.getElementById('qg-share-link');
  if(share){
    share.addEventListener('click',function(e){
      e.preventDefault();
      try{
        navigator.clipboard.writeText(window.location.href);
        share.textContent = '<?php echo esc_js( __( 'Link copied', 'sfa-quality-gate' ) ); ?>';
        setTimeout(function(){ share.textContent = '<?php echo esc_js( __( 'Share this view', 'sfa-quality-gate' ) ); ?>'; }, 1500);
      }catch(err){
        alert(window.location.href);
      }
    });
  }

  // ---- Reset layout (clears remembered open/closed state)
  var resetBtn = document.getElementById('qg-reset-layout');
  if (resetBtn) {
    resetBtn.addEventListener('click', function(e){
      e.preventDefault();
      try {
        // Remove only our keys
        Object.keys(localStorage)
          .filter(function(k){ return k.indexOf('qg_sec_') === 0; })
          .forEach(function(k){ localStorage.removeItem(k); });
      } catch(e){}
      // Reload to apply default 'open' attributes in markup
      window.location.reload();
    });
  }
  // ---- Simple filter (per page)
  function updateCount(tbl){
    var vis = tbl.querySelectorAll('tbody tr:not([hidden])').length;
    var cnt = document.querySelector('[data-count-for="#'+tbl.id+'"]');
    if(cnt){
      var total = tbl.querySelectorAll('tbody tr').length;
      cnt.textContent = vis + ' / ' + total + ' ' + '<?php echo esc_js( __( 'rows', 'sfa-quality-gate' ) ); ?>';
    }
  }
  document.querySelectorAll('[data-filter]').forEach(function(inp){
    var sel = inp.getAttribute('data-filter'), tbl = document.querySelector(sel);
    if(!tbl) return;
    inp.addEventListener('input', function(){
      var q = inp.value.toLowerCase();
      tbl.querySelectorAll('tbody tr').forEach(function(tr){
        var txt = tr.textContent.toLowerCase();
        tr.hidden = q && txt.indexOf(q) === -1;
      });
      updateCount(tbl);
    });
    updateCount(tbl);
  });

  // ---- Sortable headers (click toggles asc/desc)
  function cmp(a,b,type){
    if(type==='num'){ a=parseFloat(a)||0; b=parseFloat(b)||0; return a-b; }
    return a.localeCompare(b, undefined, {numeric:true, sensitivity:'base'});
  }
  document.querySelectorAll('.qg-table').forEach(function(tbl){
    var ths = tbl.querySelectorAll('thead th.qg-sort');
    ths.forEach(function(th, idx){
      th.addEventListener('click', function(){
        var type = th.getAttribute('data-sort') || 'text';
        var dir  = th.classList.contains('asc') ? 'desc' : 'asc';
        ths.forEach(function(t){ t.classList.remove('asc','desc'); });
        th.classList.add(dir);

        var rows = Array.from(tbl.querySelectorAll('tbody tr'));
        rows.sort(function(r1,r2){
          var t1=r1.children[idx]?.textContent.trim()||'';
          var t2=r2.children[idx]?.textContent.trim()||'';
          var v = cmp(t1,t2,type);
          return dir==='asc' ? v : -v;
        });
        var tb = tbl.querySelector('tbody');
        rows.forEach(function(r){ tb.appendChild(r); });
      });
    });
  });

  // Enable subnav jump links
  var OFFSET = 90;
  document.querySelectorAll('.sfa-qg-subnav a[href^="#"]').forEach(function(a){
    if (a.id === 'qg-share-link') return;
    a.addEventListener('click', function(e){
      var id = a.getAttribute('href').slice(1);
      var t  = document.getElementById(id);
      if (!t) return;
      e.preventDefault();
      if (t.tagName.toLowerCase() === 'details' && !t.open) t.open = true;
      t.classList.add('qg-hit'); setTimeout(function(){ t.classList.remove('qg-hit'); }, 1200);
      var y = t.getBoundingClientRect().top + window.pageYOffset - OFFSET;
      window.scrollTo({ top: y, behavior: 'smooth' });
      history.replaceState(null, '', '#' + id);
    });
  });
})();
</script>

        </div>
        <?php
        return ob_get_clean();
    }
}
