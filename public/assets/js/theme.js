// Runs in <head> to apply the saved theme before first paint.
(function () {
  try {
    var t = localStorage.getItem('tw-theme');
    if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
