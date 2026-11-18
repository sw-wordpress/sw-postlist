# Architekturentscheidungen (ADR)

Entscheidungen aus M0 der [TODO.md](../TODO.md). Grundlage ist [PLAN.md](../PLAN.md) §6 und §12. Die Einträge ADR-01 bis ADR-03 folgen den Vorschlägen des Plans. Sie sind **Arbeitsstand und bis zur Bestätigung durch Stefan nicht abgenommen** (PLAN.md §12 Punkt 2 und 5).

| ID | Thema | Entscheidung |
| --- | --- | --- |
| ADR-01 | Namen | Slug und Textdomain `sw-postlist`, Namespace `SW\Postlist`, Optionen/Hooks/Transients `sw_postlist_`, Konstanten `SW_POSTLIST_`, CSS `swp-` / `--swp-`. Geprüft gegen die vorhandenen Plugins in `wp-content/plugins/` (kein Slug-, Namespace- oder Präfix-Konflikt). |
| ADR-02 | Speichermodell | Zwei interne CPTs `swp_view` und `swp_template` (siehe unten). |
| ADR-03 | Mindestversionen | WordPress 6.5, PHP 8.2 (wie sw-cloudcalendar). `composer.json` pinnt die Plattform auf 8.2.0, PHPCompatibilityWP prüft `8.2-`. |
| ADR-06 | Capabilities | Die internen Post Types behalten die typspezifischen Standard-Capabilities (`edit_swp_templates` …). Wer `manage_options` (filterbar über `sw_postlist_manage_capability`) besitzt, bekommt sie über `user_has_cap`. Alle Meta-Capabilities auf eine gemeinsame Capability abzubilden war falsch: WordPress merkt sich pro Primitiv die Meta-Capability (`$post_type_meta_caps`) und hätte `manage_options` seitenweit in `delete_post` verwandelt (im Integrationstest aufgefallen). |
| ADR-07 | Template-Engine | Eigene Implementierung nach dem Vorbild von sw-cloudcalendar (Lexer, Parser, Validator, AST-Bauer, Renderer statt eines einzigen Kompilierdurchlaufs), damit die Stufen einzeln testbar sind. `link` liefert direkt HTML (`List → Html`), `join` Text; ein eigener Typ für Linklisten entfiel. Statische Entitäten werden nur aus einer festen Menge dekodiert und neu escaped, damit PHP und TypeScript identische Ausgabe erzeugen. Steuerzeichen werden im Renderer unabhängig vom Escaper entfernt. URLs mit Leerzeichen, Anführungszeichen, spitzen Klammern oder Backslash gelten als unsicher. Details: `docs/template-engine.md`. |
| ADR-08 | Shortcode und Kontextübergabe (M3) | Shortcode `[sw_postlist id="…"]`; ein Alias `[content_layout]` wird nicht registriert (PLAN.md §12 Punkt 3, offen für Bestätigung durch Stefan; ein Alias ist später additiv möglich). Attribute `id`, `key`, `layout`, `columns` (`3` oder `4,3,2` für Desktop, Tablet, Mobil), `per_page`, `orderby`, `order`, `pagination` sind Overrides: sie werden in das gespeicherte Dokument gemischt und vom selben Schema validiert; nicht freigegebene Schlüssel (`source`, `templates`, Auswahlkriterien) lehnt der Renderer mit `override_not_allowed` ab. Beim ersten Rendern liest PHP den Ursprung aus der Hauptabfrage (`MainQueryOriginReader`: Beitrag, Term, Autor oder Suchtext, Länge begrenzt). Bei Ansichten mit Kontextauswahl trägt der Wrapper `data-swp-token` (seit M7, vorher `data-swp-origin`) = HMAC-signierter, an Ansichts-ID und Instanzschlüssel gebundener Token (`OriginSigner`, Schlüssel `wp_salt('sw_postlist_origin')`). Beim Abruf (öffentliche REST-Route in M7) werden Signatur und Bindung geprüft, die Sichtbarkeit des Ursprungs erneut geprüft und Kategorien/Autor aus dessen ID neu abgeleitet; ein fehlender oder nicht öffentlicher Ursprung beendet die Kontextauswahl (leere, exakte Auswahl). Die Signatur sichert nur die Integrität, Berechtigungen werden bei jedem Abruf geprüft. |
| ADR-09 | Instanzschlüssel und Pagination (M3) | Jede Einbindung erhält einen reproduzierbaren Schlüssel: expliziter `key` (`[a-z0-9_-]{1,32}`) oder `view-{id}-{Vorkommen}` in Reihenfolge der Seite. Zustand in der URL: `swp[<key>][page]=N` (Seite 1 ohne Parameter). Links sind relative Query-Strings und behalten alle anderen Parameter samt Zustand anderer Einbindungen; ohne JavaScript funktionsfähig. Quellen ohne exakte Gesamtzahl erhalten nur Zurück/Weiter, „Weiter“ nur bei geprüfter Fortsetzung. |
| ADR-10 | Layouts und Frontend-Module (M6) | Registry mit Optionsschema, bedarfsgeladene Module, Budgets (siehe unten). |
| ADR-11 | Besucherfilter und dynamisches Nachladen (M7) | Filter-Dokumentabschnitt, GET-Formular, öffentliche Route ohne Nonce, signiertes Token (siehe unten). |
| ADR-04 | PHP-Stil | WordPress-Stil für sämtlichen eigenen PHP-Code. Dokumentierte Ausnahmen: PSR-4-PascalCase-Dateinamen, typisierte Signaturen statt redundanter Docblocks (`phpcs.xml.dist`). |

