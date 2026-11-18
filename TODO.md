# SW Postlist – Aufgabenliste

Grundlage ist [PLAN.md](PLAN.md), Konzeptversion 0.3 vom 18.11.2026. M0 bis M9 führen zu Version 1.0. M10 folgt nach dem Release und eigener Abstimmung. Eine Aufgabe wird abgehakt, sobald ihr Ergebnis durch Code, Testlauf, dokumentierte Entscheidung oder die vorgesehene persönliche Abnahme belegt ist.

**Für jeden Meilenstein gilt:** Den lauffähigen Stand prüfen, die zugehörige Dokumentation aktualisieren und beim Abschluss den Nachweis der Pflichtprüfungen aus §9 des Plans festhalten. Nicht ausführbare Prüfungen mit Grund als ungeprüft ausweisen.

## M0 – Projektfundament

### Entscheidungen und Projektstruktur

- [ ] Slug `sw-postlist`, Namespace `SW\Postlist`, Textdomain und Präfixe `sw_postlist_` und `swp-` prüfen. WordPress 6.5 als Minimum hinterlegen und die vorgeschlagene PHP-Untergrenze 8.2 im ADR festlegen.
- [ ] Das Speichermodell in `docs/decisions.md` festlegen. Die internen CPTs `swp_view` und `swp_template` anhand von Revisionen, Berechtigungen, Referenzabfragen und Import mit eigenen Tabellen vergleichen.
- [x] [Code-Stil](CODE-STYLE.md) auf Postlist zuschneiden und die Regeln für WordPress-PHP-Stil, `snake_case` und PSR-4 beibehalten.
- [ ] `AGENTS.md` mit Regeln für Cache-Busting, Prüfnachweise und PR-Review-Follow-up anlegen.
- [ ] Plugin-Bootstrap, Autoloading und Schichten für Domain, Application, Infrastructure, Presentation und Support einrichten. Ports unter `Application/Port` ablegen und die Dienste in `Services.php` verdrahten.
- [ ] Aktivierung und Deinstallation umsetzen. An einer Testinstallation mit fremden Daten nachweisen, dass die Bereinigung nur Postlist-Daten erfasst.
- [ ] `apps/admin` mit Vue 3, Quasar ohne Quasar CLI, Vite, TypeScript, Pinia, Hash-Router, vue-i18n und Vitest aufsetzen. Laufzeit-Assets lokal ausliefern.

### Prüfungen, Build und CI

- [ ] Die Referenzdateien aus §9 auf Eignung prüfen. PHPCS/WPCS mit Security-Sniffs, PHPCompatibilityWP und PHPStan Level 8 ohne Baseline oder Ignores für alle eigenen PHP-Bereiche konfigurieren.
- [ ] PHPUnit für Unit- und Integrationstests sowie die WordPress-Suite mit privater MariaDB und `bin/test-db.sh` einrichten. Die lokalen Prüfungen in `composer check` und `make check` bündeln.
- [ ] Typecheck, ESLint, Prettier, Vitest und die Prüfung der deutschen und englischen Übersetzungen mit `bin/i18n.sh check` einrichten.
- [ ] Getrennte Coverage-Gates für PHP und JS/TS einrichten. PHP-Berichte zusammenführen, Block und Frontend einbeziehen und prüfen, dass genau 80 Prozent sowie fehlende produktive Quellpfade zum Fehlschlag führen.
- [ ] Wegwerf-WordPress und Playwright in Docker für Browser-E2E einrichten. Gemeinsame PHP-/TS-Fixtures, Bundle-Größenprüfung und eine reproduzierbare Performance-Messung für Postlist vorbereiten.
- [ ] CI für Tests, Main und Release konfigurieren. Coverage an SonarQube Cloud übermitteln. Tägliche Sicherheitsaudits mit `composer audit --locked --no-dev` und `npm audit` sowie Dependabot einrichten.
- [ ] Composer-Abhängigkeiten mit Mozart isolieren. Quellpräfixe, ZIP-Build, Release-Manifest, Signaturprüfung und Installations-Smoke-Test auf die tatsächlichen Pluginpfade anpassen.
- [ ] **Abnahme M0.** `make check` auf dem Plugin-Gerüst ausführen, ZIP bauen und das gebaute Plugin ohne Entwicklungs-`vendor/` laden.

