#!/bin/bash
set -uo pipefail
# FPP Live Follow plugin uninstaller — reverses fpp_install.sh
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Removing Animatronic Live Follow plugin..."

# ── systemd service ─────────────────────────────────────────────────────────
if systemctl list-unit-files fpp-live-follow.service &>/dev/null; then
    systemctl disable --now fpp-live-follow.service 2>/dev/null || true
fi
rm -f /etc/systemd/system/fpp-live-follow.service
systemctl daemon-reload 2>/dev/null || true

# ── Apache proxy ────────────────────────────────────────────────────────────
rm -f /etc/apache2/conf-enabled/fpp-live-follow-proxy.conf
rm -f /etc/apache2/conf-available/fpp-live-follow-proxy.conf
systemctl reload apache2 2>/dev/null || true

# ── safe.directory entry added at install time ─────────────────────────────
git config --system --unset-all safe.directory "$PLUGIN_DIR" 2>/dev/null || true

echo "Done. The plugin's own directory (and lib/, venv-free) is removed by FPP's Plugin Manager."
