// Switch the device form between SNMP and Prometheus fields, and between v1/v2c and v3 fields.
document.querySelectorAll('[data-snmpver]').forEach(function (sel) {
  var form = sel.form;
  var type = form.querySelector('[data-type]');
  var port = form.querySelector('[data-port]');
  var interval = form.querySelector('[data-interval]');
  var isNew = !form.querySelector('input[name=id]').value;
  function sync(changed) {
    var prom = type && type.value === 'prom';
    var v3 = !prom && sel.value === '3';
    form.querySelectorAll('[data-prom]').forEach(function (e) { e.hidden = !prom; });
    form.querySelectorAll('[data-snmp]').forEach(function (e) { e.hidden = prom; });
    form.querySelectorAll('[data-v3]').forEach(function (e) { e.hidden = !v3; });
    form.querySelectorAll('[data-v12]').forEach(function (e) { e.hidden = prom || v3; });
    if (changed && isNew) { // swap untouched defaults when the type changes
      if (port && (port.value === '161' || port.value === '80')) { port.value = prom ? '80' : '161'; }
      if (interval && (interval.value === '60' || interval.value === '300')) { interval.value = prom ? '300' : '60'; }
    }
  }
  sel.addEventListener('change', function () { sync(false); });
  if (type) { type.addEventListener('change', function () { sync(true); }); }
  sync(false);
});
