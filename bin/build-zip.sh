#!/usr/bin/env bash
# Builds an installable WordPress plugin ZIP: production Composer packages + admin bundle.
# Usage: bin/build-zip.sh [output-dir]   (needs composer and node/npm on the BUILD machine only)
# SWP_SKIP_ADMIN_BUILD=1 reuses assets/admin, but only when it is newer than every file in apps/admin/src.
# Runtime dependencies are isolated with Mozart (docs/composer-vendor-prefixed.md) as soon as composer.json requires any
# package beyond php/ext-*; the plugin currently ships none, so only Composer's autoloader is packaged.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE:?}"' EXIT
NAME=sw-postlist

# Reject ambiguous identity/version before building anything.
VERSION="$(php -r '
$main = file_get_contents($argv[1]);
if (!preg_match("/^[ \t*]*Version:\s*([0-9]+(?:\.[0-9]+){2,3})\s*$/m", $main, $header)
    || !preg_match("/define\\( ".chr(39)."SW_POSTLIST_VERSION".chr(39).", ".chr(39)."([0-9.]+)".chr(39)." \\);/", $main, $constant)
    || $header[1] !== $constant[1]) {
    fwrite(STDERR, "Plugin header and version constant must match.\n"); exit(1);
}
echo $header[1];
' "$ROOT/sw-postlist.php")"

if [ "${SWP_SKIP_ADMIN_BUILD:-0}" = "1" ] && [ -f "$ROOT/assets/admin/app.js" ] \
  && [ -z "$(find "$ROOT/apps/admin/src" "$ROOT/apps/admin/package-lock.json" -newer "$ROOT/assets/admin/app.js" -print -quit)" ]; then
  echo ">> reusing admin bundle (SWP_SKIP_ADMIN_BUILD=1, assets are up to date)"
else
  echo ">> building admin bundle"
  (cd "$ROOT/apps/admin" && npm ci && npm run build)
fi

echo ">> assembling plugin tree"
mkdir -p "$STAGE/$NAME"
# Untracked, not ignored files (local notes, experiments) are never shipped.
UNTRACKED="$STAGE/untracked.txt"
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git -C "$ROOT" ls-files --others --exclude-standard | grep -v '^assets/admin/' | sed 's|^|/|' > "$UNTRACKED" || true
else
  : > "$UNTRACKED"
fi
rsync -a --exclude-from="$UNTRACKED" --exclude-from=- "$ROOT/" "$STAGE/$NAME/" <<'EXCLUDES'
.git
.gitignore
.editorconfig
.phpunit.cache
/.phpstan.cache
.phpcs*.cache
/vendor
/vendor-prefixed
/.github
/node_modules
/apps
/tests
/coverage
/.scannerwork
/sonar-project.properties
/dist
/build
/bin/test-db.sh
/CODE-STYLE.md
/AGENTS.md
/TODO.md
/PLAN.md
/Makefile
phpunit.xml.dist
*.d.ts
/phpstan.neon*
phpcs*.xml.dist
composer.lock
EXCLUDES

echo ">> installing production dependencies"
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/$NAME/"
(cd "$STAGE/$NAME" && composer validate --strict && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts)
php "$ROOT/tests/release/smoke.php" "$STAGE/$NAME"

if [ -n "$(composer --working-dir="$STAGE/$NAME" show --no-dev --name-only 2>/dev/null)" ]; then
  echo "Runtime packages are installed but the Mozart isolation step is not configured yet (docs/composer-vendor-prefixed.md)." >&2
  exit 1
fi
rm -f "${STAGE:?}/${NAME:?}/composer.json" "${STAGE:?}/${NAME:?}/composer.lock"
rm -rf "${STAGE:?}/${NAME:?}/bin"
php "$ROOT/tests/release/smoke.php" "$STAGE/$NAME"

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
rm -f "${OUT:?}/${NAME:?}-${VERSION:?}.zip"
(cd "$STAGE" && zip -qr "$OUT/$NAME-$VERSION.zip" "$NAME")
echo ">> $OUT/$NAME-$VERSION.zip"
