<?php
// FPP Live Follow — plugin web page
$DAEMON = 'http://localhost:5001';

// Fetch current status & config
$status = @json_decode(file_get_contents("$DAEMON/api/status"), true) ?? [];
$cfg    = @json_decode(file_get_contents("$DAEMON/api/config"),  true) ?? [];

$tracking     = $status['tracking']     ?? false;
$face         = $status['face_detected'] ?? false;
$cam_running  = $status['cam_running']  ?? true;   // default true; false = camera taken by another plugin
$trigger_mode = $cfg['trigger_mode']    ?? 'always_on';
$hw_type      = $cfg['hardware_type']   ?? 'mock';

// Build servo channel list from FPP's co-other.json (same source as servo calibrator)
$servo_ports = [];   // [['value'=>port_idx, 'label'=>'Port 0 — Pan', 'out'=>out_idx], ...]
$co_other_path = '/home/fpp/media/config/co-other.json';
if (file_exists($co_other_path)) {
    $co = @json_decode(file_get_contents($co_other_path), true) ?? [];
    foreach ($co['channelOutputs'] ?? [] as $out_idx => $out) {
        if (empty($out['ports'])) continue;
        $prefix = count($co['channelOutputs']) > 1 ? "Out$out_idx · " : '';
        foreach ($out['ports'] as $port_idx => $port) {
            $desc  = trim($port['description'] ?? '');
            $label = $prefix . "Port $port_idx" . ($desc !== '' ? " — $desc" : '');
            $servo_ports[] = ['value' => $port_idx, 'label' => $label, 'out' => $out_idx];
        }
    }
}
?>

