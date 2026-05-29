"""FPP Live Follow daemon.

Runs as a systemd service on port 5001.
PHP web page and FPP command scripts talk to this via HTTP.

Trigger modes
─────────────
  always_on      — tracking runs whenever the daemon is running
  show_active    — FPP callbacks script calls /api/fpp_event on playlist start/stop
  command        — /api/start and /api/stop called by FPP playlist command scripts
  motion_sensor  — GPIO pin triggers tracking; auto-off after timeout

Servo control strategy
──────────────────────
  FPP's PCA9685 channel output stays enabled at all times.  The daemon never
  touches the I2C bus directly.  Instead, when tracking is active it writes
  per-channel override values into FPP's channel data buffer via the overlay
  range API (PUT /overlays/range/{channel} on port 32322).  FPP applies those
  overrides every output frame on top of any sequence data, so the sequence
  continues animating all other channels while the daemon steers pan/tilt.
  When tracking stops the overlays are deleted and the sequence resumes control
  of pan/tilt immediately.
"""

import http.client
import json
import os
import sys
import threading
import time
import urllib.request
from pathlib import Path

# ── Resolve shared Python core ────────────────────────────────────────────────
PLUGIN_DIR   = Path(__file__).parent
LIB_DIR      = PLUGIN_DIR / 'lib'
PROJECT_ROOT = PLUGIN_DIR.parent.parent  # works when inside the project tree

for search in (LIB_DIR, PROJECT_ROOT):
    if (search / 'core').exists():
        sys.path.insert(0, str(search))
        break

# ── Imports ───────────────────────────────────────────────────────────────────
import cv2
import yaml
from flask import Flask, Response, jsonify, request

from core.camera import Camera
from core.servo_controller import ServoController, ServoBackend, create_backend
from core.tracker import Tracker
from modes.live_tracking import LiveTrackingMode

# ── Config ────────────────────────────────────────────────────────────────────
CFG_PATH    = Path('/home/fpp/media/config/animatronic_live_follow.json')
FSEQ_DIR    = Path('/home/fpp/media/sequences')
PORT        = 5001

DEFAULTS = {
    'trigger_mode':        'always_on',
    'motion_sensor_pin':   7,
    'motion_timeout_sec':  30,
    'camera_index':        0,
    'camera_width':        640,
    'camera_height':       480,
    'hardware_type':       'fpp_overlay',  # fpp_overlay | smbus2 | mock
    'pca9685_address':     '0x40',
    'pca9685_i2c_bus':     1,
    'pca9685_frequency':   50,
    'channel_pan':         0,
    'channel_tilt':        1,
    'servo_pan_min':       0,
    'servo_pan_max':       180,
    'servo_pan_center':    90,
    'servo_pan_speed':     200,   # degrees/sec at frame edge
    'servo_pan_invert':    False,
    'servo_tilt_min':      30,
    'servo_tilt_max':      150,
    'servo_tilt_center':   90,
    'servo_tilt_speed':    150,   # degrees/sec at frame edge
    'servo_tilt_invert':   False,
    'face_smoothing':         0.6,
    'deadzone_px':            25,
    'tracking_mode':          'face',   # face | body | face_or_body
    'follow_release_timeout': 1.5,
}

def _load_cfg() -> dict:
    if CFG_PATH.exists():
        try:
            return {**DEFAULTS, **json.loads(CFG_PATH.read_text())}
        except Exception:
            pass
    # Fall back to project config.yaml if available
    for search in (LIB_DIR, PROJECT_ROOT):
        yaml_path = search / 'config.yaml'
        if yaml_path.exists():
            try:
                raw = yaml.safe_load(yaml_path.read_text())
                merged = dict(DEFAULTS)
                merged['camera_index']    = raw.get('camera', {}).get('index', 0)
                merged['camera_width']    = raw.get('camera', {}).get('width', 640)
                merged['camera_height']   = raw.get('camera', {}).get('height', 480)
                merged['hardware_type']   = raw.get('hardware', {}).get('type', 'mock')
                merged['deadzone_px']     = raw.get('live_tracking', {}).get('deadzone_px', 25)
                pan  = raw.get('servos', {}).get('pan', {})
                tilt = raw.get('servos', {}).get('tilt', {})
                merged['servo_pan_min']   = pan.get('min_angle',    0)
                merged['servo_pan_max']   = pan.get('max_angle',  180)
                merged['servo_pan_center']= pan.get('center_angle', 90)
                merged['servo_pan_speed'] = pan.get('speed_limit',   8)
                merged['servo_tilt_min']  = tilt.get('min_angle',   30)
                merged['servo_tilt_max']  = tilt.get('max_angle',  150)
                merged['servo_tilt_center']= tilt.get('center_angle',90)
                merged['servo_tilt_speed']= tilt.get('speed_limit',  5)
                return merged
            except Exception:
                pass
    return dict(DEFAULTS)