## M1 – Datenmodell, Eintragstypen und Abfragen

- [ ] Vor Umsetzung den Zählvertrag aus §4 festhalten. Für jede Quelle definieren, ob sie eine exakte sichtbare Gesamtzahl oder eine geprüfte Fortsetzung für Weiter/Zurück liefert.
- [ ] Ansichts- und Template-Dokumente versionieren. JSON-Schema, TypeScript-Typen, serverseitige Validierung, gemeinsame Fixtures und Migrationsrahmen erstellen. Für die Ansicht genau eine Quellen-ID zulassen.
- [ ] Persistenz gemäß M0-ADR umsetzen. Beim CPT-Modell interne Typen von öffentlicher Suche und Quellenliste ausschließen, Dokumente in revisionsfähigem Meta speichern und Objektberechtigungen prüfen.
- [ ] Container- und Eintragstemplate über lokale IDs zuordnen. Verwendung abfragbar halten, Beziehungen bei Wiederherstellung abgleichen und verwendete Templates auch im WordPress-Admin vor Papierkorb und endgültigem Löschen schützen.
- [ ] Auf WordPress 6.5 das Speichern und Wiederherstellen eines Templates mit HTML, Anführungszeichen und Backslashes prüfen.
- [ ] `EntrySourceInterface` und Registry implementieren. `sw_postlist_entry_sources` auf gültiges Interface, eindeutige ID und Schema prüfen. Fehlerhafte Registrierungen in der Diagnose sichtbar machen.
- [ ] `PostTypeEntrySource` für Beiträge, Seiten und öffentlich abfragbare CPTs bereitstellen. Unterstützte Taxonomien, Platzhalter, Filterfelder und Sortierungen pro Quelle melden.
- [ ] Taxonomien, Autoren, Datum, Metawerte, Ein-/Ausschluss von IDs, Anzahl, Startoffset und Sortierung über WordPress-APIs umsetzen. Numerische und textuelle Metasortierung sowie die ID als zweiten Sortierschlüssel testen.
- [ ] Kontextabfragen für gleiche Kategorie, Tags und Autor sowie für Archiv und Suche aus den typisierten Ursprungsdaten erzeugen.
- [ ] `sw_postlist_query_args` und `sw_postlist_query_args_{view_id}` vor der Auswahl anbinden. Besucherfilter mit den festen Kriterien schneiden und anschließend gewählten Typ, `publish` und `has_password=false` durchsetzen.
- [ ] `sw_postlist_entries` für Darstellungsdaten anbinden. IDs, Treffermenge und Reihenfolge kontrollieren. Bei einer Verletzung von `can_view` einen neutralen Fehler ohne Zahl ausgeben und die Quelle protokollieren.
- [ ] Seitenoffset und `view.count` gemäß §4 berechnen, auch bei einer angeforderten leeren Seite. Bei Quellen ohne exakte Zahl nur eine geprüfte Fortsetzung liefern und `view.count` ungesetzt lassen.
- [ ] Query-Parameter mit ungültigen Werten, mehreren Typen, `post_type=any` und versuchtem Typwechsel durch Hooks testen. Öffentliche Ergebnisse für Gäste und angemeldete Besucher sowie den öffentlichen Datenbestand der Vorschau prüfen.
- [ ] **Abnahme M1.** Sechs veröffentlichte Treffer plus Entwurf, privater und passwortgeschützter Beitrag. Bei Startoffset 1 und Seitengröße 2 müssen fünf sichtbare Treffer auf Seiten mit 2/2/1 Einträgen erscheinen. Besucherfilter und Query-Hook jeweils auf konsistente Zahl und Auswahl testen.

## M2 – Template-Engine, Formatter und AST-Cache

