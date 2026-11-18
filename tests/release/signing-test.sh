#!/usr/bin/env bash
# Proves the signing chain with a throwaway key: sign, verify, and reject tampering, a wrong key and dev files.
# Usage: tests/release/signing-test.sh <release.zip>   (never touches the real keyring: uses a temporary GNUPGHOME)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="${1:?usage: signing-test.sh <zip>}"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK:?}"' EXIT
export GNUPGHOME="$WORK/gnupg"
mkdir -m 700 "$GNUPGHOME"
gpg --batch --quiet --passphrase '' --quick-generate-key 'Release Test <release-test@example.invalid>' ed25519 sign never 2>/dev/null
FPR="$(gpg --batch --list-keys --with-colons | awk -F: '/^fpr/ {print $10; exit}')"
gpg --batch --quiet --passphrase '' --quick-generate-key 'Other <other@example.invalid>' ed25519 sign never 2>/dev/null
OTHER="$(gpg --batch --list-keys --with-colons | awk -F: '/^fpr/ {print $10}' | tail -1)"
cp "$SRC" "$WORK/plugin.zip"
fail() { echo "FAIL: $1" >&2; exit 1; }
SWP_SIGN_KEY="$FPR" "$ROOT/bin/sign-zip.sh" "$WORK/plugin.zip" >/dev/null
SWP_SIGN_FINGERPRINT="$FPR" "$ROOT/bin/verify-zip.sh" "$WORK/plugin.zip" >/dev/null || fail "a good release must verify"
echo "PASS: signed release verifies against its key"
SWP_SIGN_FINGERPRINT="$OTHER" "$ROOT/bin/verify-zip.sh" "$WORK/plugin.zip" >/dev/null 2>&1 && fail "wrong pinned key accepted"
echo "PASS: a release signed by another key is rejected when the key is pinned"
cp "$WORK/plugin.zip" "$WORK/good.zip"
printf 'x' >> "$WORK/plugin.zip"
"$ROOT/bin/verify-zip.sh" "$WORK/plugin.zip" >/dev/null 2>&1 && fail "modified ZIP accepted"
echo "PASS: a modified ZIP is rejected"
cp "$WORK/good.zip" "$WORK/plugin.zip"
echo tampered >> "$WORK/plugin.zip.sha256"
"$ROOT/bin/verify-zip.sh" "$WORK/plugin.zip" >/dev/null 2>&1 && fail "modified checksum accepted"
echo "PASS: a modified checksum file is rejected"
cp "$WORK/good.zip" "$WORK/plugin.zip"
sed -i 's/^\(.\{40\}\)./\1A/' "$WORK/plugin.zip.sha256.asc"
"$ROOT/bin/verify-zip.sh" "$WORK/plugin.zip" >/dev/null 2>&1 && fail "damaged signature accepted"
echo "PASS: a damaged signature is rejected"
rm "$WORK/plugin.zip.sha256.asc"
"$ROOT/bin/verify-zip.sh" "$WORK/plugin.zip" >/dev/null 2>&1 && fail "unsigned release accepted"
echo "PASS: an unsigned release is rejected"
