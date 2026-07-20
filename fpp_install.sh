#!/bin/bash
set -euo pipefail
# FPP Live Follow plugin installer
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
LIB_DIR="$PLUGIN_DIR/lib"

echo "Installing Animatronic Live Follow plugin..."

# ── System packages (skip if already present) ─────────────────────────────────
if ! dpkg -s python3-opencv &>/dev/null 2>&1; then
    apt-get update -qq
    apt-get install -y python3-pip python3-opencv v4l-utils
fi

# ── uv (fast Python package installer) — install via pip, not a curl|sh script ─
export PATH="/usr/local/bin:$HOME/.local/bin:/root/.local/bin:$PATH"
if ! command -v uv &>/dev/null; then
    echo "Installing uv..."
    python3 -m pip install --quiet uv \
        || python3 -m pip install --quiet --break-system-packages uv
fi

# ── Python packages — system-wide via uv, same as FPP manages its own ─────────
echo "Installing Python packages..."
uv pip install --system --quiet \
    flask pyyaml smbus2 "mediapipe==0.10.9" RPi.GPIO

# ── Clone or update shared Python core from animatronic-motion-system ─────────
CORE_DIR="/home/fpp/media/animatronic"
if [ -d "$CORE_DIR/.git" ]; then
    echo "Updating shared core library..."
    chown -R fpp:fpp "$CORE_DIR" 2>/dev/null || true
    sudo -u fpp git -C "$CORE_DIR" fetch --quiet \
        && sudo -u fpp git -C "$CORE_DIR" reset --hard origin/master --quiet \
        || echo "  WARNING: git update failed — using existing core"
else
    echo "Cloning shared core library..."
    sudo -u fpp git clone --quiet https://github.com/pgianotto/animatronic-motion-system.git "$CORE_DIR" \
        || echo "  WARNING: git clone failed — tracking code may not work"
fi

mkdir -p "$LIB_DIR"
for d in core modes; do
    if [ -d "$CORE_DIR/$d" ]; then
        rm -rf "$LIB_DIR/$d"
        cp -r "$CORE_DIR/$d" "$LIB_DIR/$d"
        echo "  Copied $d/"
    else
        echo "  WARNING: $CORE_DIR/$d not found — tracking code may not work."
    fi
done
# Pre-create models dir and fix ownership so the fpp daemon can write model files
mkdir -p "$LIB_DIR/models"
chown -R fpp:fpp "$LIB_DIR"

# ── systemd service (always write so updates stay current) ────────────────────
SERVICE="/etc/systemd/system/fpp-live-follow.service"
echo "Installing systemd service..."
cat > /tmp/fpp-live-follow.service << 'EOF'
[Unit]
Description=FPP Animatronic Live Follow Daemon
After=network.target fppd.service

[Service]
Type=simple
User=fpp
WorkingDirectory=PLUGIN_DIR_PLACEHOLDER
ExecStartPre=/bin/sleep 8
ExecStart=/usr/bin/python3 PLUGIN_DIR_PLACEHOLDER/daemon.py
Restart=always
RestartSec=5
StartLimitIntervalSec=0

[Install]
WantedBy=multi-user.target
EOF
sed -i "s|PLUGIN_DIR_PLACEHOLDER|$PLUGIN_DIR|g" /tmp/fpp-live-follow.service
mv /tmp/fpp-live-follow.service "$SERVICE"
systemctl daemon-reload
systemctl enable fpp-live-follow.service

systemctl restart fpp-live-follow.service 2>/dev/null || true

# ── Apache proxy (always write so reinstalls and updates stay current) ────────
echo "Configuring Apache proxy..."
a2enmod proxy proxy_http 2>/dev/null || true
PROXY_CONF="/etc/apache2/conf-available/fpp-live-follow-proxy.conf"
printf '<IfModule mod_proxy.c>\n    ProxyPass        /fpp-live-follow-api/ http://localhost:5001/ flushpackets=on\n    ProxyPassReverse /fpp-live-follow-api/ http://localhost:5001/\n</IfModule>\n' \
    > "$PROXY_CONF"
ln -sf "$PROXY_CONF" /etc/apache2/conf-enabled/fpp-live-follow-proxy.conf
systemctl reload apache2 2>/dev/null || true

chmod +x "$PLUGIN_DIR/scripts/preStart.sh"

# Allow root (used by FPP's plugin manager) to run git in this directory.
# Without this, git 2.35+ rejects pull/fetch from root in fpp-owned dirs.
git config --system --add safe.directory "$PLUGIN_DIR" 2>/dev/null || true

echo "Done. Access via FPP menu: Plugins > Animatronic Live Follow"