### Sprache und Adapter

- [ ] Lexer, Parser, AST, Validator und Renderer unter `src/TemplateEngine/` implementieren. Variablen, normale und invertierte Abschnitte sowie ungültige Syntax mit Fixtures abdecken.
- [ ] Ports für Platzhalterschema, Wertauflösung, Formatter, Escaping, AST-Cache, Uhr und Locale definieren. Architekturtest für die Unabhängigkeit der Engine von WordPress und den Postlist-Fachklassen schreiben.
- [ ] `EngineProfile` für erlaubte Elemente und Attribute, Klassenpräfix, Formatter und Engine-Version einführen. CSS auf den Template-Bereich begrenzen und Text, Attribut, URL, Style und HTML-Komponente kontextgerecht behandeln.
- [ ] Formatter-Ketten mit Literalparametern und typisierten Ein-/Ausgaben kompilieren. Unbekannte Formatter, unzulässige Parameter und inkompatible Verkettungen beim Speichern melden.
- [ ] Datums-, Zeit- und Zahlenformatter aus §5 mit Site-Zeitzone und Locale umsetzen. `date`, `relative_date`, `number` und `currency` mit festen Uhrzeiten und Beispielwerten prüfen.
- [ ] `truncate`, `words`, `strip_tags`, `nl2br`, `upper`, `lower`, `capitalize`, `default`, `join`, `link`, `esc_url` und `image` mit ihren Ausgabekontexten implementieren. Bei Bildern WordPress-Größe, `srcset` und `sizes` verwenden.
- [ ] WordPress-Adapter und Registries für `sw_postlist_placeholders` und `sw_postlist_formatters` anbinden. Für die `entry.*`- und `view.*`-Platzhalter aus §5 Typ, Beschreibung, Beispiel und zulässige Kontexte hinterlegen. Meta auf freigegebene Schlüssel beschränken.
- [ ] `entry.content` für Version 1.0 bereinigt bereitstellen. Einen Beitrag mit eingebettetem Postlist-Block und -Shortcode auf begrenzte Ausgabe ohne rekursive Ausführung prüfen.

### Invalidierung und Nachweise

- [ ] Transient-Cache für ASTs implementieren. Schlüssel aus Template-Inhalt, CSS, Engine-Version, Profil, Quellenschema einschließlich Meta-Freigaben, Formatter-Signaturen und den betroffenen Cache-Generationen bilden.
- [ ] `wp_after_insert_post` und `deleted_post` an die Quelle binden. Beim Typwechsel alte und neue Quelle berücksichtigen. Revisionen und Autosaves überspringen. Den AST beim nächsten Rendern neu kompilieren.
- [ ] Relevante Meta- und Termänderungen über `added_post_meta`, `updated_post_meta`, `deleted_post_meta`, `set_object_terms` und `deleted_term_relationships` erfassen. Eine Template-Veröffentlichung für alle verwendenden Quellen invalidieren.
- [ ] `sw_postlist_invalidate_template_ast` für Integrationen und `sw_postlist_ast_invalidation_scope` für Inhaltsänderungen implementieren. Bei erneuter CPT-/Taxonomie-Registrierung den wirksamen Schema-Fingerabdruck vergleichen.
- [ ] Mit gefülltem Cache Beitrag und CPT über `wp_update_post()` ändern. Gezielte Neukompilierung, Cache-Hit bei gleicher Registrierung, aktuellen Titel bei abgeschalteter Inhaltsinvalidierung, entfernten Formatter und beschädigten Cache-Eintrag prüfen.
- [ ] PHP und TypeScript gegen dieselben JSON-Vertragsfälle unter `tests/contract/template-engine/` prüfen. CloudCalendar-Compilerfälle mit deaktivierten Formattern ergänzen. XSS-, Typketten- und Fuzz-Tests für Platzhalter, Meta, Formatter und CSS ausführen.
- [ ] Editor-Hilfen unter `apps/admin/src/template-engine/` isolieren. Die öffentliche Engine-API, Versionierungsregeln und `@since` in `docs/template-engine.md` dokumentieren.
- [ ] **Abnahme M2.** Architekturtest, gemeinsame Vertragsfälle, WordPress-Hook-Tests und Cache-Tests ausführen. Die Engine-Version bei Änderungen am AST oder seinem Verhalten berücksichtigen.

