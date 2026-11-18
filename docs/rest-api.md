# REST-API

Namespace `sw-postlist/v1`. Stand M7: öffentliche Nachlade-Route, Vorschau und private Admin-Routen.

| Route | Methode | Rechte | Zweck |
| --- | --- | --- | --- |
| `/views` | GET | `edit_posts` | Veröffentlichte Ansichten als `[{id, title}]` für die Blockauswahl. Mehr verrät die Route nicht. |
| `/views/{id}/preview` | GET | Verwaltungs-Capability (`manage_options`, filterbar über `sw_postlist_manage_capability`) | Vorschau einer gespeicherten Ansicht (auch Entwurf) mit dem öffentlichen Datenbestand. Parameter `page` (1–10000), `key` (`[a-z0-9_-]{1,32}`). Antwort `{html, css, total, has_more, page, count, error}`; `total` ist die sichtbare Gesamtzahl nach Startoffset oder `null`. Header `Cache-Control: private, no-store`. Fehler: `404` mit `error` (`view_not_found`, `source_missing`, `template_invalid`, `override_*`, `entries_contract_violation`, `visibility_violation`), `401`/`403` ohne Berechtigung, `400` bei ungültigen Parametern. |

## Öffentliche Nachlade-Route (M7)

| Route | Methode | Rechte | Zweck |
| --- | --- | --- | --- |
| `/views/{id}/render` | GET | keine (öffentlich) | Rendert eine **veröffentlichte** Ansicht für einen URL-Zustand neu: Filter, AJAX-Pagination, „Mehr laden“, Endlos-Scroll und Browser-Zurück. Parameter: `key` (Pflicht, `[a-z0-9_-]{1,32}`, Instanzschlüssel), `query` (Query-String der einbettenden Seite, höchstens 2000 Zeichen, enthält `swp[key][page]` und `swp[key][f][...]`), `token` (optional, höchstens 1000 Zeichen, das signierte `data-swp-token` des Wrappers). Antwort `{html, total, has_more, page, count, error}`; `html` ist der vollständige Wrapper `.swp-view` wie beim Seitenaufruf (ohne `<style>`). Fehler: `404` mit `error` (`view_not_found` für unbekannte, nicht veröffentlichte und gelöschte Ansichten, sonst wie bei der Vorschau), `400` bei ungültigen Parametern. |

Sicherheits- und Cache-Verhalten:

- **Derselbe Renderpfad.** Die Route ruft `ViewRenderer` mit demselben `query`-Zustand auf, den die Seite verwendet. Sichtbarkeit (veröffentlicht, ohne Passwort, `can_view` der Quelle), feste Auswahl und Zählung gelten unverändert (siehe `tests/wp/tests/PublicRestTest.php`). Angemeldete Besucher, auch Verwalter, erhalten dieselbe öffentliche Auswahl; Entwürfe liefert nur die Vorschau-Route.
- **Nur freigegebene Eingaben.** Filterwerte werden nur gelesen, wenn die Ansicht den Filter konfiguriert und die Quelle ihn unterstützt (`VisitorInputParser`): Zahlen und Texte sind begrenzt, Sortierfelder müssen angeboten sein, Werte außerhalb einer konfigurierten Begriffsliste werden verworfen. Filter bilden eine Schnittmenge, sie können nie mehr Inhalte sichtbar machen. Overrides von Shortcode oder Block kann ein Besucher nicht frei setzen.
- **Token.** Der Wrapper trägt `data-swp-token` (HMAC mit `wp_salt('sw_postlist_origin')`, gebunden an Ansichts-ID und Instanzschlüssel). Er enthält die Overrides der Einbettung und bei Kontextansichten den Ursprung. Er sichert nur die Integrität; Berechtigungen und die heutige Sichtbarkeit des Ursprungs werden bei jedem Abruf neu geprüft. Fehlt das Token oder ist es ungültig, gilt die gespeicherte Ansicht ohne Overrides; Kontextansichten zeigen dann eine leere Auswahl.
- **Kein Nonce.** Die Route ist lesend und liefert öffentliche Daten. Ein Nonce würde nur Seiten-Caches brechen und schützt hier nichts.
- **Cache.** Antwort-Header `Cache-Control: no-cache`: Browser und Proxys dürfen speichern, müssen aber vor Wiederverwendung prüfen, damit nachträglich entzogene Inhalte nicht weiterleben. Betreiber mit Seiten-Cache können über den Filter `sw_postlist_public_cache_control` eine kurze `max-age` erlauben und tragen dann die Verzögerung selbst. Es gibt keine serverseitige Zwischenspeicherung von Trefferlisten.
- **Missbrauch.** Es gibt keine eigene Ratenbegrenzung; Eingaben sind in Größe und Anzahl begrenzt, jede Abfrage nutzt die indexierten WordPress-Abfragen. Ratenbegrenzung gehört in Webserver oder WAF.

