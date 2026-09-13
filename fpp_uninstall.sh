#!/bin/bash
set -uo pipefail
# FPP Live Follow plugin uninstaller — reverses fpp_install.sh
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Removing Animatronic Live Follow plugin..."

# FPP majors before 10 have no plugin load/unload feature, so they need a full
# fppd restart to pick up this uninstall even though FPP 10 itself hot-unloads it.
# FPPDIR can be unset here (uninstall_plugin passes it only as a trailing arg
# and sudo strips the exported one) and scripts/common is not `set -u` clean
# (bare $LD_LIBRARY_PATH), so default the path and relax nounset in a subshell
# -- otherwise this line aborts the whole script under `set -u`.
( set +u; source "${FPPDIR:-/opt/fpp}/scripts/common" 2>/dev/null && setSetting restartFlag 1 ) || true

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