## M3 – Server-Rendering, Grid, Liste, Block und Shortcode

- [ ] Vor Anbindung den Shortcode-Namen und einen möglichen Alias aus §12 entscheiden. Den Vertrag für die Übergabe des Ursprungskontexts aus §4 dokumentieren.
- [ ] Einen PHP-Renderer für Container und Einträge bauen. Block, Shortcode sowie geschützte REST-Vorschau anbinden. Die Ausgabe über `sw_postlist_before_render`, `sw_postlist_render_html` und `sw_postlist_after_render` erweiterbar machen.
- [ ] Grid und Liste als Container-Templates umsetzen. Globale Layoutoptionen, `theme.json`-Paletten, stabile CSS-Klassen, Responsive Images sowie Spalten, Abstände, Sichtbarkeit, Seitenverhältnis und Schriftgrößen je Gerätebreite anwenden.
- [ ] Dynamischen Gutenberg-Block ohne Build-Schritt und Shortcode für eine gespeicherte Ansicht bereitstellen. Freigegebene Overrides über dasselbe Schema validieren.
- [ ] Ursprung vor der Plugin-Abfrage aus der WordPress-Hauptabfrage lesen. Je Modus Beitrags-, Term-/Taxonomie- oder Autor-ID bzw. begrenzten Suchtext mit Ansichts-ID und Instanzschlüssel für REST signieren.
- [ ] Beim Abruf Bindung und Signatur prüfen, Ursprung erneut auf Sichtbarkeit prüfen und abgeleitete Kriterien neu ermitteln. Bei fehlendem Pflichtursprung die kontextabhängige Auswahl beenden.
- [ ] Klassische Pagination und Ausgabe ohne Pagination implementieren. Pro Einbindung einen reproduzierbaren oder expliziten Instanzschlüssel verwenden. GET-Links und Formulare erhalten die Parameter der anderen Einbindungen.
- [ ] **Abnahme M3.** Grid und Liste über Block und Shortcode ausgeben. Zwei gleiche Ansichten auf einem Archiv ohne JavaScript getrennt blättern und per Direktlink laden. Die M1-Trefferzahlen über das Rendering und die REST-Vorschau vergleichen.

## M4 – Admin-SPA und gemeinsame Templates

- [ ] Admin-Navigation für Dashboard, Ansichten, Templates, Presets, Import/Export, Einstellungen und Diagnose anlegen. Gemeinsame UI-Komponenten aus den Referenzen auf Eignung prüfen und de/en lokalisieren.
- [ ] Ansichtseditor für Allgemeines, Quelle und Query, Layout und Design, Template/Karte, Filter, Pagination und Vorschau umsetzen. Je Quelle nur unterstützte Auswahlfelder und Navigationsmodi anbieten.
- [ ] Private Admin-REST-Routen für Laden, Speichern, Validieren und Vorschau mit Capability-, Objekt- und Nonce-Prüfungen absichern. Bei Veröffentlichung einer Ansicht die Template-Referenzen prüfen.
- [ ] CodeMirror-Editor mit Platzhalter-Panel bauen. Typ, Beschreibung und Live-Beispiel anzeigen, Einfügen per Klick und typkompatible Formatter-Vervollständigung mit Parameterhilfe unterstützen.
- [ ] Live-Validierung für Syntax, Allowlist, Formatter und CSS anzeigen. Vorschau mit echten öffentlichen Einträgen für Desktop, Tablet und Mobil bereitstellen.
- [ ] „Veröffentlichen“ bzw. „Aktualisieren“ als einzelnen Speichervorgang implementieren. Bei erfolgreicher Validierung die gemeinsam referenzierte Template-Fassung sofort verwenden. Bei Fehlern die gültige öffentliche Fassung erhalten.
- [ ] Zwei Ansichten mit gemeinsamem Template und eine dritte mit eigenem Template rendern. Bei gefülltem Cache einmal veröffentlichen und die Ausgaben in Block, Shortcode und REST prüfen.
- [ ] **Abnahme M4.** Vitest und Browser-E2E für Adminseiten und Editoren ohne Konsolenfehler ausführen. Stefans Abnahme für Bedienung und Layout festhalten.