def _save_cfg(cfg: dict):
    CFG_PATH.parent.mkdir(parents=True, exist_ok=True)
    CFG_PATH.write_text(json.dumps(cfg, indent=2))

_CO_OTHER_PATH = Path('/home/fpp/media/config/co-other.json')
_CO_OTHER_API  = 'http://localhost/api/channel/output/co-other'


# ── FPP overlay servo backend ─────────────────────────────────────────────────

def _read_pca9685_port_configs() -> list:
    """Parse co-other.json into a per-port config list for FppOverlayServoBackend."""
    try:
        cfg = json.loads(_CO_OTHER_PATH.read_text())
        for out in cfg.get('channelOutputs', []):
            if out.get('type') != 'PCA9685':
                continue
            sc    = out.get('startChannel', 1)
            ports = out.get('ports', [])
            result = []
            for p in ports:
                dt       = p.get('dataType', 0)
                is_16bit = dt in (2, 3, 5)
                result.append({
                    'fpp_start_channel': sc,
                    'is_16bit':   is_16bit,
                    'min_us':     float(p.get('min',    1000)),
                    'center_us':  float(p.get('center', 1500)),
                    'max_us':     float(p.get('max',    2000)),
                })
                sc += 2 if is_16bit else 1
            return result
    except Exception as exc:
        print(f'[LiveFollow] Could not read PCA9685 port configs from co-other.json: {exc}')
    return []


class FppOverlayServoBackend(ServoBackend):
    """Writes servo angles into FPP's channel buffer via the overlay range API.

    FPP keeps full ownership of the PCA9685 and I2C bus.  The daemon writes
    persistent per-channel overrides that FPP applies every output frame,
    merging them on top of the running sequence.  Calling delete_channels()
    removes those overrides so the sequence regains control instantly.
    """

    # FPP 16-bit SCALED format:  0=zeroBehavior, 1..32767 → min..center,
    #                            32768..65535 → center..max
    # FPP  8-bit SCALED format:  0=zeroBehavior, 1..127  → min..center,
    #                            128..255 → center..max

    def __init__(self, port_configs: list):
        self._ports = port_configs
        self._conn: http.client.HTTPConnection = None
        self._lock = threading.Lock()

    # ── HTTP helper ───────────────────────────────────────────────────────────

    def _put(self, path: str, body: str):
        with self._lock:
            try:
                if self._conn is None:
                    self._conn = http.client.HTTPConnection(
                        '127.0.0.1', 32322, timeout=0.1)
                self._conn.request('PUT', path, body,
                                   {'Content-Type': 'application/json'})
                resp = self._conn.getresponse()
                resp.read()
            except Exception:
                try:
                    self._conn.close()
                except Exception:
                    pass
                self._conn = None

    # ── Angle → FPP scaled value ──────────────────────────────────────────────

    def _angle_to_fpp_val(self, angle: float, port: dict) -> int:
        pulse_us  = 1000.0 + (float(angle) / 180.0) * 1000.0
        min_us    = port['min_us']
        center_us = port['center_us']
        max_us    = port['max_us']
        if port['is_16bit']:
            if pulse_us <= center_us:
                val = round((pulse_us - min_us) / (center_us - min_us) * 32767)
            else:
                val = 32768 + round((pulse_us - center_us) /
                                    (max_us - center_us) * 32767)
            return max(1, min(65535, val))
        else:
            if pulse_us <= center_us:
                val = round((pulse_us - min_us) / (center_us - min_us) * 127)
            else:
                val = 128 + round((pulse_us - center_us) /
                                  (max_us - center_us) * 127)
            return max(1, min(255, val))

    # ── ServoBackend interface ────────────────────────────────────────────────

    def set_angle(self, channel: int, angle: float):
        if channel >= len(self._ports):
            return
        port    = self._ports[channel]
        fpp_val = self._angle_to_fpp_val(angle, port)
        fpp_ch  = port['fpp_start_channel']
        if port['is_16bit']:
            self._put(f'/overlays/range/{fpp_ch}',
                      f'{{"Value": {(fpp_val >> 8) & 0xFF}}}')
            self._put(f'/overlays/range/{fpp_ch + 1}',
                      f'{{"Value": {fpp_val & 0xFF}}}')
        else:
            self._put(f'/overlays/range/{fpp_ch}',
                      f'{{"Value": {fpp_val}}}')

    def delete_channels(self, pca9685_channels: list):
        """Remove overlay overrides for the given PCA9685 port numbers."""
        for ch in pca9685_channels:
            if ch >= len(self._ports):
                continue
            port   = self._ports[ch]
            fpp_ch = port['fpp_start_channel']
            self._put(f'/overlays/range/{fpp_ch}', '{"delete": true}')
            if port['is_16bit']:
                self._put(f'/overlays/range/{fpp_ch + 1}', '{"delete": true}')

    def close(self):
        with self._lock:
            if self._conn:
                try:
                    self._conn.close()
                except Exception:
                    pass
                self._conn = None


