<?php
require_once __DIR__ . '/device_form.php';

page_start('Add device', 'devices');
?>
<div class="page-head"><div><h1>Add device</h1><div class="sub">SNMP or Prometheus /metrics source</div></div>
  <div class="actions"><a class="btn btn-ghost btn-sm" href="app.php?p=devices">Back to devices</a></div></div>
<?= flash() ?>
<?php device_form(); ?>
<?php
page_end();
