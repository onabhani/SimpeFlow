(function () {
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded',fn); }
  function norm(s){ return String(s||'').replace(/\s+/g,' ').trim(); }

  

  // ---- SFA SCI robust fallback for Gravity Flow / AJAXed pages ----
  function normDeep(s){
    try{ s = String(s||''); if (s.normalize) s = s.normalize('NFKC'); }
    catch(e){ s = String(s||''); }
    return s.replace(/\xA0/g,' ').replace(/\s+/g,' ').trim().toLowerCase();
  }
  function deriveFromCard(){
    var labels = [];
    var ids    = [];
    var values = {};
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
  function hidePairsByCardValues(valuesMap){
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
      var vt = normDeep(valueCell.textContent||'');
      if (!vt || vt.length<2) continue;
      var hit = false;
      for (var k in valuesMap){
        if (!valuesMap.hasOwnProperty(k)) continue;
        if (normDeep(valuesMap[k]) === vt){
          hit = true; break;
        }
      }
      if (hit){
        if (nameRow.style.display!=='none'){ nameRow.style.display='none'; hidden++; }
        if (valRow.style.display!=='none'){ valRow.style.display='none'; hidden++; }
      }
    }
    return hidden;
  }
// Targeted field hiding for entry-details-table structure
  function hideFieldsByLabels(labels) {
    var hiddenCount = 0;
    
    console.log('Looking for entry table with class "entry-details-table"');
    
    // Target the specific table structure found in diagnostic
    var entryTable = document.querySelector('table.entry-details-table');
    if (!entryTable) {
      console.log('entry-details-table not found, trying fallbacks...');
      // Fallback options
      var fallbacks = [
        'table[class*="entry-details"]',
        'table[class*="entry"]',
        'table.widefat'
      ];
      
      for (var i = 0; i < fallbacks.length; i++) {
        entryTable = document.querySelector(fallbacks[i]);
        if (entryTable) {
          console.log('Found table with fallback selector:', fallbacks[i]);
          break;
        }
      }
    } else {
      console.log('Found entry-details-table');
    }
    
    if (!entryTable) {
      console.log('No suitable entry table found');
      return 0;
    }
    
    console.log('Table found with', entryTable.rows.length, 'rows');
    
    // Look for field name cells and hide their rows + value rows
    labels.forEach(function(label) {
      var normalizedLabel = norm(label);
      console.log('Looking for field label:', normalizedLabel);
      
      // Find all field name cells
      var fieldNameCells = entryTable.querySelectorAll('td.entry-view-field-name');
      console.log('Found', fieldNameCells.length, 'field name cells');
      
      fieldNameCells.forEach(function(cell) {
        var cellText = norm(cell.textContent);
        if (cellText === normalizedLabel) {
          var row = cell.closest('tr');
          if (row) {
            console.log('Hiding name row for field:', normalizedLabel);
            row.style.display = 'none';
            hiddenCount++;
            
            // Also hide the next row (which should contain the field value)
            var nextRow = row.nextElementSibling;
            if (nextRow && nextRow.tagName === 'TR') {
              console.log('Hiding value row for field:', normalizedLabel);
              nextRow.style.display = 'none';
              hiddenCount++;
            }
            
            // Alternative approach: look for value cell in the same row
            var valueCell = row.querySelector('td.entry-view-field-value');
            if (valueCell) {
              console.log('Found value cell in same row for field:', normalizedLabel);
              // Value is in the same row, already hidden
            }
          }
        }
      });
    });
    
    return hiddenCount;
  }

  // Also try to hide by field IDs (for standard GF structures)
  function hideFieldsByIds(ids) {
    var hiddenCount = 0;
    
    // Try standard GF selectors first
    var tables = [
      document.querySelector('table.entry-detail-view'),
      document.querySelector('table.entry-view'),
      document.querySelector('table.widefat')
    ];
    
    var entryTable = null;
    for (var i = 0; i < tables.length; i++) {
      if (tables[i]) {
        entryTable = tables[i];
        console.log('Found standard GF table for ID-based hiding');
        break;
      }
    }
    
    if (!entryTable) {
      console.log('No standard GF table found for ID-based hiding');
      return 0;
    }
    
    ids.forEach(function(fieldId) {
      var selectors = [
        'tr.entry-view-field-' + Math.floor(fieldId),
        'tr#field_' + Math.floor(fieldId),
        'tr[id^="field_' + Math.floor(fieldId) + '_"]'
      ];
      
      if (fieldId % 1 !== 0) {
        var fieldIdStr = String(fieldId).replace('.', '_');
        selectors.push('tr#field_' + fieldIdStr);
        selectors.push('tr.entry-view-field-' + fieldIdStr);
      }
      
      selectors.forEach(function(selector) {
        var rows = entryTable.querySelectorAll(selector);
        rows.forEach(function(row) {
          row.style.display = 'none';
          hiddenCount++;
        });
      });
    });
    
    return hiddenCount;
  }

  // Main field hiding function
  function performFieldHiding() {
    try {
      console.log('=== SFA SCI FIELD HIDING (TARGETED) ===');
      
      if (window.SFA_SCI_SKIP_HIDE || window.SFA_SCI_HIDE_ENABLED === false) {
        console.log('Field hiding disabled');
        return;
      }
      
      var labels = Array.isArray(window.SFA_SCI_HIDE_LABELS) ? window.SFA_SCI_HIDE_LABELS : [];
      var ids = Array.isArray(window.SFA_SCI_HIDE_IDS) ? window.SFA_SCI_HIDE_IDS : [];
      
      console.log('Labels to hide:', labels);
      console.log('IDs to hide:', ids);
      
      var totalHidden = 0;
      
      // Try label-based hiding first (for custom table structures)
      if (labels.length > 0) {
        console.log('Attempting label-based hiding...');
        totalHidden += hideFieldsByLabels(labels);
      }
      
      // Try ID-based hiding as fallback (for standard GF structures)
      if (ids.length > 0 && totalHidden === 0) {
        console.log('Attempting ID-based hiding as fallback...');
        totalHidden += hideFieldsByIds(ids);
      }
      
      console.log('Total fields hidden:', totalHidden);
      
      // Simple retry if no fields were hidden
      if (totalHidden === 0) {
        console.log('No fields hidden, retrying in 500ms...');
        setTimeout(function() {
          console.log('=== RETRY ATTEMPT ===');
          var retryHidden = 0;
          
          if (labels.length > 0) {
            retryHidden += hideFieldsByLabels(labels);
          }
          if (ids.length > 0 && retryHidden === 0) {
            retryHidden += hideFieldsByIds(ids);
          }
          
          console.log('SFA SCI: Hidden ' + retryHidden + ' field elements (retry)');
        }, 500);
      } else {
        console.log('SFA SCI: Hidden ' + totalHidden + ' field elements');
      }
      
    } catch(e) {
      console.error('SFA SCI: Error hiding fields:', e);
    }
  }

  ready(function(){
    // Toggle functionality for customer card
    document.querySelectorAll('.sfa-sci-card').forEach(function(card){
      var header = card.querySelector('.sfa-sci-header');
      var body   = card.querySelector('.sfa-sci-body');
      if(!header || !body) return;

      var collapsed = card.hasAttribute('data-collapsed');
      body.hidden = collapsed;
      header.setAttribute('aria-expanded', String(!collapsed));

      function toggle(){ body.hidden = !body.hidden; header.setAttribute('aria-expanded', String(!body.hidden)); }
      header.addEventListener('click', toggle);
      header.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); toggle(); } });
    });

    // Perform field hiding with delay
    setTimeout(performFieldHiding, 300);
  });
})();

