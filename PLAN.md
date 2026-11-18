# PLAN.md – SW Postlist

Stand: 18.11.2026 · Konzeptversion: 0.3 · Status: Entwurf zur Abstimmung

## 1. Ziel und Verbindlichkeit

**SW Postlist** (Slug `sw-postlist`, Namespace `SW\Postlist`, Textdomain `sw-postlist`, Präfixe `sw_postlist_*` / CSS `swp-`) gibt WordPress-Inhalte (Beiträge, Seiten, Custom Post Types und per Filter registrierbare weitere Eintragstypen) in frei definierbaren **Ansichten** aus. Eine Ansicht kombiniert Auswahl (Query), Darstellung (Layout) und **Templates** mit Platzhaltern und Formattern.

Konzeptionell, technisch und im Admin-Layout orientiert sich das Plugin an **sw-cloudcalendar** (Views + Templates, Mustache-ähnliche Template-Sprache, Vue-3-/Quasar-Admin-SPA, Block ohne Build-Schritt, Shortcode). Für eigenen PHP-Code gelten WordPress-Stil und `snake_case` für Funktionen, Methoden, Parameter, Eigenschaften und Variablen. Klassen, Interfaces und PSR-4-Dateien folgen der PascalCase-Konvention aus [CODE-STYLE.md](CODE-STYLE.md). Die Richtlinie regelt die Codequalität. Für Produktumfang und Abnahme gilt dieser Plan. Mindestversion für WordPress ist 6.5. Weitere Vorgaben: [AGENTS.md](AGENTS.md) (analog sw-cloudcalendar anlegen).

Quellen: die Anforderungsspezifikation „WordPress Content Layouts“ (Layouttypen, Query Builder, Filter, Pagination, Presets, Sicherheit, Tests) sowie die Ergänzungen des Auftraggebers (Filter-Auswahl der Einträge, Eintragstypen per Filter, Platzhalter-Panel und Formatter).

## 2. Grundsätze

- **Ein Dokumentmodell, ein Renderer:** Eine Ansicht ist ein versioniertes, validierbares Konfigurationsdokument (Schema-Version + Migrationen). Admin-Vorschau, Block, Shortcode und REST nutzen dieselbe PHP-Renderfunktion.
- **Templates sind Daten, kein Code:** Kein PHP/JS in Templates; Validierung beim Speichern, Kompilierung zu AST (Details Abschnitt 5, Referenz: sw-cloudcalendar `docs/templates.md`).
- **Erweiterbar über Filter:** Eintragstypen, Platzhalter, Formatter, Layouts, Presets, Filtertypen (Abschnitt 8).
- **Sicher und öffentlich nur Öffentliches:** Keine Ausgabe nicht öffentlicher Inhalte ohne Berechtigung; Capability-/Nonce-Prüfung; validierte Query-Parameter.
- **Performance/A11y:** Assets nur bei Bedarf, Responsive Images, `prefers-reduced-motion`, ohne JS sinnvolle serverseitige Navigation.
- **Keine externen Laufzeit-Abhängigkeiten** (keine CDN, keine Webfonts).

## 3. Funktionsumfang (Zusammenfassung)

- **Eintragstypen („Entry Sources“)**: eingebaut `post`, `page` und alle öffentlich abfragbaren CPTs (automatisch erkannt). Weitere Typen (z. B. Termine, Benutzer, Terms, externe Daten) registrierbar über Filter (Abschnitt 4).
- **Layouts:** Grid, List, Masonry, Hover Grid, Slider/Carousel. Presets sind reine Konfigurationsvarianten (Abschnitt 7).
- **Query Builder:** Pro Ansicht und Abfrage genau eine Entry Source, bei WordPress-Inhalten genau ein Post Type. Auswahlkriterien sind Taxonomien, Autoren, Datum, Ein-/Ausschluss, Anzahl, Offset, Sortierung (inkl. Meta numerisch/Text), Kontextabfragen (gleiche Kategorie/Tags/Autor, Archiv, Suche). Alles über WP-APIs.
- **Frontend-Filter:** Kategorien, Tags, Taxonomien, Autor, Suche, Sortierung; Buttons/Dropdown/Checkboxen/Suchfeld; REST-Nachladen, Ladezustand, Fehler, Empty State, URL-Sync.
- **Pagination:** klassisch (server-seitig, auch ohne JS), AJAX, Load More, Infinite Scroll, keine.
- **Design:** globale Layoutoptionen, `theme.json`/Paletten, stabile dokumentierte CSS-Klassen, Template-CSS auf die Ansicht begrenzt.
- **Wiederverwendung:** Ansichten speichern/duplizieren, Shortcode `[sw_postlist id="…"]`, Block, JSON-Import/-Export (Schema-Version, Validierung, Migration), Preset-Bibliothek.
- **Responsive:** Desktop/Tablet/Mobile (Spalten, Abstände, Sichtbarkeit, Seitenverhältnis, Schriftgrößen) mit Vorschau je Gerätebreite.
- **Nicht enthalten:** Spec §12 (Frontend-Erstellung von Beiträgen, Login, KI, Events, Shop, Zahlung, Theme-Builder, CPT-Verwaltung).

## 4. Eintragstypen und Auswahl per Filter