# ── FPP output toggle ─────────────────────────────────────────────────────────

def _set_fpp_pca9685_output(enabled: bool):
    """Enable or disable fppd's PCA9685 channel output via the FPP API.

    Only needed when switching to/from smbus2 direct-control mode.
    In fpp_overlay mode fppd's PCA9685 output must remain enabled.
    """
    try:
        with urllib.request.urlopen(_CO_OTHER_API, timeout=3) as resp:
            cfg = json.loads(resp.read())
        changed = False
        for out in cfg.get('channelOutputs', []):
            if out.get('type') == 'PCA9685':
                out['enabled'] = 1 if enabled else 0
                changed = True
        if not changed:
            return
        data = json.dumps(cfg).encode()
        req  = urllib.request.Request(_CO_OTHER_API, data=data, method='POST',
                                      headers={'Content-Type': 'application/json'})
        urllib.request.urlopen(req, timeout=3)
        print(f'[LiveFollow] FPP PCA9685 output {"enabled" if enabled else "disabled"}.')
    except Exception as exc:
        print(f'[LiveFollow] Could not toggle FPP PCA9685 output: {exc}')


def _ensure_fpp_pca9685_enabled():
    """Re-enable FPP's PCA9685 output if it was left disabled by a previous run.

    Skips if fpp-servo-calibrator is active — that service intentionally disables
    PCA9685 output for exclusive I2C access, and re-enabling while it's running
    would break it.
    """
    try:
        import subprocess
        r = subprocess.run(['systemctl', 'is-active', 'fpp-servo-calibrator'],
                           capture_output=True, text=True)
        if r.stdout.strip() == 'active':
            print('[LiveFollow] Servo calibrator is running; skipping PCA9685 re-enable.')
            return
    except Exception:
        pass
    try:
        cfg = json.loads(_CO_OTHER_PATH.read_text())
        for out in cfg.get('channelOutputs', []):
            if out.get('type') == 'PCA9685' and not out.get('enabled', 1):
                print('[LiveFollow] Re-enabling FPP PCA9685 output (was disabled).')
                _set_fpp_pca9685_output(True)
                return
    except Exception:
        pass


# ── Tracking config builder ───────────────────────────────────────────────────

