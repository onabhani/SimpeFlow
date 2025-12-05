<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/collect.php';
require_once __DIR__ . '/render.php';

if ( ! function_exists( 'sfa_qg_report_admin_page' ) ) {
	function sfa_qg_report_admin_page() {

		// --- Read canonical params used by renderer/collector
		$range    = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : 'month';
		$range    = in_array( $range, array( 'today','month','year','month_custom','year_custom' ), true ) ? $range : 'month';
		$form_id  = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$ym       = isset( $_GET['ym'] )  ? preg_replace( '/[^0-9\-]/', '', (string) $_GET['ym'] )  : '';
		$ym2      = isset( $_GET['ym2'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $_GET['ym2'] ) : '';

		// Helpful defaults
		if ( $range === 'month' && $ym === '' ) {
			$ym = date_i18n( 'Y-m', current_time( 'timestamp' ) );
		}
		if ( $range === 'year_custom' && $ym !== '' && ! preg_match( '/^\d{4}$/', $ym ) ) {
			$ym = date_i18n( 'Y', current_time( 'timestamp' ) );
		}

		// For pre-filling the new UI (non-canonical helpers)
		$mode   = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : '';
		$ctype  = isset( $_GET['ctype'] ) ? sanitize_key( $_GET['ctype'] ) : 'mm'; // mm or yy
		if ( $mode === '' ) {
			// Infer a sensible default from canonical range
			if ( $range === 'today' )            { $mode = 'today'; }
			elseif ( $range === 'month' )        { $mode = 'month'; }
			elseif ( $range === 'year' )         { $mode = 'year'; }
			elseif ( $range === 'month_custom' ) { $mode = 'month_custom'; }
			elseif ( $range === 'year_custom' )  { $mode = 'compare'; $ctype='yy'; }
			else                                 { $mode = 'month'; }
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Quality Gate Report', 'sfa-quality-gate' ) . '</h1>';

		// =========================
		// Filter / Search bar (UI)
		// =========================
		$forms = class_exists( 'GFAPI' ) ? \GFAPI::get_forms() : array();
		?>
		<form method="get" class="sfa-qg-filter" style="position:sticky;top:32px;z-index:100;background:#fff;padding:10px 12px;margin:-6px -12px 12px;border-bottom:1px solid #e5e5e5;">
			<input type="hidden" name="page" value="sfa-qg-report">
			<input type="hidden" name="range" value="<?php echo esc_attr( $range ); ?>" id="qg-range">
			<!-- canonical targets that the backend actually reads -->
			<input type="hidden" name="ym"  id="canon-ym"  value="<?php echo esc_attr( $ym ); ?>">
			<input type="hidden" name="ym2" id="canon-ym2" value="<?php echo esc_attr( $ym2 ); ?>">

<style>
  .sfa-qg-filter .seg {display:inline-flex;gap:6px;flex-wrap:wrap}
  .sfa-qg-filter .seg label{border:1px solid #d0d7de;border-radius:20px;padding:6px 10px;cursor:pointer;background:#fff;line-height:1}
  .sfa-qg-filter .seg input{display:none}
  .sfa-qg-filter .seg input:checked + span{background:#f0f6ff;border-color:#84b6f4}
  .sfa-qg-filter .row{display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap;justify-content:flex-start}
  .sfa-qg-filter .row.row-top .seg{margin-right:6px}
  .sfa-qg-filter .hint{color:#666;font-size:12px;margin-left:6px}
</style>

			<div class="row row-top">
				<!-- Range chips -->
				<div class="seg" role="tablist" aria-label="<?php esc_attr_e('Range','sfa-quality-gate'); ?>">
					<label><input type="radio" name="mode" value="today"        <?php checked( $mode, 'today' ); ?>><span><?php esc_html_e('Today','sfa-quality-gate'); ?></span></label>
					<label><input type="radio" name="mode" value="month"        <?php checked( $mode, 'month' ); ?>><span><?php esc_html_e('This month','sfa-quality-gate'); ?></span></label>
					<label><input type="radio" name="mode" value="year"         <?php checked( $mode, 'year' ); ?>><span><?php esc_html_e('This year','sfa-quality-gate'); ?></span></label>
					<label><input type="radio" name="mode" value="month_custom" <?php checked( $mode, 'month_custom' ); ?>><span><?php esc_html_e('Specific month','sfa-quality-gate'); ?></span></label>
					<label><input type="radio" name="mode" value="compare"      <?php checked( $mode, 'compare' ); ?>><span><?php esc_html_e('Compare','sfa-quality-gate'); ?></span></label>
				</div>

				<!-- Form select + Apply packed beside the chips -->
				<label for="qg-form" style="font-weight:600;margin-left:6px;"><?php esc_html_e('Form','sfa-quality-gate'); ?>:</label>
				<select id="qg-form" name="form_id">
					<option value="0"><?php esc_html_e('All forms','sfa-quality-gate'); ?></option>
					<?php foreach ( (array) $forms as $f ): ?>
						<option value="<?php echo (int) $f['id']; ?>" <?php selected( $form_id, (int) $f['id'] ); ?>>
							<?php echo esc_html( $f['title'] . ' (ID ' . (int) $f['id'] . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<button class="button button-primary" type="submit"><?php esc_html_e('Apply','sfa-quality-gate'); ?></button>
			</div>

			<!-- Specific month -->
			<div class="row js-specific" style="display:<?php echo ( $mode === 'month_custom' ) ? 'flex' : 'none'; ?>">
				<label for="qg-ym" style="font-weight:600;"><?php esc_html_e('Month','sfa-quality-gate'); ?>:</label>
				<!-- NOTE: name is m1 (not ym). Canonical ym is filled into the hidden #canon-ym on submit. -->
				<input id="qg-ym" type="month" name="m1" value="<?php echo esc_attr( $ym ?: date_i18n('Y-m', current_time('timestamp') ) ); ?>">
				<span class="hint"><?php esc_html_e('Tip: use ←/→ keys while focused to step months','sfa-quality-gate'); ?></span>
			</div>

			<!-- Compare chooser -->
			<div class="row js-compare" style="display:<?php echo ( $mode === 'compare' ) ? 'flex' : 'none'; ?>">
				<div class="seg" aria-label="<?php esc_attr_e('Compare type','sfa-quality-gate'); ?>">
					<label><input type="radio" name="ctype" value="mm" <?php checked( $ctype, 'mm' ); ?>><span><?php esc_html_e('Month ↔ Month','sfa-quality-gate'); ?></span></label>
					<label><input type="radio" name="ctype" value="yy" <?php checked( $ctype, 'yy' ); ?>><span><?php esc_html_e('Year ↔ Year','sfa-quality-gate'); ?></span></label>
				</div>
				<label style="margin-left:12px;"><input type="checkbox" name="autoprev" value="1" <?php checked( isset($_GET['autoprev']) && $_GET['autoprev'] === '1' ); ?>> <?php esc_html_e('vs previous period','sfa-quality-gate'); ?></label>
			</div>

			<!-- Month↔Month fields -->
			<div class="row js-mm" style="display:<?php echo ( $mode === 'compare' && $ctype === 'mm' ) ? 'flex' : 'none'; ?>">
				<label style="font-weight:600;"><?php esc_html_e('Left month','sfa-quality-gate'); ?>:</label>
				<input type="month" name="m1"  value="<?php echo esc_attr( $ym ?: date_i18n('Y-m', current_time('timestamp') ) ); ?>" class="js-month-a">
				<label style="font-weight:600;"><?php esc_html_e('Right month','sfa-quality-gate'); ?>:</label>
				<input type="month" name="m2" value="<?php echo esc_attr( $ym2 ); ?>" class="js-month-b">
			</div>

			<!-- Year↔Year fields -->
			<div class="row js-yy" style="display:<?php echo ( $mode === 'compare' && $ctype === 'yy' ) ? 'flex' : 'none'; ?>">
				<label style="font-weight:600;"><?php esc_html_e('Left year','sfa-quality-gate'); ?>:</label>
				<input type="number" name="yy"  min="2000" max="2100" step="1" value="<?php echo esc_attr( preg_match('/^\d{4}$/',$ym) ? $ym : date_i18n('Y') ); ?>" class="js-year-a">
				<label style="font-weight:600;"><?php esc_html_e('Right year','sfa-quality-gate'); ?>:</label>
				<input type="number" name="yy2" min="2000" max="2100" step="1" value="<?php echo esc_attr( preg_match('/^\d{4}$/',$ym2) ? $ym2 : '' ); ?>" class="js-year-b">
				<span class="hint"><?php esc_html_e('Use ←/→ keys to step years','sfa-quality-gate'); ?></span>
			</div>

			<script>
			(function(){
				var form = document.currentScript.closest('form');

				function setVis(){
					var mode  = (form.querySelector('input[name="mode"]:checked')||{}).value || '';
					var ctype = (form.querySelector('input[name="ctype"]:checked')||{}).value || 'mm';
					var spec  = form.querySelector('.js-specific');
					var cmp   = form.querySelector('.js-compare');
					var mm    = form.querySelector('.js-mm');
					var yy    = form.querySelector('.js-yy');
					if (spec) spec.style.display = (mode==='month_custom') ? 'flex' : 'none';
					if (cmp)  cmp.style.display  = (mode==='compare') ? 'flex' : 'none';
					if (mm)   mm.style.display   = (mode==='compare' && ctype==='mm') ? 'flex' : 'none';
					if (yy)   yy.style.display   = (mode==='compare' && ctype==='yy') ? 'flex' : 'none';
				}
				form.addEventListener('change', setVis); setVis();

				function doSubmit(){
					if (form.requestSubmit) { form.requestSubmit(); }
					else {
						var btn = form.querySelector('button[type="submit"]');
						if (btn) btn.click(); else form.submit();
					}
				}

				// Auto-apply quick ranges (clicking chips)
				form.addEventListener('change', function(e){
					if (e.target && e.target.name === 'mode') {
						var v = e.target.value;
						if (v === 'today' || v === 'month' || v === 'year') {
							doSubmit();
						}
					}
				});

				// Auto-apply quick ranges when the Form dropdown changes
				form.addEventListener('change', function(e){
					if (e.target && e.target.id === 'qg-form') {
						var v = (form.querySelector('input[name="mode"]:checked')||{}).value || '';
						if (v==='today' || v==='month' || v==='year') doSubmit();
					}
				});

				// Autofill right side when "vs previous period" is checked
				form.addEventListener('change', function(e){
					if ( e.target && e.target.name === 'autoprev' && e.target.checked ) {
						var ctype = (form.querySelector('input[name="ctype"]:checked')||{}).value || 'mm';
						if (ctype==='mm') {
							var A = form.querySelector('.js-month-a');
							if (A && A.value) {
								var d=new Date(A.value+'-01T00:00:00'); d.setMonth(d.getMonth()-1);
								var B = form.querySelector('.js-month-b');
								if (B) B.value = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
							}
						} else {
							var y = parseInt((form.querySelector('.js-year-a')||{}).value||'',10);
							var Y2 = form.querySelector('.js-year-b');
							if (y && Y2) Y2.value = String(y-1);
						}
					}
				});

				// Arrow keys step month/year
				form.addEventListener('keydown', function(e){
					var el = e.target;
					if (el && el.type==='month' && (e.key==='ArrowLeft'||e.key==='ArrowRight')){
						e.preventDefault();
						var d=new Date((el.value||'<?php echo esc_js(date_i18n('Y-m')); ?>')+'-01T00:00:00');
						d.setMonth(d.getMonth() + (e.key==='ArrowRight'?+1:-1));
						el.value=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
						el.dispatchEvent(new Event('change',{bubbles:true}));
					}
					if (el && el.type==='number' && (e.key==='ArrowLeft'||e.key==='ArrowRight')){
						e.preventDefault();
						var y=parseInt(el.value||'<?php echo esc_js(date_i18n('Y')); ?>',10);
						el.value=String(y + (e.key==='ArrowRight'?+1:-1));
						el.dispatchEvent(new Event('change',{bubbles:true}));
					}
				});

				// Normalize to canonical query for backend before submit
				form.addEventListener('submit', function(){
					var mode  = (form.querySelector('input[name="mode"]:checked')||{}).value || '';
					var ctype = (form.querySelector('input[name="ctype"]:checked')||{}).value || 'mm';
					var range = 'month';

					if (mode==='today')             range='today';
					else if (mode==='month')        range='month';
					else if (mode==='year')         range='year';
					else if (mode==='month_custom') range='month_custom';
					else if (mode==='compare')      range = (ctype==='yy') ? 'year_custom' : 'month_custom';

					document.getElementById('qg-range').value = range;

					// Canonical fields the backend reads
					var cYM  = document.getElementById('canon-ym');
					var cYM2 = document.getElementById('canon-ym2');

					// Reset first
					if (cYM)  cYM.value  = '';
					if (cYM2) cYM2.value = '';

					if (mode==='month_custom') {
						var m = form.querySelector('#qg-ym'); // name=m1
						if (m && m.value && cYM) cYM.value = m.value;
					}

					if (mode==='compare' && ctype==='mm') {
						var A = form.querySelector('.js-month-a'); // name=m1
						var B = form.querySelector('.js-month-b'); // name=m2
						// If "previous period" checked and right empty, fill now
						if ((form.querySelector('input[name="autoprev"]:checked')) && A && A.value && B && !B.value) {
							var d=new Date(A.value+'-01T00:00:00'); d.setMonth(d.getMonth()-1);
							B.value = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
						}
						if (A && A.value && cYM)  cYM.value  = A.value;
						if (B && B.value && cYM2) cYM2.value = B.value;
					}

					if (mode==='compare' && ctype==='yy') {
						var yA = (form.querySelector('input[name="yy"]')||{}).value || '';
						var yB = (form.querySelector('input[name="yy2"]')||{}).value || '';
						if ((form.querySelector('input[name="autoprev"]:checked')) && yA && !yB) {
							yB = String(parseInt(yA,10)-1);
						}
						if (cYM)  cYM.value  = yA;
						if (cYM2) cYM2.value = yB;
					}
				});
			})();
			</script>
		</form>
		<?php

		// Render report with filters applied
		echo sfa_qg_report_render_html( $range, $form_id, $ym, $ym2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}
}