## ADR-02 Speichermodell: CPT statt eigener Tabellen

**Entscheidung:** `swp_view` und `swp_template` als nicht öffentliche CPTs. Das schema-versionierte Dokument liegt in einem registrierten Post-Meta-Feld mit Revisionsunterstützung. Referenzen Ansicht → Template werden zusätzlich als abfragbares Meta gepflegt (`_swp_template_refs`).

| Kriterium | CPT | Eigene Tabellen |
| --- | --- | --- |
| Revisionen | WordPress-Revisionen für Meta (ab 6.4 verfügbar, daher Minimum 6.5) | Selbst zu bauen |
| Berechtigungen | Objektbezogene Capabilities über `map_meta_cap` | Selbst zu bauen |
| Papierkorb/Wiederherstellung | Vorhanden, muss für verwendete Templates geschützt werden | Selbst zu bauen |
| Referenzabfragen („wo verwendet?“) | Über Meta-Query, bei sehr vielen Ansichten langsamer | Index, schnell |
| Atomare Mehrdokument-Änderungen | Nicht transaktional | Möglich |
| Import | `wp_insert_post` | Eigene Insert-Logik |

Das Mengengerüst (wenige redaktionelle Dokumente) spricht für CPT. Die Schwächen werden abgefedert: Schutz vor Löschen über `wp_trash_post`/`before_delete_post`-Prüfung, Importe validieren vor dem ersten Schreiben und legen erst bei vollständigen Referenzen als veröffentlicht an. **Revisionsentscheidung (M1, belegt):** Das Dokument liegt im registrierten Meta-Feld `_swp_document` mit `revisions_enabled`. Speichern und Wiederherstellen eines Templates mit HTML, Anführungszeichen, Backslashes und Unicode ist auf WordPress 6.5.14 und 6.8 im Integrationstest nachgewiesen (`PersistenceTest`). Dazu wird der JSON-String vor `update_post_meta()` mit `wp_slash()` behandelt. Der Rückfall auf `post_content` ist nicht nötig.

**Template-Verwendung:** Pro Ansicht eine Meta-Zeile `_swp_uses_template` je referenziertem Template. Löschschutz über `pre_trash_post`/`pre_delete_post` (gilt für alle WordPress-Wege), Abgleich bei `untrashed_post` und `wp_restore_post_revision`.

## ADR-05 Zählvertrag (M1, PLAN.md §4)

Vor der Umsetzung festgehalten. Der Vorschlag aus dem Plan gilt unverändert:

- Jede Quelle meldet in `query_fields()['count_mode']` entweder `exact` (sichtbare Gesamtzahl) oder `continuation` (nur geprüfte Fortsetzung für Weiter/Zurück).
- Sichtbarkeit, feste Auswahl der Ansicht und Besucherfilter wirken **vor** Zählung und Seitenauswahl gemeinsam. Besucherfilter sind eine Schnittmenge mit den festen Kriterien.
- `exact`: `total = max(0, sichtbare Treffer − Startoffset)`, Abfrageoffset = `Startoffset + (Seite − 1) × Seitengröße`, zweiter Sortierschlüssel ist die ID. Bei leerer angeforderter Seite wird die Gesamtzahl gesondert ermittelt.
- `continuation`: `EntryResult::total` bleibt `null`, `has_more` ist geprüft (ein weiterer sichtbarer Treffer wurde gelesen). Der Editor erlaubt nur passende Navigationsmodi.
- Hooks: `sw_postlist_query_args` schränkt nur vorab ein; der WordPress-Adapter erzwingt danach Post Type, `publish` und `has_password=false`. `sw_postlist_entries` darf nur Darstellungsdaten ändern; Identität, Mitgliedschaft und Reihenfolge werden geprüft, `can_view` ist die letzte Instanz. Bei Verstoß: neutraler Fehler ohne Zahl, Protokoll.
- Auch angemeldete Besucher erhalten auf öffentlichen Routen dieselbe Auswahl; die Admin-Vorschau nutzt zunächst denselben öffentlichen Datenbestand.


