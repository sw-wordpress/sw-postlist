# Tests und Prüfungen

| Prüfung | Befehl | Hinweise |
| --- | --- | --- |
| PHP-Stil (WPCS, Security-Sniffs, PHPCompatibilityWP) | `composer cs` | `phpcs.xml.dist`, Ausnahmen dokumentiert (PSR-4-Dateinamen, typisierte Signaturen) |
| PHPStan Level 8, ohne Baseline/Ignores | `composer stan` | `phpstan.neon.dist` |
| Unit- und Integrationstests (ohne WordPress) | `composer test` | PHPUnit 11 |
| WordPress-Suite (Einzelsite + Multisite) | `bin/test-db.sh start && composer test:wp` | private MariaDB auf Port 33079, nie die Entwicklungsdatenbank |
| WordPress 6.5 (Mindestversion) | `COMPOSER=composer-65.json composer install --working-dir=tests/wp && composer test:wp65` | `tests/wp/composer-65.json`, Abhängigkeiten in `tests/wp/vendor-65` |
| PHP-Coverage-Gate (> 80 %, exakt 80 % scheitert) | `composer test:coverage` | führt Unit, WP und Multisite aus, `tests/coverage/{merge,check}.php` |
| Admin: Typecheck, ESLint, Prettier, Vitest, JS/TS-Coverage-Gate | `cd apps/admin && npm run check` | `scripts/check-coverage.mjs` (> 80 %, fehlende Quellpfade scheitern) |
| Übersetzungen | `bin/i18n.sh check` | de/en der SPA liegen in `apps/admin/src/i18n` (Paritätstest) |
| ZIP-Build und Smoke-Test | `bin/build-zip.sh [dir]` | nur committete Dateien werden eingepackt |
| Bundle-Budgets (Admin-App und Frontend-Module) | `node bin/check-bundles.mjs` (Teil von `npm run check`) | gzip: `app.js` 120, `app.css` 60, Editor-Chunk 200, übrige Chunks 150, `masonry.js` 2, `slider.js` 4, `dynamic.js` 6, `frontend.css` 8 KiB |
| Browser-E2E (Playwright in Docker, wegwerfbares WordPress, MariaDB 33079) | `tests/E2E/with-browser.sh tests/E2E/run.sh <Arbeitsordner>` | Admin-SPA, Layouts auf drei Gerätebreiten, Filter und Pagination mit und ohne JavaScript, Ausgabe `PASS`/`FAIL` und Screenshots |
| Barrierefreiheit (axe-core über die ausgelieferten Layouts) | Teil des Browser-E2E | `heading-order` wird nicht geprüft: die Überschriftsebene der Einträge ist eine Template-Entscheidung der einbettenden Seite |
| Theme-Matrix | `SWP_E2E_THEME=classic tests/E2E/with-browser.sh tests/E2E/run.sh <Ordner>` | klassisches Minimal-Theme (`tests/Fixtures/classic-theme`) gegenüber dem Block-Theme Twenty Twenty-Five |
| Release-ZIP signieren, prüfen, installieren | siehe [release.md](release.md) | `tests/release/signing-test.sh <zip>` |
| Performance-Messung | `composer test:performance` | 2.000 Einträge |
| Alles lokal | `make check` | ohne E2E |

PHP-Matrix: die Unit- und WP-Suiten laufen unter PHP 8.2 (Minimum) und 8.3; `php8.2 vendor/bin/phpunit` bzw. `XDEBUG_MODE=off php8.2 vendor/bin/phpunit -c phpunit.xml.dist` in `tests/wp`.
