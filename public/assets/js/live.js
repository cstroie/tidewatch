// Live mode: direct SNMP reads streamed over SSE (live.php) or polled (api.php?a=live).
// <div id="live" data-device="3" data-metrics="1,2,3">
(function () {
  var root = document.getElementById('live');
  if (!root || !window.uPlot) return;
  var dev = root.dataset.device, mids = root.dataset.metrics;
  var WINDOW = 300, IDLE_STOP = 15 * 60 * 1000;
  var PALETTE = ['--s1', '--s2', '--s3', '--s4', '--s5', '--s6'];
  var $ = function (s) { return root.querySelector(s); };
  var statusEl = $('[data-live-status]'), grid = $('[data-live-grid]'), btn = $('[data-live-toggle]');
  var pref = function (k, d) { try { return localStorage.getItem(k) || d; } catch (e) { return d; } };
  var mode = pref('tw-live-mode', 'stream'), interval = +pref('tw-live-int', '2');
  var running = false, es = null, pollTimer = null, watchdog = null, startedAt = 0, state = {};
  var groups = [], byId = {}, lastT = 0, ticker = null;

  function setStatus(txt, cls) { statusEl.textContent = txt; statusEl.className = 'live-status ' + (cls || ''); }

  function build(meta) {
    if (groups.length) return;
    var map = {};
    meta.forEach(function (m) {
      var key, title;
      if (m.kind === 'if_in' || m.kind === 'if_out') { title = m.label.replace(/ (in|out)$/, ''); key = 'if:' + title; }
      else if (m.kind === 'load') { key = 'load'; title = 'Load average'; }
      else { key = 'sys'; title = 'CPU & memory'; }
      if (!map[key]) { map[key] = { title: title, unit: m.unit, metrics: [] }; groups.push(map[key]); }
      map[key].metrics.push(m);
    });
    grid.innerHTML = '';
    groups.forEach(function (g) {
      var card = document.createElement('div');
      card.className = 'card';
      card.innerHTML = '<header class="card-head"><hgroup><h2></h2></hgroup><span class="live-now"></span></header><div class="chart"></div>';
      card.querySelector('h2').textContent = g.title;
      g.now = card.querySelector('.live-now');
      g.el = card.querySelector('.chart');
      g.data = [[]].concat(g.metrics.map(function () { return []; }));
      g.metrics.forEach(function (m, i) { byId[m.id] = { g: g, i: i + 1, m: m }; });
      grid.appendChild(card);
    });
    if (!groups.length) grid.innerHTML = '<p class="empty">No CPU, memory or interface metrics to show live.</p>';
    groups.forEach(draw);
  }

  function latest(u) {
    var n = u.data[0].length;
    if (n && u.cursor.idx == null) u.setLegend({ idx: n - 1 }, false);
  }

  function opts(g) {
    var grid = TW.css('--grid'), text = TW.css('--muted');
    var ax = { stroke: text, grid: { stroke: grid, width: 1 }, ticks: { stroke: grid, width: 1 }, font: '11px ' + TW.css('--font') };
    var unit = g.unit || '';
    return {
      width: g.el.clientWidth || 300, height: 200,
      series: [{}].concat(g.metrics.map(function (m, i) {
        var c = TW.css(PALETTE[i % PALETTE.length]);
        return { label: m.label, stroke: c, width: 1.6, spanGaps: true, fill: g.metrics.length <= 2 ? c + '1f' : undefined, points: { show: false },
                 value: function (u, v) { return v == null ? '—' : TW.fmt(v, m.unit || ''); } };
      })),
      cursor: { points: { size: 6 } },
      // Legend shows the latest sample while the cursor is off the plot
      hooks: { ready: [latest], setData: [latest], setCursor: [latest] },
      scales: { x: { time: true, range: function (u, min, max) { return [max - WINDOW, max]; } }, y: { range: function (u, min, max) { return [Math.min(0, min || 0), unit === '%' ? Math.max(100, max || 0) : (max > 0 ? max * 1.15 : 1)]; } } },
      axes: [Object.assign({}, ax), Object.assign({ size: 66, values: function (u, vals) { return vals.map(function (v) { return v == null ? '' : TW.fmt(v, unit).replace(' ', ''); }); } }, ax)]
    };
  }

  function draw(g) {
    if (g.u) g.u.destroy();
    g.el.innerHTML = '';
    g.u = new uPlot(opts(g), g.data, g.el);
  }

  function add(s) {
    lastT = s.t;
    groups.forEach(function (g) { g.data[0].push(s.t); g.metrics.forEach(function (m, i) { g.data[i + 1].push(null); }); });
    Object.keys(s.v || {}).forEach(function (id) {
      var r = byId[id]; if (!r) return;
      var col = r.g.data[r.i]; col[col.length - 1] = s.v[id];
    });
    groups.forEach(function (g) {
      while (g.data[0].length && g.data[0][0] < s.t - WINDOW) g.data.forEach(function (c) { c.shift(); });
      g.u.setData(g.data);
      var n = g.data[0].length - 1, cpu = [], parts = [];
      g.metrics.forEach(function (m, i) {
        var v = g.data[i + 1][n];
        if (v == null) return;
        if (m.kind === 'cpu') cpu.push(v); else parts.push((g.metrics.length > 2 ? m.label + ' ' : '') + TW.fmt(v, m.unit || ''));
      });
      if (cpu.length) parts.unshift('CPU ' + TW.fmt(cpu.reduce(function (a, b) { return a + b; }, 0) / cpu.length, '%'));
      if (parts.length) g.now.textContent = parts.join(' · ');
    });
  }

  function gap(t) { // device did not answer: break the lines
    groups.forEach(function (g) { g.data[0].push(t); g.metrics.forEach(function (m, i) { g.data[i + 1].push(null); }); g.u.setData(g.data); });
  }

  // ---- stream (SSE) ----
  function startStream() {
    es = new EventSource('live.php?d=' + dev + '&m=' + mids + '&i=' + interval);
    var got = false;
    clearTimeout(watchdog);
    watchdog = setTimeout(function () { if (!got) fallback('stream not delivering (server buffers responses?)'); }, interval * 1000 + 6000);
    es.addEventListener('meta', function (e) { got = true; build(JSON.parse(e.data).metrics); setStatus('Streaming', 'on'); });
    es.addEventListener('sample', function (e) { got = true; add(JSON.parse(e.data)); });
    es.addEventListener('nodata', function (e) { got = true; gap(JSON.parse(e.data).t); setStatus('Streaming · device not answering', 'warn'); });
    es.addEventListener('busy', function () { fallback('all live streams in use'); });
    es.onerror = function () { if (running) setStatus('Reconnecting…', 'warn'); };
  }
  function fallback(why) {
    stopTransport();
    mode = 'poll'; syncSeg();
    setStatus('Polling (' + why + ')', 'on');
    startPoll();
  }

  // ---- poll (fetch) ----
  function startPoll() {
    var tick = function () {
      if (!running) return;
      var t0 = Date.now();
      var next = function () { if (running) pollTimer = setTimeout(tick, Math.max(200, interval * 1000 - (Date.now() - t0))); };
      if (document.hidden) { state = {}; return next(); }
      TW.post('api.php?a=live', { d: +dev, m: mids.split(',').map(Number), state: state }).then(function (r) {
        if (r.meta) build(r.meta);
        state = r.state || {};
        if (r.sample) { add(r.sample); setStatus('Polling', 'on'); } else { gap(Date.now() / 1000); setStatus('Polling · device not answering', 'warn'); }
        next();
      }).catch(function () { setStatus('Polling · request failed', 'warn'); next(); });
    };
    tick();
  }

  function stopTransport() {
    if (es) { es.close(); es = null; }
    clearTimeout(pollTimer); clearTimeout(watchdog);
    state = {};
  }
  function start() {
    running = true; startedAt = Date.now();
    btn.textContent = 'Pause';
    setStatus('Connecting…');
    if (mode === 'stream' && window.EventSource) startStream(); else startPoll();
  }
  function stop(msg) {
    running = false; stopTransport();
    btn.textContent = 'Resume';
    setStatus(msg || 'Paused', '');
  }
  function restart() { if (running) { stopTransport(); start(); } }

  function syncSeg() {
    root.querySelectorAll('[data-live-mode]').forEach(function (b) { b.classList.toggle('active', b.dataset.liveMode === mode); });
    root.querySelectorAll('[data-live-int]').forEach(function (b) { b.classList.toggle('active', +b.dataset.liveInt === interval); });
  }
  root.querySelectorAll('[data-live-mode]').forEach(function (b) {
    b.addEventListener('click', function () { mode = b.dataset.liveMode; try { localStorage.setItem('tw-live-mode', mode); } catch (e) {} syncSeg(); restart(); });
  });
  root.querySelectorAll('[data-live-int]').forEach(function (b) {
    b.addEventListener('click', function () { interval = +b.dataset.liveInt; try { localStorage.setItem('tw-live-int', interval); } catch (e) {} syncSeg(); restart(); });
  });
  btn.addEventListener('click', function () { running ? stop() : start(); });

  ticker = setInterval(function () {
    if (running && Date.now() - startedAt > IDLE_STOP) stop('Paused after 15 minutes. Resume to keep watching.');
  }, 5000);
  document.addEventListener('tw:theme', function () { groups.forEach(draw); });
  if (window.ResizeObserver) new ResizeObserver(function () { groups.forEach(function (g) { if (g.u) g.u.setSize({ width: g.el.clientWidth, height: 200 }); }); }).observe(grid);
  window.addEventListener('pagehide', stopTransport);

  syncSeg();
  start();
})();
