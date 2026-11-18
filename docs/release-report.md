# Abnahmebericht Stand 1.0-Kandidat

Ergebnisse der Pflichtprüfungen (lokal, 2026-11-18). Nichts davon ist die praktische Abnahme durch Stefan.

| Prüfung | Ergebnis |
| --- | --- |
| PHP Unit (PHP 8.3 und 8.2) | 300 Tests grün |
| WordPress-Suite WP 6.8 (Einzelsite, Multisite; PHP 8.3 und 8.2) | 175 Tests grün |
| WordPress-Suite WP 6.5.14 (Mindestversion) | 175 Tests grün |
| PHPStan Level 8 (ohne Baseline, ohne Ignores), PHPCS | ohne Befund |
| Vitest, Typecheck, ESLint, Prettier | 379 Tests grün |
| Coverage PHP / JS/TS (je > 80 % verlangt) | 96,9 % / 94,2 % |
| Bundle-Budgets (Admin und Frontend-Module) | eingehalten, siehe [performance.md](performance.md) |
| Übersetzungen (de_DE) | vollständig |
| Browser-E2E, Block-Theme Twenty Twenty-Five | 93 Prüfungen, kein FAIL |
| Browser-E2E, klassisches Minimal-Theme | 93 Prüfungen, kein FAIL |
| Browser-E2E aus dem Release-ZIP (ohne Entwicklungs-`vendor/`) | 84 Prüfungen (Stand vor dem axe-Scan), kein FAIL |
| axe-core über alle ausgelieferten Layouts (WCAG 2.x A/AA, Best Practice) | keine Verstöße außer `heading-order`, das bewusst nicht geprüft wird |
| Sicherheits-Regressionstests (`SecurityReviewTest`) | grün |
| Signaturkette mit Wegwerfschlüssel (`tests/release/signing-test.sh`) | 6 von 6 |
| Leistung mit 2.000 Einträgen | siehe [performance.md](performance.md) |

## Befunde dieser Runde (behoben)
- Mehrfach eingebundene Ansichten erzeugten mehrere gleich benannte Landmarks (`Pagination`, `Filter`): jetzt `Pagination 2`, `Filter 2` usw.
- Quellen ohne `WP_Post` konnten keine Platzhalterwerte liefern: jetzt über `Entry::data`.
- Der Build des ZIPs packt nur versionierte Dateien. Solange das Repository nichts außer dem ersten Konzept enthält, wäre das ZIP leer; der Test lief deshalb auf einer temporär committeten Kopie.

## Nicht geprüft oder offen
- **Echte Signatur:** Stefan setzt Schlüssel und Verfahren nachträglich selbst um (Entscheidung vom 2026-11-18); Commit und Build aus versionierten Dateien ebenfalls. Die Skripte erwarten `SWP_SIGN_KEY` (GPG); ein anderes Verfahren (minisign, Sigstore) wäre ein kleiner Austausch in `bin/sign-zip.sh` und `bin/verify-zip.sh`.
- **CI und SonarQube:** Die Workflows in `.github/` liefen nie, es wurde nichts gepusht; SonarCloud kennt das Projekt `sw-wordpress_sw-postlist` nicht (HTTP 404). Es gibt daher keine CI- oder Sonar-Befunde.
- **WordPress 6.9 oder neuer und PHP 8.4:** nicht verfügbar, nicht geprüft.
- **Echte Touch-Geräte, Screenreader (NVDA, VoiceOver), der Hover-Zustand des Hover-Rasters** (das headless Chromium meldet keine Hover-Fähigkeit) und **der Block im echten Block-Editor.**
- **Cache-Plugins:** Das Verhalten ist durch das Design abgesichert (öffentliche Route ohne Nonce, `Cache-Control: no-cache`, Filter für eine kurze `max-age`), aber mit keinem konkreten Cache-Plugin getestet.
- Die ADR-01 bis ADR-11 sind von Stefan noch nicht bestätigt.

## Oberfläche der Verwaltung
Die Shell der Admin-App folgt jetzt sw-backupit und sw-cloudcalendar: seitliche Rail mit Logo und Versionsnummer, einklappbar, Hell/Dunkel-Umschalter, Skip-Link, Live-Region, Design-Tokens (Farben, Radien, Schatten), lokal ausgelieferte Inter-Schrift. Die Tabs im Ansichts-Editor blieben bewusst unverändert. Nicht übernommen wurden die Komponenten `PageHeader`, `SwbiCard` und `StatTile`: die Seiten erhalten deren Aussehen über die gemeinsamen Klassen im Stylesheet.