def _build_tracking_config(cfg: dict) -> dict:
    return {
        'camera':  {'index': cfg['camera_index'],
                    'width': cfg['camera_width'],
                    'height': cfg['camera_height'], 'fps': 30},
        'hardware': {'type': cfg['hardware_type'],
                     'pca9685_address':   cfg['pca9685_address'],
                     'pca9685_frequency': cfg['pca9685_frequency'],
                     'channel_assignments': {'pan':  cfg['channel_pan'],
                                             'tilt': cfg['channel_tilt']}},
        'servos': {
            'pan':  {'min_angle':    cfg['servo_pan_min'],
                     'max_angle':    cfg['servo_pan_max'],
                     'center_angle': cfg['servo_pan_center'],
                     'speed_limit':  cfg['servo_pan_speed'],
                     'invert':       bool(cfg.get('servo_pan_invert', False))},
            'tilt': {'min_angle':    cfg['servo_tilt_min'],
                     'max_angle':    cfg['servo_tilt_max'],
                     'center_angle': cfg['servo_tilt_center'],
                     'speed_limit':  cfg['servo_tilt_speed'],
                     'invert':       bool(cfg.get('servo_tilt_invert', False))},
        },
        'live_tracking': {
            'deadzone_px':    cfg['deadzone_px'],
            'face_smoothing': cfg.get('face_smoothing', 0.25),
            'tracking_mode':  cfg.get('tracking_mode', 'face'),
        },
    }


# ── Daemon state ──────────────────────────────────────────────────────────────

