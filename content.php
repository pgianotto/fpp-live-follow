<?php
// FPP Live Follow — plugin web page
// Styled only with FPP's Bootstrap classes (no hardcoded colours or pixel
// widths) so it follows FPP's light/dark theme and works on phones.
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

$hide = fn(bool $hidden) => $hidden ? ' d-none' : '';
?>

<div class="container-fluid px-0">

<!-- Status bar -->
<div class="card mb-3">
  <div class="card-header fw-semibold">Status</div>
  <div class="card-body">
    <div class="row g-2 align-items-center mb-2">
      <div class="col-4 col-md-2 text-body-secondary">Tracking</div>
      <div class="col-8 col-md-10 d-flex flex-wrap gap-2 align-items-center">
        <span class="badge <?= $tracking ? 'bg-success' : 'bg-secondary' ?>" id="badge-tracking">
          <?= $tracking ? 'ACTIVE' : 'STOPPED' ?>
        </span>
        <button type="button" class="btn btn-success<?= $hide($tracking) ?>" onclick="sendCmd('/api/start')" id="btn-start">▶ Start</button>
        <button type="button" class="btn btn-danger<?= $hide(!$tracking) ?>" onclick="sendCmd('/api/stop')" id="btn-stop">■ Stop</button>
        <button type="button" class="btn btn-outline-primary" onclick="testFollow()" id="btn-test" title="Start tracking for 5 seconds to verify servo response">◆ Test (5s)</button>
      </div>
    </div>
    <div class="row g-2 align-items-center mb-2">
      <div class="col-4 col-md-2 text-body-secondary">Face</div>
      <div class="col-8 col-md-10">
        <span class="badge <?= $face ? 'bg-info' : 'bg-secondary' ?>" id="badge-face">
          <?= $face ? 'DETECTED' : 'NOT DETECTED' ?>
        </span>
      </div>
    </div>
    <!-- sequence_follow status rows — shown only when trigger_mode == sequence_follow -->
    <div id="row-seq-status" class="<?= $hide($trigger_mode !== 'sequence_follow') ?>">
      <div class="row g-2 align-items-center mb-2">
        <div class="col-4 col-md-2 text-body-secondary">Sequence</div>
        <div class="col-8 col-md-10"><span class="badge bg-secondary" id="badge-seq">IDLE</span></div>
      </div>
      <div class="row g-2 align-items-center mb-2">
        <div class="col-4 col-md-2 text-body-secondary">Body</div>
        <div class="col-8 col-md-10"><span class="badge bg-secondary" id="badge-body">NOT IN FRAME</span></div>
      </div>
      <div class="row g-2 align-items-center mb-2">
        <div class="col-4 col-md-2 text-body-secondary">Follow</div>
        <div class="col-8 col-md-10"><span class="badge bg-secondary" id="badge-follow">WAITING</span></div>
      </div>
    </div>
    <div class="row g-2 align-items-center mb-2">
      <div class="col-4 col-md-2 text-body-secondary">Pan</div>
      <div class="col-8 col-md-4 font-monospace" id="val-pan"><?= number_format($status['pan'] ?? 90, 1) ?>°</div>
      <div class="col-4 col-md-2 text-body-secondary">Tilt</div>
      <div class="col-8 col-md-4 font-monospace" id="val-tilt"><?= number_format($status['tilt'] ?? 90, 1) ?>°</div>
    </div>
    <div class="row g-2 align-items-center">
      <div class="col-4 col-md-2 text-body-secondary">Trigger Mode</div>
      <div class="col-8 col-md-10 font-monospace" id="val-mode"><?= htmlspecialchars($trigger_mode) ?></div>
    </div>
    <div id="status-msg" class="small text-success mt-2" role="status"></div>
  </div>
</div>

