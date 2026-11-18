# Leistung und ausgelieferte Assets

Messung mit `composer test:performance` (WordPress 6.8, PHP 8.3, MariaDB lokal, 2.000 veröffentlichte Beiträge mit Kategorien und Meta). Die Zeiten sind Millisekunden einer einzelnen Ausführung auf einer Entwicklungsmaschine, kein Lastversuch; das Budget des Tests liegt bei einer Sekunde je Abfrage. Die Rohdaten schreibt der Test nach `coverage/performance.json`.

| Fall | ms |
| --- | --- |
| erste Seite | 3,6 |
| tiefe Seite (100) | 3,7 |
| leere Seite hinter dem Ende | 3,5 |
| feste Kategorieauswahl | 2,9 |
| Besucherfilter Kategorie | 2,9 |
| Besuchersuche | 3,7 |
| Sortierung nach numerischem Meta | 10,6 |
| vollständiges Rendern mit Filterformular (kalt) | 22,2 |
| vollständiges Rendern mit Filterformular (warm, AST im Cache) | 2,5 |

Die Meta-Sortierung läuft über eine korrelierte Unterabfrage statt einer ODER-Meta-Abfrage (zuvor 2,1 s). Es gibt keine eigenen Tabellen und keinen serverseitigen Cache für Trefferlisten; kompilierte Templates werden zwischengespeichert (AST).

## Gelieferte Assets (gzip)

Die Admin-App lädt nur im Backend. Im Frontend gilt: **eine Seite lädt nur die Module ihrer Layouts**.

| Datei | Größe | Wird geladen, wenn die Seite … |
| --- | --- | --- |
| `assets/css/frontend.css` | 2,4 KiB | mindestens eine Ansicht rendert |
| `assets/js/masonry.js` | 1,1 KiB | Masonry nutzt |
| `assets/js/slider.js` | 2,1 KiB | den Slider nutzt |
| `assets/js/dynamic.js` | 4,1 KiB | Filter oder AJAX-, „Mehr laden“-, Endlos-Pagination nutzt |
| Raster, Liste, Hover-Raster, klassische Pagination | kein Skript | |

Budgets prüft `node bin/check-bundles.mjs`; der Browser-Test belegt je Seite, welche Skripte geladen werden. Alle Assets liegen im Plugin (keine CDN-, keine Webfont-Verweise), Skripte tragen die Plugin-Version als Cache-Buster und laden mit `defer` im Footer.
