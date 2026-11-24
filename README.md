# FPP Animatronic Live Follow Plugin

Real-time face-tracking servo control for FPP animatronics. Detects a face in the camera frame and moves pan/tilt servos to keep it centered. Supports four trigger modes: always-on, show-active, FPP playlist command, and motion-sensor (GPIO).

---

## Install (SSH into your Pi first)

```bash
git clone https://github.com/pgianotto/fpp-live-follow.git \
    /home/fpp/media/plugins/fpp-live-follow
bash /home/fpp/media/plugins/fpp-live-follow/fpp_install.sh
```

The install script:
1. Installs Flask, MediaPipe, RPi.GPIO system-wide via `uv pip install --system`
2. Clones [animatronic-motion-system](https://github.com/pgianotto/animatronic-motion-system) to `/home/fpp/media/animatronic` for shared tracking code
3. Copies `core/` and `modes/` into the plugin's `lib/` folder
4. Creates and starts a systemd service on port 5001
5. Configures an Apache proxy so FPP's UI can reach the daemon

Refresh the FPP browser — the plugin appears under **Plugins → Animatronic Live Follow**.

---

## Update

```bash
cd /home/fpp/media/plugins/fpp-live-follow && git pull
sudo systemctl restart fpp-live-follow
```

Re-run `fpp_install.sh` only if the release notes mention new dependencies or changes
to `core/` or `modes/` that need to be re-copied into `lib/`.

---

## Uninstall

```bash
bash /home/fpp/media/plugins/fpp-live-follow/fpp_uninstall.sh
```

Stops and removes the systemd service and Apache proxy config. FPP's Plugin
Manager removes the plugin directory itself.

---

## Trigger Modes

| Mode | Behavior |
|------|----------|
| **Always On** | Tracking runs whenever the daemon is running |
| **Show Active** | Activates when an FPP playlist starts, stops when it ends |
| **FPP Command** | Controlled via playlist command scripts (`/api/start`, `/api/stop`) |
| **Motion Sensor** | GPIO pin triggers tracking; auto-off after configurable timeout |

Configure the mode and all servo settings from the plugin page in the FPP UI.

---

## Hardware

- Raspberry Pi running FPP (tested on FPP v6+)
- Servos driven by FPP, either:
  - a PCA9685 add-on board configured on FPP's **PWM** tab, or
  - a native servo cape (e.g. Kulp K2-Pi-Servo) configured on FPP's **Servos** tab
- USB or CSI camera
- Pan/tilt servo assembly; pick the Pan and Tilt ports on the plugin page

Ports on the **Servos** tab must use the *Scaled* data type. For those ports,
0–180° covers the port's full Min–Max range, and picking one fills in the
center from [fpp-servo-calibrator](https://github.com/pgianotto/fpp-servo-calibrator)
if you've calibrated it. With a native cape, don't also add a PCA9685 row on
the PWM tab for the same chip: two drivers on one chip make the servos stutter.
