window.SFAQG_DEBUG = (typeof window.SFAQG_DEBUG === 'boolean') ? window.SFAQG_DEBUG : false;
function qgDebug(){ if (window.SFAQG_DEBUG && window.console && console.debug) { console.debug.apply(console, arguments); } }




(function($){
  // Build a metrics definition from labels (array of strings) or fall back to 10 defaults.
  function buildMetricsDef(labels){
    var out = [];
    if (Array.isArray(labels) && labels.length){
      for (var i=0;i<labels.length;i++){
        out.push({ k: 'm'+(i+1), label: String(labels[i]||('Metric '+(i+1))) });
      }
      return out;
    }
    for (var j=1;j<=10;j++){ out.push({ k:'m'+j, label:'Metric '+j }); }
    return out;
  }

  function indexByName(items){
    var map = {};
    (items||[]).forEach(function(it){ if (it && typeof it.name==='string') map[it.name]=it; });
    return map;
  }

  // existing shape: {items:[{name,metrics:[{k,result,note}]}], scope:{recheckOnly:[names]}, fixedItems:[names]}
  function hydrateFromExisting(items, existing, metricsDef){
    if (!existing || !existing.items) return;
    var byName = indexByName(items);
    (existing.items||[]).forEach(function(eit){
      if (!eit || !eit.name) return;
      var it = byName[eit.name];
      if (!it) return;
      var hydrated = [];
      metricsDef.forEach(function(def){
        var found = (eit.metrics||[]).find(function(m){ return m && m.k===def.k; }) || {};
        hydrated.push({ k:def.k, result:(found.result||''), note:(found.note||'') });
      });
      it.metrics = hydrated;
    });
  }

  function ensureMetricContainers(items, metricsDef){
    (items||[]).forEach(function(it){
      if (!Array.isArray(it.metrics) || it.metrics.length!==metricsDef.length){
        it.metrics = metricsDef.map(function(m){ return {k:m.k, result:'', note:''}; });
      }
    });
  }

  // QG-017 — helper to toggle required UI state on note inputs
  function qgApplyNoteRequiredUI($note, makeRequired){
    if (makeRequired){
      $note.attr('required','required');
      if (($note.val() || '').trim() === ''){
        $note.addClass('is-required-empty').attr('aria-invalid','true');
      }
    } else {
      $note.removeAttr('required')
           .removeClass('is-required-empty')
           .removeAttr('aria-invalid');
    }
  }

  // --- QG-205 helpers ---
  function qgStatusFromData(it, metricsDef){
    var chosen = 0, fails = 0, total = metricsDef.length;
    var ms = Array.isArray(it && it.metrics) ? it.metrics : [];
    for (var i = 0; i < total; i++){
      var m = ms[i] || {};
      var r = m.result;
      if (r === 'pass' || r === 'fail'){
        chosen++;
        if (r === 'fail') fails++;
      }
    }
    if (chosen === 0) return 'pending';
    if (fails > 0)    return 'fail';
    if (chosen === total) return 'pass';
    return 'pending';
  }

  // Prefer JSON->cfg->DOM for Fixed (aligns with how PASS/FAIL hydrate from JSON)
  function qgBuildFixedLookup(cfg, existing){
    var map = {};
    var list = null;

    if (existing && Array.isArray(existing.fixedItems) && existing.fixedItems.length){
      list = existing.fixedItems;
    } else if (cfg && Array.isArray(cfg.fixedItems) && cfg.fixedItems.length){
      list = cfg.fixedItems;
    }

    if (list){
      list.forEach(function(n){ if(n) map[String(n)] = 1; });
    } else {
      // fallback union (data-config on other fields, localized fields, live DOM if present)
      map = qgComputeFixedLookup();
    }
    return map;
  }

  function qgSortItemsForQG205(items, recheckOnly, metricsDef){
    var target = {};
    if (Array.isArray(recheckOnly)){
      recheckOnly.forEach(function(n){ if (n) target[String(n)] = 1; });
    }
    // Stable-ish sort: keep original order inside same bucket
    var withFlags = items.map(function(it, idx){
      var st  = qgStatusFromData(it, metricsDef);        // 'pass' | 'fail' | 'pending'
      var pri = (target[it.name] || st !== 'pass') ? 0 : 1; // 0 = top bucket (fixed or not-passed-yet)
      var sub = (st === 'fail') ? 0 : (st === 'pending' ? 1 : 2); // fail → pending → pass
      return { it: it, idx: idx, pri: pri, sub: sub };
    });
    withFlags.sort(function(a,b){
      if (a.pri !== b.pri) return a.pri - b.pri;   // bucket first
      if (a.sub !== b.sub) return a.sub - b.sub;   // then status within bucket
      return a.idx - b.idx;                        // preserve original order
    });
    return withFlags.map(function(x){ return x.it; });
  }

  // ---------- QG-021 helpers: per-item status + collapsible ----------
  function getItemStatusFromDOM($item){
    var $checked = $item.find('.sfa-qg-result:checked');
    var total = $item.find('.sfa-qg-result').length / 2; // two radios per metric
    var chosen = $checked.length;
    if (chosen === 0) return 'pending';
    // if any fail => fail
    var hasFail = false;
    $checked.each(function(){ if ($(this).val()==='fail') hasFail = true; });
    if (hasFail) return 'fail';
    // all chosen and none fail => pass, else pending
    return (chosen === total) ? 'pass' : 'pending';
  }

  function badgeHtml(status){
    // reuse badge styles from quality.css (.sfa-qg-badge)
    var cls = 'sfa-qg-badge ';
    var text = '';
    if (status==='pass'){ cls += 'is-pass'; text='PASS'; }
    else if (status==='fail'){ cls += 'is-fail'; text='FAIL'; }
    else { cls += 'is-empty'; text='PENDING'; }
    return '<span class="'+cls+'">'+text+'</span>';
  }

  function setCollapseState($item, expanded){
    $item.toggleClass('is-collapsed', !expanded);
    var $head = $item.find('.sfa-qg-item-head').first();
    $head.attr('aria-expanded', expanded ? 'true':'false');
    $head.find('.sfa-qg-caret').text(expanded ? '▾' : '▸');
    $item.find('.sfa-qg-item-body').css('display', expanded ? '' : 'none');
  }

  function buildItemHead(name, initialStatus){
    var safe = $('<div/>').text(name).html();
    return $(
      '<div class="sfa-qg-item-head" role="button" tabindex="0" aria-expanded="false" style="cursor:pointer;">' +
        '<div class="sfa-qg-item-row" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
          '<div class="sfa-qg-item-name">' +
            '<span class="sfa-qg-item-label">Item:</span> ' + safe +
          '</div>' +
          '<div class="sfa-qg-status" style="display:inline-flex;gap:6px;align-items:center;">' +
            '<span class="sfa-qg-status-badge">' + badgeHtml(initialStatus) + '</span>' +
            '<span class="sfa-qg-caret" aria-hidden="true">▸</span>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  function updateItemBadge($item){
    var st = getItemStatusFromDOM($item);
    $item.find('.sfa-qg-status-badge').html( badgeHtml(st) );
  }
  // -------------------------------------------------------------------

  function buildMetricRow(i, mIndex, mdef, metric, readOnly, requireNoteOnFail){
    function esc(s){ return $('<div/>').text(s).html(); }

    var $row = $('<div class="sfa-qg-metric-row"></div>');

    // Col 1: label
    $row.append('<div class="sfa-qg-metric-label">'+ esc(mdef.label) +'</div>');

    // Col 2: inline radios
    var groupName = 'qg_'+i+'_'+mIndex;
    var $choices  = $('<div class="sfa-qg-choice" role="radiogroup" aria-label="'+esc(mdef.label)+'"></div>');

    var $pass = $(
      '<label class="sfa-qg-radio" data-value="pass">'+
        '<input type="radio" class="sfa-qg-result" name="'+groupName+'" data-i="'+i+'" data-m="'+mIndex+'" value="pass">'+
        '<span>Pass</span>'+
      '</label>'
    );
    var $fail = $(
      '<label class="sfa-qg-radio" data-value="fail">'+
        '<input type="radio" class="sfa-qg-result" name="'+groupName+'" data-i="'+i+'" data-m="'+mIndex+'" value="fail">'+
        '<span>Fail</span>'+
      '</label>'
    );

    // initial selection + highlight
    if (metric && metric.result === 'pass'){
      $pass.find('input').prop('checked', true);
      $pass.addClass('is-checked is-pass');
    }
    if (metric && metric.result === 'fail'){
      $fail.find('input').prop('checked', true);
      $fail.addClass('is-checked is-fail');
    }

    $choices.append($pass).append($fail);
    $row.append($choices); // grid column 2

    // Col 3: note + photo button wrapper
    var $noteWrapper = $('<div class="sfa-qg-note-wrapper"></div>');
    var $note = $('<input type="text" class="sfa-qg-note" data-i="'+i+'" data-m="'+mIndex+'" placeholder="Note (required if Fail)">');
    if (metric && metric.note) $note.val(metric.note);
    if (requireNoteOnFail && metric && metric.result === 'fail') $note.attr('required','required');

    // Photo evidence button (visible on all devices for testing, can be refined later)
    // Button is hidden by default, only shown when FAIL is explicitly selected
    if (!readOnly) {
      var hasPhoto = !!(metric && metric.photo);
      // Only show if result is explicitly 'fail', otherwise always hidden
      var shouldShow = (metric && metric.result === 'fail');

      var $photoBtn = $(
        '<button type="button" class="sfa-qg-photo-btn' +
          (hasPhoto ? ' has-photo' : '') +
          (!shouldShow ? ' hidden' : '') + '" ' +
          'data-i="'+i+'" data-m="'+mIndex+'" title="Add photo evidence">' +
          '📷' +
        '</button>'
      );
      $noteWrapper.append($note).append($photoBtn);
    } else {
      $noteWrapper.append($note);
    }

    if (readOnly){
      $choices.find('input').prop('disabled', true);
      $note.prop('disabled', true);
      $row.addClass('sfa-qg-readonly');
    }
    $row.append($noteWrapper); // grid column 3

    return $row;
  }

  function computeSummary(items){
    var total=0, failed=0;
    (items||[]).forEach(function(it){
      (it.metrics||[]).forEach(function(m){
        if (m.result==='pass' || m.result==='fail'){ total++; if (m.result==='fail') failed++; }
      });
    });
    return { items_total: (items||[]).length, metrics_total: total, metrics_failed: failed };
  }

  function isComplete(items, recheckOnly, metricsDef){
    var targetNames = Array.isArray(recheckOnly) && recheckOnly.length ? recheckOnly : null;

    function allMetricsChosen(it){
      if (!it.metrics || it.metrics.length !== metricsDef.length) return false;
      for (var i=0;i<it.metrics.length;i++){
        var res = it.metrics[i].result;
        if (!(res==='pass' || res==='fail')) return false;
      }
      return true;
    }

    if (targetNames){
      var targets = {};
      targetNames.forEach(function(n){ targets[n]=1; });
      for (var i=0;i<items.length;i++){
        var it = items[i];
        if (!it || !it.name) continue;
        if (targets[it.name] && !allMetricsChosen(it)) return false;
      }
      return true;
    }

    for (var j=0;j<items.length;j++){
      if (!allMetricsChosen(items[j])) return false;
    }
    return true;
  }

  // Iterate all rework gfields (support both with/without explicit class)
  function qgEachReworkGfield(cb){
    var $A = $('.qg-rework-field');
    var $B = $('.gfield').filter(function(){ return $(this).find('.qg-rework-help').length; });
    var seen = new Set();
    $A.add($B).each(function(){
      var el = this;
      if (seen.has(el)) return;
      seen.add(el);
      cb($(el));
    });
  }

  // Build a lookup of fixed items from the best available sources:
  // 1) data-config.fixedItems on any .sfa-qg-field (survives refresh),
  // 2) window.SFA_QG_CFG.fields[*].fixedItems if present,
  // 3) rework-field sources (data-fixed, live ticks, row chips) when available.
  function qgComputeFixedLookup(){
    var fixed = {};

    // (1) Read from each QC field's data-config (most reliable on reload)
    $('.sfa-qg-field').each(function(){
      var cfg = $(this).data('config');
      // jQuery may return the raw string. Parse if needed.
      if (typeof cfg === 'string') {
        try { cfg = JSON.parse(cfg); } catch(e){ cfg = null; }
      }
      if (cfg && Array.isArray(cfg.fixedItems)) {
        cfg.fixedItems.forEach(function(n){
          n = $.trim(String(n||'')); if (n) fixed[n] = 1;
        });
      }
    });

    // (2) Also merge from localized config, if present
    if (window.SFA_QG_CFG && Array.isArray(window.SFA_QG_CFG.fields)) {
      window.SFA_QG_CFG.fields.forEach(function(cfg){
        if (!cfg) return;
        if (typeof cfg === 'string') {
          try { cfg = JSON.parse(cfg); } catch(e){ cfg = null; }
        }
        if (cfg && Array.isArray(cfg.fixedItems)) {
          cfg.fixedItems.forEach(function(n){
            n = $.trim(String(n||'')); if (n) fixed[n] = 1;
          });
        }
      });
    }

    // (3) Fall back to the rework-field DOM when available on the page
    qgEachReworkGfield(function($g){
      var $help = $g.find('.qg-rework-help').first();

      // saved list on data-fixed
      var raw = $help.attr('data-fixed');
      if (raw){
        try{
          var arr = JSON.parse(raw);
          if (Array.isArray(arr)){ arr.forEach(function(n){ if (n) fixed[String(n)] = 1; }); }
        }catch(e){
          qgDebug('[QG] Failed to parse data-fixed JSON', raw, e);
        }
      }

      // live checked inputs (if present)
      $g.find('input[type="checkbox"]:checked').each(function(){
        var v = $.trim(String($(this).val()||'')); if (v) fixed[v] = 1;
      });

      // row badges (when inputs are hidden)
      $g.find('table tbody tr').each(function(){
        var $td  = $(this).find('td').first();
        if ($td.find('.sfa-qg-badge.is-fixed').length){
          var name = $.trim($td.clone().children().remove().end().text());
          if (name) fixed[name] = 1;
        }
      });
    });

    return fixed;
  }

  // QG-204 — mirror “Fixed” chip into QC item headers (beside PASS/FAIL)
  function qgSyncFixedBadgesIntoQC(){
    var fixed = qgComputeFixedLookup(); // { "ItemName": 1, ... }

    $('.sfa-qg-item').each(function(){
      var $item = $(this);

      // Prefer the canonical name we set during render
      var rawName = $.trim(String($item.attr('data-name') || ''));

      // Fallback to dom text (keeps back-compat)
      if (!rawName){
        var $nameEl = $item.find('.sfa-qg-item-name').first().clone();
        $nameEl.find('.sfa-qg-tag').remove();
        rawName = $.trim($nameEl.text());
        rawName = rawName.replace(/^\s*Item:\s*/i,''); // EN fallback
      }

      var $status = $item.find('.sfa-qg-status').first();
      if (!$status.length) return;

      var $chip = $status.find('.sfa-qg-badge.is-fixed');
      if (fixed[rawName]){
        if (!$chip.length){
          var $caret = $status.find('.sfa-qg-caret').first();
          var $new   = $('<span class="sfa-qg-badge is-fixed">Fixed</span>');
          if ($caret.length) { $new.insertBefore($caret); } else { $status.append($new); }
        }
      } else {
        $chip.remove();
      }
    });
  }

  // after defining qgEachReworkGfield and the (correct) qgSyncFixedBadgesIntoQC
  window.SFAQG = window.SFAQG || {};
  window.SFAQG.qgEachReworkGfield = qgEachReworkGfield;
  window.SFAQG.qgSyncFixedBadgesIntoQC = qgSyncFixedBadgesIntoQC;

  // Derive target names (recheckOnly) from the helper table, if cfg.recheckOnly is missing
  function qgGetRecheckNamesFromDOM(){
    var names = [];
    qgEachReworkGfield(function($g){
      $g.find('.qg-rework-help table tbody tr').each(function(){
        var name = $.trim($(this).find('td').first().clone().children().remove().end().text());
        if (name) names.push(name);
      });
    });
    names = names.filter(Boolean);
    return names.length ? Array.from(new Set(names)) : null;
  }

  function renderField(cfg){
    // Validate formId and fieldId are numeric to prevent selector injection
    if (!cfg || !cfg.formId || !cfg.fieldId) return;
    var formId = parseInt(cfg.formId, 10);
    var fieldId = parseInt(cfg.fieldId, 10);
    if (isNaN(formId) || isNaN(fieldId)) {
      qgDebug('[QG] Invalid formId or fieldId', cfg);
      return;
    }

    var $wrap = $('.sfa-qg-field[data-form="'+formId+'"][data-field="'+fieldId+'"]');
    if (!$wrap.length) return;

    var metricsDef = buildMetricsDef(cfg.metricLabels);
    var isUserInput   = (cfg && cfg.context === 'user_input');
    var isQualityGate = (cfg && cfg.context === 'quality_gate');

    var $input = $wrap.find('input.sfa-qg-input');
    var existing = null;
    try { existing = JSON.parse($input.val()||''); } catch(e){ existing = null; }

qgDebug('[QG] render cfg', cfg);
qgDebug('[QG] existing fixedItems', existing && existing.fixedItems);

var fixedLookup = qgBuildFixedLookup(cfg, existing);
qgDebug('[QG] fixed lookup used', fixedLookup);

    var items = (cfg.items && Array.isArray(cfg.items) && cfg.items.length)
      ? cfg.items.map(function(it){ return { name: it.name||'Item', metrics: [] }; })
      : [{ name:'Batch', metrics: [] }];

    if (!Array.isArray(items)) items = []; // safety

    ensureMetricContainers(items, metricsDef);
    hydrateFromExisting(items, existing, metricsDef);

    var recheckOnly = (cfg.recheckOnly && Array.isArray(cfg.recheckOnly) && cfg.recheckOnly.length) ? cfg.recheckOnly : null;

    /* QG-110 — fallback derive recheckOnly from the rework helper table */
    if (!recheckOnly){
      var fallback = qgGetRecheckNamesFromDOM();
      if (fallback && fallback.length){ recheckOnly = fallback; }
    }

    var isRecheckTarget = {};
    if (recheckOnly){ recheckOnly.forEach(function(n){ isRecheckTarget[n]=1; }); }

    var $ui = $('<div class="sfa-qg-ui"></div>');

    // Determine if we should show batch controls (global editability)
    var showBatchControls = isQualityGate || isUserInput; // Show on both QC and rework steps

    // QG-205 — sort when returning after rework:
    if (isQualityGate || (Array.isArray(recheckOnly) && recheckOnly.length)) {
      items = qgSortItemsForQG205(items, recheckOnly, metricsDef);
    }

    // fixedLookup already built above (line 417), no need to rebuild

    items.forEach(function(it, idx){
      var isFixedTarget = !!(recheckOnly && isRecheckTarget[it.name]); // items coordinator marked to recheck
      // Only lock non-target items on the User Input (rework) step
      var readOnly = !!(recheckOnly && !isFixedTarget && isUserInput);

      var $item = $('<div class="sfa-qg-item" data-index="'+idx+'"></div>');
      $item.attr('data-name', it.name || '');

      // QG-021: Collapsible Head (with status badge + caret)
      var initialStatus = 'pending';
      if (it.metrics && it.metrics.length){
        var anyFail=false, allChosen=true;
        for (var k=0;k<it.metrics.length;k++){
          var r = it.metrics[k].result;
          if (r!=='pass' && r!=='fail') { allChosen=false; }
          if (r==='fail') anyFail=true;
        }
        initialStatus = anyFail ? 'fail' : (allChosen ? 'pass' : 'pending');
      }

      var $head = buildItemHead(it.name, initialStatus);

      // (readOnly) “Passed Previously” tag…
if (readOnly && initialStatus === 'pass') {
  $head.find('.sfa-qg-item-name').append(' <span class="sfa-qg-tag">Passed Previously</span>');
}


      // Persistent “Fixed” chip (from preferred fixedLookup)
      if (fixedLookup[it.name] && $head && typeof $head.find === 'function') {
        var $status = $head.find('.sfa-qg-status').first();
        if ($status && $status.length) {
          $status.find('.sfa-qg-badge.is-fixed').remove();
          var $caret = $status.find('.sfa-qg-caret').first();
          var $chip  = $('<span class="sfa-qg-badge is-fixed">Fixed</span>');
          if ($caret && $caret.length) { $chip.insertBefore($caret); } else { $status.append($chip); }
        }
      }

      $item.append($head);

      // Body (metrics)
      var $body = $('<div class="sfa-qg-item-body"></div>');
      var $metrics = $('<div class="sfa-qg-metrics"></div>');
      metricsDef.forEach(function(mdef, mIndex){
        var metric = it.metrics[mIndex];
        $metrics.append( buildMetricRow(idx, mIndex, mdef, metric, readOnly, !!cfg.requireNoteOnFail) );
      });
      $body.append($metrics);
      $item.append($body);

      updateItemBadge($item);
      // default collapsed? Expand if any fail or if it's an active recheck target with incomplete metrics.
      var expand = (initialStatus==='fail') || (recheckOnly && !readOnly);
      setCollapseState($item, expand);

      // toggle handlers
      $head.on('click', function(){
        setCollapseState($item, $item.hasClass('is-collapsed'));
      }).on('keydown', function(e){
        if (e.key==='Enter' || e.key===' '){
          e.preventDefault();
          setCollapseState($item, $item.hasClass('is-collapsed'));
        }
      });

      $ui.append($item);
    });

    $wrap.find('.sfa-qg-ui').replaceWith($ui);
    // Remove the placeholder once UI exists
    $wrap.find('.sfa-qg-placeholder').remove();

    // LocalStorage backup helpers
    var backupKey = 'qg_backup_' + formId + '_' + fieldId;
    var backupTimer;

    function saveToLocalStorage() {
      try {
        var backup = {
          timestamp: Date.now(),
          items: items,
          formId: formId,
          fieldId: fieldId,
          entryId: getParam('lid') || getParam('entry_id') || 0
        };
        localStorage.setItem(backupKey, JSON.stringify(backup));
        qgDebug('[QG] Auto-saved to localStorage', backupKey);
      } catch(e) {
        qgDebug('[QG] Failed to save to localStorage', e);
      }
    }

    function scheduleAutoSave() {
      clearTimeout(backupTimer);
      backupTimer = setTimeout(saveToLocalStorage, 2000); // 2 second debounce
    }

    function clearLocalStorageBackup() {
      try {
        localStorage.removeItem(backupKey);
        qgDebug('[QG] Cleared localStorage backup', backupKey);
      } catch(e) {}
    }

    function getParam(name) {
      try {
        return new URLSearchParams(window.location.search).get(name);
      } catch(e) {
        return null;
      }
    }

    function collectAndWrite(){
      // sync metrics from DOM into items
      qgSyncFixedBadgesIntoQC();
      $ui.find('.sfa-qg-item').each(function(){
        var $item = $(this);
        var i = parseInt($item.attr('data-index'),10);

        $item.find('.sfa-qg-metric-row').each(function(){
          var $row = $(this);
          var $rChecked = $row.find('.sfa-qg-result:checked');
          var $rAny     = $row.find('.sfa-qg-result').first(); // fallback for indexes

          var m = parseInt(($rChecked.attr('data-m') || $rAny.attr('data-m')),10);
          var res = ($rChecked.val() || '');
          var note= $row.find('.sfa-qg-note').val();

          if (!items[i].metrics) items[i].metrics = [];
          items[i].metrics[m] = {
            k: metricsDef[m].k,
            label: metricsDef[m].label,   // include label for reporting
            result: res,
            note: note
          };
        });

        // update per-item badge every collect
        updateItemBadge($item);
      });

      // Collect Fixed names currently known (union) but filter to current item set
      var fixedMap = qgComputeFixedLookup();
      var fixedList = [];
      (items||[]).forEach(function(it){
        if (it && it.name && fixedMap[it.name]) fixedList.push(it.name);
      });
      // De-dupe
      var fixedUnique = Array.from(new Set(fixedList));

      var complete = isComplete(items, recheckOnly, metricsDef);
      var summary  = computeSummary(items);
      var payload  = { items: items, summary: summary };
      if (recheckOnly){ payload.scope = { recheckOnly: recheckOnly.slice(0) }; }
      if (fixedUnique.length){ payload.fixedItems = fixedUnique; }

      // Keep data-config in sync so future renders can read fixed without DOM
      try{
        var cfgLocal = $wrap.data('config') || JSON.parse($wrap.attr('data-config')||'{}');
        if (cfgLocal && typeof cfgLocal === 'object'){
          cfgLocal.fixedItems = fixedUnique.slice(0);
          $wrap.data('config', cfgLocal);
          $wrap.attr('data-config', JSON.stringify(cfgLocal));
        }
      }catch(e){}

      if (complete){
        $input.val(JSON.stringify(payload)).trigger('change');
        clearLocalStorageBackup(); // Clear backup on successful completion
      } else {
        // Not complete → keep input empty (legacy behavior) but still updated data-config lets the UI show badges
        $input.val('').trigger('change');
      }

      // Schedule auto-save to localStorage
      scheduleAutoSave();
    }

    // Try to restore from localStorage backup if no existing data
    if (!existing || !existing.items) {
      try {
        var backupData = localStorage.getItem(backupKey);
        if (backupData) {
          var backup = JSON.parse(backupData);
          var age = Date.now() - backup.timestamp;

          // Only restore if backup is less than 4 hours old
          if (age < 4 * 60 * 60 * 1000 && backup.items && backup.items.length) {
            var ageMinutes = Math.round(age / 60000);
            var restoreMsg = 'Found unsaved work from ' + ageMinutes + ' minutes ago. Restore it?';

            if (confirm(restoreMsg)) {
              existing = { items: backup.items };
              qgDebug('[QG] Restored from localStorage backup', backup);
            } else {
              clearLocalStorageBackup();
            }
          } else if (age >= 4 * 60 * 60 * 1000) {
            // Clear old backups
            clearLocalStorageBackup();
          }
        }
      } catch(e) {
        qgDebug('[QG] Failed to restore from localStorage', e);
      }
    }

    // Restore previous selections (pill highlight + QG-017 visual hint)
    if (existing && existing.items){
      existing.items.forEach(function(eit){
        var idx = (items||[]).findIndex(function(it){ return it.name===eit.name; });
        if (idx>-1){
          (eit.metrics||[]).forEach(function(m){
            var mIdx = metricsDef.findIndex(function(d){ return d.k===m.k; });
            if (mIdx>-1){
              var $item   = $ui.find('.sfa-qg-item[data-index="'+idx+'"]');
              var $row    = $item.find('.sfa-qg-metric-row').eq(mIdx);
              var val     = (m.result||'');
              if (val==='pass' || val==='fail'){
                $row.find('.sfa-qg-result[value="'+val+'"]').prop('checked',true);
                $row.find('.sfa-qg-radio').removeClass('is-checked is-pass is-fail');
                $row.find('.sfa-qg-result[value="'+val+'"]').closest('.sfa-qg-radio')
                    .addClass('is-checked ' + (val==='pass' ? 'is-pass' : 'is-fail'));
              }
              var note = (m.note||'');
              var $note = $row.find('.sfa-qg-note');
              $note.val(note);
              qgApplyNoteRequiredUI($note, !!cfg.requireNoteOnFail && val==='fail');
            }
          });
          updateItemBadge( $ui.find('.sfa-qg-item[data-index="'+idx+'"]') );
        }
      });
      // If the saved JSON also had fixedItems, mirror them into config and headers now.
      if (Array.isArray(existing.fixedItems) && existing.fixedItems.length){
        try{
          var cfgLocal = $wrap.data('config') || JSON.parse($wrap.attr('data-config')||'{}');
          if (cfgLocal && typeof cfgLocal === 'object'){
            cfgLocal.fixedItems = existing.fixedItems.slice(0);
            $wrap.data('config', cfgLocal);
            $wrap.attr('data-config', JSON.stringify(cfgLocal));
          }
        }catch(e){}
      }
    }else{
      $input.val('');
    }

    // Swipe gestures for iPad: left = fail, right = pass
    if ('ontouchstart' in window) {
      var touchStartX = 0;
      var touchStartY = 0;

      $wrap.on('touchstart', '.sfa-qg-metric-row', function(e) {
        touchStartX = e.originalEvent.touches[0].clientX;
        touchStartY = e.originalEvent.touches[0].clientY;
      });

      $wrap.on('touchend', '.sfa-qg-metric-row', function(e) {
        if (!touchStartX || !touchStartY) return;

        var touchEndX = e.originalEvent.changedTouches[0].clientX;
        var touchEndY = e.originalEvent.changedTouches[0].clientY;
        var deltaX = touchEndX - touchStartX;
        var deltaY = Math.abs(touchEndY - touchStartY);

        // Require 80px horizontal swipe and less than 30px vertical (prevent accidental swipes during scroll)
        if (Math.abs(deltaX) > 80 && deltaY < 30) {
          var $row = $(this);
          var $radio;

          if (deltaX > 0) {
            // Swipe right = Pass
            $radio = $row.find('.sfa-qg-result[value="pass"]');
          } else {
            // Swipe left = Fail
            $radio = $row.find('.sfa-qg-result[value="fail"]');
          }

          if ($radio.length && !$radio.prop('disabled')) {
            $radio.prop('checked', true).trigger('change');

            // Visual feedback: brief highlight
            $row.addClass('sfa-qg-swipe-feedback');
            setTimeout(function() {
              $row.removeClass('sfa-qg-swipe-feedback');
            }, 300);
          }
        }

        touchStartX = 0;
        touchStartY = 0;
      });

      $wrap.on('touchmove', '.sfa-qg-metric-row', function(e) {
        // Allow scrolling, but reset if significant movement
        var currentX = e.originalEvent.touches[0].clientX;
        var currentY = e.originalEvent.touches[0].clientY;
        var deltaX = Math.abs(currentX - touchStartX);
        var deltaY = Math.abs(currentY - touchStartY);

        // If vertical scroll is detected, cancel swipe
        if (deltaY > deltaX) {
          touchStartX = 0;
          touchStartY = 0;
        }
      });
    }

    // Batch controls for quick operations
    function addBatchControls() {
      var $batchBar = $(
        '<div class="sfa-qg-batch-controls">' +
          '<button type="button" class="qg-batch-btn qg-batch-all-pass">✓ All Pass</button>' +
          '<button type="button" class="qg-batch-btn qg-batch-all-clear">⟲ Clear All</button>' +
          '<span class="qg-batch-info"></span>' +
        '</div>'
      );

      $ui.prepend($batchBar);

      // All Pass handler
      $batchBar.find('.qg-batch-all-pass').on('click', function(e) {
        e.preventDefault();

        if (!confirm('Mark all metrics as PASS?')) return;

        $ui.find('.sfa-qg-item').each(function() {
          var $item = $(this);
          if ($item.find('.sfa-qg-readonly').length) return; // Skip read-only items

          $item.find('.sfa-qg-result[value="pass"]').each(function() {
            if (!$(this).prop('disabled')) {
              $(this).prop('checked', true).trigger('change');
            }
          });

          setCollapseState($item, false); // Collapse after marking pass
        });

        $(this).blur();
      });

      // Clear All handler
      $batchBar.find('.qg-batch-all-clear').on('click', function(e) {
        e.preventDefault();

        if (!confirm('Clear all selections?')) return;

        $ui.find('.sfa-qg-result:checked').each(function() {
          if (!$(this).prop('disabled')) {
            $(this).prop('checked', false);
            $(this).closest('.sfa-qg-radio').removeClass('is-checked is-pass is-fail');
          }
        });

        $ui.find('.sfa-qg-note').each(function() {
          if (!$(this).prop('disabled')) {
            $(this).val('');
          }
        });

        collectAndWrite();
        $(this).blur();
      });

      updateBatchInfo();
    }

    function updateBatchInfo() {
      var total = $ui.find('.sfa-qg-result').length / 2; // 2 radios per metric
      var checked = $ui.find('.sfa-qg-result:checked').length;
      var pct = total > 0 ? Math.round((checked / total) * 100) : 0;

      $ui.find('.qg-batch-info').text(checked + '/' + total + ' (' + pct + '%)');
    }

    function addExpandCollapseAll() {
      var $controls = $ui.find('.sfa-qg-batch-controls');
      if (!$controls.length) return;

      var $expandBtn = $(
        '<button type="button" class="qg-batch-btn qg-expand-all">▾ Expand</button>'
      );
      var $collapseBtn = $(
        '<button type="button" class="qg-batch-btn qg-collapse-all">▸ Collapse</button>'
      );

      // Insert after batch info
      $controls.find('.qg-batch-info').before($expandBtn).before($collapseBtn);

      $expandBtn.on('click', function(e) {
        e.preventDefault();
        $ui.find('.sfa-qg-item').each(function() {
          setCollapseState($(this), true); // true = expanded
        });
        $(this).blur();
      });

      $collapseBtn.on('click', function(e) {
        e.preventDefault();
        $ui.find('.sfa-qg-item').each(function() {
          setCollapseState($(this), false); // false = collapsed
        });
        $(this).blur();
      });
    }

    // Add batch controls and expand/collapse toggles
    if (showBatchControls) {
      addBatchControls();
      addExpandCollapseAll();
    }

    // Photo evidence handler
    $wrap.on('click', '.sfa-qg-photo-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      var i = parseInt($btn.data('i'), 10);
      var m = parseInt($btn.data('m'), 10);

      // Create hidden file input for iPad camera
      var $fileInput = $('<input type="file" accept="image/*" capture="environment" style="display:none;">');
      $('body').append($fileInput);

      $fileInput.on('change', function() {
        if (this.files && this.files[0]) {
          var file = this.files[0];
          var reader = new FileReader();

          reader.onload = function(ev) {
            var photoData = ev.target.result; // base64 data URL

            // Store photo in metric data
            if (!items[i]) items[i] = { name: '', metrics: [] };
            if (!items[i].metrics[m]) items[i].metrics[m] = { k: metricsDef[m].k, result: '', note: '' };
            items[i].metrics[m].photo = photoData;
            items[i].metrics[m].photoName = file.name;

            // Update button appearance
            $btn.addClass('has-photo');
            $btn.text('✓');

            qgDebug('[QG] Photo added', { item: i, metric: m, size: file.size });

            collectAndWrite();
          };

          reader.readAsDataURL(file);
        }

        // Remove temporary input
        $fileInput.remove();
      });

      // Trigger file picker
      $fileInput.click();
    });

    // listeners
    $wrap.off('change input', '.sfa-qg-result, .sfa-qg-note');

    // Radio change — toggle pills, QG-017 hint, expand on fail, save
    $wrap.on('change', '.sfa-qg-result', function(){
      var i = parseInt($(this).attr('data-i'),10);
      var m = parseInt($(this).attr('data-m'),10);
      var val = $(this).val();

      var $row  = $(this).closest('.sfa-qg-metric-row');
      var $item = $(this).closest('.sfa-qg-item');

      // pill highlight
      $row.find('.sfa-qg-radio').removeClass('is-checked is-pass is-fail');
      $(this).closest('.sfa-qg-radio').addClass('is-checked').addClass(val==='pass' ? 'is-pass' : 'is-fail');

      // QG-017: manage note required state + auto-expand on fail
      var $note = $item.find('.sfa-qg-note[data-i="'+i+'"][data-m="'+m+'"]');
      qgApplyNoteRequiredUI($note, !!cfg.requireNoteOnFail && val==='fail');
      if (val==='fail'){ setCollapseState($item, true); }

      // Show/hide photo button based on fail/pass selection
      var $photoBtn = $item.find('.sfa-qg-photo-btn[data-i="'+i+'"][data-m="'+m+'"]');
      if ($photoBtn.length) {
        if (val === 'fail') {
          $photoBtn.removeClass('hidden');
        } else {
          $photoBtn.addClass('hidden');
        }
      }

      collectAndWrite();
      updateBatchInfo();
    });

    // Note typing — clear/add hint instantly, then save
    $wrap.on('input', '.sfa-qg-note', function(){
      var $n = $(this);
      if (($n.val() || '').trim() === ''){
        if ($n.is('[required]')){
          $n.addClass('is-required-empty').attr('aria-invalid','true');
        }
      } else {
        $n.removeClass('is-required-empty').removeAttr('aria-invalid');
      }
      collectAndWrite();
    });

    // Ensure we capture fixedItems & metrics before submit as well
    var $form = $wrap.closest('form');
    if ($form.length){
      $form.off('submit.qg'+formId+'_'+fieldId).on('submit.qg'+formId+'_'+fieldId, function(){
        collectAndWrite();
        // Clear localStorage backup on submit (assuming successful save to server)
        setTimeout(clearLocalStorageBackup, 1000); // Delay to ensure submission completes
      });
    }

    // Initial compute
    collectAndWrite();

    // QG-110 — after first render, sync Fixed badges from any rework field (if present)
    qgSyncFixedBadgesIntoQC();
    // tiny defer ensures badges also appear if localized config lands a tick later
    setTimeout(qgSyncFixedBadgesIntoQC, 0);
  }

  function renderFromDataAttrs(){
    $('.sfa-qg-field').each(function(){
      var $wrap = $(this);
      var cfg = $wrap.data('config');
      if (!cfg) { try { cfg = JSON.parse($wrap.attr('data-config')||''); } catch(e){ cfg = null; } }
      if (cfg && cfg.formId && cfg.fieldId) renderField(cfg);
    });
  }

  // Re-render only the field whose items were lazy-loaded
  document.addEventListener('sfa-qg:items-loaded', function(ev){
    var d = ev.detail || {};
    var cfg = d.config || {};
    if (!cfg.formId || !cfg.fieldId) return;

    // Validate formId and fieldId
    var formId = parseInt(cfg.formId, 10);
    var fieldId = parseInt(cfg.fieldId, 10);
    if (isNaN(formId) || isNaN(fieldId)) {
      qgDebug('[QG] Invalid formId or fieldId in items-loaded event', cfg);
      return;
    }

    // Re-read data-config (it was updated by the lazy-loader) and render
    var sel = '.sfa-qg-field[data-form="' + formId + '"][data-field="' + fieldId + '"]';
    var $wrap = jQuery(sel);
    if (!$wrap.length) return;

    var freshCfg = $wrap.data('config');
    if (!freshCfg) {
      try { freshCfg = JSON.parse($wrap.attr('data-config') || '{}'); } catch (e) { freshCfg = null; }
    }
    if (freshCfg && freshCfg.formId && freshCfg.fieldId) {
      // Important: this re-run will now hit the QG-205 sort with real items
      renderField(freshCfg);
    }
  });

  /* QG-202 — Mark all fixed (works whether inputs were moved into the table or not) */
  $(document).on('click', '.qg-select-all-fixed', function (e) {
    e.preventDefault();

    var $help   = $(this).closest('.qg-rework-help');
    var $gfield = $help.closest('.gfield');
    if (!$gfield.length) return;

    // 1) Row-mounted checkboxes (moved by qgWireReworkField)
    var $rowChecks = $help.find('input.qg-row-check');

    // 2) Native GF checkboxes (if any still in the hidden container)
    var fieldId = String($help.data('field-id') || '');
    var $native = fieldId
      ? $gfield.find([
          'input[type=checkbox][name^="input_' + fieldId + '."]',
          'input[type=checkbox][name^="input_' + fieldId + '_"]',
          'input[type=checkbox][name="input_' + fieldId + '[]"]',
          'input[type=checkbox][id^="choice_' + fieldId + '_"]'
        ].join(','))
      : $gfield.find('.ginput_container_checkbox input[type="checkbox"]');

    var $all = $rowChecks.add($native);
    if (!$all.length) return;

    // Check everything and fire change so badges + QC headers refresh
    $all.each(function () {
      if (!this.checked) {
        this.checked = true;
        $(this).trigger('change');
      }
    });
  });

  // ---- Live "Fixed" chip in the rework table (reflects current ticks) ----
  ;(function ($) {
    function qgUpdateFixedBadges($gfield) {
      var checked = {};
      $gfield.find('input[type="checkbox"]:checked').each(function () {
        var v = $.trim(String($(this).val() || ''));
        if (v) checked[v] = 1;
      });

      $gfield.find('.qg-rework-help table tbody tr').each(function () {
        var $td = $(this).find('td').first();
        var name = $.trim($td.clone().children().remove().end().text());
        if (checked[name]) {
          if (!$td.find('.sfa-qg-badge.is-fixed').length) {
            $td.append(' <span class="sfa-qg-badge is-fixed">Fixed</span>');
          }
        } else {
          $td.find('.sfa-qg-badge.is-fixed').remove();
        }
      });
    }

    // Change handler for BOTH wrapper patterns
    $(document).on('change', '.qg-rework-field input[type="checkbox"], .gfield:has(.qg-rework-help) input[type="checkbox"]', function () {
      qgEachReworkGfield(function($g){ qgUpdateFixedBadges($g); });
      qgSyncFixedBadgesIntoQC(); // mirror into QC headers
    });

    // Init on render and DOM ready (both patterns)
    $(document).on('gform_post_render', function () {
      qgEachReworkGfield(function($g){ qgUpdateFixedBadges($g); });
      qgSyncFixedBadgesIntoQC();
    });
    $(function () {
      qgEachReworkGfield(function($g){ qgUpdateFixedBadges($g); });
      qgSyncFixedBadgesIntoQC();
    });
  })(jQuery);

  (function($){

    function qgUpdateRowBadge($row, isFixed){
      const $cell = $row.find('td:first');

      // Remove only our fixed-chip(s), not other badges
      $cell.find('.sfa-qg-badge.is-fixed').remove();

      if (isFixed) {
        // Re-add a single, styled chip
        $cell.append(' <span class="sfa-qg-badge is-fixed">Fixed</span>');
      }
    }

    function qgWireReworkField($help, formId){
      const fieldId = String($help.data('field-id'));
      if(!fieldId) return;

      // find the GF field wrapper
      let $field = $('#field_' + formId + '_' + fieldId);
      if(!$field.length){
        $field = $help.closest('.gfield'); // fallback in admin
      }

      // build map value -> row
      const rowByVal = {};
      $help.find('table tbody tr').each(function(){
        const $row = $(this);
        const $slot = $row.find('.qg-row-slot');
        if($slot.length){
          rowByVal[$slot.data('value')] = $row;
        }else{
          // fallback: text without children
          const txt = $.trim($row.find('td:first').clone().children().remove().end().text());
          rowByVal[txt] = $row;
        }
      });

      // Move the native GF inputs into the matching row slot (preferred) or first cell
      $field.find('.ginput_container_checkbox input[type=checkbox]').each(function(){
        var $inp = $(this);
        var val  = String($inp.val() || '');
        var $row = rowByVal[val];
        if (!$row) return;

        var $slot   = $row.find('.qg-row-slot');
        var $target = $slot.length ? $slot : $row.find('td:first');

        // avoid double-inserting
        if ($target.find('input.qg-row-check').length) return;

        // hide original label
        var id = $inp.attr('id');
        if (id) $field.find('label[for="' + id + '"]').hide();

        $inp.addClass('qg-row-check');
        $slot.length ? $inp.appendTo($target) : $inp.prependTo($target); // <= slot = append, no slot = prepend
        qgUpdateRowBadge($row, $inp.is(':checked'));
      });

      // Hide the original checkbox list now that inputs are moved
      $field.find('.ginput_container_checkbox').hide();
    }

    // handle changes & "Mark all fixed"
    $(document).on('change', '.qg-row-check', function(){
      qgUpdateRowBadge($(this).closest('tr'), this.checked);
    });

    // run after GF renders
    $(document).on('gform_post_render', function(e, formId){
      $('.qg-rework-help').each(function(){ qgWireReworkField($(this), formId); });
    });

    // fallback for admin entry screens where gform_post_render may not fire
    $(function(){
      var _id = $('form[id^="gform_"]').first().attr('id') || '';
var formIdGuess = _id.replace(/\D/g,'') || '';
      $('.qg-rework-help').each(function(){ qgWireReworkField($(this), formIdGuess); });
    });

  })(jQuery);

  $(document).on('gform_post_render', function(){
    if (window.SFA_QG_CFG && SFA_QG_CFG.fields) { SFA_QG_CFG.fields.forEach(renderField); }
    renderFromDataAttrs();
  });

  $(function(){ renderFromDataAttrs(); });
})(jQuery);