class LiveFollowDaemon:
    def __init__(self):
        self.cfg         = _load_cfg()
        self._lock       = threading.Lock()
        self._frame_lock = threading.Lock()
        self._latest_jpg = b''
        self._result     = None
        self._tracking   = False
        self._cam_running = False

        self._camera  = None
        self._tracker = None
        self._servos  = None
        self._mode    = None

        self._motion_timer: threading.Timer = None
        self._test_timer:   threading.Timer = None

        # sequence_follow state
        self._sequence_playing = False
        self._body_last_seen   = 0.0
        self._follow_active    = False
        self._body_in_frame    = False
        self._user_stopped     = False  # set by /api/stop; suppresses idle auto-restart

        # overlay release state — tracks whether pan/tilt overlays are live
        self._overlay_active   = False
        self._overlay_last_seen = 0.0

        if self.cfg.get('hardware_type') == 'fpp_overlay':
            _ensure_fpp_pca9685_enabled()

        self._fpp_poll_running = False
        self._poll_generation  = 0   # incremented on reload to stop stale threads

        self._start_components()
        self._start_camera_thread()
        self._apply_trigger_mode()

    # ── Component management ─────────────────────────────────────────────────

    def _start_components(self):
        tc  = _build_tracking_config(self.cfg)
        hw  = tc['hardware']
        hw_type = hw.get('type', 'mock')

        if hw_type == 'fpp_overlay':
            port_configs = _read_pca9685_port_configs()
            if port_configs:
                backend = FppOverlayServoBackend(port_configs)
            else:
                print('[LiveFollow] No PCA9685 port configs found; using mock backend.')
                from core.servo_controller import MockServoBackend
                backend = MockServoBackend()
        else:
            try:
                backend = create_backend(hw)
            except Exception:
                from core.servo_controller import MockServoBackend
                backend = MockServoBackend()

        self._servos  = ServoController(backend, tc['servos'],
                                        hw['channel_assignments'])
        self._tracker = Tracker()
        cam_cfg = tc['camera']
        self._camera  = Camera(index=cam_cfg['index'],
                               width=cam_cfg['width'],
                               height=cam_cfg['height'])
        self._tracker.start(cam_cfg['width'], cam_cfg['height'])
        self._mode = LiveTrackingMode(self._servos, tc)

    def _stop_components(self):
        if self._tracker:
            self._tracker.stop()
        if self._camera:
            self._camera.stop()
        if self._servos:
            self._servos.close()

    # ── Camera thread ────────────────────────────────────────────────────────

    def _start_camera_thread(self):
        if not self._camera.start():
            print('[LiveFollow] Camera failed to open.')
            return
        self._cam_running = True
        threading.Thread(target=self._cam_loop, daemon=True).start()

    def _cam_loop(self):
        while self._cam_running:
            ok, frame = self._camera.read()
            if not ok or frame is None:
                continue
            result = self._tracker.process(frame)
            body_in_frame = self._is_body_in_frame(result)
            with self._lock:
                self._result = result
                self._body_in_frame = body_in_frame
                if self._tracking:
                    self._mode.update(result)
            if self.cfg.get('trigger_mode') == 'sequence_follow':
                self._update_follow_state(body_in_frame)
            if self._tracking:
                self._handle_overlay_release(body_in_frame)
            display = self._tracker.draw_overlay(frame.copy(), result)
            _, buf = cv2.imencode('.jpg', display, [cv2.IMWRITE_JPEG_QUALITY, 70])
            with self._frame_lock:
                self._latest_jpg = buf.tobytes()

    # ── Trigger mode bootstrap ───────────────────────────────────────────────

    def _apply_trigger_mode(self):
        mode = self.cfg.get('trigger_mode', 'always_on')
        if mode == 'always_on':
            self.start_tracking()
        elif mode == 'motion_sensor':
            self._setup_motion_sensor()
        elif mode in ('sequence_follow', 'show_active'):
            self._start_fpp_poll_thread()
        # 'command' mode: do nothing — tracking only starts on explicit command

    def _start_fpp_poll_thread(self):
        """Continuously poll FPP playback status for sequence_follow and show_active modes."""
        if self._fpp_poll_running:
            return
        self._fpp_poll_running = True
        my_gen = self._poll_generation
        def _loop():
            while self._fpp_poll_running and self._poll_generation == my_gen:
                try:
                    with urllib.request.urlopen(
                            'http://localhost/api/fppd/status', timeout=2) as r:
                        data = json.loads(r.read())
                    playing = data.get('status_name', 'idle').lower() in ('playing', 'testing')
                    was_playing = self._sequence_playing
                    self._sequence_playing = playing
                    mode = self.cfg.get('trigger_mode', 'always_on')
                    if mode == 'sequence_follow':
                        if playing and not was_playing:
                            # Sequence just started — hand control to FSEQ
                            self._follow_active = False
                            self.stop_tracking()
                        elif not playing and was_playing:
                            # Sequence stopped — resume free follow
                            self._follow_active = False
                            self.start_tracking()
                        elif not playing and not self._tracking and not self._user_stopped:
                            # Idle on startup — start free follow
                            self.start_tracking()
                    elif mode == 'show_active':
                        if playing and not was_playing:
                            self.start_tracking()
                        elif not playing and was_playing:
                            self.stop_tracking()
                except Exception:
                    pass
                time.sleep(3)
        threading.Thread(target=_loop, daemon=True, name='fpp-status-poll').start()

    # ── Tracking control ─────────────────────────────────────────────────────

    def start_tracking(self):
        self._user_stopped = False
        with self._lock:
            if not self._tracking:
                self._mode.start()
                self._tracking = True
        self._overlay_active    = False
        self._overlay_last_seen = 0.0
        print('[LiveFollow] Tracking started.')

    def stop_tracking(self):
        with self._lock:
            if self._tracking:
                self._mode.stop()
                self._tracking = False
        self._overlay_active = False
        # Remove pan/tilt overlays so the sequence resumes control of those channels
        if self._servos and isinstance(self._servos._backend, FppOverlayServoBackend):
            pan_ch  = self.cfg['channel_pan']
            tilt_ch = self.cfg['channel_tilt']
            self._servos._backend.delete_channels([pan_ch, tilt_ch])
        print('[LiveFollow] Tracking stopped.')

    def _handle_overlay_release(self, body_in_frame: bool):
        """Delete pan/tilt overlays when no face/body has been seen for the release timeout.

        While a subject is in frame the servo loop writes overlays continuously.
        When the subject leaves the last overlay value would otherwise freeze the
        servos; this method cleans them up so the sequence resumes control.
        """
        if not isinstance(self._servos._backend, FppOverlayServoBackend):
            return
        now = time.time()
        if body_in_frame:
            self._overlay_active    = True
            self._overlay_last_seen = now
        elif self._overlay_active:
            timeout = float(self.cfg.get('follow_release_timeout', 1.5))
            if now - self._overlay_last_seen >= timeout:
                self._overlay_active = False
                self._servos._backend.delete_channels(
                    [self.cfg['channel_pan'], self.cfg['channel_tilt']]
                )

    # ── Motion sensor ────────────────────────────────────────────────────────

    def _setup_motion_sensor(self):
        try:
            import RPi.GPIO as GPIO
            pin = int(self.cfg.get('motion_sensor_pin', 7))
            GPIO.setmode(GPIO.BCM)
            GPIO.setup(pin, GPIO.IN)
            GPIO.add_event_detect(pin, GPIO.RISING,
                                  callback=self._on_motion,
                                  bouncetime=500)
            print(f'[LiveFollow] Motion sensor on GPIO {pin}.')
        except Exception as exc:
            print(f'[LiveFollow] Motion sensor not available: {exc}')

    def _on_motion(self, channel):
        self.start_tracking()
        timeout = float(self.cfg.get('motion_timeout_sec', 30))
        if self._motion_timer:
            self._motion_timer.cancel()
        self._motion_timer = threading.Timer(timeout, self.stop_tracking)
        self._motion_timer.start()

    # ── sequence_follow helpers ──────────────────────────────────────────────

    def _is_body_in_frame(self, result) -> bool:
        if result is None:
            return False
        mode = self.cfg.get('tracking_mode', 'face')
        if mode == 'body':
            return bool(getattr(result, 'body_detected', result.face_detected))
        if mode == 'face_or_body':
            return bool(result.face_detected or getattr(result, 'body_detected', False))
        return bool(result.face_detected)

    def _update_follow_state(self, body_in_frame: bool):
        """Drive sequence_follow mode: take over pan/tilt on body detect, release on timeout."""
        now = time.time()
        if body_in_frame:
            self._body_last_seen = now
        if self._sequence_playing:
            if body_in_frame and not self._follow_active and not self._user_stopped:
                self._follow_active = True
                self.start_tracking()
            elif not body_in_frame and self._follow_active:
                timeout = float(self.cfg.get('follow_release_timeout', 1.5))
                if now - self._body_last_seen >= timeout:
                    self._follow_active = False
                    self.stop_tracking()
        # No sequence playing → free follow (tracking already on from startup / playlist_stop)

    # ── Status ───────────────────────────────────────────────────────────────

    def status(self) -> dict:
        with self._lock:
            r = self._result
            body_in_frame = self._body_in_frame
        return {
            'tracking':         self._tracking,
            'trigger_mode':     self.cfg.get('trigger_mode', 'always_on'),
            'face_detected':    bool(r and r.face_detected),
            'body_in_frame':    body_in_frame,
            'sequence_playing': self._sequence_playing,
            'follow_active':    self._follow_active,
            'pan':              self._servos.get_angle('pan')  if self._servos else 90,
            'tilt':             self._servos.get_angle('tilt') if self._servos else 90,
            'head_yaw':         float(r.head_yaw)   if r else 0.0,
            'head_pitch':       float(r.head_pitch) if r else 0.0,
            'cam_running':      self._cam_running,
        }

    # ── MJPEG stream ─────────────────────────────────────────────────────────

    def mjpeg_frames(self):
        while True:
            with self._frame_lock:
                jpg = self._latest_jpg
            if jpg:
                yield (b'--frame\r\nContent-Type: image/jpeg\r\n\r\n'
                       + jpg + b'\r\n')
            time.sleep(0.033)

    def reload_config(self):
        self.cfg = _load_cfg()
        self.stop_tracking()
        self._fpp_poll_running = False
        self._poll_generation += 1   # invalidates any running poll thread immediately
        self._cam_running = False
        time.sleep(0.2)
        self._stop_components()
        if self.cfg.get('hardware_type') == 'fpp_overlay':
            _ensure_fpp_pca9685_enabled()
        self._sequence_playing = False
        self._follow_active    = False
        self._body_last_seen   = 0.0
        self._start_components()
        self._start_camera_thread()
        self._apply_trigger_mode()


