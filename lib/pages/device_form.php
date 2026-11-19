<?php
/** Shared add/edit device form; posts to the devices page. */
function device_form(?array $d = null): void
{
    $d = $d ?? ['id' => '', 'name' => '', 'host' => '', 'port' => 161, 'version' => '2c', 'community' => 'public', 'v3_user' => '', 'v3_level' => 'authPriv',
        'v3_auth_proto' => 'SHA', 'v3_auth_pass' => '', 'v3_priv_proto' => 'AES', 'v3_priv_pass' => '', 'poll_interval' => 60, 'enabled' => 1, 'public' => 0, 'icon' => null, 'sysdescr' => null, 'type' => 'snmp', 'scheme' => 'http', 'path' => '/metrics'];
    $d += ['type' => 'snmp', 'scheme' => 'http', 'path' => '/metrics'];
    $sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
    ?>
<form method="post" action="app.php?p=devices" class="card" data-busy>
  <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= e($d['id']) ?>">
  <header class="card-head"><hgroup><h2><?= $d['id'] ? 'Device settings' : 'Add device' ?></h2><p>Saving polls the device once, so it can take a few seconds.</p></hgroup></header>
  <div class="form-grid">
    <div class="field"><label>Type
      <select name="type" data-type <?= $d['id'] ? 'disabled' : '' ?>>
      <option value="snmp" <?= $sel($d['type'], 'snmp') ?>>SNMP</option><option value="prom" <?= $sel($d['type'], 'prom') ?>>Prometheus /metrics</option></select>
      <?php if ($d['id']): ?><small>Fixed once the device is added.</small><?php endif ?></label>
      <?php if ($d['id']): ?><input type="hidden" name="type" value="<?= e($d['type']) ?>"><?php endif ?></div>
    <div class="field"><label>Name
      <input type="text" name="name" value="<?= e($d['name']) ?>" placeholder="core-switch">
      <small>Optional. Left empty, it is taken from the device (sysName), else the host.</small></label></div>
    <div class="field"><label>Host / IP
      <input type="text" name="host" required value="<?= e($d['host']) ?>" placeholder="192.168.1.1" class="mono"></label></div>
    <div class="field"><label>Port
      <input type="number" name="port" data-port value="<?= e($d['port']) ?>"></label></div>
    <div class="field" data-prom><label>Scheme
      <select name="scheme"><option value="http" <?= $sel($d['scheme'], 'http') ?>>http</option><option value="https" <?= $sel($d['scheme'], 'https') ?>>https</option></select></label></div>
    <div class="field" data-prom><label>Path
      <input type="text" name="path" value="<?= e($d['path']) ?>" placeholder="/metrics" class="mono"></label></div>
    <div class="field" data-snmp><label>SNMP version
      <select name="version" data-snmpver>
      <option value="1" <?= $sel($d['version'], '1') ?>>v1</option><option value="2c" <?= $sel($d['version'], '2c') ?>>v2c</option><option value="3" <?= $sel($d['version'], '3') ?>>v3</option></select></label></div>
    <div class="field" data-snmp data-v12><label>Community
      <input type="text" name="community" value="<?= e($d['community']) ?>" placeholder="public" autocomplete="off"></label></div>
    <div class="field"><label>Icon
      <select name="icon">
      <option value=""><?= $d['id'] ? 'Auto (' . e(DEVICE_ICONS[device_icon_name($d)]) . ')' : 'Auto' ?></option>
      <?php foreach (DEVICE_ICONS as $k => $l): ?><option value="<?= $k ?>" <?= $sel($d['icon'] ?? '', $k) ?>><?= e($l) ?></option><?php endforeach ?></select></label></div>
    <div class="field"><label>Poll interval (seconds)
      <input type="number" name="poll_interval" data-interval min="60" step="60" value="<?= e($d['poll_interval']) ?>">
      <small>At least 60; the poller runs once a minute.</small></label></div>
  </div>
  <div class="form-grid" data-snmp data-v3>
    <div class="field"><label>User
      <input type="text" name="v3_user" value="<?= e($d['v3_user']) ?>" autocomplete="off"></label></div>
    <div class="field"><label>Security level
      <select name="v3_level">
      <?php foreach (['noAuthNoPriv', 'authNoPriv', 'authPriv'] as $l): ?><option <?= $sel($d['v3_level'], $l) ?>><?= $l ?></option><?php endforeach ?></select></label></div>
    <div class="field"><label>Auth protocol
      <select name="v3_auth_proto">
      <?php foreach (['SHA', 'MD5', 'SHA-256', 'SHA-512'] as $l): ?><option <?= $sel($d['v3_auth_proto'], $l) ?>><?= $l ?></option><?php endforeach ?></select></label></div>
    <div class="field"><label>Auth passphrase
      <input type="password" name="v3_auth_pass" value="" placeholder="<?= $d['v3_auth_pass'] ? 'unchanged' : '' ?>" autocomplete="new-password">
      <?php if ($d['v3_auth_pass']): ?><small>Left empty, the stored one is kept.</small><?php endif ?></label></div>
    <div class="field"><label>Privacy protocol
      <select name="v3_priv_proto">
      <?php foreach (['AES', 'DES', 'AES-192', 'AES-256'] as $l): ?><option <?= $sel($d['v3_priv_proto'], $l) ?>><?= $l ?></option><?php endforeach ?></select></label></div>
    <div class="field"><label>Privacy passphrase
      <input type="password" name="v3_priv_pass" value="" placeholder="<?= $d['v3_priv_pass'] ? 'unchanged' : '' ?>" autocomplete="new-password">
      <?php if ($d['v3_priv_pass']): ?><small>Left empty, the stored one is kept.</small><?php endif ?></label></div>
  </div>
  <div class="checks">
    <label class="check"><input type="checkbox" name="enabled" value="1" <?= $d['enabled'] ? 'checked' : '' ?>> Enabled</label>
    <label class="check"><input type="checkbox" name="public" value="1" <?= $d['public'] ? 'checked' : '' ?>> Show on public status page</label>
    <?php if (!$d['id']): ?><label class="check"><input type="checkbox" name="discover" value="1" checked> Discover metrics now</label><?php endif ?>
  </div>
  <footer>
    <?php if ($d['id']): ?><button class="btn btn-ghost btn-danger" form="deldev">Delete device</button><?php endif ?>
    <button class="btn"><?= $d['id'] ? 'Save' : 'Add device' ?></button>
  </footer>
</form>
<?php if ($d['id']): ?>
<form id="deldev" method="post" action="app.php?p=devices" data-confirm="Delete this device, its metrics and all history?"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= e($d['id']) ?>"></form>
<?php endif ?>
<script src="assets/js/device-form.js"></script>
<?php
}