// --------- Lazy-load items in Inbox/Entry (Upload->items) ----------
;(function(){
  function getParam(n){ try{ return new URLSearchParams(window.location.search).get(n); }catch(e){ return null; } }
  async function fetchItems(cfg){
    if (!window.SFA_QG_AJAX||!SFA_QG_AJAX.url||!SFA_QG_AJAX.nonce) return null;
    var eid = getParam('lid') || getParam('entry_id');
    if (!eid) return null;
    var fd = new FormData();
    fd.append('action','sfa_qg_items');
    fd.append('nonce', SFA_QG_AJAX.nonce);
    fd.append('eid', eid);
    fd.append('sourceId', cfg.sourceId||0);
    try{
      var r = await fetch(SFA_QG_AJAX.url, {method:'POST', credentials:'same-origin', body:fd});
      var j = await r.json();
      if (j && j.success && j.data && Array.isArray(j.data.items)) return j.data.items;
    }catch(e){
      qgDebug('[QG] Failed to fetch items', e);
    }
    return null;
  }

  // QG-106 (client-side): block submit if not all failed items are ticked
  document.addEventListener('submit', function(e){
    var form = e.target;
    if (!form || !form.querySelector) return;

    // Find the rework block we injected (table lives just above the checkbox field)
    var help = form.querySelector('.qg-rework-help');
    if (!help) return; // not the rework step

    // The checkbox field is the nearest .gfield after the help block
    var gfield = help.closest('.gfield') || help.parentElement;
    if (!gfield) return;

    var checks = gfield.querySelectorAll('input[type="checkbox"]');
    if (!checks.length) return;

    var total   = checks.length;
    var checked = 0;
    checks.forEach(function(cb){ if (cb.checked) checked++; });

    if (checked < total) {
      e.preventDefault();
      alert('Please tick all fixed items before submitting.');
      // scroll to the field for convenience
      gfield.scrollIntoView({behavior:'smooth', block:'center'});
    }
  }, true);

  async function boot(){
    var nodes = document.querySelectorAll('.sfa-qg-field');
    for (var i=0;i<nodes.length;i++){
      var el = nodes[i];
      var cfg = {};
      try{ cfg = JSON.parse(el.getAttribute('data-config')||'{}'); }catch(e){ cfg={}; }
      if (cfg && (!cfg.items || !cfg.items.length) && cfg.sourceId){
        var items = await fetchItems(cfg);
        if (items && items.length){
          cfg.items = items;
          el.setAttribute('data-config', JSON.stringify(cfg));
          var ev = new CustomEvent('sfa-qg:items-loaded', {detail:{items:items, config:cfg}});
          el.dispatchEvent(ev);
        }
      }
    }
  }
  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
