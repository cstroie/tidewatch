// Dashboard renderer + editor (vanilla JS, HTML5 drag and drop).
(function () {
  var root = document.querySelector('[data-dash]');
  if (!root) return;
  var api = root.dataset.api || 'api.php';
  var layout = JSON.parse(document.getElementById('dash-layout').textContent || '[]');
  var saved = JSON.stringify(layout);
  var editing = false, metricsCache = null, timers = [];

  function h(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }

  function render() {
    timers.forEach(clearInterval); timers = [];
    root.innerHTML = '';
    root.classList.toggle('editing', editing);
    if (!layout.length) {
      root.appendChild(h('div', 'card empty-state', editing ? 'Empty dashboard. Click <b>+ Panel</b> to add one.' : 'This dashboard is empty.'));
      root.firstChild.style.gridColumn = 'span 12';
    }
    layout.forEach(function (p, i) { root.appendChild(panel(p, i)); });
  }

  function panel(p, i) {
    var el = h('div', 'card panel p-' + p.type);
    el.style.gridColumn = 'span ' + (p.w || 6);
    el.style.gridRow = 'span ' + (p.h || 3);
    var title = h('div', 'p-title', '<span>' + esc(p.title || '') + '</span>');
    var tools = h('div', 'p-tools');
    [['✎', 'Edit', function () { openDialog(i); }], ['−', 'Narrower', function () { p.w = Math.max(1, (p.w || 6) - 1); render(); }],
     ['+', 'Wider', function () { p.w = Math.min(12, (p.w || 6) + 1); render(); }], ['↑', 'Shorter', function () { p.h = Math.max(1, (p.h || 3) - 1); render(); }],
     ['↓', 'Taller', function () { p.h = Math.min(8, (p.h || 3) + 1); render(); }], ['✕', 'Remove', function () { layout.splice(i, 1); render(); }]
    ].forEach(function (t) { var b = h('button', null, t[0]); b.type = 'button'; b.title = t[1]; b.onclick = t[2]; tools.appendChild(b); });
    title.appendChild(tools);
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
      body.style.display = 'grid'; body.style.placeItems = 'center';
      var holder = h('div', p.type === 'gauge' ? 'gauge' : 'big', '—');
      if (p.type === 'gauge') holder.style.width = '100%';
      body.appendChild(holder);
      var sub = h('div', 'small muted'); body.appendChild(sub);
      var upd = function () { updateValue(p, holder, sub); };
      upd(); timers.push(setInterval(upd, 20000));
    } else if (p.type === 'status') {
      var list = h('div', null); list.style.overflow = 'auto'; list.style.height = '100%';
      body.appendChild(list);
      var st = function () {
        TW.get(api + '?a=status').then(function (r) {
          list.innerHTML = r.devices.map(function (d) {
            return '<div style="display:flex;align-items:center;gap:8px;padding:4px 0"><span class="dot dot-' + esc(d.status) + '"></span>' + esc(d.name) + '<span class="pill pill-' + esc(d.status) + '" style="margin-left:auto">' + esc(d.status) + '</span></div>';
          }).join('') || '<div class="muted small">No devices</div>';
        });
      };
      st(); timers.push(setInterval(st, 20000));
    }

    if (editing) {
      el.draggable = true;
      el.addEventListener('dragstart', function (e) { el.classList.add('dragging'); e.dataTransfer.setData('text/plain', String(i)); });
      el.addEventListener('dragend', function () { el.classList.remove('dragging'); });
      el.addEventListener('dragover', function (e) { e.preventDefault(); el.classList.add('drop-target'); });
      el.addEventListener('dragleave', function () { el.classList.remove('drop-target'); });
      el.addEventListener('drop', function (e) {
        e.preventDefault();
        var from = +e.dataTransfer.getData('text/plain');
        if (from === i || isNaN(from)) return;
        var moved = layout.splice(from, 1)[0];
        layout.splice(i, 0, moved);
        render();
      });
    }
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

  // ---- editor ----
  var dlg = document.getElementById('panel-dlg'), form = document.getElementById('panel-form'), editIdx = -1;
  function loadMetrics() {
    if (metricsCache || !root.dataset.editable) return Promise.resolve(metricsCache);
    return TW.get(api + '?a=metrics').then(function (r) { metricsCache = r.metrics; return metricsCache; }).catch(function () { metricsCache = []; });
  }
  function fillMetrics(selected, filter) {
    var sel = form.elements.metrics; sel.innerHTML = '';
    var groups = {};
    (metricsCache || []).forEach(function (m) {
      if (filter && (m.device + ' ' + m.label + ' ' + m.kind).toLowerCase().indexOf(filter) < 0 && selected.indexOf(m.id) < 0) return;
      var g = groups[m.device] || (groups[m.device] = document.createElement('optgroup'));
      g.label = m.device;
      var o = new Option(m.label + (m.unit ? ' (' + m.unit + ')' : ''), m.id);
      o.selected = selected.indexOf(m.id) >= 0;
      g.appendChild(o);
    });
    Object.keys(groups).forEach(function (k) { sel.appendChild(groups[k]); });
  }
  function syncType() {
    var t = form.elements.type.value;
    form.querySelector('[data-for=graph]').hidden = t !== 'graph';
    form.querySelector('[data-for=gauge]').hidden = t !== 'gauge';
    form.querySelector('[data-for=metrics]').hidden = t === 'status';
  }
  function openDialog(i) {
    editIdx = i;
    var p = i >= 0 ? layout[i] : { type: 'graph', title: '', w: 6, h: 3, range: '24h', metrics: [] };
    loadMetrics().then(function () {
      ['type', 'title', 'w', 'h', 'range'].forEach(function (k) { form.elements[k].value = p[k] != null ? p[k] : ''; });
      form.elements.max.value = p.max != null ? p.max : '';
      form.querySelector('[data-filter]').value = '';
      fillMetrics(p.metrics || [], '');
      syncType();
      dlg.showModal();
    });
  }
  if (form) {
    form.elements.type.addEventListener('change', function () {
      syncType();
      var t = form.elements.type.value;
      if (t === 'gauge' || t === 'stat') { form.elements.w.value = 3; form.elements.h.value = 3; }
      if (t === 'graph') { form.elements.w.value = 6; form.elements.h.value = 4; }
    });
    form.querySelector('[data-filter]').addEventListener('input', function (e) {
      var cur = Array.prototype.map.call(form.elements.metrics.selectedOptions, function (o) { return +o.value; });
      fillMetrics(cur, e.target.value.toLowerCase());
    });
    dlg.addEventListener('close', function () {
      if (dlg.returnValue !== 'ok') return;
      var f = form.elements;
      var ids = Array.prototype.map.call(f.metrics.selectedOptions, function (o) { return +o.value; });
      var p = { type: f.type.value, title: f.title.value.trim(), w: +f.w.value || 6, h: +f.h.value || 3, range: f.range.value, metrics: ids, max: f.max.value === '' ? null : +f.max.value };
      if (!p.title && ids.length) {
        var m = metricsCache.filter(function (x) { return x.id === ids[0]; })[0];
        if (m) p.title = ids.length > 1 ? m.device : m.device + ' · ' + m.label;
      }
      if (editIdx >= 0) layout[editIdx] = p; else layout.push(p);
      render();
    });
  }

  function setEditing(on) {
    editing = on;
    document.querySelectorAll('[data-edit-only]').forEach(function (e) { e.hidden = !on; });
    var eb = document.querySelector('[data-edit]'); if (eb) eb.hidden = on;
    if (on) loadMetrics();
    render();
  }
  var q = function (s) { return document.querySelector(s); };
  if (q('[data-edit]')) {
    q('[data-edit]').onclick = function () { setEditing(true); };
    q('[data-cancel]').onclick = function () { layout = JSON.parse(saved); setEditing(false); };
    q('[data-add]').onclick = function () { openDialog(-1); };
    q('[data-save]').onclick = function () {
      TW.post(api + '?a=dashboard_save', { id: +root.dataset.id, layout: layout }).then(function () {
        saved = JSON.stringify(layout); setEditing(false);
      }).catch(function (e) { alert('Save failed: ' + e.message); });
    };
  }
  window.addEventListener('beforeunload', function (e) { if (editing && JSON.stringify(layout) !== saved) { e.preventDefault(); e.returnValue = ''; } });

  loadMetrics().then(function () { setEditing(root.dataset.startEdit === '1'); });
})();
