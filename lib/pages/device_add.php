<?php
require_once __DIR__ . '/device_form.php';

page_start('Add device', 'devices');
?>
<div class="page-head"><hgroup><h1>Add device</h1><p>An SNMP agent or a Prometheus /metrics endpoint.</p></hgroup>
  <div class="actions"><a class="btn btn-ghost btn-sm" href="app.php?p=devices">Back to devices</a></div></div>
<?= flash() ?>
<?php device_form(); ?>
<?php
page_end();
