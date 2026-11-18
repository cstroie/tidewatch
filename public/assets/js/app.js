// Shared UI: theme toggle, nav drawer, helpers.
(function () {
  var root = document.documentElement;
  function current() {
    var t = root.getAttribute('data-theme');
    if (t) return t;
    return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var next = current() === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('tw-theme', next); } catch (e) {}
      document.dispatchEvent(new CustomEvent('tw:theme'));
    });
  });
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
    if (!root.getAttribute('data-theme')) document.dispatchEvent(new CustomEvent('tw:theme'));
  });
  document.querySelectorAll('[data-nav-toggle]').forEach(function (b) {
    b.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  });
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault(); });
  });
})();

window.TW = {
  css: function (name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); },
  csrf: function () { var m = document.querySelector('meta[name=csrf]'); return m ? m.content : ''; },
  fmt: function (v, unit) {
    if (v == null || isNaN(v)) return '—';
    var a = Math.abs(v), s = '';
    if (unit === 'bps' || unit === 'pps' || unit === 'pages' || unit === '') {
      if (a >= 1e9) { v /= 1e9; s = 'G'; } else if (a >= 1e6) { v /= 1e6; s = 'M'; } else if (a >= 1e3) { v /= 1e3; s = 'k'; }
    }
    a = Math.abs(v);
    var d = a >= 100 ? 0 : a >= 10 ? 1 : 2;
    return v.toFixed(d).replace(/\.0+$/, '') + (s || unit ? ' ' + s + (unit || '') : '');
  },
  get: function (url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error(r.status);
      return r.json();
    });
  },
  post: function (url, data) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': TW.csrf() },
      body: JSON.stringify(data)
    }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
  }
};