## M5 – Visueller Builder und Presets

- [ ] Builder für Bild, Titel, Excerpt, Datum, Autor, Taxonomie, Custom Field, Weiterlesen, Freitext, Container und Abstandshalter einschließlich Verschachtelung und Einstellungen aus Spec §3.3 umsetzen.
- [ ] Builder-Ausgabe als dasselbe Template und CSS im Code-Tab öffnen. Rückübernahme für Builder-Strukturen und den Erhalt frei bearbeiteten Codes testen.
- [ ] Mitgelieferte Presets als JSON in `templates/presets/` anlegen. Benutzerdefinierte Presets und `sw_postlist_presets` mit Schemavalidierung anbinden.
- [ ] Vorschau, Anwenden, Überschreiben, Duplizieren, Zurücksetzen und Export von Presets im Admin bereitstellen.
- [ ] **Abnahme M5.** Eine Karte mit verschachteltem Container aus einem Preset erstellen, anpassen und die gespeicherte Ausgabe aus Builder und Code-Tab vergleichen.

## M6 – Masonry, Hover Grid und Slider

- [ ] Masonry mit eigenem bedarfsgeladenem Modul und responsivem Container umsetzen.
- [ ] Hover Grid mit bedienbaren Inhalten und Berücksichtigung von `prefers-reduced-motion` umsetzen.
- [ ] Slider/Carousel mit Tastatur, Touch, Autoplay und Pause umsetzen. Reduzierte Bewegung berücksichtigen.
- [ ] `sw_postlist_layouts` für eigene Layouts mit Schema und Render-Callback anbinden. Ein gültiges und ein fehlerhaftes Drittanbieter-Layout im Vertragstest prüfen.
- [ ] **Abnahme M6.** Layouts auf den vorgesehenen Gerätebreiten prüfen. Bundle-Budget einhalten und nachweisen, dass Seiten nur die für ihre Layouts benötigten Module laden.

## M7 – Besucherfilter und dynamische Pagination

- [ ] Kategorie, Tag, weitere Taxonomien, Autor, Suche und Sortierung als Buttons, Dropdowns, Checkboxen oder Suchfeld anbieten. `sw_postlist_filter_types` mit Schema und Diagnose anbinden.
- [ ] Öffentliches REST-Nachladen an die Query- und Renderpfade aus M1/M3 anbinden. Nur sichtbare Treffer und freigegebene Filter zulassen. Nonce- und Cache-Verhalten dokumentieren.
- [ ] AJAX-Pagination, „Mehr laden“ und Infinite Scroll mit den Fähigkeiten der Quelle verbinden. Servernavigation als bedienbaren Rückfall erhalten und das Filtermodul nur bei Bedarf laden.
- [ ] Ladezustand, Fehler, leere Trefferliste und zugängliche Statusmeldungen umsetzen. Bei Filterwechsel nur die betroffene Instanz auf Seite eins setzen.
- [ ] Filter und Seitenstand mit der URL synchronisieren. Direktlinks sowie Browser-Zurück mit zwei gleichen Einbindungen auf einer Archivseite prüfen.
- [ ] **Abnahme M7.** M1-Zählfall über öffentliches HTML und REST wiederholen. Filter, Query-Hook, Quelle ohne Gesamtzahl, manipulierten Kontext und nach dem ersten Rendern entzogene Sichtbarkeit testen.

## M8 – Wiederverwendung, Import/Export und Dokumentation

