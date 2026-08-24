#!/usr/bin/env bash
# Long-running WordPress dev server (PHP built-in server via WP-CLI).
# Runs as a visible terminal so logs and lifecycle stay inspectable.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

# Make sure the DB is up even if the terminal starts before start.sh finishes.
start_mariadb >/dev/null 2>&1 || true

echo "==> Serving WordPress at http://localhost:$WP_PORT (admin/admin)"
exec wp server --host=0.0.0.0 --port="$WP_PORT"
