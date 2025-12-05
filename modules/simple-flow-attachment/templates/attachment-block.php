<?php
defined( 'ABSPATH' ) || exit;

$title   = isset( $context['title'] ) ? $context['title'] : __( 'Order Attachment', 'simple-flow-attachment' );
$groups  = isset( $context['groups'] ) ? (array) $context['groups'] : array();
$entry   = isset( $context['entry'] ) ? (array) $context['entry'] : array();
$entry_id= isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
$nonce   = isset( $context['nonce'] ) ? $context['nonce'] : '';
$base = admin_url( 'admin-post.php' );
$zip_all_url = $entry_id ? wp_nonce_url( add_query_arg( array( 'action'=>'sfa_download_zip','entry'=>$entry_id ), $base ), 'sfa_zip_' . $entry_id ) : '';
$pref = defined('SFA_DEFAULT_OPEN') ? SFA_DEFAULT_OPEN : 'auto';
$open = ( $pref === true || $pref === 1 || $pref === '1' ) ? true : ( ($pref === false || $pref === 0 || $pref === '0') ? false : ( is_admin() ? true : false ) );
?>
<?php if ( ! wp_style_is( is_admin() ? 'sfa-admin' : 'sfa-frontend', 'enqueued' ) ) : ?>
<style id="sfa-inline">
/* SFA inline critical styles */
.sfa-card{background:#fff!important;border:1px solid #dcdcde!important;border-radius:6px!important;box-shadow:none!important;padding:12px!important}
.sfa-sep{border-top:1px solid #dcdcde!important;margin-top:8px!important;padding-top:8px!important}
.sfa-sep-bottom{border-bottom:1px solid #dcdcde!important;padding-bottom:8px!important;margin-bottom:8px!important}
.sfa-card-head,.sfa-group-row{cursor:pointer!important;min-height:34px!important;align-items:center!important}
.sfa-title{margin:0!important;font-size:16px!important;font-weight:700!important;line-height:1.3!important;color:#1d2327!important}
.sfa-group-title{margin:0!important;font-size:14px!important;font-weight:700!important;line-height:1.3!important;color:#1d2327!important}
.sfa-actions{display:flex!important;align-items:center!important;gap:8px!important;margin-left:auto!important}
.sfa-card .sfa-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:32px!important;padding:0 12px!important;font-weight:600!important;color:#fff!important;background:#2271b1!important;border:1px solid #2271b1!important;border-radius:4px!important;text-decoration:none!important;transition:none!important;box-shadow:none!important}
.sfa-card .sfa-btn[disabled]{opacity:.5!important;pointer-events:none!important;color:#fff!important}
.sfa-card .sfa-chip{border:1px solid #c3c4c7!important;padding:2px 8px!important;border-radius:4px!important;font-size:12px!important;text-decoration:none!important;background:#f0f0f1!important;color:#2c3338!important;transition:none!important;box-shadow:none!important}
.sfa-card .sfa-toggle{border:1px solid #c3c4c7!important;background:#f0f0f1!important;border-radius:4px!important;width:22px!important;height:22px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;cursor:pointer!important;font-size:12px!important;color:#2c3338!important;transition:none!important;box-shadow:none!important}
.sfa-list{list-style:none!important;padding:0!important;margin:0!important}
.sfa-item{display:flex!important;gap:10px!important;align-items:center!important;padding:8px 0!important}
.sfa-icon{width:24px!important;height:24px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;border-radius:6px!important;background:#f3f4f6!important;flex:0 0 24px!important;font-size:10px!important;font-weight:700!important;color:#111!important}
.sfa-icon-pdf{background:linear-gradient(180deg,#fef2f2,#fee2e2)!important}
.sfa-icon-image{background:linear-gradient(180deg,#eff6ff,#dbeafe)!important}
.sfa-icon-sheet{background:linear-gradient(180deg,#ecfdf5,#d1fae5)!important}
.sfa-icon-ppt{background:linear-gradient(180deg,#fff7ed,#ffedd5)!important}
.sfa-icon-doc{background:linear-gradient(180deg,#eef2ff,#e0e7ff)!important}
.sfa-link{font-weight:600!important;text-decoration:none!important;color:#2271b1!important;font-size:13px!important;line-height:1.4!important}
.sfa-bytes{font-size:12px!important;color:#6b7280!important}
/* 1.6.9 INLINE */
.sfa-card-head,.sfa-group-row{display:flex!important;gap:10px!important;justify-content:space-between!important;align-items:center!important;flex-wrap:nowrap!important}
.sfa-title{flex:1 1 auto!important;min-width:0!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
.sfa-actions{display:flex!important;gap:8px!important;align-items:center!important;flex:0 0 auto!important}
.sfa-actions .sfa-btn{min-width:172px!important;text-align:center!important}
/* No hover effects */
.sfa-card .sfa-btn:hover,.sfa-card .sfa-btn:focus{background:#2271b1!important;border-color:#2271b1!important;color:#fff!important;box-shadow:none!important;text-decoration:none!important}
.sfa-card .sfa-chip:hover,.sfa-card .sfa-chip:focus{background:#f0f0f1!important;border-color:#c3c4c7!important;color:#2c3338!important;box-shadow:none!important;text-decoration:none!important}
.sfa-card .sfa-toggle:hover,.sfa-card .sfa-toggle:focus{background:#f0f0f1!important;border-color:#c3c4c7!important;color:#2c3338!important;box-shadow:none!important}
/* Download selected explicit */
.sfa-card .sfa-dl-selected,.sfa-card .sfa-dl-selected:hover,.sfa-card .sfa-dl-selected:focus{background:#2271b1!important;border-color:#2271b1!important;color:#fff!important;box-shadow:none!important;text-decoration:none!important}
/* 1.6.9.1 INLINE */
.sfa-title{white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
.sfa-actions .sfa-btn,.sfa-actions .sfa-btn:visited,.sfa-actions .sfa-btn:hover,.sfa-actions .sfa-btn:focus{color:#fff!important}
@media (max-width:480px){.sfa-actions .sfa-btn{min-width:130px!important}.sfa-title{font-size:13px!important}.sfa-group-title{font-size:12px!important}.sfa-link{font-size:11.5px!important}}
</style>
<?php endif; ?>
<?php if ( ! wp_script_is( 'sfa-ui', 'enqueued' ) ) : ?>
<script id="sfa-inline-js">
(function(){
  if(window.SFA && window.SFA.__bound){return;}
  function isInteractive(el){
    return !!(el.closest('.sfa-actions') || el.closest('a') || el.closest('input') || el.closest('label') || el.classList.contains('sfa-toggle'));
  }
  function toggleByContainer(container){
    var btn = container.querySelector('.sfa-toggle');
    if(!btn) return;
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', (!expanded).toString());
    var id = btn.getAttribute('aria-controls');
    var el = document.getElementById(id);
    if(!el) return;
    if(expanded){ el.setAttribute('hidden',''); btn.textContent='▸'; }
    else { el.removeAttribute('hidden'); btn.textContent='▾'; }
  }
  function updateButtons(root){
    var selected = root.querySelectorAll('.sfa-select-item:checked').length;
    var btn = root.querySelector('.sfa-dl-selected');
    if(btn){ btn.disabled = selected === 0; }
  }
  document.addEventListener('click', function(e){
    var head = e.target.closest('[data-sfa-head]');
    if(head && !isInteractive(e.target)){ toggleByContainer(head); return; }
    var row = e.target.closest('[data-sfa-row]');
    if(row && !isInteractive(e.target)){ toggleByContainer(row); return; }
    if(e.target && e.target.classList.contains('sfa-toggle')){ toggleByContainer(e.target.parentElement || e.target); return; }
    if(e.target && e.target.classList.contains('sfa-dl-selected')){
      var root = e.target.closest('.sfa-card');
      var tokens = []; root.querySelectorAll('.sfa-select-item:checked').forEach(function(i){ tokens.push(i.getAttribute('data-token')); });
      if(tokens.length===0) return;
      var entry = e.target.getAttribute('data-entry');
      var nonce = e.target.getAttribute('data-nonce');
      var url = (window.ajaxurl || (document.body && document.body.dataset && document.body.dataset.ajaxurl)) || (document.location.origin + '/wp-admin/admin-post.php');
      var qs = '?action=sfa_download_zip&entry='+encodeURIComponent(entry)+'&_wpnonce='+encodeURIComponent(nonce)+'&sel='+encodeURIComponent(tokens.join('|'));
      window.location.href = url + qs;
    }
  });
  document.addEventListener('change', function(e){
    if(e.target.classList.contains('sfa-select-item')){
      var root = e.target.closest('.sfa-card'); if(root){ updateButtons(root); }
    }
  });
  window.SFA = window.SFA || {}; window.SFA.__bound = true;
})();
</script>
<?php endif; ?>

<div class="sfa-card" data-sfa-collapsible>
  <div class="sfa-card-head sfa-sep-bottom" data-sfa-head>
    <button type="button" class="sfa-toggle" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-controls="sfa-body-<?php echo esc_attr( $entry_id ); ?>"><?php echo $open ? '▾' : '▸'; ?></button>
    <h2 class="sfa-title"><?php echo esc_html( $title ); ?></h2>
    <div class="sfa-actions">
      <button type="button" class="sfa-btn sfa-dl-selected" data-entry="<?php echo esc_attr( $entry_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" disabled><?php esc_html_e('Download selected','simple-flow-attachment'); ?></button>
      <?php if ( $zip_all_url ) : ?><a class="sfa-btn" href="<?php echo esc_url( $zip_all_url ); ?>"><?php esc_html_e('Download all (ZIP)','simple-flow-attachment'); ?></a><?php endif; ?>
    </div>
  </div>

  <div id="sfa-body-<?php echo esc_attr( $entry_id ); ?>" class="sfa-body"<?php echo $open ? '' : ' hidden'; ?>>
  <?php $gi = 0; foreach ( $groups as $group => $items ) : $gi++; ?>
    <?php $zip_group_url = $entry_id ? wp_nonce_url( add_query_arg( array( 'action'=>'sfa_download_zip','entry'=>$entry_id,'group'=>$group ), $base ), 'sfa_zip_' . $entry_id ) : ''; ?>
    <section class="sfa-group sfa-sep" data-sfa-collapsible>
      <div class="sfa-group-row" data-sfa-row>
        <button type="button" class="sfa-toggle" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-controls="sfa-group-<?php echo esc_attr( $entry_id . '-' . $gi ); ?>"><?php echo $open ? '▾' : '▸'; ?></button>
        <h3 class="sfa-group-title"><?php echo esc_html( ucwords( str_replace( '-', ' ', $group ) ) ); ?></h3>
        <div class="sfa-actions"><a class="sfa-chip" href="<?php echo esc_url( $zip_group_url ); ?>">ZIP</a></div>
      </div>
      <ul id="sfa-group-<?php echo esc_attr( $entry_id . '-' . $gi ); ?>" class="sfa-list"<?php echo $open ? '' : ' hidden'; ?>>
        <?php foreach ( (array) $items as $i => $item ) : $token = $group . ':' . $i; ?>
          <li class="sfa-item">
            <label class="sfa-check item"><input type="checkbox" class="sfa-select-item" data-token="<?php echo esc_attr( $token ); ?>" data-group="<?php echo esc_attr( $group ); ?>" /></label>
            <span class="sfa-icon sfa-icon-<?php echo esc_attr( $item['icon'] ); ?>"><?php echo esc_html( $item['ext'] ); ?></span>
            <a class="sfa-link" href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['name'] ); ?></a>
            <?php if ( ! empty( $item['size'] ) ) : ?><span class="sfa-bytes"><?php echo esc_html( sfa_format_bytes( $item['size'] ) ); ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
  </div>
</div>