Ein **Entry Source** beschreibt, woher Einträge kommen und wie sie in Platzhalter-Werte übersetzt werden:

```php
interface EntrySourceInterface {
    public function id(): string;                    // z. B. "post:event"
    public function label(): string;
    public function query_fields(): array;            // unterstützte Query-Parameter/Sortierungen (Schema)
    public function query( QueryArgs $args ): EntryResult; // Seitenergebnis der gewählten Quelle
    public function placeholders(): array;           // eingehängte Platzhalter dieses Typs
    public function can_view( Entry $entry, ?int $user_id ): bool;  // Sichtbarkeitsprüfung
}
```

- Eingebaut: `PostTypeEntrySource` (eine Quelle je Typ: post, page und öffentlich abfragbare CPTs; page wird ausdrücklich berücksichtigt).
- Registrierung: `apply_filters( 'sw_postlist_entry_sources', array $sources )`. Dritte können eigene Typen ergänzen; Registry validiert Interface, ID-Eindeutigkeit und Schema.
- **Auswahl der ausgegebenen Einträge per Filter:** Das Schema speichert genau eine Quellen-ID. Editor, Import und REST validieren sie. Mehrfachauswahl, `post_type=any` und Typwechsel durch Besucherparameter oder Query-Hooks sind ausgeschlossen. Die Auswahl innerhalb der Quelle ist ein Query-Filtersatz (Taxonomien, Autoren, Datum, Meta, Einzel-IDs …). Zusätzlich greifen Hooks:
  - `sw_postlist_query_args` (Args vor Ausführung, je Entry Source; Beispiele in `docs/hooks.md`),
  - `sw_postlist_entries` (vorgeschlagener Vertrag für die Ergebnisaufbereitung siehe unten),
  - Ansichtsspezifische Variante `sw_postlist_query_args_{view_id}`.
- Nicht-WP_Query-Quellen (Terms, Benutzer, externe Daten) liefern über dieselbe Schnittstelle und nutzen dieselben Templates/Formatter.

### Vorschlag für Trefferzahlen und Sichtbarkeit (M1)

- Die Quelle wendet Sichtbarkeit, feste Auswahl der Ansicht und Besucherfilter vor der Zählung und der Seitenauswahl gemeinsam an. Für die eingebaute WordPress-Quelle setzt der Adapter nach Auswertung der Hooks den gewählten Post Type, `post_status=publish` und `has_password=false` durch. Besucherfilter bilden eine Schnittmenge mit den festen Kriterien. Auch angemeldete Besucher erhalten auf öffentlichen Routen dieselbe Auswahl. Die geschützte Admin-Vorschau verwendet für echte Einträge zunächst denselben öffentlichen Datenbestand.
- Auswahl-Hooks schränken die Abfrage vorab ein. Für `sw_postlist_entries` wird vorgeschlagen, nur Darstellungsdaten zu ergänzen. Identität, Mitgliedschaft und Reihenfolge der Treffer bleiben überprüfbar. Erweiterungen, die Zeilen ausblenden, müssen ihre Bedingung an die Quelle geben. `can_view` bleibt eine letzte Prüfung. Bei einem Widerspruch zwischen Abfrage und Sichtbarkeitsprüfung liefert der Renderer einen neutralen Fehler ohne Trefferzahl und protokolliert die fehlerhafte Quelle.
- Bei exakter Zählung gilt für unveränderte Daten: Gesamtzahl = sichtbare Treffer abzüglich Startoffset, mindestens null. Der Abfrageoffset ist Startoffset + (Seite − 1) × Seitengröße. Bei gleichem Sortierwert entscheidet die ID. Bei leerer angeforderter Seite wird die gültige Gesamtzahl gesondert ermittelt, falls WordPress sie nicht liefert. `view.count` bezeichnet die sichtbare Gesamtzahl nach Startoffset.
- Eine Drittquelle kann als Fähigkeit „nur Weiter/Zurück“ deklarieren, wenn sie keine verlässliche Gesamtzahl liefert. Dann liefert sie sichtbare Einträge und eine geprüfte Fortsetzung, etwa durch Lesen eines weiteren sichtbaren Treffers. Der Editor erlaubt nur passende Navigationsmodi, `view.count` bleibt ungesetzt. Ein öffentlicher Rohzähler wäre dafür ungeeignet.
- Abnahme mit sechs passenden veröffentlichten Beiträgen, einem Entwurf, einem privaten und einem passwortgeschützten Beitrag. Bei Startoffset 1 und Seitengröße 2 müssen HTML und REST fünf Treffer und Seiten mit 2/2/1 Einträgen liefern. Ein Besucherfilter und ein Query-Hook müssen Treffer und Gesamtzahl konsistent reduzieren.

### Vorschlag für Kontext und mehrere Einbindungen (M3/M7)

Beim ersten Rendern ermittelt PHP den Ursprung aus der WordPress-Hauptabfrage, vor dem Start einer Postlist-Abfrage. Je nach Modus genügt die ID des Ursprungsbeitrags, die Taxonomie mit Term-ID, die Autor-ID oder der begrenzte Suchtext. Der REST-Aufruf erhält diesen minimalen öffentlichen Kontext zusammen mit Ansichts-ID und Instanzschlüssel als serverseitig signierte Daten. Der Server prüft Signatur und Bindung, lädt die aktuelle Ansicht und prüft die heutige Sichtbarkeit des Ursprungs. Kategorien und Autor eines Beitrags werden aus dessen ID neu abgeleitet. Fehlt ein erforderlicher Ursprung, beendet der Renderer die kontextabhängige Auswahl. Die Signatur dient der Integrität, Berechtigungen werden bei jedem Abruf geprüft.

