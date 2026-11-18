// SVG arc gauges: <div data-gauge data-value data-max data-unit data-warn data-crit>
(function () {
  var NS = 'http://www.w3.org/2000/svg';
  var SWEEP = 240, R = 40, CX = 50, CY = 50;
  function polar(a) { var r = (a - 90) * Math.PI / 180; return [CX + R * Math.cos(r), CY + R * Math.sin(r)]; }
  var p0 = polar(-SWEEP / 2), p1 = polar(SWEEP / 2);
  var ARC = 'M' + p0[0] + ' ' + p0[1] + ' A' + R + ' ' + R + ' 0 1 1 ' + p1[0] + ' ' + p1[1];
  var LEN = 2 * Math.PI * R * SWEEP / 360;

  function el(tag, attrs) {
    var e = document.createElementNS(NS, tag);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    return e;
  }
  function num(v) { return v === '' || v == null || isNaN(+v) ? null : +v; }

  function build(g) {
    var svg = el('svg', { viewBox: '0 0 100 84' });
    svg.appendChild(el('path', { d: ARC, class: 'track' }));
    var bar = el('path', { d: ARC, class: 'bar', 'stroke-dasharray': LEN, 'stroke-dashoffset': LEN });
    svg.appendChild(bar);
    var val = el('text', { x: 50, y: 54, class: 'val' });
    var unit = el('text', { x: 50, y: 68, class: 'unit' });
    svg.appendChild(val); svg.appendChild(unit);
    g.innerHTML = ''; g.appendChild(svg);
    g._tw = { bar: bar, val: val, unit: unit };
  }

  function set(g, value, opts) {
    if (!g._tw) build(g);
    opts = opts || {};
    var max = num(opts.max != null ? opts.max : g.dataset.max) || 100;
    var unitTxt = opts.unit != null ? opts.unit : (g.dataset.unit || '');
    var warn = num(opts.warn != null ? opts.warn : g.dataset.warn);
    var crit = num(opts.crit != null ? opts.crit : g.dataset.crit);
    var v = num(value);
    var frac = v == null ? 0 : Math.max(0, Math.min(1, v / max));
    g._tw.bar.setAttribute('stroke-dashoffset', LEN * (1 - frac));
    if (v == null) { g._tw.val.textContent = '—'; g._tw.unit.textContent = ''; }
    else if (unitTxt === '%') { g._tw.val.textContent = (v >= 10 ? Math.round(v) : v.toFixed(1)) + '%'; g._tw.unit.textContent = ''; }
    else {
      var s = TW.fmt(v, unitTxt).split(' ');
      g._tw.val.textContent = s[0]; g._tw.unit.textContent = s.slice(1).join(' ');
    }
    var low = warn != null && crit != null && warn > crit;
    var hit = function (t) { return t != null && v != null && (low ? v <= t : v >= t); };
    g.classList.toggle('is-crit', hit(crit));
    g.classList.toggle('is-warn', !hit(crit) && hit(warn));
  }

  window.TW = window.TW || {};
  TW.gauge = set;
  document.querySelectorAll('[data-gauge]').forEach(function (g) { set(g, g.dataset.value); });
})();
