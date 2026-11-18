#!/usr/bin/env bash
# Signs a release ZIP: SHA-256 checksum file plus a detached, ASCII-armored GPG signature of that file.
# Usage: SWP_SIGN_KEY=<key id or fingerprint> bin/sign-zip.sh dist/sw-postlist-1.0.0.zip
# The key stays with the release manager (never in the repository); GNUPGHOME may point to a dedicated keyring.
# Output next to the ZIP: <zip>.sha256 and <zip>.sha256.asc
set -euo pipefail
ZIP="${1:?usage: sign-zip.sh <zip>}"
KEY="${SWP_SIGN_KEY:?set SWP_SIGN_KEY to the signing key (id or fingerprint)}"
[ -f "$ZIP" ] || { echo "no such file: $ZIP" >&2; exit 1; }
DIR="$(cd "$(dirname "$ZIP")" && pwd)"
FILE="$(basename "$ZIP")"
(cd "$DIR" && sha256sum "$FILE" > "$FILE.sha256")
rm -f "$DIR/$FILE.sha256.asc"
gpg --batch --yes --local-user "$KEY" --armor --detach-sign --output "$DIR/$FILE.sha256.asc" "$DIR/$FILE.sha256"
echo ">> $DIR/$FILE.sha256 and $FILE.sha256.asc"
