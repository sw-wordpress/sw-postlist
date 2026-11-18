#!/usr/bin/env bash
# Browser E2E against a disposable WordPress (private MariaDB from bin/test-db.sh, PHP built-in server, Playwright).
# Usage: [SWP_E2E_ZIP=dist/sw-postlist-x.y.z.zip] tests/E2E/run.sh [workdir]
# SWP_E2E_THEME=classic activates the minimal classic theme of tests/Fixtures (default: the block theme Twenty Twenty-Five).
# Needs: bin/test-db.sh start, tests/wp vendor (WordPress core copy), npm ci --prefix tests/E2E
#        Optional overrides: PW_DIR (node_modules), SWP_BROWSER_CDP (running Chromium) or CHROME (executable).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="${1:-$(mktemp -d)}"
PORT=8090
mkdir -p "$WORK"
WORK="$(cd "$WORK" && pwd)"
export SWP_E2E_WP="$WORK/wp"
rm -rf "${SWP_E2E_WP:?}" && cp -r "$ROOT/tests/wp/vendor/johnpbloch/wordpress-core" "$SWP_E2E_WP"
mkdir -p "$SWP_E2E_WP/wp-content/plugins" "$WORK/shots"
if [ "${SWP_E2E_THEME:-}" = "classic" ]; then
  mkdir -p "$SWP_E2E_WP/wp-content/themes" && cp -r "$ROOT/tests/Fixtures/classic-theme" "$SWP_E2E_WP/wp-content/themes/swp-classic"
fi
if [ -n "${SWP_E2E_ZIP:-}" ]; then
  # Release smoke test: the plugin comes out of the ZIP, without development vendor/ or sources.
  unzip -q "$SWP_E2E_ZIP" -d "$SWP_E2E_WP/wp-content/plugins"
else
  ln -s "$ROOT" "$SWP_E2E_WP/wp-content/plugins/sw-postlist"
fi
MYSQL_PWD="${SWP_TEST_DB_PASSWORD:-swp}" mariadb --host="${SWP_TEST_DB_HOST:-127.0.0.1}" \
  --port="${SWP_TEST_DB_PORT:-33079}" --user="${SWP_TEST_DB_USER:-swp}" -e "DROP DATABASE IF EXISTS swp_e2e; CREATE DATABASE swp_e2e CHARACTER SET utf8mb4"
cat > "$SWP_E2E_WP/wp-config.php" <<CONFIG
<?php
define( 'DB_NAME', 'swp_e2e' );
define( 'DB_USER', getenv( 'SWP_TEST_DB_USER' ) ?: 'swp' );
define( 'DB_PASSWORD', getenv( 'SWP_TEST_DB_PASSWORD' ) ?: 'swp' );
define( 'DB_HOST', ( getenv( 'SWP_TEST_DB_HOST' ) ?: '127.0.0.1' ) . ':' . ( getenv( 'SWP_TEST_DB_PORT' ) ?: '33079' ) );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, 'e2e-' . \$k ); }
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false ); define( 'DISABLE_WP_CRON', true );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CONFIG
(cd "$SWP_E2E_WP" && php "$ROOT/tests/E2E/setup.php" && php "$ROOT/tests/E2E/seed.php")
(cd "$SWP_E2E_WP" && exec php -S 127.0.0.1:$PORT >"$WORK/server.log" 2>&1) &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do
  if curl --silent --fail "http://127.0.0.1:$PORT/" >/dev/null; then break; fi
  sleep 1
done
curl --silent --fail "http://127.0.0.1:$PORT/" >/dev/null
export SWP_E2E_SEED="$WORK/seed.json"
export PW_DIR="${PW_DIR:-$ROOT/tests/E2E/node_modules}"
node "$ROOT/tests/E2E/browser.mjs" "$WORK/shots" | tee "$WORK/result.txt"
if grep -q '^FAIL' "$WORK/result.txt"; then exit 1; fi
if [ -s "$SWP_E2E_WP/wp-content/debug.log" ]; then
  echo "PHP debug log is not empty:" >&2
  cat "$SWP_E2E_WP/wp-content/debug.log" >&2
  exit 1
fi
