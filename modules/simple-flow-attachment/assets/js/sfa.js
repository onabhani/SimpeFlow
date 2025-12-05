
(function(){
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
    if(expanded){ el.setAttribute('hidden',''); btn.textContent='\u25B8'; }
    else { el.removeAttribute('hidden'); btn.textContent='\u25BE'; }
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
})();