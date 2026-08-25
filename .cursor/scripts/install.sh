#!/usr/bin/env bash
# Idempotent repository bootstrap for the TSO Admin Notices plugin.
# Prepares a local WordPress install with the plugin symlinked in, seeds demo
# notice fixtures, and installs any dev dependencies. Safe to run repeatedly.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

echo "==> Downloading WordPress core into $WP_DIR"
mkdir -p "$WP_DIR"
if [ ! -f "$WP_DIR/wp-load.php" ]; then
  wp core download --version=latest
else
  echo "    WordPress core already present."
fi

echo "==> Writing wp-config.php"
wp config create \
  --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
  --skip-check --force
wp config set FS_METHOD direct

echo "==> Symlinking plugin into wp-content/plugins/$PLUGIN_SLUG"
ln -sfn "$PLUGIN_SRC" "$WP_DIR/wp-content/plugins/$PLUGIN_SLUG"

echo "==> Creating demo notice fixtures (third-party nag emulators)"
ACME_DIR="$WP_DIR/wp-content/plugins/acme-promo"
mkdir -p "$ACME_DIR"
cat > "$ACME_DIR/acme-promo.php" <<'PHP'
<?php
/**
 * Plugin Name: ACME Promo Nag
 * Description: Emits a promotional admin notice (dev fixture for TSO Admin Notices).
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'admin_notices', function () {
	echo '<div class="notice notice-info acme-promo-notice"><p><strong>ACME Promo:</strong> Upgrade to ACME Pro now and save 50%! Please rate us 5 stars.</p></div>';
} );
PHP

BZ_DIR="$WP_DIR/wp-content/plugins/backupzilla"
mkdir -p "$BZ_DIR"
cat > "$BZ_DIR/backupzilla.php" <<'PHP'
<?php
/**
 * Plugin Name: BackupZilla
 * Description: Emits a backup-completed admin notice (dev fixture for TSO Admin Notices).
 * Version: 2.3.1
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'all_admin_notices', function () {
	echo '<div class="notice notice-success backupzilla-notice"><p><strong>BackupZilla:</strong> Your backup completed successfully. Buy more cloud storage!</p></div>';
} );
PHP

# NOTE: no database work here on purpose. install.sh must terminate without
# depending on a running service, and it also runs inside the restricted build
# sandbox (where mariadbd cannot start). Installing the WordPress site and
# activating plugins requires a live DB, so that is done at boot in start.sh,
# which runs in the agent pod where MariaDB works normally.
echo "==> install.sh complete (site install + activation happen in start.sh)."
