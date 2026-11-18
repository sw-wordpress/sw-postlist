# Release-Ablauf

1. Alle Prüfungen laufen lassen (siehe [testing.md](testing.md)): `make check`, `composer test:wp65`, `composer test:coverage`, `bin/i18n.sh check`, Browser-E2E (Block- und klassisches Theme), `composer test:performance`.
2. Version im Plugin-Header und in `SW_POSTLIST_VERSION` setzen (der Build bricht bei Abweichung ab).
3. Die Änderungen committen: `bin/build-zip.sh` packt nur versionierte Dateien. Unversionierte Dateien kommen nie ins ZIP.
4. ZIP bauen: `bin/build-zip.sh dist` (Admin-Bundle, Produktions-Abhängigkeiten, Smoke-Test des Pakets, keine Entwicklungsdateien).
5. Signieren: `SWP_SIGN_KEY=<Schlüssel> bin/sign-zip.sh dist/sw-postlist-<version>.zip` erzeugt `.sha256` und die abgetrennte GPG-Signatur `.sha256.asc`. Der Schlüssel liegt beim Release-Verantwortlichen, nie im Repository.
6. Verifizieren: `SWP_SIGN_FINGERPRINT=<Fingerabdruck> bin/verify-zip.sh dist/sw-postlist-<version>.zip` prüft Signatur (gegen den festgelegten Schlüssel), Prüfsumme und Inhalt.
7. Installationstest aus dem ZIP: `SWP_E2E_ZIP=dist/sw-postlist-<version>.zip tests/E2E/with-browser.sh tests/E2E/run.sh <Ordner>` installiert das Plugin ohne Entwicklungs-`vendor/` in ein frisches WordPress und führt die Browser-Tests aus (Block, Shortcode, Admin).

`tests/release/signing-test.sh <zip>` beweist die Kette mit einem Wegwerfschlüssel (gültig, falscher Schlüssel, geändertes ZIP, geänderte Prüfsumme, beschädigte Signatur, fehlende Signatur).
