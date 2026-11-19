// Dashboard renderer + editor (vanilla JS, HTML5 drag and drop).
// Editable dashboards (data-editable) save every change at once, with Undo; public ones only render.
(function () {
  var root = document.querySelector('[data-dash]');
  if (!root) return;
  var api = root.dataset.api || 'api.php';
  var editable = !!root.dataset.editable;
  var layout = JSON.parse(document.getElementById('dash-layout').textContent || '[]');
  var metricsCache = null, timers = [], history = [];

  function h(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }

  // ---- render ----
  function render() {
    timers.forEach(clearInterval); timers = [];
    root.innerHTML = '';
    root.classList.toggle('editing', editable);
    if (!layout.length) {
      root.appendChild(h('p', 'empty', editable ? 'Empty dashboard. Use <b>Add panel</b> or <b>Add from device</b>.' : 'This dashboard is empty.'));
    }
    layout.forEach(function (p, i) { root.appendChild(panel(p, i)); });
  }

  function panel(p, i) {
    var el = h('div', 'card panel p-' + p.type);
    el.style.gridColumn = 'span ' + (p.w || 6);
    el.style.gridRow = 'span ' + (p.h || 3);
    var title = h('div', 'p-title', '<span>' + esc(p.title || '') + '</span>');
    if (editable) title.appendChild(tools(p, i));
    el.appendChild(title);
    var body = h('div', 'p-body');
    el.appendChild(body);

    if (p.type === 'graph') {
      var c = h('div', 'chart');
      c.dataset.metrics = (p.metrics || []).join(',');
      c.dataset.range = p.range || '24h';
      c.dataset.fixedRange = '1';
      c.dataset.fit = '1';
      c.dataset.api = api;
      body.appendChild(c);
      if (p.metrics && p.metrics.length) requestAnimationFrame(function () { TW.chart(c); });
      else c.innerHTML = '<div class="empty">No metrics selected</div>';
    } else if (p.type === 'gauge' || p.type === 'stat') {
      body.classList.add('is-center');
      var holder = h('div', p.type === 'gauge' ? 'gauge' : 'big', '—');
      body.appendChild(holder);
      var sub = h('div', 'small muted'); body.appendChild(sub);
      var upd = function () { updateValue(p, holder, sub); };
      upd(); timers.push(setInterval(upd, 20000));
    } else if (p.type === 'status') {
      var list = h('div', 'status-list');
      body.appendChild(list);
      var st = function () {
        TW.get(api + '?a=status').then(function (r) {
          list.innerHTML = r.devices.map(function (d) {
            return '<div><span class="dot dot-' + esc(d.status) + '"></span>' + esc(d.name) + '<span class="pill pill-' + esc(d.status) + '">' + esc(d.status) + '</span></div>';
          }).join('') || '<p class="empty">No devices.</p>';
        });
      };
      st(); timers.push(setInterval(st, 20000));
    }
    if (editable) dragAndDrop(el, i);
    return el;
  }

  function updateValue(p, holder, sub) {
    if (!p.metrics || !p.metrics.length) { holder.textContent = '—'; sub.textContent = 'No metric selected'; return; }
    TW.get(api + '?a=last&m=' + p.metrics.join(',')).then(function (r) {
      var meta = r.meta[p.metrics[0]];
      var v = r.values[p.metrics[0]];
      var unit = meta ? meta.unit : (p.type === 'gauge' ? '%' : '');
      if (p.type === 'gauge') {
        TW.gauge(holder, v, { max: p.max || 100, unit: unit || '', warn: meta ? meta.warn : null, crit: meta ? meta.crit : null });
      } else {
        holder.textContent = v == null ? '—' : (unit === 'pages' ? Math.round(v).toLocaleString() : TW.fmt(v, unit || ''));
        sub.textContent = p.metrics.length > 1 ? p.metrics.slice(1).map(function (id) { return TW.fmt(r.values[id], unit || ''); }).join(' · ') : '';
      }
    }).catch(function () { holder.textContent = '—'; });
  }

  if (!editable) { render(); return; }

  // ---- saving: every change at once, with an undo stack ----
  var state = document.querySelector('[data-save-state]');
  var undoBtn = document.querySelector('[data-undo]');
  var saveTimer = null, saving = false;
  function setState(t) { if (state) state.textContent = t; }
  function change(fn) {
    history.push(JSON.stringify(layout));
    if (history.length > 50) history.shift();
    fn();
    render();
    save();
  }
  function save() {
    if (undoBtn) undoBtn.disabled = !history.length;
    setState('Saving…');
    saving = true;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      TW.post(api + '?a=dashboard_save', { id: +root.dataset.id, layout: layout })
        .then(function () { saving = false; setState('Saved'); })
        .catch(function () { setState('Not saved: check the connection, then change something to retry'); });
    }, 400);
  }
  if (undoBtn) undoBtn.onclick = function () {
    if (!history.length) return;
    layout = JSON.parse(history.pop());
    render();
    save();
  };

  // ---- panel tools: edit, size, remove ----
  var WIDTHS = [3, 6, 9, 12], HEIGHTS = [2, 3, 5];
  function step(list, cur, dir) { // the next preset up or down from the current value
    var c = cur || list[1], i;
    if (dir > 0) { for (i = 0; i < list.length; i++) if (list[i] > c) return list[i]; return list[list.length - 1]; }
    for (i = list.length - 1; i >= 0; i--) if (list[i] < c) return list[i];
    return list[0];
  }
  function tools(p, i) {
    var t = h('div', 'p-tools');
    [['✎', 'Edit', function () { openDialog(i); }],
     ['−', 'Narrower', function () { change(function () { p.w = step(WIDTHS, p.w, -1); }); }],
     ['+', 'Wider', function () { change(function () { p.w = step(WIDTHS, p.w, 1); }); }],
     ['↑', 'Shorter', function () { change(function () { p.h = step(HEIGHTS, p.h, -1); }); }],
     ['↓', 'Taller', function () { change(function () { p.h = step(HEIGHTS, p.h, 1); }); }],
     ['✕', 'Remove', function () { change(function () { layout.splice(i, 1); }); }]
    ].forEach(function (d) {
      var b = h('button', null, d[0]); b.type = 'button'; b.title = d[1]; b.setAttribute('aria-label', d[1]);
      b.onclick = d[2]; t.appendChild(b);
    });
    return t;
  }

  function dragAndDrop(el, i) {
    el.draggable = true;
    el.addEventListener('dragstart', function (e) { el.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(i)); });
    el.addEventListener('dragend', function () { el.classList.remove('dragging'); });
    el.addEventListener('dragover', function (e) { e.preventDefault(); el.classList.add('drop-target'); });
    el.addEventListener('dragleave', function () { el.classList.remove('drop-target'); });
    el.addEventListener('drop', function (e) {
      e.preventDefault();
      el.classList.remove('drop-target');
      var from = +e.dataTransfer.getData('text/plain');
      if (from === i || isNaN(from)) return;
      change(function () { layout.splice(i, 0, layout.splice(from, 1)[0]); });
    });
  }

  // ---- add / edit dialog: device, then tick metrics; type and size are guessed ----
  var dlg = document.getElementById('panel-dlg'), form = document.getElementById('panel-form');
  var f = form.elements, devSel = form.querySelector('[data-device]'), filter = form.querySelector('[data-filter]');
  var list = form.querySelector('[data-list]'), picked = form.querySelector('[data-picked]');
  var sdlg = document.getElementById('starter-dlg'), starterSel = sdlg.querySelector('[data-starter-device]');
  var editIdx = -1, chosen = [], typeTouched = false, sizeTouched = false, keepW = 6, keepH = 3;

  function loadMetrics() {
    if (metricsCache) return Promise.resolve(metricsCache);
    return TW.get(api + '?a=metrics').then(function (r) {
      metricsCache = r.metrics;
      var seen = {};
      metricsCache.forEach(function (m) {
        if (seen[m.device_id]) return;
        seen[m.device_id] = 1;
        devSel.appendChild(new Option(m.device, m.device_id));
        starterSel.appendChild(new Option(m.device, m.device_id));
      });
      return metricsCache;
    }).catch(function () { metricsCache = []; return metricsCache; });
  }
  function byId(id) { return (metricsCache || []).filter(function (m) { return m.id === id; })[0]; }
  function single() { return f.type.value === 'gauge' || f.type.value === 'stat'; }

  function fillList() {
    var dev = devSel.value, q = filter.value.trim().toLowerCase(), groups = {}, order = [];
    list.innerHTML = '';
    (metricsCache || []).forEach(function (m) {
      var keep = chosen.indexOf(m.id) >= 0; // ticked metrics stay visible whatever the filter
      if (!keep && dev && String(m.device_id) !== dev) return;
      if (!keep && q && (m.label + ' ' + m.kind + ' ' + m.device).toLowerCase().indexOf(q) < 0) return;
      var key = (dev && String(m.device_id) === dev ? '' : m.device + ' · ') + m.kind;
      if (!groups[key]) { groups[key] = []; order.push(key); }
      groups[key].push(m);
    });
    if (!order.length) list.appendChild(h('p', 'empty', 'No metrics match.'));
    order.forEach(function (key) {
      list.appendChild(h('div', 'metric-group', esc(key)));
      groups[key].forEach(function (m) {
        var lab = h('label', 'check');
        var box = h('input'); box.type = single() ? 'radio' : 'checkbox'; box.name = 'pick'; box.value = m.id;
        box.checked = chosen.indexOf(m.id) >= 0;
        box.addEventListener('change', function () { pick(m.id, box.checked); });
        lab.appendChild(box);
        lab.appendChild(document.createTextNode(m.label + (m.unit ? ' (' + m.unit + ')' : '')));
        list.appendChild(lab);
      });
    });
    showPicked();
  }
  function pick(id, on) {
    if (single()) chosen = on ? [id] : [];
    else if (on) { if (chosen.indexOf(id) < 0 && chosen.length < 12) chosen.push(id); }
    else chosen = chosen.filter(function (x) { return x !== id; });
    if (!typeTouched) guessType();
    var top = list.scrollTop;
    fillList();
    list.scrollTop = top;
  }
  function showPicked() {
    if (f.type.value === 'status') { picked.textContent = 'The status panel lists every device; no metric needed.'; return; }
    if (!chosen.length) { picked.textContent = 'Nothing selected.'; return; }
    picked.textContent = chosen.length + ' selected: ' + chosen.map(function (id) { var m = byId(id); return m ? m.device + ' · ' + m.label : id; }).join(', ') +
      (!single() && chosen.length >= 12 ? ' (the most a graph takes)' : '');
  }
  // one percentage → gauge; one counter of pages → stat; anything else → graph
  function guessType() {
    var t = 'graph';
    if (chosen.length === 1) { var m = byId(chosen[0]); t = m && m.unit === '%' ? 'gauge' : (m && m.kind === 'pages' ? 'stat' : 'graph'); }
    setType(t);
  }
  function setType(t) {
    var r = form.querySelector('input[name=type][value="' + t + '"]');
    if (r) r.checked = true;
    if (single() && chosen.length > 1) chosen = chosen.slice(0, 1);
    form.querySelector('[data-for=graph]').hidden = t !== 'graph';
    form.querySelector('[data-for=gauge]').hidden = t !== 'gauge';
    form.querySelector('[data-for=metrics]').hidden = t === 'status';
    if (!sizeTouched) setSize(t === 'graph' ? 6 : 3, t === 'graph' ? 3 : 2);
  }
  function setSize(w, hh) {
    keepW = w; keepH = hh;
    Array.prototype.forEach.call(form.querySelectorAll('input[name=w]'), function (r) { r.checked = +r.value === w; });
    Array.prototype.forEach.call(form.querySelectorAll('input[name=h]'), function (r) { r.checked = +r.value === hh; });
  }
  function radio(name) { var r = form.querySelector('input[name=' + name + ']:checked'); return r ? r.value : ''; }

  Array.prototype.forEach.call(form.querySelectorAll('input[name=type]'), function (r) {
    r.addEventListener('change', function () { typeTouched = true; setType(r.value); fillList(); });
  });
  Array.prototype.forEach.call(form.querySelectorAll('input[name=w], input[name=h]'), function (r) {
    r.addEventListener('change', function () { sizeTouched = true; });
  });
  devSel.addEventListener('change', fillList);
  filter.addEventListener('input', fillList);
  filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });

  function openDialog(i) {
    editIdx = i;
    var p = i >= 0 ? layout[i] : { type: 'graph', title: '', range: '24h', metrics: [] };
    loadMetrics().then(function () {
      chosen = (p.metrics || []).slice();
      typeTouched = sizeTouched = i >= 0;
      form.querySelector('[data-dlg-title]').textContent = i >= 0 ? 'Edit panel' : 'Add panel';
      form.querySelector('[data-ok]').textContent = i >= 0 ? 'Save panel' : 'Add panel';
      f.title.value = p.title || '';
      f.range.value = p.range || '24h';
      f.max.value = p.max != null ? p.max : '';
      filter.value = '';
      var first = chosen.length ? byId(chosen[0]) : null;
      devSel.value = first ? String(first.device_id) : devSel.value;
      setType(p.type || 'graph');
      if (i >= 0) setSize(p.w || 6, p.h || 3);
      fillList();
      dlg.showModal();
      (devSel.value ? filter : devSel).focus();
    });
  }
  dlg.addEventListener('close', function () {
    if (dlg.returnValue !== 'ok') return;
    var type = radio('type') || 'graph';
    var p = {
      type: type, title: f.title.value.trim(),
      w: +radio('w') || keepW, h: +radio('h') || keepH, // a size from before the presets is kept
      range: f.range.value, metrics: type === 'status' ? [] : chosen.slice(),
      max: f.max.value === '' ? null : +f.max.value
    };
    if (!p.title && p.metrics.length) {
      var m = byId(p.metrics[0]);
      var oneDev = m && p.metrics.every(function (id) { var x = byId(id); return x && x.device_id === m.device_id; });
      if (m) p.title = p.metrics.length > 1 ? (oneDev ? m.device + ' · ' + m.kind : 'Graph') : m.device + ' · ' + m.label;
    }
    if (!p.title && type === 'status') p.title = 'Devices';
    change(function () { if (editIdx >= 0) layout[editIdx] = p; else layout.push(p); });
  });

  // ---- add a device's starter panels ----
  sdlg.addEventListener('close', function () {
    if (sdlg.returnValue !== 'ok' || !starterSel.value) return;
    TW.get(api + '?a=starter&d=' + encodeURIComponent(starterSel.value)).then(function (r) {
      if (!r.panels.length) { setState('That device has nothing to add'); return; }
      change(function () { layout = layout.concat(r.panels); });
    }).catch(function () { setState('Could not load the device panels'); });
  });

  document.querySelector('[data-add]').onclick = function () { openDialog(-1); };
  document.querySelector('[data-starter]').onclick = function () { loadMetrics().then(function () { sdlg.showModal(); }); };
  window.addEventListener('beforeunload', function (e) { if (saving) { e.preventDefault(); e.returnValue = ''; } });

  render();
  if (root.dataset.startAdd === '1') openDialog(-1);
})();