- [ ] JSON-Export für Ansichten, Templates und Presets implementieren. Jedes verwendete Template einmal aufnehmen und Referenzen mit paketlokalen Schlüsseln speichern.
- [ ] Import vor dem Anlegen auf Größe, Schema, Version, Quellen-ID und vollständige Referenzen prüfen. Unterstützte ältere Dokumente migrieren und inkompatible Dokumente verständlich melden.
- [ ] Neue lokale IDs vergeben und Template-Beziehungen neu zuordnen. Erst bei vollständigen Referenzen veröffentlichen. Namenskollisionen, fehlerhafte Teilimporte und Roundtrip auf einer zweiten Installation testen.
- [ ] Ansicht duplizieren sowie Konfiguration kopieren und zurücksetzen. Gemeinsame Template-Referenzen nach Duplizieren, Import und Wiederherstellung prüfen.
- [ ] `docs/hooks.md`, `docs/templates.md` und `docs/rest-api.md` fertigstellen. Öffentliche Hooks, Datentypen, Fehler, Formatter, Layoutklassen, Berechtigungen und Cache-Regeln anhand der tatsächlich verfügbaren Schnittstellen beschreiben.
- [ ] **Abnahme M8.** Beispiel-Erweiterungsplugin mit eigener Quelle und eigenem Formatter als Vertragstest ausführen. Erweiterungen zusammen mit eigenen Layouts auf Schemafehler, Diagnose und kompatible Hooks prüfen.

## M9 – Stabilisierung und Release 1.0

- [ ] Postlist-Abfragen und Rendering mit repräsentativer Datenmenge messen, etwa 2.000 Einträgen. Ergebnisse, Bundle-Größen und bedarfsgeladene Assets dokumentieren.
- [ ] WordPress-/PHP-Matrix einschließlich WordPress 6.5 mit klassischem Theme und Block-Theme prüfen. Mehrfachinstanzen, Cache-Plugin-Verhalten und lokale Auslieferung aller Laufzeit-Assets testen.
- [ ] Barrierefreiheit für Tastatur, Touch, Fokus, Statusmeldungen, reduzierte Bewegung und die Navigation ohne JavaScript über die ausgelieferten Layouts prüfen.
- [ ] Import-, REST- und Template-Pfade auf Rechte, Sichtbarkeit, Eingabevalidierung, kontextgerechte Ausgabe und CSS-Begrenzung prüfen. Die Sicherheits- und Erweiterungstests aus §9 auf dem Release-Stand ausführen.
- [ ] Release-ZIP mit isolierten Abhängigkeiten bauen, signieren und verifizieren. Aus dem ZIP ohne Entwicklungs-`vendor/` installieren und Block, Shortcode sowie Admin als Smoke-Test aufrufen.
- [ ] **Abnahme M9.** Ergebnisse der Pflichtprüfungen, Coverage beider Sprachen und CI-/SonarQube-Befunde für 1.0 dokumentieren. Freigabe durch Stefan einholen.

## M10 – Template-Engine nach Version 1.0 auslagern

- [ ] Auslagerung abstimmen sowie Name und Repository-Ort der Pakete festlegen.
- [ ] PHP-Engine als Composer-Paket und Editor-Hilfen als npm-Paket extrahieren. Öffentliche API, Semver, Profile und gemeinsame Vertrags-Fixtures übernehmen. Eigene CI mit den Prüfungen aus §9 einrichten.
- [ ] Postlist mit den Paketversionen integrieren. Mozart-Isolierung bei gleichzeitig aktiven Plugins mit unterschiedlichen Engine-Versionen testen.
- [ ] CloudCalendar-Migration als eigenes Vorhaben abstimmen. Profil mit deaktivierten Formattern verwenden und bestehende Templates, Cache-Schlüssel und Ausgaben gegen die Kompatibilitätsfälle prüfen.
- [ ] **Abnahme M10.** Vertrags-Fixtures der beteiligten Plugins ausführen und die Einbindung weiterer sw-Plugins über eigene Schemas und Profile dokumentieren.
