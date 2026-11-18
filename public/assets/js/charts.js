// uPlot time-series charts: <div class="chart" data-metrics="1,2" data-range="24h" data-height="220">
(function () {
  var charts = [];
  var PALETTE = ['--s1', '--s2', '--s3', '--s4', '--s5', '--s6'];

  function savedRange() { try { return localStorage.getItem('tw-range'); } catch (e) { return null; } }

  function axisFmt(unit) {
    return function (u, vals) { return vals.map(function (v) { return v == null ? '' : TW.fmt(v, unit === '%' ? '%' : unit || '').replace(' ', ''); }); };
  }

  function opts(el, data) {
    var unit = (data.series[0] && data.series[0].unit) || '';
    var grid = TW.css('--grid'), text = TW.css('--muted');
    var ax = { stroke: text, grid: { stroke: grid, width: 1 }, ticks: { stroke: grid, width: 1 }, font: '11px ' + TW.css('--font') };
    var series = [{}];
    data.series.forEach(function (s, i) {
      var c = TW.css(PALETTE[i % PALETTE.length]);
      series.push({
        label: s.label, stroke: c, width: 1.6, spanGaps: false,
        fill: i === 0 && data.series.length <= 2 ? c + '1f' : undefined,
        value: function (u, v) { return v == null ? '—' : TW.fmt(v, s.unit || ''); },
        points: { show: false }
      });
    });
    return {
      width: el.clientWidth || 300,
      height: +el.dataset.height || el.clientHeight || 220,
      series: series,
      cursor: { points: { size: 6 }, drag: { x: true, y: false } },
      legend: { show: el.dataset.legend !== '0' },
      scales: { x: { time: true }, y: { range: function (u, min, max) { return [Math.min(0, min), unit === '%' ? Math.max(100, max) : (max > 0 ? max * 1.08 : 1)]; } } },
      axes: [Object.assign({}, ax), Object.assign({ size: 66, values: axisFmt(unit) }, ax)]
    };
  }

  function load(c) {
    var url = (c.el.dataset.api || 'api.php') + '?a=series&m=' + c.el.dataset.metrics + '&range=' + c.el.dataset.range;
    return TW.get(url).then(function (data) {
      var t = data.t || [], old = c.data && c.data.t;
      var newest = t.length ? t[t.length - 1] : 0;
      // skip the redraw when nothing changed since the last fetch (keeps zoom/cursor)
      if (old && old.length === t.length && old.length && old[old.length - 1] === newest && c.range === c.el.dataset.range) return;
      c.data = data; c.range = c.el.dataset.range; c.newest = newest;
      render(c);
    }).catch(function () { c.el.innerHTML = '<div class="empty">Could not load data</div>'; });
  }

  function render(c) {
    var data = c.data;
    if (c.u) { c.u.destroy(); c.u = null; }
    c.el.innerHTML = '';
    if (!data || !data.t.length) { c.el.innerHTML = '<div class="empty">No data yet</div>'; return; }
    var arr = [data.t].concat(data.series.map(function (s) { return s.data; }));
    c.u = new uPlot(opts(c.el, data), arr, c.el);
    fit(c);
  }

  // In fixed-height containers (dashboard panels) shrink the plot so the legend fits.
  function fit(c) {
    if (!c.el.dataset.fit || !c.u) return;
    var avail = c.el.clientHeight, used = c.u.root.offsetHeight;
    if (used > avail) c.u.setSize({ width: c.el.clientWidth, height: Math.max(60, c.u.height - (used - avail)) });
  }

  function init(el) {
    if (el._tw) return el._tw;
    if (!el.dataset.range) el.dataset.range = savedRange() || '24h';
    var c = { el: el };
    el._tw = c;
    charts.push(c);
    load(c);
    if (window.ResizeObserver) {
      var last = 0;
      new ResizeObserver(function () {
        var w = el.clientWidth;
        if (c.u && Math.abs(w - last) > 2) { last = w; c.u.setSize({ width: w, height: +el.dataset.height || el.clientHeight || 220 }); fit(c); }
      }).observe(el);
    }
    return c;
  }

  document.addEventListener('tw:theme', function () { charts.forEach(render); });
  // Refresh every 20 s; the poller writes once a minute, so most fetches are cheap no-ops.
  setInterval(function () { if (!document.hidden) charts.forEach(function (c) { if (c.el.isConnected) load(c); }); }, 20000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) charts.forEach(function (c) { if (c.el.isConnected) load(c); }); });

  // "updated Ns ago" ticker: <span data-chart-age></span> shows the age of the newest sample on the page
  setInterval(function () {
    var els = document.querySelectorAll('[data-chart-age]');
    if (!els.length) return;
    var n = 0;
    charts.forEach(function (c) { if (c.newest > n) n = c.newest; });
    var txt = '';
    if (n) { var a = Math.max(0, Math.round(Date.now() / 1000 - n)); txt = 'updated ' + (a < 90 ? a + 's' : Math.round(a / 60) + 'm') + ' ago'; }
    els.forEach(function (e) { e.textContent = txt; });
  }, 1000);

  // Global range picker: <div class="seg" data-range-picker> <button data-range="1h">…
  document.querySelectorAll('[data-range-picker]').forEach(function (seg) {
    var cur = savedRange() || '24h';
    seg.querySelectorAll('button').forEach(function (b) {
      b.classList.toggle('active', b.dataset.range === cur);
      b.addEventListener('click', function () {
        seg.querySelectorAll('button').forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active');
        try { localStorage.setItem('tw-range', b.dataset.range); } catch (e) {}
        charts.forEach(function (c) { if (!c.el.dataset.fixedRange) { c.el.dataset.range = b.dataset.range; load(c); } });
      });
    });
  });

  window.TW = window.TW || {};
  TW.chart = init;
  TW.chartReload = function (el) { if (el._tw) load(el._tw); };
  document.querySelectorAll('.chart[data-metrics]').forEach(init);
})();
