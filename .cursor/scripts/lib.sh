#!/usr/bin/env bash
# Shared helpers for the TSO Admin Notices Cloud Agent environment scripts.
set -euo pipefail

# Where a full WordPress install lives for developing/running the plugin.
export WP_DIR="${WP_DIR:-$HOME/wp}"
# Repository checkout (the plugin itself) is symlinked into wp-content/plugins.
export PLUGIN_SRC="${PLUGIN_SRC:-/workspace}"
export PLUGIN_SLUG="tso-admin-notices"

export WP_URL="${WP_URL:-http://localhost:8080}"
export WP_PORT="${WP_PORT:-8080}"

export DB_NAME="${DB_NAME:-wordpress}"
export DB_USER="${DB_USER:-wp}"
export DB_PASS="${DB_PASS:-wp}"
export DB_HOST="${DB_HOST:-127.0.0.1}"

export MYSQL_DATADIR="${MYSQL_DATADIR:-/var/lib/mysql}"
export MYSQL_SOCKET="${MYSQL_SOCKET:-/var/run/mysqld/mysqld.sock}"

# WP-CLI runs against $WP_DIR and (in cloud) as the ubuntu user, so allow-root is harmless.
wp() {
  command wp --path="$WP_DIR" --allow-root "$@"
}

mariadb_running() {
  mariadb-admin --socket="$MYSQL_SOCKET" ping >/dev/null 2>&1
}

start_mariadb() {
  if mariadb_running; then
    return 0
  fi
  sudo mkdir -p "$(dirname "$MYSQL_SOCKET")" "$MYSQL_DATADIR"
  sudo chown -R mysql:mysql "$(dirname "$MYSQL_SOCKET")" "$MYSQL_DATADIR"
  # Clear a stale socket left by a previous (crashed or snapshotted) daemon.
  if [ -S "$MYSQL_SOCKET" ] && ! mariadb_running; then
    sudo rm -f "$MYSQL_SOCKET"
  fi
  if [ ! -d "$MYSQL_DATADIR/mysql" ]; then
    sudo mariadb-install-db --user=mysql --basedir=/usr --datadir="$MYSQL_DATADIR" >/dev/null 2>&1 || true
  fi
  # Disable io_uring / native AIO and reverse-DNS: sandboxed build & agent pods
  # can block those syscalls, which makes mariadbd hang during InnoDB startup.
  sudo bash -c "nohup mariadbd --user=mysql --datadir='$MYSQL_DATADIR' --socket='$MYSQL_SOCKET' --innodb-use-native-aio=0 --skip-name-resolve > /tmp/mariadb.log 2>&1 &"
  # Wait generously: a datadir captured from a live snapshot may run InnoDB
  # crash recovery on first start, which can take well beyond a few seconds.
  for _ in $(seq 1 120); do
    if mariadb_running; then
      return 0
    fi
    if ! pgrep -x mariadbd >/dev/null 2>&1; then
      break
    fi
    sleep 1
  done
  echo "MariaDB failed to start. mariadbd process:" >&2
  pgrep -ax mariadbd >&2 || echo "  (no mariadbd process running)" >&2
  echo "--- /tmp/mariadb.log (line-prefixed) ---" >&2
  sed 's/^/[mariadb] /' /tmp/mariadb.log >&2 || true
  return 1
}

ensure_database() {
  sudo mariadb --socket="$MYSQL_SOCKET" -e "
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
    FLUSH PRIVILEGES;"
}