<!-- Camera unavailable card -->
<div class="card mb-3 border-warning<?= $hide($cam_running) ?>" id="cam-ownership-card">
  <div class="card-header fw-semibold">Camera Unavailable</div>
  <div class="card-body">
    <p class="small text-body-secondary">
      The camera may be held by the Performance Capture plugin.
      Click <strong>Claim Camera</strong> to release it and resume tracking.
    </p>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <button type="button" class="btn btn-success" onclick="claimCamera()">▶ Claim Camera</button>
      <span id="cam-claim-msg" class="small"></span>
    </div>
  </div>
</div>

<!-- Camera feed -->
<div class="card mb-3" id="cam-feed-card">
  <div class="card-header fw-semibold">Camera Feed</div>
  <div class="card-body">
    <img src="/fpp-live-follow-api/stream" alt="Live camera feed"
         class="img-fluid rounded border" id="cam-stream"
         onerror="onCamError()">
    <div id="cam-error" class="text-danger small py-2 d-none">
      Camera stream unavailable.
    </div>
  </div>
</div>

<!-- Trigger Mode config -->
<div class="card mb-3">
  <div class="card-header fw-semibold">Trigger Mode</div>
  <div class="card-body">
    <div class="row g-2 align-items-center mb-2">
      <label class="col-md-3 col-form-label" for="cfg-trigger_mode">Mode</label>
      <div class="col-md-9">
        <select class="form-select" id="cfg-trigger_mode" onchange="onModeChange(this.value)">
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
    </div>

    <!-- Mode descriptions -->
    <div id="mode-desc-sequence_follow" class="mode-desc small text-body-secondary mb-2<?= $hide($trigger_mode !== 'sequence_follow') ?>">
      FPP sequences control all servo channels by default. When a face or body enters the frame,
      live follow temporarily overrides pan &amp; tilt. When the subject leaves, the sequence
      resumes control. Set the <strong>Backend Type</strong> below to <strong>FPP Overlay</strong>.
    </div>
    <div id="mode-desc-command" class="mode-desc small text-body-secondary mb-2<?= $hide($trigger_mode !== 'command') ?>">
      Tracking only activates when explicitly commanded. In FPP's playlist editor, add a
      <strong>Script</strong> item and point it to:<br>
      <code class="text-break">plugins/fpp-live-follow/commands/start_tracking.sh</code><br>
      <code class="text-break">plugins/fpp-live-follow/commands/stop_tracking.sh</code>
    </div>
    <div id="mode-desc-always_on" class="mode-desc small text-body-secondary mb-2<?= $hide($trigger_mode !== 'always_on') ?>">
      Live tracking is always active. <strong class="text-warning-emphasis">Do not use this mode when FPP
      sequences control the same servo channels</strong> — the live-follow overlays will block the
      sequence data. Use Show Mode instead.
    </div>
    <div id="mode-desc-show_active" class="mode-desc small text-body-secondary mb-2<?= $hide($trigger_mode !== 'show_active') ?>">
      Tracking activates when any FPP playlist starts playing and stops when it ends.
      FPP status is polled every 3 seconds; for instant response also set up FPP callbacks.
    </div>
    <div id="mode-desc-motion_sensor" class="mode-desc small text-body-secondary mb-2<?= $hide($trigger_mode !== 'motion_sensor') ?>">
      A PIR or other sensor on the GPIO pin below triggers tracking. Tracking auto-stops
      after the timeout if no rising edge is seen.
    </div>

    <div class="row g-2 align-items-center mb-2<?= $hide($trigger_mode !== 'motion_sensor') ?>" id="row-motion">
      <label class="col-6 col-md-3 col-form-label" for="cfg-motion_sensor_pin">GPIO Pin (BCM)</label>
      <div class="col-6 col-md-3">
        <input class="form-control" id="cfg-motion_sensor_pin" type="number" value="<?= (int)($cfg['motion_sensor_pin'] ?? 7) ?>">
      </div>
      <label class="col-6 col-md-3 col-form-label" for="cfg-motion_timeout_sec">Auto-off (sec)</label>
      <div class="col-6 col-md-3">
        <input class="form-control" id="cfg-motion_timeout_sec" type="number" value="<?= (int)($cfg['motion_timeout_sec'] ?? 30) ?>">
      </div>
    </div>
    <div class="row g-2 align-items-center mb-2<?= $hide($trigger_mode !== 'sequence_follow') ?>" id="row-seq-follow">
      <label class="col-6 col-md-3 col-form-label" for="cfg-follow_release_timeout">Release Timeout</label>
      <div class="col-6 col-md-3">
        <input class="form-control" id="cfg-follow_release_timeout" type="number" step="0.1" min="0.1"
               value="<?= floatval($cfg['follow_release_timeout'] ?? 1.5) ?>">
      </div>
      <div class="col-md-6 form-text mt-0">seconds after body leaves frame before handing back to sequence</div>
    </div>
    <button type="button" class="btn btn-primary mt-2" onclick="saveConfig()">Save &amp; Apply</button>
  </div>
