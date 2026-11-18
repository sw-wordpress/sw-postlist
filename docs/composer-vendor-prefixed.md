# Composer-Abhängigkeiten und Mozart-Isolierung

Stand M0: `composer.json` verlangt zur Laufzeit nur `php` und `ext-*`. Es gibt **keine** Laufzeitpakete, die isoliert werden müssten. Das Release-ZIP enthält daher nur Composers Autoloader (`vendor/composer`, `vendor/autoload.php`) ohne Entwicklungspakete.

Die Mozart-Konfiguration (`extra.mozart`, Namespace `SW\Postlist\Dependencies\`, Präfixe `SWPostlist_`/`swpostlist_`) steht bereits in `composer.json`. Sobald ein Laufzeitpaket hinzukommt:

1. `bin/build-zip.sh` bricht absichtlich ab, solange Laufzeitpakete installiert sind, aber der Mozart-Schritt fehlt.
2. Mozart-Lauf (Docker-Image `coenjacobs/mozart`) und `bin/prefix-sources.php` aus sw-cloudcalendar (`wp-content/plugins/sw-cloudcalendar/bin/prefix-sources.php`) auf die Postlist-Pfade anpassen und in `bin/build-zip.sh` aktivieren.
3. Smoke-Test (`tests/release/smoke.php`) um eine Prüfung ergänzen, dass kein unpräfixierter Namespace des Pakets geladen wird.

`sw-postlist.php` lädt zuerst `vendor-prefixed/autoload.php` und fällt auf `vendor/autoload.php` zurück; die Plugin-Klassen werden immer per PSR-4 auf `src/` abgebildet.
