#!/usr/bin/env bash
# Per-boot reconciliation: bring the MariaDB daemon up and confirm readiness.
# The WordPress dev server itself runs as a visible terminal (see environment.json).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

echo "==> Starting MariaDB"
start_mariadb
ensure_database
echo "==> MariaDB is ready on socket $MYSQL_SOCKET"

# One-time WordPress site install + plugin activation. Requires a live DB, so it
# lives here (agent boot) rather than in install.sh. Idempotent: guarded by
# `wp core is-installed`, and plugin activation is a no-op when already active.
if [ -f "$WP_DIR/wp-load.php" ]; then
  if ! wp core is-installed 2>/dev/null; then
    echo "==> Installing WordPress site"
    wp core install \
      --url="$WP_URL" \
      --title="TSO Plugin Dev" \
      --admin_user=admin \
      --admin_password=admin \
      --admin_email=dev@example.com \
      --skip-email
  else
    echo "==> WordPress site already installed"
  fi

  echo "==> Activating plugin and demo fixtures"
  wp plugin activate "$PLUGIN_SLUG" acme-promo backupzilla || true
fi