</div>

<!-- Servo & Tracking config -->
<div class="card mb-3">
  <div class="card-header fw-semibold">Servo &amp; Tracking</div>
  <div class="card-body">
    <div class="row g-2 align-items-center mb-3">
      <label class="col-md-3 col-form-label" for="cfg-tracking_mode">Track Mode</label>
      <div class="col-md-9">
        <select class="form-select" id="cfg-tracking_mode">
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
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-2">
        <thead>
          <tr class="small text-body-secondary">
            <th scope="col"></th>
            <th scope="col">Pan</th>
            <th scope="col">Tilt</th>
          </tr>
        </thead>
        <tbody>
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
          <th scope="row" class="fw-normal text-body-secondary"><?= $label ?></th>
          <?php foreach (['pan', 'tilt'] as $s): ?>
          <td>
            <input class="form-control form-control-sm" id="cfg-<?= sprintf($key_pat, $s) ?>"
                   type="<?= $type ?>" step="<?= $step ?>" aria-label="<?= ucfirst($s) . ' ' . $label ?>"
                   value="<?= htmlspecialchars((string)($cfg[sprintf($key_pat, $s)] ?? '')) ?>">
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <tr>
          <th scope="row" class="fw-normal text-body-secondary">Invert Direction</th>
          <?php foreach (['pan', 'tilt'] as $s): ?>
          <td>
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" role="switch" id="cfg-servo_<?= $s ?>_invert"
                     aria-label="Invert <?= $s ?>"
                     <?= !empty($cfg["servo_{$s}_invert"]) ? 'checked' : '' ?>>
            </div>
          </td>
          <?php endforeach; ?>
        </tr>
        <tr>
          <th scope="row" class="fw-normal text-body-secondary">Face Smoothing</th>
          <td colspan="2">
            <input class="form-control form-control-sm" id="cfg-face_smoothing" type="number" step="0.05" min="0.05" max="1"
                   aria-describedby="help-smoothing"
                   value="<?= htmlspecialchars((string)($cfg['face_smoothing'] ?? 0.25)) ?>">
            <div id="help-smoothing" class="form-text">0.05 = smoothest, 1.0 = raw</div>
          </td>
        </tr>
        <tr>
          <th scope="row" class="fw-normal text-body-secondary">Deadzone (px)</th>
          <td colspan="2">
            <input class="form-control form-control-sm" id="cfg-deadzone_px" type="number"
                   value="<?= htmlspecialchars((string)($cfg['deadzone_px'] ?? 25)) ?>">
          </td>
        </tr>
        </tbody>
      </table>
    </div>
    <button type="button" class="btn btn-primary" onclick="saveConfig()">Save &amp; Apply</button>
  </div>
</div>