Jede Einbindung erhält einen beim erneuten Laden reproduzierbaren Instanzschlüssel, auch bei zwei Kopien desselben Blocks oder Shortcodes. Ein expliziter Schlüssel ist möglich, sonst vergibt der Server einen Schlüssel nach Ansicht und Vorkommen in der Seite. Nummerierte Links und GET-Formulare verwenden z. B. `swp[news-1][page]`, erhalten den Zustand der anderen Instanzen und funktionieren ohne JavaScript. REST verwendet dieselbe Parametervalidierung. Ein Filterwechsel setzt nur die eigene Seite zurück. Browser-Zurück stellt den Zustand aus der URL wieder her. Abnahme mit zwei identischen Ansichten auf einer Archivseite, direktem Seitenlink, Browser-Zurück und zwischenzeitlich entzogenem Zugriff auf den Ursprung.

## 5. Templates, Platzhalter und Formatter

Basis: Template-Sprache von sw-cloudcalendar (siehe dort `docs/templates.md`): HTML mit `{{platzhalter}}`, Abschnitte `{{#x}}…{{/x}}`, invertiert `{{^x}}`, Element-/Attribut-Allowlist, Kontext-Escaping, URL-Platzhalter, CSS-Scoping (`.swp-tpl-{id}`), AST-Cache mit quellen- und profilbezogenem Schlüssel. **Unterschied:** Formatter-Aufrufe werden zusätzlich zugelassen (kein allgemeiner Funktionsaufruf).

### Ereignisgesteuerte Neukompilierung (M2)

Postlist liefert Standardregeln für die WordPress-Quellen. Eine Invalidierung wechselt die Cache-Generation der betroffenen Quelle oder des Templates. Erst beim nächsten Rendern kompiliert die Engine den benötigten AST neu. Dadurch löst auch ein Import vieler Beiträge keine sofortige Neukompilierung aller Templates aus.

| Anlass | WordPress-Action bzw. Einstiegspunkt | Standardverhalten |
| --- | --- | --- |
| Beitrag, Seite oder CPT-Eintrag angelegt oder bearbeitet, einschließlich Statuswechsel über die regulären WordPress-Schreibwege | `wp_after_insert_post` | Quelle aus dem tatsächlichen Post Type bestimmen und deren AST-Cache invalidieren. Bei Typwechsel auch die frühere Quelle aus `$post_before` berücksichtigen. Revisionen und Autosaves anhand des gespeicherten Objekts überspringen. |
| Eintrag endgültig gelöscht | `deleted_post` | Quelle aus dem mitgelieferten Post-Objekt ermitteln und invalidieren. |
| Ein verwendetes Metafeld oder eine Term-Zuordnung über die jeweilige WordPress-API geändert | `added_post_meta`, `updated_post_meta`, `deleted_post_meta`, `set_object_terms`, `deleted_term_relationships` | Änderungen an freigegebenen, für die Quelle relevanten Feldern und zugeordneten Taxonomien lösen die Invalidierung der Quelle auch bei einem separaten API-Aufruf aus. |
| Gemeinsames Template veröffentlicht oder aktualisiert | Nach erfolgreichem Schreiben der veröffentlichten Template-Fassung | Template-ID für alle verwendenden Quellen invalidieren. Alle zugeordneten Ansichten beziehen beim nächsten Rendern die neue Fassung. |
| Quellen- oder Template-Regeln geändert | WordPress-Registrierung von Post Types und Taxonomien sowie der Aufbau der Postlist-Registries | Den wirksamen Schema-Fingerabdruck prüfen. Eine gewöhnliche erneute Registrierung beim nächsten WordPress-Start mit denselben Regeln erlaubt einen Cache-Hit. |

Der generische Speicher-Hook gilt auch für CPTs aus Drittplugins, wenn diese über die WordPress-API schreiben. Eigene Entry Sources und Integrationen können `sw_postlist_invalidate_template_ast` mit Grund und Quellen- oder Template-ID auslösen. Der Filter `sw_postlist_ast_invalidation_scope` kann die Standardinvalidierung bei reinen Inhaltsänderungen je Quelle eingrenzen oder abschalten. Ein AST enthält die kompilierte Template-Struktur. Titel, Datum und Metawerte werden beim Rendern aktuell aufgelöst. Falls Postlist später fertiges HTML oder Trefferlisten zwischenspeichert, brauchen diese eigene Invalidierungsregeln für Inhaltsänderungen.

Änderungen am veröffentlichten Template, CSS, Engine-Version, Profil, Quellenschema, erlaubten Meta-Schlüsseln oder Formatter-Signaturen wirken unabhängig von diesem Filter über den Cache-Schlüssel. Abnahme mit gefülltem Cache: `wp_update_post()` für einen Beitrag und für einen eigenen CPT erzwingt jeweils beim nächsten Rendern eine Neukompilierung nur für die betroffene Quelle. Eine unveränderte CPT-Registrierung bleibt ein Cache-Hit. Nach Ausschalten der Inhaltsinvalidierung per Filter erscheint der geänderte Titel auch bei einem AST-Cache-Hit. Ein entfernter Formatter führt weiterhin zu einem Validierungsfehler.

