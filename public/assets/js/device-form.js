// Show v1/v2c or v3 fields depending on the selected SNMP version.
document.querySelectorAll('[data-snmpver]').forEach(function (sel) {
  var form = sel.form;
  function sync() {
    var v3 = sel.value === '3';
    form.querySelectorAll('[data-v3]').forEach(function (e) { e.hidden = !v3; });
    form.querySelectorAll('[data-v12]').forEach(function (e) { e.hidden = v3; });
  }
  sel.addEventListener('change', sync);
  sync();
});