<!-- Hardware -->
<div class="card mb-3">
  <div class="card-header fw-semibold">Hardware</div>
  <div class="card-body">
    <div class="row g-2 align-items-center mb-2">
      <label class="col-md-3 col-form-label" for="cfg-hardware_type">Backend Type</label>
      <div class="col-md-9">
        <select class="form-select" id="cfg-hardware_type" onchange="onHwTypeChange(this.value)">
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
    </div>
    <div id="row-hw-hint-overlay" class="form-text mb-2 offset-md-3<?= $hide($hw_type !== 'fpp_overlay') ?>">
      FPP Overlay keeps FPP in control of the PCA9685. Live-follow writes servo overrides on top of
      any playing sequence — when tracking stops, the sequence resumes control of those channels instantly.
    </div>
    <div id="row-hw-hint-direct" class="alert alert-warning small py-2 mb-2<?= $hide($hw_type === 'fpp_overlay') ?>">
      Direct I2C backends conflict with FPP's Channel Outputs — disable the PCA9685 channel output
      in FPP or use FPP Overlay mode instead if playing FSEQ sequences.
    </div>
    <div class="row g-2 align-items-center mb-2<?= $hide($hw_type === 'fpp_overlay') ?>" id="row-i2c">
      <label class="col-6 col-md-3 col-form-label" for="cfg-pca9685_address">I2C Address</label>
      <div class="col-6 col-md-3">
        <input class="form-control" id="cfg-pca9685_address"
               value="<?= htmlspecialchars($cfg['pca9685_address'] ?? '0x40') ?>">
      </div>
      <label class="col-6 col-md-3 col-form-label" for="cfg-pca9685_i2c_bus">I2C Bus</label>
      <div class="col-6 col-md-3">
        <input class="form-control" id="cfg-pca9685_i2c_bus" type="number"
               value="<?= (int)($cfg['pca9685_i2c_bus'] ?? 1) ?>">
      </div>
    </div>
    <div class="row g-2 align-items-center mb-2<?= $hide($hw_type !== 'pca9685') ?>" id="row-freq">
      <label class="col-6 col-md-3 col-form-label" for="cfg-pca9685_frequency">Frequency (Hz)</label>
      <div class="col-6 col-md-3">
        <input class="form-control" id="cfg-pca9685_frequency" type="number"
               value="<?= (int)($cfg['pca9685_frequency'] ?? 50) ?>">
      </div>
      <div class="col-md-6 form-text mt-0">pca9685 backend only</div>
    </div>
    <?php foreach (['pan' => ['Pan Channel', 0], 'tilt' => ['Tilt Channel', 1]] as $axis => [$axis_label, $axis_default]):
      $cur_ch = (int)($cfg["channel_$axis"] ?? $axis_default);
    ?>
    <div class="row g-2 align-items-center mb-2">
      <label class="col-md-3 col-form-label" for="cfg-channel_<?= $axis ?>"><?= $axis_label ?></label>
      <div class="col-md-9">
        <?php if ($servo_ports): ?>
        <select class="form-select" id="cfg-channel_<?= $axis ?>">
          <?php foreach ($servo_ports as $p):
            $sel = ($cur_ch === (int)$p['value']) ? 'selected' : '';
          ?>
            <option value="<?= (int)$p['value'] ?>" <?= $sel ?>><?= htmlspecialchars($p['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input class="form-control" id="cfg-channel_<?= $axis ?>" type="number" value="<?= $cur_ch ?>">
        <div class="form-text">No servo outputs found on FPP's PWM tab (co-other)</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <button type="button" class="btn btn-primary mt-2" onclick="saveConfig()">Save &amp; Apply</button>
  </div>
</div>

</div><!-- /container -->

<script>
const API = '/fpp-live-follow-api';

// Show/hide via Bootstrap's d-none so visibility never fights theme styles.
function show(id, visible) {
  const el = typeof id === 'string' ? document.getElementById(id) : id;
  if (el) el.classList.toggle('d-none', !visible);
}

function setBadge(id, text, variant) {
  const el = document.getElementById(id);
  el.textContent = text;
  el.className   = 'badge bg-' + variant;
}

// FPP's standard toast; falls back to the inline message line.
function notify(text, ok = true) {
  if (window.jQuery && jQuery.jGrowl) {
    jQuery.jGrowl(text, {themeState: ok ? 'success' : 'danger'});
  } else {
    const msg = document.getElementById('status-msg');
    msg.className   = 'small mt-2 ' + (ok ? 'text-success' : 'text-danger');
    msg.textContent = text;
    setTimeout(() => msg.textContent = '', 3000);
  }
}

function sendCmd(endpoint) {
  fetch(API + endpoint, {method:'POST'})
    .then(r => r.json())
    .then(() => pollStatus())
    .catch(() => notify('Could not reach Live Follow daemon', false));
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
    })
    .catch(() => notify('Could not reach Live Follow daemon', false));
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
  }).then(r => {
    notify(r.ok ? 'Config saved.' : 'Config save failed.', r.ok);
    pollStatus();
  }).catch(() => notify('Could not reach Live Follow daemon', false));
}