### Engine-Architektur (wiederverwendbar für sw-cloudcalendar und weitere Plugins)

Die Template-Engine wird als **eigenständige, WordPress- und domänenfreie Komponente** gebaut, damit sie später unverändert von `sw-cloudcalendar` und anderen sw-* Plugins genutzt werden kann. Sie lebt zunächst in diesem Plugin unter dem Namespace `SW\Postlist\TemplateEngine` (Verzeichnis `src/TemplateEngine/`), ohne Abhängigkeit auf den Rest des Plugins und ohne WordPress-Funktionen. Die Auslagerung als Paket ist Milestone M10.

**Bestandteile:** Lexer/Parser, AST, Validator, Renderer (Kontext-Escaping: Text, Attribut, URL, Style, HTML-Komponente), CSS-Scoper, Formatter-Registry, AST-Cache-Schlüsselbildung.

**Ports (Schnittstellen, vom Host-Plugin implementiert):**

| Port | Aufgabe |
| --- | --- |
| `PlaceholderSchemaInterface` | Platzhalter mit Datentyp, Beschreibung, Beispielwert, Kontexterlaubnis (`entry.*` bzw. `event.*` liefert das Host-Plugin) |
| `ValueResolverInterface` | Werte zu einem Platzhalter für den aktuellen Kontext |
| `FormatterRegistryInterface` | verfügbare Formatter inkl. Typsignatur; leere Registry = Formatter ausgeschaltet |
| `EscaperInterface` | Escaping/URL-Prüfung (WordPress-Adapter im Host-Plugin, Engine selbst ruft keine WP-Funktionen) |
| `AstCacheInterface` | Speichern/Laden des kompilierten AST |
| `ClockInterface`/Locale | Datumsformatierung mit Zeitzone und Sprache (Support-Port wie in sw-cloudcalendar) |

**Profil** (`EngineProfile`): erlaubte Elemente/Attribute, CSS-Klassenpräfix (`swp-tpl-` / `swc-tpl-`), ob Formatter erlaubt sind, Engine-Version. Das Profil macht Unterschiede zwischen Plugins zu Konfiguration statt zu Code-Forks; `sw-cloudcalendar` würde mit „Formatter aus“ laufen und bliebe syntaktisch kompatibel.