# ── Flask app ─────────────────────────────────────────────────────────────────

app    = Flask(__name__)
daemon = LiveFollowDaemon()


@app.route('/api/status')
def api_status():
    return jsonify(daemon.status())


@app.route('/api/start', methods=['POST'])
def api_start():
    daemon.start_tracking()
    return jsonify({'ok': True})


@app.route('/api/stop', methods=['POST'])
def api_stop():
    daemon._user_stopped = True
    daemon.stop_tracking()
    return jsonify({'ok': True})


@app.route('/api/test', methods=['POST'])
def api_test():
    """Start tracking for a short duration for verification; auto-stops after duration."""
    data = request.get_json(force=True, silent=True) or {}
    duration = float(data.get('duration', 5))
    if daemon._test_timer:
        daemon._test_timer.cancel()
    daemon.start_tracking()
    daemon._test_timer = threading.Timer(duration, daemon.stop_tracking)
    daemon._test_timer.start()
    return jsonify({'ok': True, 'duration': duration})


@app.route('/api/fpp_event', methods=['POST'])
def api_fpp_event():
    """Called by FPP callbacks (playlist start/stop) for instant response without waiting for poll."""
    data  = request.get_json(force=True, silent=True) or {}
    event = data.get('event', '')
    mode  = daemon.cfg.get('trigger_mode', 'always_on')
    if event == 'playlist_start':
        daemon._sequence_playing = True
        if mode == 'sequence_follow':
            daemon._follow_active = False
            daemon.stop_tracking()
        elif mode == 'show_active':
            daemon.start_tracking()
    elif event == 'playlist_stop':
        daemon._sequence_playing = False
        if mode == 'sequence_follow':
            daemon._follow_active = False
            daemon.start_tracking()
        elif mode == 'show_active':
            daemon.stop_tracking()
    return jsonify({'ok': True})