function onCamError() {
  show('cam-stream', false);
  show('cam-error', true);
  show('cam-ownership-card', true);
}

function setClaimMsg(text, cls) {
  const msg = document.getElementById('cam-claim-msg');
  msg.className   = 'small ' + cls;
  msg.textContent = text;
}

function claimCamera() {
  setClaimMsg('Releasing from Performance Capture…', 'text-body-secondary');
  // Swallow capture errors — if it's not running the camera is already free
  fetch('/fpp-capture-api/api/camera/release', {method: 'POST'})
    .catch(() => null)
    .then(() => {
      setClaimMsg('Claiming camera…', 'text-body-secondary');
      return fetch(API + '/api/camera/restore', {method: 'POST'});
    })
    .then(r => r.json())
    .then(d => {
      if (d.cam_running) {
        setClaimMsg('✓ Camera claimed', 'text-success');
        show('cam-ownership-card', false);
        show('cam-stream', true);
        show('cam-error', false);
        document.getElementById('cam-stream').src = '/fpp-live-follow-api/stream?' + Date.now();
      } else {
        setClaimMsg('✗ Camera still unavailable — try again', 'text-danger');
      }
    })
    .catch(() => setClaimMsg('✗ Could not reach Live Follow daemon', 'text-danger'));
}

function pollStatus() {
  fetch(API + '/api/status')
    .then(r => r.json())
    .then(s => {
      setBadge('badge-tracking', s.tracking ? 'ACTIVE' : 'STOPPED', s.tracking ? 'success' : 'secondary');
      setBadge('badge-face', s.face_detected ? 'DETECTED' : 'NOT DETECTED', s.face_detected ? 'info' : 'secondary');
      show('btn-start', !s.tracking);
      show('btn-stop',  s.tracking);
      document.getElementById('val-pan').textContent  = s.pan.toFixed(1)  + '°';
      document.getElementById('val-tilt').textContent = s.tilt.toFixed(1) + '°';
      document.getElementById('val-mode').textContent = s.trigger_mode;
      if (s.cam_running === false) show('cam-ownership-card', true);
      // sequence_follow badges
      const isSeqFollow = s.trigger_mode === 'sequence_follow';
      show('row-seq-status', isSeqFollow);
      if (isSeqFollow) {
        setBadge('badge-seq', s.sequence_playing ? 'PLAYING' : 'IDLE', s.sequence_playing ? 'warning' : 'secondary');
        setBadge('badge-body', s.body_in_frame ? 'IN FRAME' : 'NOT IN FRAME', s.body_in_frame ? 'info' : 'secondary');
        setBadge('badge-follow', s.follow_active ? 'ACTIVE' : 'WAITING', s.follow_active ? 'success' : 'secondary');
      }
    })
    .catch(() => {});
}

function onModeChange(val) {
  document.querySelectorAll('.mode-desc').forEach(el => show(el, false));
  show('mode-desc-' + val, true);
  show('row-motion',     val === 'motion_sensor');
  show('row-seq-follow', val === 'sequence_follow');
}

function onHwTypeChange(val) {
  const isOverlay = val === 'fpp_overlay';
  show('row-i2c',              !isOverlay);
  show('row-freq',             val === 'pca9685');
  show('row-hw-hint-overlay',  isOverlay);
  show('row-hw-hint-direct',   !isOverlay);
}

// Poll status every 2 seconds
setInterval(pollStatus, 2000);
pollStatus();
</script>