**Regeln für die Wiederverwendbarkeit:**
- Keine `use`-Abhängigkeit der Engine auf Klassen außerhalb von `TemplateEngine\`; ein Architekturtest (PHPUnit) schlägt fehl, wenn Imports gegen die Regel verstoßen.
- Engine-Version fließt in den AST-Cache-Schlüssel; jede AST-/Verhaltensänderung erhöht sie.
- **Contract-Fixtures** (`tests/contract/template-engine/*.json`: Template, Profil, Daten, erwartetes HTML bzw. erwarteter Fehler) laufen in PHP und TypeScript und werden später 1:1 ins Paket übernommen. Aus den vorhandenen CloudCalendar-Compiler-Tests werden zusätzliche Kompatibilitätsfälle abgeleitet.
- Das Admin-Gegenstück (Platzhalter-Autovervollständigung, Formatter-Hilfe, Validierungstypen) liegt in `apps/admin/src/template-engine/` ohne Abhängigkeit auf Postlist-Features (später npm-Paket).
- Öffentliche Engine-API wird dokumentiert (`docs/template-engine.md`), mit `@since`, und gilt ab M2 als stabilitätsrelevant (AGENTS.md: keine Breaking Changes ohne Abstimmung).

### Formatter-Syntax

`{{entry.date|date:"d.m.Y"}}`, `{{entry.title|truncate:60}}`, `{{entry.excerpt|words:25|strip_tags}}`, Verkettung mit `|`. Parameter nur Literale (String/Zahl/Bool). Unbekannter Formatter oder falsche Parameter = Validierungsfehler beim Speichern.

| Formatter | Zweck |
| --- | --- |
| `date:"fmt"` | Datum/Zeit (PHP-Datumsformat, `wp_date`, Zeitzone der Site; Voreinstellung = WP-Setting), `date_i18n` |
| `relative_date` | „vor 3 Tagen“ (`human_time_diff`) |
| `number:decimals` / `currency:"EUR"` | Zahlenformat mit Locale |
| `truncate:n` / `words:n` | Kürzen auf Zeichen/Wörter |
| `strip_tags` / `nl2br` / `upper` / `lower` / `capitalize` | Textumformung |
| `default:"…"` | Fallback bei leerem Wert |
| `join:", "` | Listen (Kategorien/Tags) verbinden |
| `link` / `esc_url` | Listenelemente als Link / URL-Normalisierung |
| `image:"size"` | WP-Bildgröße samt `srcset`/`sizes` |

Formatter sind registrierbar: `apply_filters( 'sw_postlist_formatters', array $formatters )`. Jeder Formatter deklariert Eingabe-/Ausgabetyp (Text, Datum, Zahl, Liste, URL, HTML-Komponente) und Ausgabe-Kontext (escaped Text oder vertrauenswürdige HTML-Komponente); der Compiler prüft Typkompatibilität der Kette.

### Platzhalter

Zentrale `PlaceholderRegistry`, je Typ mit Beschreibung, Datentyp, Beispielwert und Kontextrestriktion. Standard (Auszug): `entry.id`, `entry.title`, `entry.excerpt`, `entry.content`, `entry.date` / `entry.modified`, `entry.url`, `entry.image` (+ Größen), `entry.author.name`/`.url`, `entry.terms.{taxonomy}`, `entry.meta.{key}` (nur freigegebene Schlüssel, nie ungeprüftes HTML), `view.title`, `view.count`, `view.navigation`, `view.filters`, `view.entries`. Erweiterung: `apply_filters( 'sw_postlist_placeholders', array $placeholders, string $entry_source_id )`.

### Editor-Unterstützung (Admin-SPA)

- **Platzhalter-Panel** (analog `PlaceholderPanel.vue`): listet Platzhalter des gewählten Eintragstyps mit Typ, Beschreibung, Live-Beispielwert; Einfügen per Klick, Autovervollständigung im CodeMirror-Editor (`placeholderCompletion.ts`), auch für Formatter-Kette (nach `|` nur typkompatible Formatter mit Parameterhilfe).
- **Live-Validierung** (Syntax, Allowlist, Formatter, CSS) über REST; Vorschau mit echten Einträgen und Gerätebreite.
- Visueller Builder (wie `visualBuilder.ts`): Elementliste (Bild, Titel, Excerpt, Datum, Autor, Taxonomie, Custom Field, Weiterlesen, Freitext, Container, Abstandshalter) mit Einstellungen laut Spec §3.3; erzeugt Template + CSS, die anschließend im Code-Tab bearbeitbar sind.

## 6. Admin-Layout und Tech-Stack

Analog sw-cloudcalendar `apps/admin` (Layout/Design-System wie sw-backupit): **Vue 3 + Quasar (ohne Quasar CLI), Vite, TypeScript, Pinia, vue-router (Hash), vue-i18n (de/en), CodeMirror 6**, Vitest.

Navigation (`NAV_ITEMS`): Dashboard · **Ansichten** · **Templates** · **Presets** · Import/Export · Einstellungen · Diagnose. Ansichtseditor mit Tabs: Allgemein · Quelle & Query · Layout & Design (responsive) · Template/Karte · Filter · Pagination · Vorschau. Gemeinsame UI-Komponenten (`PageHeader`, `FormSheet`, `DataState`, `ConfirmDialog` …) und Komposables aus sw-cloudcalendar übernehmen bzw. als Muster verwenden (Eignung je Komponente prüfen und für Postlist anpassen).

Backend-Struktur nach sw-cloudcalendar: `src/{Domain,Application,Infrastructure,Presentation,Support}`, Ports in `Application/Port`, Verdrahtung nur in `Services.php`, REST-Vertrag in `docs/rest-api.md`, Entscheidungen in `docs/decisions.md`. Das Speichermodell wird per ADR in M0 festgelegt und ab M1 umgesetzt.

### Speichervorschlag für M0 und Veröffentlichung gemeinsamer Templates

- Zwei interne CPTs, `swp_view` und `swp_template`, bilden die bearbeitbaren Dokumente ab. WordPress verwaltet lokale IDs, Titel, Status, Papierkorb, Revisionshistorie und objektspezifische Capabilities. Die CPTs sind von öffentlicher Abfrage, Suche und der Quellenliste ausgenommen. Die SPA verwendet eigene REST-Routen mit Objektberechtigungen. Das validierte, schema-versionierte Dokument liegt in einem registrierten, revisionsfähigen Post-Meta-Feld. Abnahme auf WP 6.5 inklusive Speichern und Wiederherstellen von Template-HTML, Anführungszeichen und Backslashes.
- Ansichten referenzieren Templates über lokale IDs je Rolle (Container oder Eintrag). Für die Prüfung „wo verwendet?“ werden die Referenzen als abfragbare Beziehungen gepflegt und bei Wiederherstellung oder Import abgeglichen. Die Alternative sind eigene Tabellen mit indizierten Beziehungen. Dafür spricht insbesondere eine Anforderung an atomare Änderungen über mehrere Dokumente oder häufige komplexe Referenzabfragen. Für die vorgesehenen einzelnen redaktionellen Dokumente sprechen die vorhandenen WordPress-Verwaltungsfunktionen für CPTs.
- **Einmal veröffentlichen, in allen zugeordneten Ansichten wirksam.** Jede Ansicht referenziert dieselbe Template-ID. Der Editor zeigt Änderungen vorab in der Vorschau. „Veröffentlichen“ bzw. „Aktualisieren“ validiert und speichert das Template in einem Schritt. Schlägt die Validierung fehl, bleibt die letzte gültige Fassung öffentlich. Bei Erfolg verwenden Block, Shortcode und REST beim nächsten Rendern die neue Fassung. Die Ansichten selbst müssen dafür weder bearbeitet noch erneut veröffentlicht werden.
- Abnahme mit zwei Ansichten, die dasselbe Template verwenden. Beide zunächst rendern, das Template einmal ändern und veröffentlichen, dann beide Ausgaben über Block, Shortcode und REST mit gefülltem Cache prüfen. Eine dritte Ansicht mit eigenem Template behält ihre Darstellung.
- Verwendete Templates sind vor Papierkorb und endgültigem Löschen geschützt, einschließlich der WordPress-Verwaltungswege. Erst neu zuordnen oder die Referenz entfernen. Freigabe einer Ansicht prüft die referenzierten Templates. Ein Export enthält alle verwendeten Templates genau einmal und verknüpft sie über paketlokale Schlüssel. Der Import validiert vor dem Anlegen, vergibt lokale IDs, ordnet Referenzen neu zu und veröffentlicht erst nach vollständiger Zuordnung. Namensgleichheit führt zu keinem Überschreiben.
- Für `entry.content` empfehlen wir in 1.0 eine bereinigte Inhaltsfassung ohne Ausführung eingebetteter Shortcodes oder dynamischer Blöcke. Der Abnahmetest enthält einen gelisteten Beitrag mit derselben Postlist als Block und als Shortcode. Beide Varianten müssen mit begrenzter Ausgabe rendern.

## 7. Layouts und Presets

Layouts: Grid, List, Masonry, Hover Grid, Slider/Carousel (Anforderungen Spec §2.2). Jedes Layout ist ein Container-Template + Layout-Konfiguration + minimales Frontend-Modul, nur bei Bedarf geladen (Slider/Masonry/Filter getrennte Bundles). Presets (Spec §2.3) liegen als JSON in `templates/presets/`, sind vollständig überschreibbar, duplizierbar, exportierbar; Erweiterung über `apply_filters( 'sw_postlist_presets', … )` und `sw_postlist_layouts` (eigene Layouttypen mit Schema + Render-Callback).

## 8. Erweiterbarkeit (Hook-Übersicht, Doku: `docs/hooks.md`)

`sw_postlist_entry_sources`, `sw_postlist_query_args`, `sw_postlist_entries`, `sw_postlist_placeholders`, `sw_postlist_formatters`, `sw_postlist_layouts`, `sw_postlist_presets`, `sw_postlist_filter_types`, `sw_postlist_render_html`, Actions `sw_postlist_before_render` / `after_render`. Jede Erweiterung wird gegen Schema validiert; Fehler erzeugen Diagnose-Eintrag statt Fatal Error. Hooks sind abwärtskompatibel zu halten.

## 9. Pflichtprüfungen (Übernahme aus den sw-* Plugins)

Alle einschlägigen Codestil- und Sicherheitsprüfungen der Referenzplugins werden übernommen. Regeln, Pfade, Fixtures, Skripte und Workflows werden einzeln auf Eignung geprüft und auf den tatsächlichen Postlist-Code zugeschnitten. Für PHP setzt PHPCS durchgehend den WordPress-Stil durch. Security-Sniffs und Kompatibilitätsprüfungen gelten auch für die isolierte Engine. Referenzen (relativ zu `wp-content/plugins/`):

| Bereich | Quelle zum Übernehmen |
| --- | --- |
| Regeln für Agenten, Cache-Busting, PR-Review-Follow-up | `sw-cloudcalendar/AGENTS.md` |
| Coding-Richtlinien | `CODE-STYLE.md` (für Postlist angepasst) |
| PHP-Prüfungen: PHPCS/WPCS inkl. Security-Sniffs und PHPCompatibilityWP, PHPStan Level 8 ohne Baseline/Ignores (`composer stan`), PHPUnit Unit/Integration (`composer test`), `composer check` | `sw-cloudcalendar/{composer.json,phpcs.xml.dist,phpcs-wordpress.xml.dist,phpstan.neon.dist,phpunit.xml.dist}`, `tests/phpstan/` |
| WordPress-Integrationstests mit privater MariaDB (`tests/wp`, `bin/test-db.sh`) | `sw-cloudcalendar/tests/wp`, `bin/test-db.sh` |
| JS/TS-Prüfungen: `typecheck`, ESLint, Prettier, Vitest | `sw-cloudcalendar/apps/admin/{package.json,eslint.config.js,tsconfig.json,vite.config.ts}` |
| Coverage-Gates **> 80 %** (PHP und JS/TS getrennt, genau 80 % schlägt fehl, jeder produktive ausführbare Quellpfad einschließlich Block und Frontend wird erfasst) | `apps/admin/scripts/check-coverage.mjs`, `tests/coverage/{merge,check}.php`, Regeln in `sw-cloudcalendar/docs/testing.md` |
| Browser-E2E mit Playwright/Docker, Wegwerf-WordPress | `sw-cloudcalendar/tests/E2E`, `docs/testing.md` |
| i18n-Prüfung (`bin/i18n.sh check`, de/en-Dateien der SPA) | `sw-cloudcalendar/bin/i18n*.{sh,php}`, `apps/admin/src/i18n` |
| Dependency-Security (`composer audit --locked --no-dev`, `npm audit`), tägliche Audit-Workflow, Dependabot | `sw-cloudcalendar/.github/{workflows/security-audit.yml,dependabot.yml}`, `docs/development.md` |
| SonarQube Cloud (Coverage-Upload, `sonar-project.properties`) | `sw-cloudcalendar/docs/sonarqube.md`, `sonar-project.properties` |
| CI-Workflows (Tests, Main/Release) | `sw-cloudcalendar/.github/workflows/{tests,main}.yml` |
| Composer-Isolierung mit Mozart + `prefix-sources.php` | `sw-cloudcalendar/docs/composer-vendor-prefixed.md`, `bin/prefix-sources.php` |
| Build/Release/Verifikation des ZIP, Smoke-Test ohne `vendor/` | `sw-cloudcalendar/bin/{build-zip,release,release-manifest,verify-release}.*`, `tests/release/` |
| Bundle-/Größenbudget, Template-Kompilierungs-/Contract-Fixtures (PHP/TS gemeinsam) | `sw-sliderman/bin/{check-bundles.mjs,contract-fixtures.php}`, `schemas/` |
| Performance-Messung | `sw-cloudcalendar/tests/Performance` (angepasst auf Postlist-Query, z. B. 2 000 Einträge) |

**Verbindlich vor jedem Milestone-Abschluss:** alle vorstehenden Prüfungen laufen grün; nicht ausführbare Prüfungen werden ausdrücklich als ungeprüft gekennzeichnet (AGENTS.md).

**Zusätzlich plugin-spezifisch** (neu zu schreiben): XSS-Tests für Platzhalter/Formatter/Meta-Ausgabe, ACL-Tests (private/Entwurfs-/passwortgeschützte Inhalte nie in Ausgabe/REST), Query-Parameter-Fuzzing, Import-Validierung, Formatter-Typkettentests, Hook-Vertragstests (eigene Entry Source / Formatter / Layout aus Testplugin).

## 10. Milestones

Jeder Meilenstein liefert einen lauffähigen, reviewbaren Stand mit Tests und aktualisierter Dokumentation. Die [Markdown-Checkliste in TODO.md](TODO.md) enthält die Teilaufgaben und Abnahmekriterien für M0 bis M10. Eine Aufgabe wird mit Nachweis abgehakt.

### M0 – Projektfundament
- Plugin-Bootstrap, `AGENTS.md`, `Makefile`, `composer.json` (inkl. Mozart-Konfiguration), `apps/admin`-Gerüst, Lifecycle (Aktivierung/Deinstallation entfernt nur eigene Daten).
- Alle Prüfungen aus Abschnitt 9 lauffähig (leeres Gerüst grün, Coverage-Gate-Skripte aktiv), CI-Workflows, SonarQube-Anbindung, i18n-Pipeline.
- ADRs: Speichermodell (Tabellen vs. CPT), Namensprüfung (Slug/Namespace), Mindestversionen PHP/WP.
- **Abnahme:** `make check` und Build-ZIP laufen auf leerem Gerüst.

### M1 – Datenmodell, Query Engine, Eintragstypen
- Ansichts-Schema v1 + JSON-Schema + TS-Typen + gemeinsame Fixtures, Validierung, Migrationsrahmen.
- `EntrySourceInterface`, Registry, `PostTypeEntrySource` (automatische Erkennung öffentlicher CPTs/Taxonomien), Filter `sw_postlist_entry_sources`.
- Query Engine (Spec §4.1–4.3) mit Hooks `sw_postlist_query_args` / `sw_postlist_entries`, Sichtbarkeits-/Rechteprüfung.
- Tests: genau eine Quelle, sichtbare Trefferzahlen, Offset/Pagination, ACL, Parameter-Validierung und Hook-Verträge gemäß Entscheidung zu §4.

### M2 – Template-Engine (isoliert), Platzhalter, Formatter
- Engine nach Abschnitt 5 unter `src/TemplateEngine/` mit Ports, Profil und Architekturtest (keine Abhängigkeit nach außen); Compiler/AST/Cache nach Vorbild sw-cloudcalendar, Allowlist, CSS-Scoping.
- Postlist-seitige Adapter: `PlaceholderRegistry`, `FormatterRegistry` mit Typketten, WordPress-Escaper, Transient-AST-Cache; Filter `sw_postlist_placeholders` / `sw_postlist_formatters`; Standard-Formatter (Datum etc.).
- Contract-Fixtures (PHP + TS) und Kompatibilitätsprüfung anhand der Template-Testfälle von sw-cloudcalendar (Profil „Formatter aus“).
- Tests: XSS in Text/Attribut/URL/Style, Syntaxfehler, Formatter-Fehler, Cache-Korruption und ereignisgesteuerte Invalidierung nach §5.

### M3 – Rendering, Grid + List, Shortcode, Block
- Server-Renderer für Container + Eintrag, Layouttypen Grid und List, stabile CSS-Klassen, Responsive-Konfiguration, Responsive Images.
- Shortcode `[sw_postlist id]`, schlanker Block (ohne Build-Schritt) mit Server-Rendering, REST-Vorschau.
- Klassische Pagination ohne JS, Ursprungskontext und unabhängige URL-Parameter pro Einbindung nach Entscheidung zu §4.

### M4 – Admin-SPA: Ansichten, Templates, Platzhalter-Panel
- Layout/Navigation wie sw-cloudcalendar, Ansichtseditor (Quelle/Query, Layout, Design, Responsive), Template-Editor mit CodeMirror, Platzhalter-Panel, Formatter-Hilfe, Live-Validierung, Vorschau mit Gerätebreite.
- Vitest-Tests, Browser-E2E der SPA-Seiten ohne Konsolenfehler.
- **Abnahme durch Stefan:** Bedienung und Layout.

### M5 – Visueller Builder und Presets
- Visueller Karteneditor (Elementliste, verschachtelte Container, Elementeinstellungen), erzeugt Template + CSS.
- Preset-Bibliothek (mitgeliefert + benutzerdefiniert, Vorschau, Duplizieren, Zurücksetzen), Filter `sw_postlist_presets`.

### M6 – Weitere Layouts
- Masonry, Hover Grid (inkl. Reduced Motion), Slider/Carousel (Tastatur, Touch, Autoplay mit Pause); jeweils eigene, nur bei Bedarf geladene Module und Bundle-Budget. Filter `sw_postlist_layouts`.

### M7 – Frontend-Filter und dynamische Pagination
- Besucherfilter (Taxonomien, Autor, Suche, Sortierung; Darstellungsarten), REST-Nachladen, Ladezustand, Fehler, Empty State, URL-Sync, AJAX-Pagination, Load More, Infinite Scroll, A11y-Statusmeldungen, Filter `sw_postlist_filter_types`.
- Öffentliche REST-Endpunkte liefern nur öffentlich sichtbare Inhalte; Nonce-/Cache-Verhalten dokumentiert.

### M8 – Wiederverwendung: Import/Export, Doku
- JSON-Export/-Import (Validierung, Versionierung, Migration, Größenlimits), Ansicht duplizieren, Konfiguration kopieren/zurücksetzen.
- `docs/hooks.md`, `docs/templates.md`, `docs/rest-api.md`, Beispiel-Erweiterungsplugin (Entry Source + Formatter) als Vertragstest.

### M9 – Stabilisierung und Release 1.0
- Performance (Messung nach sw-cloudcalendar), Barrierefreiheitsprüfung, Sicherheitsprüfung (Review der Import-, REST-, Template-Pfade), WP-/PHP-/Theme-Matrix (klassisch + Block-Theme), Mehrfach-Instanzen pro Seite, Cache-Plugin-Verhalten.
- Release-Build mit Mozart, signierte/verifizierte ZIPs, Smoke-Test ohne `vendor/`. Freigabe durch Stefan.

### M10 – Extraktion der Template-Engine (nach 1.0, mit Abstimmung)
- Engine als eigenes Composer-Paket (eigenes Repository, eigene CI mit den Prüfungen aus Abschnitt 9) plus npm-Paket für die Editor-Hilfen; Einbindung per Mozart-Isolierung (`docs/composer-vendor-prefixed.md`), damit Plugins unterschiedliche Versionen ausliefern können.
- Migration von `sw-cloudcalendar` auf das Paket als eigenes, mit dem Auftraggeber abgestimmtes Vorhaben: bestehende Templates, Cache-Schlüssel und Ausgaben bleiben unverändert (Kompatibilitätsfixtures), Formatter im Profil abgeschaltet bis explizit gewünscht.
- Weitere sw-* Plugins können das Paket danach über eigene Schemas/Profile nutzen.

## 11. Risiken

| Risiko | Umgang |
| --- | --- |
| Formatter erweitern die Template-Sprache und öffnen XSS-/Komplexitätsrisiken | Nur Whitelist-Formatter mit Typketten, Literal-Parameter, Escaping nach Ausgabekontext, Fuzz-Tests |
| Eintragstypen von Drittplugins verletzen Sichtbarkeitsregeln | `can_view` als Pflicht, Ergebnis-Filter kann nichts hinzufügen, Diagnose bei Verstoß |
| Zwei Wege (Code/visueller Builder) driften auseinander | Builder erzeugt ausschließlich Template + CSS; Rückweg nur für vom Builder erzeugte Strukturen |
| Viele Layout-Module vergrößern Frontend-Assets | Getrennte Bundles, nur bei Bedarf, Budget in CI |
| Engine-Änderung bricht mehrere Plugins nach Extraktion | Semver, Contract-Fixtures aus allen Konsumenten, Engine-Version im Cache-Schlüssel, Mozart-Isolierung je Plugin |
| Abweichung vom Spec-Ziel „Editor im Gutenberg-Block“ | Siehe Abschnitt 1; Entscheidung bestätigen lassen |

## 12. Offene Punkte und Entscheidungszeitpunkte

1. Bestätigung der Abweichung: visueller Editor in der Admin-SPA statt im Block.
2. Für das ADR in M0 den CPT-Vorschlag sowie die Referenz- und Importregeln aus §6 bewerten. Die gemeinsame Aktualisierung bei Veröffentlichung ist festgelegt. Vor M1 den Zählvertrag aus §4 festlegen, vor M3 die Kontextübergabe.
3. Shortcode-Name (`[sw_postlist]` vs. Spec-Beispiel `[content_layout]`; Alias möglich).
4. Template-Engine: entschieden – zunächst isoliert in diesem Plugin (Abschnitt 5, M2), Auslagerung als Paket und Migration von sw-cloudcalendar in M10. Offen: Repository-Ort/Name des späteren Pakets.
5. WordPress 6.5 ist als Minimum festgelegt und gehört in die Testmatrix. PHP 8.2+ ist der Vorschlag für das M0-ADR.