## ADR-10 Layouts und Frontend-Module (M6)

**Entscheidung:** Layouts sind `LayoutDefinition`-Objekte in einer `LayoutRegistry` (Application-Schicht, ohne WordPress-Abhängigkeit). Der Validator prüft Layoutoptionen gegen das Schema der Definition. Das TypeScript-Gegenstück (`apps/admin/src/documents/layouts.ts`) spiegelt die eingebauten Schemas und läuft gegen dieselben Vertragsfixtures; Layouts von Drittanbietern werden im Browser nur als bekannte ID akzeptiert und serverseitig vollständig validiert.

- Masonry und Slider sind progressive Erweiterungen: ohne JavaScript bleibt ein responsives Raster bzw. ein nativer Scroll-Snap-Container (Wischen und Scrollen funktionieren).
- Module laden nur bei Bedarf (`FrontendAssets::enqueue_module`), je Modul ein eigenes Skript, kein gemeinsames Frontend-Bundle und kein Build-Schritt. Budgets in `bin/check-bundles.mjs`: `masonry.js` 2 KiB, `slider.js` 4 KiB, `frontend.css` 6 KiB (gzip).
- Der Slider berücksichtigt `prefers-reduced-motion` (kein Autoplay, kein weiches Scrollen), pausiert bei Hover, Fokus und verborgenem Tab und bietet bei Autoplay eine Pause-Schaltfläche.
- Bedienelemente des Moduls werden über `window.SWPostlist.i18n` übersetzt (inline vor dem Skript).


## ADR-11 Besucherfilter und dynamisches Nachladen (M7)

Zur Bestätigung durch Stefan (wie ADR-01 bis ADR-10).

**Entscheidung:**

- **Dokument.** Die Ansicht erhält den optionalen Abschnitt `filters` (Liste, höchstens sechs Einträge, je `type` plus Optionen nach dem Schema des Filtertyps). Er wird nur geschrieben, wenn er genutzt wird; Schemaversion 1 bleibt, bestehende Dokumente und Exporte ändern sich nicht. Filtertypen sind `FilterTypeDefinition`-Objekte in einer `FilterTypeRegistry` (`sw_postlist_filter_types`); Optionsschemas nutzen dasselbe Vokabular wie Layouts (`OptionSchema`).
- **Zustand in der URL.** `swp[{Instanz}][f][{Parameter}]` neben `swp[{Instanz}][page]`. Die Eingabe liest ausschließlich `VisitorInputParser` und nur für konfigurierte Filter; Seitenaufruf, Formular ohne JavaScript und öffentliche Route verwenden denselben Code.
- **Ohne JavaScript.** Filter sind ein GET-Formular (Schaltflächen, Auswahllisten, Kontrollkästchen, Suchfeld) mit versteckten Feldern für fremde Parameter und andere Instanzen. Das Formular enthält keine Seitenzahl: ein Filterwechsel setzt nur die eigene Instanz auf Seite 1.
- **Öffentliche Route.** `GET /views/{id}/render` ohne Nonce (lesend, öffentliche Daten) mit `Cache-Control: no-cache`; Details und Begründung in `docs/rest-api.md`. Das signierte `data-swp-token` bindet Overrides und Ursprung an Ansicht und Instanz; es wurde aus `data-swp-origin` verallgemeinert.
- **Modul.** `assets/js/dynamic.js` wird nur für Ansichten mit Filtern oder Pagination AJAX/Mehr laden/Endlos geladen. Die Adressleiste ist die Quelle der Wahrheit: der neue Zustand einer Instanz wird in die aktuelle URL eingesetzt, damit veraltete Links oder Felder andere Instanzen nicht zurücksetzen. Filter und AJAX-Seitenwechsel erzeugen Verlaufseinträge (Zurück/Vor lädt aus der URL); „Mehr laden“ und Endlos-Scroll ändern die URL nicht.
- **Zugänglichkeit.** Statusmeldungen über `role="status"` (Laden, Anzahl, leer), Fehler als `role="alert"` mit „Erneut versuchen“, `aria-busy`, Fokus auf die Liste nach AJAX-Seitenwechsel, Fokus und Cursor im Suchfeld bleiben beim Nachladen erhalten, die Seitenlinks bleiben als Rückfall (bei Endlos-Scroll zusätzlich die Schaltfläche).
- **Grenzen in 1.0.** Mehr laden und Endlos-Scroll hängen Einträge an und sind für Raster, Liste, Masonry und Hover-Raster gedacht, nicht für den Slider. Autoren- und Begriffslisten sind auf 100 Einträge begrenzt.