@app.route('/api/fpp_command', methods=['POST'])
def api_fpp_command():
    """Endpoint for FPP Command playlist items and scripts.

    Accepts JSON body: {"command": "start" | "stop" | "toggle"}
    Also accepts query-string: ?command=start

    Use this in FPP playlists via 'Run Script' items pointing to
    commands/start_tracking.sh or commands/stop_tracking.sh.
    """
    data = request.get_json(force=True, silent=True) or {}
    cmd  = data.get('command') or request.args.get('command', '').lower()
    if cmd == 'start':
        daemon.start_tracking()
    elif cmd == 'stop':
        daemon._user_stopped = True
        daemon.stop_tracking()
    elif cmd == 'toggle':
        if daemon._tracking:
            daemon._user_stopped = True
            daemon.stop_tracking()
        else:
            daemon.start_tracking()
    return jsonify({'ok': True, 'tracking': daemon._tracking})


@app.route('/api/config', methods=['GET'])
def api_get_config():
    return jsonify(daemon.cfg)


@app.route('/api/config', methods=['POST'])
def api_set_config():
    updates = request.get_json(force=True, silent=True) or {}
    daemon.cfg.update(updates)
    _save_cfg(daemon.cfg)
    daemon.reload_config()
    return jsonify({'ok': True})


@app.route('/stream')
def stream():
    return Response(daemon.mjpeg_frames(),
                    mimetype='multipart/x-mixed-replace; boundary=frame')


@app.route('/api/camera/release', methods=['POST'])
def api_cam_release():
    """Stop the camera thread so another daemon can claim the device."""
    daemon._cam_running = False
    time.sleep(0.15)
    if daemon._camera:
        daemon._camera.stop()
    return jsonify({'ok': True})


@app.route('/api/camera/restore', methods=['POST'])
def api_cam_restore():
    """Restart the camera thread after it was released."""
    if not daemon._cam_running:
        daemon._start_camera_thread()
    return jsonify({'ok': True, 'cam_running': daemon._cam_running})


if __name__ == '__main__':
    print(f'[LiveFollow] Daemon starting on port {PORT}')
    app.run(host='0.0.0.0', port=PORT, threaded=True)