Die Vorschau nutzt dieselbe PHP-Renderfunktion wie Block und Shortcode (`ViewRenderer`); Zählung und Auswahl sind identisch (siehe `tests/wp/tests/RenderingTest.php`). CSS wird getrennt in `css` geliefert, das `html` enthält kein `<style>`.

## Private Admin-Routen (M4)

Alle Routen verlangen die Verwaltungs-Capability und die WordPress-Cookie-Authentifizierung mit `X-WP-Nonce` (`wp_rest`); ohne gültigen Nonce behandelt WordPress die Anfrage als Gast, die Berechtigungsprüfung scheitert (CSRF-Schutz). Routen mit `{id}` prüfen zusätzlich die Objekt-Capability (`edit_post`) des Dokuments; ein fehlendes Dokument ergibt `404`. Antworten tragen `Cache-Control: private, no-store`. Fehlerformat: `{code, message, …}`; Validierungsfehler `422` mit `errors: [{code, path, message}]`.

| Route | Methode | Zweck |
| --- | --- | --- |
| `/admin/views` | GET | Ansichten (Entwürfe und veröffentlichte): `{views: [{id, title, status, source, layout, modified}]}` |
| `/admin/views` | POST | Anlegen: `{document, status}` (`publish`/`draft`), `201` mit `{id, status, document}` |
| `/admin/views/{id}` | GET, PUT, DELETE | Laden, Speichern (gleiches Format wie POST), in den Papierkorb |
| `/admin/views/validate` | POST | `{valid, errors}` für ein Ansichtsdokument |
| `/admin/views/preview` | POST | Vorschau eines **ungespeicherten** Dokuments: `{document, page?, container_template?, entry_template?}` (die Templates als `{html, css}`) → `{html, css, total, has_more, page, count, error}` |
| `/admin/templates` | GET, POST | Liste mit `used_by`; Anlegen: `{document, source}` |
| `/admin/templates/{id}` | GET, PUT, DELETE | Laden (mit `used_by`: Ansichts-IDs), Aktualisieren, Papierkorb (`409 template_in_use`, solange Ansichten es verwenden) |
| `/admin/templates/validate` | POST | `{document, source}` → `{valid, errors: [{code, message, line, column, context}]}`; der Titel ist hier nicht erforderlich |
| `/admin/templates/preview` | POST | `{document, source}` → `{html, css, count, error}` mit drei echten öffentlichen Einträgen |
| `/admin/presets` | GET, POST | Presets (mitgeliefert, Erweiterungen, eigene) mit `origin`, `overrides`, `can_reset`; POST speichert ein eigenes Preset (gleiche ID überschreibt das Original) |
| `/admin/presets/{id}` | DELETE | Eigenes Preset bzw. Überschreibung löschen (Zurücksetzen auf das Original); `409` für rein mitgelieferte, `404` für unbekannte |
| `/admin/presets/{id}/duplicate` | POST | `{id, title}` → eigene Kopie (`409 preset_id_taken`) |
| `/admin/presets/{id}/apply` | POST | `{source}` → legt Container- und Eintrags-Template an (beide müssen für die Quelle kompilieren, sonst `422` und nichts wird geschrieben): `{container_template, entry_template, layout}` |
| `/admin/sources` | GET | Verfügbare Quellen mit `fields` (Taxonomien, Sortierungen, `count_mode`) |
| `/admin/placeholders` | GET | `?source=&role=` → Platzhalter (Typ, Beschreibung, Beispiel) und typisierte Formatter-Signaturen für das Editor-Panel |
| `/admin/diagnostics` | GET | `{registrations, log}`: fehlerhafte Erweiterungs-Registrierungen und das begrenzte Protokoll |
| `/admin/layouts` | GET | `{layouts}`: registrierte Layouts mit `id`, `label`, `module` und Optionsschema (`schema`) |
| `/admin/filter-types` | GET | `{filter_types}`: registrierte Besucherfilter-Typen mit `id`, `label`, `builtin` und Optionsschema (`schema`) |
| `/admin/export` | POST | `{views: [id], templates: [id], presets: [slug]}` → `{package}`. Von Ansichten genutzte Templates kommen einmal mit, Referenzen sind paketlokale Schlüssel (`t1`, `t2` …). `404` mit `missing`, wenn etwas nicht (mehr) existiert, `422` bei leerer Auswahl. Paketformat: `format` = `sw-postlist-package`, `format_version` 1, `plugin_version`, `exported_at`, `templates` (Schlüssel → Dokument), `views` (`key`, `status`, `document`), `presets`. |
| `/admin/import` | POST | `{package, dry_run}` → Bericht `{ok, dry_run, errors, counts, created, renamed}`. Alles wird vor dem ersten Schreiben geprüft (Größe höchstens 2 MB → sonst `413`, höchstens 100 Ansichten/200 Templates/50 Presets, Formatversion, Dokumente samt Migration, Quellen-ID, Referenzen und Rollen, Template-Sprache gegen die Quelle). Fehler: `422` mit `errors[{code, path, message}]`. Bei Erfolg entstehen neue lokale IDs; Ansichten werden zuerst als Entwurf angelegt und erst veröffentlicht, wenn alle Templates existieren; schlägt etwas fehl, wird alles Angelegte wieder in den Papierkorb verschoben bzw. entfernt (`import_failed`). Vorhandene Titel erhalten ` (2)`, ` (3)` …, Preset-IDs `-2`, `-3` …; nichts wird überschrieben. |
| `/admin/views/{id}/duplicate` | POST | Legt eine Kopie als Entwurf an (`201`, `{id, status, document}`); die Template-Referenzen bleiben gemeinsam (eine Änderung wirkt in beiden Ansichten). |
| `/admin/templates/{id}/duplicate` | POST | Legt eine unabhängige Kopie des Templates an (`201`, `{id, document}`). |
| `/admin/settings` | GET, PUT | `{meta_keys: {source: [key]}, maps: {name: {hide: [text], labels: {von: nach}, strip_prefix: text}}}`. Meta-Keys und Maps werden bereinigt (Typen, Längen, höchstens 30 Maps mit je 100 Einträgen). Ohne `maps` im Körper bleiben die gespeicherten Maps unverändert. Eine Änderung invalidiert kompilierte Templates. |

**Veröffentlichen als ein Schritt:** `POST`/`PUT` eines Templates validiert gegen die gewählte Quelle **und** gegen die Quellen aller Ansichten, die es verwenden, und schreibt erst dann. Bei Fehlern (`422 template_invalid`, `sources: {source: [errors]}`) bleibt die letzte gültige Fassung öffentlich. Eine Ansicht prüft beim Veröffentlichen, dass jedes Template existiert, zur Rolle passt und für ihre Quelle kompiliert (`422 template_references_invalid`); Entwürfe dürfen unfertige Templates referenzieren.
