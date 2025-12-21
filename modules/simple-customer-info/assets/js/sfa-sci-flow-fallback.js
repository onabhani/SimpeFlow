
/*! SFA SCI Flow fallback (isolated; safe for card) */
(function(){
  'use strict';

  function isFlow(){
    try {
      if (document.querySelector('.gravityflow-step-user_input')) return true;
      var qs = (window.location && window.location.search) ? window.location.search : '';
      return /gravityflow\-inbox/.test(qs);
    } catch(e){ return false; }
  }

  function norm(s){
    try{ s = String(s||''); if (s.normalize) s = s.normalize('NFKC'); }catch(e){ s = String(s||''); }
    return s.replace(/\u00a0/g,' ').replace(/\s+/g,' ').trim().toLowerCase();
  }

  function deriveFromCardSafe(){
    var labels = [], ids = [], values = {};
    var card = document.querySelector('.sfa-sci-card');
    if (!card) return {labels:labels, ids:ids, values:values};
    card.querySelectorAll('.sfa-sci-item[data-field-id]').forEach(function(item){
      var fid = parseInt(item.getAttribute('data-field-id'),10)||0;
      if (fid && ids.indexOf(fid)===-1) ids.push(fid);
      var keyEl = item.querySelector('.sfa-sci-key');
      var valEl = item.querySelector('.sfa-sci-val');
      if (keyEl){
        var L = keyEl.textContent||'';
        if (L && labels.indexOf(L)===-1) labels.push(L);
      }
      if (valEl){
        var V = valEl.textContent||'';
        values[fid] = V;
      }
    });
    return {labels:labels, ids:ids, values:values};
  }

  function hidePairsByCardValuesSafe(valuesMap){
    var table = document.querySelector('table.entry-detail-view, table.entry-details-table, table.widefat.fixed.entry-detail-view, table.entry-view');
    if (!table) return 0;
    var hidden = 0;
    var rows = table.querySelectorAll('tr');
    for (var i=0;i<rows.length;i++){
      var nameRow = rows[i];
      var nameCell = nameRow.querySelector('td.entry-view-field-name');
      if (!nameCell) continue;
      var valRow = nameRow.nextElementSibling;
      if (!valRow) continue;
      var valueCell = valRow.querySelector('td.entry-view-field-value');
      if (!valueCell) continue;
      var vt = norm(valueCell.textContent||'');
      if (!vt || vt.length<2) continue;
      var hit = false;
      for (var k in valuesMap){
        if (!valuesMap.hasOwnProperty(k)) continue;
        if (norm(valuesMap[k]) === vt){ hit = true; break; }
      }
      if (hit){
        nameRow.style.display='none';
        valRow.style.display='none';
        hidden += 2;
      }
    }
    return hidden;
  }

  function hasEditControls(){
    return !!document.querySelector('.gravityflow-editable-field input, .gravityflow-editable-field select, .gravityflow-editable-field textarea, .gform_wrapper form[id^="gform_"]');
  }

  function hideNow(){
    if (!isFlow()) return;
    var card = document.querySelector('.sfa-sci-card');
    if (!card) return; // wait for card to render
    if (hasEditControls()) return; // don't hide on edit

    var labels = (Array.isArray(window.SFA_SCI_HIDE_LABELS) && window.SFA_SCI_HIDE_LABELS.length) ? window.SFA_SCI_HIDE_LABELS : [];
    var ids    = (Array.isArray(window.SFA_SCI_HIDE_IDS) && window.SFA_SCI_HIDE_IDS.length) ? window.SFA_SCI_HIDE_IDS : [];

    if (!labels.length || !ids.length){
      var d = deriveFromCardSafe();
      if (!labels.length) labels = d.labels;
      if (!ids.length)    ids    = d.ids;
    }

    var total = 0;
    if (labels.length && typeof window.hideFieldsByLabels === 'function'){ total += window.hideFieldsByLabels(labels); }
    if (ids.length    && typeof window.hideFieldsByIds    === 'function'){ total += window.hideFieldsByIds(ids); }
    if (total === 0){
      var d2 = deriveFromCardSafe();
      total += hidePairsByCardValuesSafe(d2.values || {});
    }
  }

  function schedule(){
    [200, 400, 800, 1500, 2500, 4000].forEach(function(ms){ setTimeout(hideNow, ms); });
  }

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', schedule, {once:true});
  } else {
    schedule();
  }

  try{
    var obs = new MutationObserver(function(muts){
      for (var i=0;i<muts.length;i++){
        var n = muts[i].addedNodes;
        for (var j=0;j<n.length;j++){
          var node = n[j];
          if (!node || node.nodeType !== 1) continue;
          if ((node.matches && (node.matches('table.entry-detail-view, .sfa-sci-card') || node.matches('.gravityflow-step-user_input'))) ||
              (node.querySelector && (node.querySelector('table.entry-detail-view') || node.querySelector('.sfa-sci-card') || node.querySelector('.gravityflow-step-user_input')))) {
            hideNow();
            return;
          }
        }
      }
    });
    obs.observe(document.body, {childList:true, subtree:true});
  }catch(e){}
})();
