<?php
/** Shared add/edit device form; posts to the devices page. */
function device_form(?array $d = null): void
{
    $d = $d ?? ['id' => '', 'name' => '', 'host' => '', 'port' => 161, 'version' => '2c', 'community' => 'public', 'v3_user' => '', 'v3_level' => 'authPriv',
        'v3_auth_proto' => 'SHA', 'v3_auth_pass' => '', 'v3_priv_proto' => 'AES', 'v3_priv_pass' => '', 'poll_interval' => 60, 'enabled' => 1, 'public' => 0];
    $sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
    ?>
<form method="post" action="app.php?p=devices" class="card">
  <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= e($d['id']) ?>">
  <h3><?= $d['id'] ? 'Device settings' : 'Add device' ?></h3>
  <div class="form-grid">
    <div class="field"><label>Name</label><input type="text" name="name" required value="<?= e($d['name']) ?>" placeholder="core-switch"></div>
    <div class="field"><label>Host / IP</label><input type="text" name="host" required value="<?= e($d['host']) ?>" placeholder="192.168.1.1"></div>
    <div class="field"><label>Port</label><input type="number" name="port" value="<?= e($d['port']) ?>"></div>
    <div class="field"><label>SNMP version</label><select name="version" data-snmpver>
      <option value="1" <?= $sel($d['version'], '1') ?>>v1</option><option value="2c" <?= $sel($d['version'], '2c') ?>>v2c</option><option value="3" <?= $sel($d['version'], '3') ?>>v3</option></select></div>
    <div class="field" data-v12><label>Community</label><input type="text" name="community" value="<?= e($d['community']) ?>" autocomplete="off"></div>
    <div class="field"><label>Poll interval (s)</label><input type="number" name="poll_interval" min="60" step="60" value="<?= e($d['poll_interval']) ?>"></div>
  </div>
  <div class="form-grid" data-v3>
    <div class="field"><label>v3 user</label><input type="text" name="v3_user" value="<?= e($d['v3_user']) ?>" autocomplete="off"></div>
    <div class="field"><label>Security level</label><select name="v3_level">
      <?php foreach (['noAuthNoPriv', 'authNoPriv', 'authPriv'] as $l): ?><option <?= $sel($d['v3_level'], $l) ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Auth protocol</label><select name="v3_auth_proto">
      <?php foreach (['SHA', 'MD5', 'SHA-256', 'SHA-512'] as $l): ?><option <?= $sel($d['v3_auth_proto'], $l) ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Auth passphrase</label><input type="password" name="v3_auth_pass" value="" placeholder="<?= $d['v3_auth_pass'] ? '(unchanged)' : '' ?>" autocomplete="new-password"></div>
    <div class="field"><label>Privacy protocol</label><select name="v3_priv_proto">
      <?php foreach (['AES', 'DES', 'AES-192', 'AES-256'] as $l): ?><option <?= $sel($d['v3_priv_proto'], $l) ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Privacy passphrase</label><input type="password" name="v3_priv_pass" value="" placeholder="<?= $d['v3_priv_pass'] ? '(unchanged)' : '' ?>" autocomplete="new-password"></div>
  </div>
  <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px">
    <label class="check"><input type="checkbox" name="enabled" value="1" <?= $d['enabled'] ? 'checked' : '' ?>> Enabled</label>
    <label class="check"><input type="checkbox" name="public" value="1" <?= $d['public'] ? 'checked' : '' ?>> Show on public status page</label>
    <?php if (!$d['id']): ?><label class="check"><input type="checkbox" name="discover" value="1" checked> Discover metrics now</label><?php endif ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn"><?= $d['id'] ? 'Save' : 'Add device' ?></button>
    <?php if ($d['id']): ?><button class="btn btn-danger" form="deldev" style="margin-left:auto">Delete device</button><?php endif ?>
  </div>
</form>
<?php if ($d['id']): ?>
<form id="deldev" method="post" action="app.php?p=devices" data-confirm="Delete this device, its metrics and all history?"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= e($d['id']) ?>"></form>
<?php endif ?>
<script src="assets/js/device-form.js"></script>
<?php
}