// --- Force card above Gravity Flow entry details (admin + front-end), one-time only ---
(function($){
  $(function(){
    try {
      var $card = $('.sfa-sci-card').first();
      var $table = $('table.entry-detail-view, table.entry-details-table').first();
      if ($card.length && $table.length) {
        $card.insertBefore($table);
      }
    } catch(e){ /* no-op */ }
  });

  function hasVisibleControls(scope){
    var sc = scope || document;
    var el = sc.querySelector('table.entry-detail-view, .gform_wrapper');
    if (!el) return false;
    var ctrls = el.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
    for (var i=0;i<ctrls.length;i++){
      if (ctrls[i].offsetParent !== null) return true;
    }
    return false;
  }

  function runHideFallback(){
    if (hasVisibleControls()) return;
    var total = 0;
    try{
      var cfgLabels = (Array.isArray(window.SFA_SCI_HIDE_LABELS)&&window.SFA_SCI_HIDE_LABELS.length)?window.SFA_SCI_HIDE_LABELS:[];
      var cfgIds    = (Array.isArray(window.SFA_SCI_HIDE_IDS)&&window.SFA_SCI_HIDE_IDS.length)?window.SFA_SCI_HIDE_IDS:[];
      var derived   = deriveFromCard();
      var labels    = cfgLabels.length ? cfgLabels : derived.labels;
      var ids       = cfgIds.length    ? cfgIds    : derived.ids;
      if (labels.length){ total += hideFieldsByLabels(labels); }
      if (ids.length){ total += hideFieldsByIds(ids); }
      if (total === 0 && Object.keys(derived.values).length){
        total += hidePairsByCardValues(derived.values);
      }
    }catch(e){}
    return total;
  }

  (function boot(){
    var tries = [150, 300, 600, 1000, 1600, 2500, 4000];
    tries.forEach(function(t){ setTimeout(runHideFallback, t); });
    try{
      var obs = new MutationObserver(function(muts){
        for (var i=0;i<muts.length;i++){
          var n = muts[i].addedNodes;
          for (var j=0;j<n.length;j++){
            var node = n[j];
            if (node && node.nodeType===1){
              if ((node.matches && node.matches('table.entry-detail-view, table.entry-details-table, .gform_wrapper, .gravityflow-step-user_input')) ||
                  (node.querySelector && (node.querySelector('table.entry-detail-view') || node.querySelector('table.entry-details-table')))){
                runHideFallback();
                return;
              }
            }
          }
        }
      });
      obs.observe(document.body, {childList:true, subtree:true});
    }catch(e){}
  })();
})(jQuery);

