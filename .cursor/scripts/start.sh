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
