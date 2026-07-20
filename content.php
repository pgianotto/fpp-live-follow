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

// Build servo channel list from FPP's co-other config (same source as servo
// calibrator) via the API — co-other.json's on-disk format isn't a stable
// contract across FPP releases, the API is.
$servo_ports = [];   // [['value'=>port_idx, 'label'=>'Port 0 — Pan', 'out'=>out_idx], ...]
$co_other_ctx = stream_context_create(['http' => ['timeout' => 3]]);
$co = @json_decode(@file_get_contents('http://localhost/api/channel/output/co-other', false, $co_other_ctx), true) ?? [];
foreach ($co['channelOutputs'] ?? [] as $out_idx => $out) {
    if (empty($out['ports'])) continue;
    $prefix = count($co['channelOutputs']) > 1 ? "Out$out_idx · " : '';
    foreach ($out['ports'] as $port_idx => $port) {
        $desc  = trim($port['description'] ?? '');
        $label = $prefix . "Port $port_idx" . ($desc !== '' ? " — $desc" : '');
        $servo_ports[] = ['value' => $port_idx, 'label' => $label, 'out' => $out_idx];
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
.btn-test     { background:#7209b7; color:#fff; }
.btn-save     { background:#06d6a0; color:#000; }
.badge-seq    { background:#f8961e; color:#000; }
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
    <button class="af-btn btn-test"  onclick="testFollow()" id="btn-test" title="Start tracking for 5 seconds to verify servo response">◆ Test (5s)</button>
  </div>
  <div class="af-row">
    <span class="af-label">Face</span>
    <span class="af-badge <?= $face ? 'badge-face' : 'badge-off' ?>" id="badge-face">
      <?= $face ? 'DETECTED' : 'NOT DETECTED' ?>
    </span>
  </div>
  <!-- sequence_follow status rows — shown only when trigger_mode == sequence_follow -->
  <div id="row-seq-status" style="<?= $trigger_mode !== 'sequence_follow' ? 'display:none' : '' ?>">
    <div class="af-row">
      <span class="af-label">Sequence</span>
      <span class="af-badge badge-off" id="badge-seq">IDLE</span>
    </div>
    <div class="af-row">
      <span class="af-label">Body</span>
      <span class="af-badge badge-off" id="badge-body">NOT IN FRAME</span>
    </div>
    <div class="af-row">
      <span class="af-label">Follow</span>
      <span class="af-badge badge-off" id="badge-follow">WAITING</span>
    </div>
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
    <select class="af-select" id="cfg-trigger_mode" onchange="onModeChange(this.value)">
      <?php
      $modes = [
        'sequence_follow' => 'Show Mode (Recommended) — FSEQ controls servos; detected face activates live follow',
        'command'         => 'FPP Command — start/stop via FPP playlist command or script',
        'always_on'       => 'Always On — live tracking at all times (standalone use only)',
        'show_active'     => 'During Show — activates when any FPP playlist runs',
        'motion_sensor'   => 'Motion Sensor — GPIO pin triggers tracking',
      ];
      foreach ($modes as $val => $label):
        $sel = ($trigger_mode === $val) ? 'selected' : '';
      ?>
        <option value="<?= $val ?>" <?= $sel ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Mode descriptions -->
  <div id="mode-desc-sequence_follow" class="mode-desc" style="<?= $trigger_mode !== 'sequence_follow' ? 'display:none':'' ?>">
    <p style="color:#888; font-size:12px; margin:8px 0 4px;">
      FPP sequences control all servo channels by default. When a face or body enters the frame,
      live follow temporarily overrides pan &amp; tilt. When the subject leaves, the sequence
      resumes control. Set the <strong>Backend Type</strong> below to <strong>FPP Overlay</strong>.
    </p>
  </div>
  <div id="mode-desc-command" class="mode-desc" style="<?= $trigger_mode !== 'command' ? 'display:none':'' ?>">
    <p style="color:#888; font-size:12px; margin:8px 0 4px;">
      Tracking only activates when explicitly commanded. In FPP's playlist editor, add a
      <strong>Script</strong> item and point it to:<br>
      <code style="color:#4cc9f0;">plugins/fpp-live-follow/commands/start_tracking.sh</code><br>
      <code style="color:#4cc9f0;">plugins/fpp-live-follow/commands/stop_tracking.sh</code>
    </p>
  </div>
  <div id="mode-desc-always_on" class="mode-desc" style="<?= $trigger_mode !== 'always_on' ? 'display:none':'' ?>">
    <p style="color:#888; font-size:12px; margin:8px 0 4px;">
      Live tracking is always active. <strong style="color:#fb8500;">Do not use this mode when FPP
      sequences control the same servo channels</strong> — the live-follow overlays will block the
      sequence data. Use Show Mode instead.
    </p>
  </div>
  <div id="mode-desc-show_active" class="mode-desc" style="<?= $trigger_mode !== 'show_active' ? 'display:none':'' ?>">
    <p style="color:#888; font-size:12px; margin:8px 0 4px;">
      Tracking activates when any FPP playlist starts playing and stops when it ends.
      FPP status is polled every 3 seconds; for instant response also set up FPP callbacks.
    </p>
  </div>
  <div id="mode-desc-motion_sensor" class="mode-desc" style="<?= $trigger_mode !== 'motion_sensor' ? 'display:none':'' ?>">
    <p style="color:#888; font-size:12px; margin:8px 0 4px;">
      A PIR or other sensor on the GPIO pin below triggers tracking. Tracking auto-stops
      after the timeout if no rising edge is seen.
    </p>
  </div>

  <div class="af-row" id="row-motion" style="<?= $trigger_mode !== 'motion_sensor' ? 'display:none' : '' ?>">
    <span class="af-label">GPIO Pin (BCM)</span>
    <input class="af-input" id="cfg-motion_sensor_pin"    type="number" value="<?= (int)($cfg['motion_sensor_pin'] ?? 7) ?>">
    <span class="af-label" style="width:auto; margin-left:16px;">Auto-off (sec)</span>
    <input class="af-input" id="cfg-motion_timeout_sec" type="number" value="<?= (int)($cfg['motion_timeout_sec'] ?? 30) ?>">
  </div>
  <div class="af-row" id="row-seq-follow" style="<?= $trigger_mode !== 'sequence_follow' ? 'display:none' : '' ?>">
    <span class="af-label">Release Timeout</span>
    <input class="af-input" id="cfg-follow_release_timeout" type="number" step="0.1" min="0.1"
           value="<?= floatval($cfg['follow_release_timeout'] ?? 1.5) ?>">
    <span style="color:#888; font-size:11px; margin-left:8px;">seconds after body leaves frame before handing back to sequence</span>
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
    <select class="af-select" id="cfg-hardware_type" onchange="onHwTypeChange(this.value)">
      <?php
      $hw_options = [
        'fpp_overlay' => 'FPP Overlay (Recommended) — FPP owns I2C; overlays steer pan/tilt',
        'smbus2'      => 'smbus2 — direct I2C (only for standalone, no FPP sequences)',
        'pca9685'     => 'pca9685 — Adafruit library direct I2C',
        'mock'        => 'mock — testing only',
      ];
      foreach ($hw_options as $t => $label): ?>
        <option value="<?= $t ?>" <?= ($hw_type === $t) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div id="row-hw-hint-overlay" style="<?= $hw_type !== 'fpp_overlay' ? 'display:none':'' ?>; margin:4px 0 8px 142px;">
    <span style="color:#888; font-size:11px;">
      FPP Overlay keeps FPP in control of the PCA9685. Live-follow writes servo overrides on top of
      any playing sequence — when tracking stops, the sequence resumes control of those channels instantly.
    </span>
  </div>
  <div id="row-hw-hint-direct" style="<?= $hw_type === 'fpp_overlay' ? 'display:none':'' ?>; margin:4px 0 8px 142px;">
    <span style="color:#fb8500; font-size:11px;">
      Direct I2C backends conflict with FPP's Channel Outputs — disable the PCA9685 channel output
      in FPP or use FPP Overlay mode instead if playing FSEQ sequences.
    </span>
  </div>
  <div class="af-row" id="row-i2c" style="<?= $hw_type === 'fpp_overlay' ? 'display:none' : '' ?>">
    <span class="af-label">I2C Address</span>
    <input class="af-input" id="cfg-pca9685_address" style="width:90px"
           value="<?= htmlspecialchars($cfg['pca9685_address'] ?? '0x40') ?>">
    <span class="af-label" style="width:auto; margin-left:16px;">I2C Bus</span>
    <input class="af-input" id="cfg-pca9685_i2c_bus" type="number" style="width:60px"
           value="<?= (int)($cfg['pca9685_i2c_bus'] ?? 1) ?>">
  </div>
  <div class="af-row" id="row-freq" style="<?= ($hw_type !== 'pca9685') ? 'display:none' : '' ?>">
    <span class="af-label">Frequency (Hz)</span>
    <input class="af-input" id="cfg-pca9685_frequency" type="number"
           value="<?= (int)($cfg['pca9685_frequency'] ?? 50) ?>">
    <span style="color:#888; font-size:11px; margin-left:8px;">pca9685 backend only</span>
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

function testFollow() {
  fetch(API + '/api/test', {method:'POST', headers:{'Content-Type':'application/json'},
                            body: JSON.stringify({duration: 5})})
    .then(r => r.json())
    .then(() => {
      document.getElementById('status-msg').textContent = 'Test follow active — stops in 5 seconds…';
      setTimeout(() => {
        document.getElementById('status-msg').textContent = '';
        pollStatus();
      }, 5500);
      pollStatus();
    });
}

function saveConfig() {
  const fields = [
    'trigger_mode','motion_sensor_pin','motion_timeout_sec','follow_release_timeout',
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
      // sequence_follow badges
      const isSeqFollow = s.trigger_mode === 'sequence_follow';
      document.getElementById('row-seq-status').style.display = isSeqFollow ? '' : 'none';
      if (isSeqFollow) {
        document.getElementById('badge-seq').textContent    = s.sequence_playing ? 'PLAYING' : 'IDLE';
        document.getElementById('badge-seq').className      = 'af-badge ' + (s.sequence_playing ? 'badge-seq' : 'badge-off');
        document.getElementById('badge-body').textContent   = s.body_in_frame ? 'IN FRAME' : 'NOT IN FRAME';
        document.getElementById('badge-body').className     = 'af-badge ' + (s.body_in_frame ? 'badge-face' : 'badge-off');
        document.getElementById('badge-follow').textContent = s.follow_active ? 'ACTIVE' : 'WAITING';
        document.getElementById('badge-follow').className   = 'af-badge ' + (s.follow_active ? 'badge-on' : 'badge-off');
      }
    })
    .catch(() => {});
}

function onModeChange(val) {
  document.querySelectorAll('.mode-desc').forEach(el => el.style.display = 'none');
  const desc = document.getElementById('mode-desc-' + val);
  if (desc) desc.style.display = '';
  document.getElementById('row-motion').style.display     = val === 'motion_sensor'   ? '' : 'none';
  document.getElementById('row-seq-follow').style.display = val === 'sequence_follow' ? '' : 'none';
}

function onHwTypeChange(val) {
  const isOverlay = val === 'fpp_overlay';
  document.getElementById('row-i2c').style.display          = isOverlay ? 'none' : '';
  document.getElementById('row-freq').style.display         = val === 'pca9685' ? '' : 'none';
  document.getElementById('row-hw-hint-overlay').style.display = isOverlay ? '' : 'none';
  document.getElementById('row-hw-hint-direct').style.display  = isOverlay ? 'none' : '';
}

// Poll status every 2 seconds
setInterval(pollStatus, 2000);
pollStatus();
</script>