<style>
.af-card      { background:#16213e; border-radius:8px; padding:18px; margin-bottom:16px; }
.af-card h3   { color:#4cc9f0; margin:0 0 12px; font-size:13px; letter-spacing:1px; text-transform:uppercase; }
.af-row       { display:flex; align-items:center; gap:12px; margin-bottom:8px; }
.af-label     { color:#888; font-size:12px; width:130px; flex-shrink:0; }
.af-value     { color:#e0e0e0; font-size:13px; font-family:monospace; }
.af-badge     { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:bold; }
.badge-on     { background:#06d6a0; color:#000; }
.badge-off    { background:#444; color:#aaa; }
.badge-face   { background:#f72585; color:#fff; }
.af-btn       { padding:8px 20px; border:none; border-radius:5px; font-weight:bold; cursor:pointer; font-size:13px; }
.btn-start    { background:#4cc9f0; color:#000; }
.btn-stop     { background:#e63946; color:#fff; }
.btn-save     { background:#06d6a0; color:#000; }
.af-select    { background:#0f3460; color:#e0e0e0; border:1px solid #4cc9f0; border-radius:4px; padding:5px 10px; font-size:13px; }
.af-input     { background:#0f3460; color:#e0e0e0; border:1px solid #555; border-radius:4px; padding:5px 8px; width:70px; font-size:13px; }
.af-stream    { border:2px solid #0f3460; border-radius:6px; max-width:100%; }
#status-msg   { color:#06d6a0; font-size:12px; margin-top:6px; min-height:18px; }
</style>

<div style="max-width:960px;">

<!-- Status bar -->
<div class="af-card">
  <h3>Status</h3>
  <div class="af-row">
    <span class="af-label">Tracking</span>
    <span class="af-badge <?= $tracking ? 'badge-on' : 'badge-off' ?>" id="badge-tracking">
      <?= $tracking ? 'ACTIVE' : 'STOPPED' ?>
    </span>
    <button class="af-btn btn-start" onclick="sendCmd('/api/start')"  style="<?= $tracking ? 'display:none' : '' ?>" id="btn-start">▶ Start</button>
    <button class="af-btn btn-stop"  onclick="sendCmd('/api/stop')"   style="<?= $tracking ? '' : 'display:none' ?>" id="btn-stop">■ Stop</button>
  </div>
  <div class="af-row">
    <span class="af-label">Face</span>
    <span class="af-badge <?= $face ? 'badge-face' : 'badge-off' ?>" id="badge-face">
      <?= $face ? 'DETECTED' : 'NOT DETECTED' ?>
    </span>
  </div>
  <div class="af-row">
    <span class="af-label">Pan</span>   <span class="af-value" id="val-pan"><?= number_format($status['pan'] ?? 90, 1) ?>°</span>
    <span class="af-label">Tilt</span>  <span class="af-value" id="val-tilt"><?= number_format($status['tilt'] ?? 90, 1) ?>°</span>
  </div>
  <div class="af-row">
    <span class="af-label">Trigger Mode</span>
    <span class="af-value" id="val-mode"><?= htmlspecialchars($trigger_mode) ?></span>
  </div>
  <div id="status-msg"></div>
</div>

<!-- Camera unavailable card -->
<div class="af-card" id="cam-ownership-card" style="<?= $cam_running ? 'display:none' : '' ?>">
  <h3>Camera Unavailable</h3>
  <p style="color:#888; font-size:12px; margin-bottom:12px;">
    The camera may be held by the Performance Capture plugin.
    Click <strong>Claim Camera</strong> to release it and resume tracking.
  </p>
  <div style="display:flex; gap:10px; align-items:center;">
    <button class="af-btn btn-start" onclick="claimCamera()">▶ Claim Camera</button>
    <span id="cam-claim-msg" style="font-size:12px;"></span>
  </div>
</div>

<!-- Camera feed -->
<div class="af-card" id="cam-feed-card">
  <h3>Camera Feed</h3>
  <img src="/fpp-live-follow-api/stream"
       class="af-stream" id="cam-stream"
       onerror="onCamError()">
  <div id="cam-error" style="display:none; color:#e63946; font-size:13px; padding:8px 0;">
    Camera stream unavailable.
  </div>
</div>

<!-- Trigger Mode config -->
<div class="af-card">
  <h3>Trigger Mode</h3>
  <div class="af-row">
    <span class="af-label">Mode</span>
    <select class="af-select" id="cfg-trigger">
      <?php
      $modes = [
        'always_on'     => 'Always On — tracking runs whenever FPP is running',
        'show_active'   => 'Show Active — activates when a sequence plays',
        'command'       => 'FPP Command — controlled via playlist commands',
        'motion_sensor' => 'Motion Sensor — GPIO pin triggers tracking',
      ];
      foreach ($modes as $val => $label):
        $sel = ($trigger_mode === $val) ? 'selected' : '';
      ?>
        <option value="<?= $val ?>" <?= $sel ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="af-row" id="row-motion" style="<?= $trigger_mode !== 'motion_sensor' ? 'display:none' : '' ?>">
    <span class="af-label">GPIO Pin (BCM)</span>
    <input class="af-input" id="cfg-motion-pin"    type="number" value="<?= (int)($cfg['motion_sensor_pin'] ?? 7) ?>">
    <span class="af-label" style="width:auto; margin-left:16px;">Auto-off (sec)</span>
    <input class="af-input" id="cfg-motion-timeout" type="number" value="<?= (int)($cfg['motion_timeout_sec'] ?? 30) ?>">
  </div>
  <div style="margin-top:8px;">
    <button class="af-btn btn-save" onclick="saveConfig()">Save &amp; Apply</button>
  </div>
</div>

<!-- Servo & Tracking config -->
<div class="af-card">
  <h3>Servo &amp; Tracking</h3>
  <div class="af-row" style="margin-bottom:12px;">
    <span class="af-label">Track Mode</span>
    <select class="af-select" id="cfg-tracking_mode">
      <?php
      $track_modes = [
        'face'        => 'Face — track detected face',
        'body'        => 'Body — track full body (nose position)',
        'face_or_body'=> 'Face or Body — face first, fall back to body',
      ];
      $cur_mode = $cfg['tracking_mode'] ?? 'face';
      foreach ($track_modes as $val => $label):
        $sel = ($cur_mode === $val) ? 'selected' : '';
      ?>
        <option value="<?= $val ?>" <?= $sel ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <table style="border-collapse:collapse; font-size:13px; color:#e0e0e0;">
    <tr style="color:#888; font-size:11px;">
      <td style="padding:4px 12px 4px 0;"></td>
      <td style="padding:4px 12px;">Pan</td>
      <td style="padding:4px 12px;">Tilt</td>
    </tr>
    <?php
    $servo_fields = [
      ['Min Angle',     'servo_%s_min',    'number', '0.5'],
      ['Max Angle',     'servo_%s_max',    'number', '0.5'],
      ['Center',        'servo_%s_center', 'number', '0.5'],
      ['Speed (°/sec)', 'servo_%s_speed',  'number', '1'],
    ];
    foreach ($servo_fields as [$label, $key_pat, $type, $step]):
    ?>
    <tr>
      <td style="color:#888; padding:4px 12px 4px 0;"><?= $label ?></td>
      <?php foreach (['pan', 'tilt'] as $s): ?>
      <td style="padding:4px 12px;">
        <input class="af-input" id="cfg-<?= sprintf($key_pat, $s) ?>"
               type="<?= $type ?>" step="<?= $step ?>"
               value="<?= $cfg[sprintf($key_pat, $s)] ?? '' ?>">
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    <tr>
      <td style="color:#888; padding:4px 12px 4px 0;">Invert Direction</td>
      <?php foreach (['pan', 'tilt'] as $s): ?>
      <td style="padding:4px 12px;">
        <input type="checkbox" id="cfg-servo_<?= $s ?>_invert"
               <?= !empty($cfg["servo_{$s}_invert"]) ? 'checked' : '' ?>
               style="width:18px; height:18px; accent-color:#4cc9f0; cursor:pointer;">
      </td>
      <?php endforeach; ?>
    </tr>
    <tr>
      <td style="color:#888; padding:4px 12px 4px 0;">Face Smoothing</td>
      <td colspan="2" style="padding:4px 12px;">
        <input class="af-input" id="cfg-face_smoothing" type="number" step="0.05" min="0.05" max="1"
               value="<?= $cfg['face_smoothing'] ?? 0.25 ?>">
        <span style="color:#888; font-size:11px; margin-left:8px;">0.05 = smoothest, 1.0 = raw</span>
      </td>
    </tr>
    <tr>
      <td style="color:#888; padding:4px 12px 4px 0;">Deadzone (px)</td>
      <td colspan="2" style="padding:4px 12px;">
        <input class="af-input" id="cfg-deadzone_px" type="number"
               value="<?= $cfg['deadzone_px'] ?? 25 ?>">
      </td>
    </tr>
  </table>
  <div style="margin-top:12px;">
    <button class="af-btn btn-save" onclick="saveConfig()">Save &amp; Apply</button>
  </div>
</div>

<!-- Hardware -->
<div class="af-card">
  <h3>Hardware</h3>
  <div class="af-row">
    <span class="af-label">Backend Type</span>
    <select class="af-select" id="cfg-hardware_type">
      <?php foreach (['smbus2','pca9685','gpio','serial','mock'] as $t): ?>
        <option value="<?= $t ?>" <?= ($hw_type === $t) ? 'selected' : '' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="af-row" id="row-i2c">
    <span class="af-label">I2C Address</span>
    <input class="af-input" id="cfg-pca9685_address" style="width:90px"
           value="<?= htmlspecialchars($cfg['pca9685_address'] ?? '0x40') ?>">
    <span class="af-label" style="width:auto; margin-left:16px;">I2C Bus</span>
    <input class="af-input" id="cfg-pca9685_i2c_bus" type="number" style="width:60px"
           value="<?= (int)($cfg['pca9685_i2c_bus'] ?? 1) ?>">
  </div>
  <div class="af-row" id="row-freq" style="<?= in_array($hw_type, ['smbus2','gpio','serial','mock']) ? 'display:none' : '' ?>">
    <span class="af-label">Frequency (Hz)</span>
    <input class="af-input" id="cfg-pca9685_frequency" type="number"
           value="<?= (int)($cfg['pca9685_frequency'] ?? 50) ?>">
    <span style="color:#888; font-size:11px; margin-left:8px;">pca9685 backend only — smbus2 reads this from device</span>
  </div>
  <div class="af-row">
    <span class="af-label">Pan Channel</span>
    <?php if ($servo_ports): ?>
    <select class="af-select" id="cfg-channel_pan">
      <?php foreach ($servo_ports as $p):
        $sel = ((int)($cfg['channel_pan'] ?? 0) === (int)$p['value']) ? 'selected' : '';
      ?>
        <option value="<?= (int)$p['value'] ?>" <?= $sel ?>><?= htmlspecialchars($p['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <input class="af-input" id="cfg-channel_pan" type="number" value="<?= (int)($cfg['channel_pan'] ?? 0) ?>">
    <span style="color:#888; font-size:11px; margin-left:8px;">No servo outputs in co-other.json</span>
    <?php endif; ?>
  </div>
  <div class="af-row">
    <span class="af-label">Tilt Channel</span>
    <?php if ($servo_ports): ?>
    <select class="af-select" id="cfg-channel_tilt">
      <?php foreach ($servo_ports as $p):
        $sel = ((int)($cfg['channel_tilt'] ?? 1) === (int)$p['value']) ? 'selected' : '';
      ?>
        <option value="<?= (int)$p['value'] ?>" <?= $sel ?>><?= htmlspecialchars($p['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <input class="af-input" id="cfg-channel_tilt" type="number" value="<?= (int)($cfg['channel_tilt'] ?? 1) ?>">
    <?php endif; ?>
  </div>
  <div style="margin-top:8px;">
    <button class="af-btn btn-save" onclick="saveConfig()">Save &amp; Apply</button>
  </div>
</div>

</div><!-- /max-width -->

<script>
const API = '/fpp-live-follow-api';

function sendCmd(endpoint) {
  fetch(API + endpoint, {method:'POST'})
    .then(r => r.json())
    .then(() => pollStatus());
}

function saveConfig() {
  const fields = [
    'trigger_mode','motion_sensor_pin','motion_timeout_sec',
    'hardware_type','pca9685_address','pca9685_i2c_bus','pca9685_frequency',
    'channel_pan','channel_tilt',
    'tracking_mode',
    'servo_pan_min','servo_pan_max','servo_pan_center','servo_pan_speed','servo_pan_invert',
    'servo_tilt_min','servo_tilt_max','servo_tilt_center','servo_tilt_speed','servo_tilt_invert',
    'face_smoothing','deadzone_px',
  ];
  const payload = {};
  fields.forEach(f => {
    const el = document.getElementById('cfg-' + f);
    if (!el) return;
    if (el.type === 'checkbox') payload[f] = el.checked;
    else payload[f] = isNaN(el.value) ? el.value : Number(el.value);
  });
  fetch(API + '/api/config', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  }).then(() => {
    document.getElementById('status-msg').textContent = 'Config saved.';
    setTimeout(() => document.getElementById('status-msg').textContent = '', 3000);
    pollStatus();
  });
}

function onCamError() {
  document.getElementById('cam-stream').style.display = 'none';
  document.getElementById('cam-error').style.display  = 'block';
  document.getElementById('cam-ownership-card').style.display = '';
}

function claimCamera() {
  const msg = document.getElementById('cam-claim-msg');
  msg.style.color = '#888';
  msg.textContent = 'Releasing from Performance Capture…';
  // Swallow capture errors — if it's not running the camera is already free
  fetch('/fpp-capture-api/api/camera/release', {method: 'POST'})
    .catch(() => null)
    .then(() => {
      msg.textContent = 'Claiming camera…';
      return fetch(API + '/api/camera/restore', {method: 'POST'});
    })
    .then(r => r.json())
    .then(d => {
      if (d.cam_running) {
        msg.style.color = '#06d6a0';
        msg.textContent = '✓ Camera claimed';
        document.getElementById('cam-ownership-card').style.display = 'none';
        const img = document.getElementById('cam-stream');
        img.style.display = '';
        document.getElementById('cam-error').style.display = 'none';
        img.src = '/fpp-live-follow-api/stream?' + Date.now();
      } else {
        msg.style.color = '#e63946';
        msg.textContent = '✗ Camera still unavailable — try again';
      }
    })
    .catch(() => {
      msg.style.color = '#e63946';
      msg.textContent = '✗ Could not reach Live Follow daemon';
    });
}

function pollStatus() {
  fetch(API + '/api/status')
    .then(r => r.json())
    .then(s => {
      document.getElementById('badge-tracking').textContent = s.tracking ? 'ACTIVE' : 'STOPPED';
      document.getElementById('badge-tracking').className   = 'af-badge ' + (s.tracking ? 'badge-on' : 'badge-off');
      document.getElementById('badge-face').textContent     = s.face_detected ? 'DETECTED' : 'NOT DETECTED';
      document.getElementById('badge-face').className       = 'af-badge ' + (s.face_detected ? 'badge-face' : 'badge-off');
      document.getElementById('btn-start').style.display    = s.tracking ? 'none' : '';
      document.getElementById('btn-stop').style.display     = s.tracking ? ''     : 'none';
      document.getElementById('val-pan').textContent        = s.pan.toFixed(1)  + '°';
      document.getElementById('val-tilt').textContent       = s.tilt.toFixed(1) + '°';
      document.getElementById('val-mode').textContent       = s.trigger_mode;
      if (s.cam_running === false) {
        document.getElementById('cam-ownership-card').style.display = '';
      }
    })
    .catch(() => {});
}

// Show/hide motion sensor fields
document.getElementById('cfg-trigger').addEventListener('change', function() {
  document.getElementById('row-motion').style.display =
    this.value === 'motion_sensor' ? '' : 'none';
});

// Show frequency row only for pca9685 (Adafruit) backend
document.getElementById('cfg-hardware_type').addEventListener('change', function() {
  document.getElementById('row-freq').style.display =
    this.value === 'pca9685' ? '' : 'none';
});

// Poll status every 2 seconds
setInterval(pollStatus, 2000);
pollStatus();
</script>
