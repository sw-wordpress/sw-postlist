#!/usr/bin/env bash
# Verifies a release ZIP: signature of the checksum file (against the trusted public key in the keyring, optionally
# pinned to SWP_SIGN_FINGERPRINT), checksum of the ZIP and its contents (no development files, version matches).
# Usage: [SWP_SIGN_FINGERPRINT=<fingerprint>] bin/verify-zip.sh dist/sw-postlist-1.0.0.zip
set -euo pipefail
ZIP="${1:?usage: verify-zip.sh <zip>}"
DIR="$(cd "$(dirname "$ZIP")" && pwd)"
FILE="$(basename "$ZIP")"
for part in "$FILE" "$FILE.sha256" "$FILE.sha256.asc"; do
  [ -f "$DIR/$part" ] || { echo "missing $part" >&2; exit 1; }
done
STATUS="$(gpg --batch --status-fd 1 --verify "$DIR/$FILE.sha256.asc" "$DIR/$FILE.sha256" 2>/dev/null)" \
  || { echo "signature verification failed" >&2; exit 1; }
echo "$STATUS" | grep -q '^\[GNUPG:\] VALIDSIG' || { echo "no valid signature" >&2; exit 1; }
if [ -n "${SWP_SIGN_FINGERPRINT:-}" ]; then
  echo "$STATUS" | grep '^\[GNUPG:\] VALIDSIG' | grep -qi "${SWP_SIGN_FINGERPRINT}" || { echo "signed by another key" >&2; exit 1; }
fi
(cd "$DIR" && sha256sum --check --strict "$FILE.sha256") >/dev/null || { echo "checksum mismatch" >&2; exit 1; }
LIST="$(unzip -Z1 "$DIR/$FILE")"
for forbidden in '/tests/' '/apps/' '/node_modules/' '/.git/' 'composer.json' 'phpunit' 'AGENTS.md' '/bin/'; do
  if echo "$LIST" | grep -q "$forbidden"; then echo "ZIP contains development file: $forbidden" >&2; exit 1; fi
done
for required in 'sw-postlist/sw-postlist.php' 'sw-postlist/assets/admin/app.js' 'sw-postlist/vendor/autoload.php' 'sw-postlist/assets/js/dynamic.js' 'sw-postlist/uninstall.php'; do
  echo "$LIST" | grep -qx "$required" || { echo "ZIP lacks $required" >&2; exit 1; }
done
echo ">> $FILE verified"
