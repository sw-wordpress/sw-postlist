# Templates

Stand M2. Templates sind HTML mit einer kleinen, Mustache-ähnlichen Sprache. Es gibt **kein** PHP, kein JavaScript, keine Schleifen, keine Partials und keine allgemeinen Funktionsaufrufe. Templates werden beim Speichern vollständig validiert und zu einem AST kompiliert (Schnittstelle für Integratoren: [template-engine.md](template-engine.md)). Die Datei wird in M8 mit Layoutklassen und Rest-Verträgen vervollständigt.

## Syntax

| Form | Bedeutung |
| --- | --- |
| `{{entry.title}}` | Platzhalter, passend zum Kontext escaped |
| `{{entry.date\|date:"d.m.Y"}}` | Platzhalter mit Formatter; Verkettung mit `\|`, Parameter nur Literale (`"Text"`, `60`, `true`) |
| `{{#entry.image}}…{{/entry.image}}` | Abschnitt, wenn der Wert nicht leer/wahr ist |
| `{{^entry.image}}…{{/entry.image}}` | invertierter Abschnitt |

Regeln:

- Nur registrierte Platzhalter und Formatter. `{{{…}}}`, `{{> …}}` und `{{! …}}` sind Fehler.
- Abschnitte müssen innerhalb desselben Elements (oder desselben Attributwerts) geschlossen werden.
- Erlaubte Elemente und Attribute: siehe `EngineProfile` (`div span p a strong em … table details summary`; global `class id title lang dir role hidden tabindex style aria-* data-*`, je Element zusätzliche wie `href`, `src`, `alt`). Event-Handler (`on…`) sind verboten. HTML-Kommentare werden entfernt.
- **Kontexte:** In Text und Attributen wird HTML-escaped. `href`/`src`/`cite` enthalten eine statische sichere URL (`http`, `https`, `mailto`, `tel`, relativ) oder **genau einen** URL-Platzhalter; ein unsicherer Wert lässt das Attribut weg. Im `style`-Attribut sind nur Farb-Platzhalter erlaubt. HTML-Komponenten (`entry.content`, `view.entries`, `…|nl2br`, `…|link`, `…|image`) nur als Textknoten.
- `target="_blank"` erhält automatisch `rel="noopener noreferrer"`.
- CSS: keine `url()`, `@import`, `@font-face`, `expression()`, Escapes oder `<`; Selektoren werden auf `.swp-tpl-{id}` begrenzt; `@media`, `@supports`, `@container`, `@keyframes` sind erlaubt.

## Platzhalter

`view.title` (beide Rollen). `{{view.filters}}` setzt das Filterformular der Ansicht (leer ohne Filter); fehlt der Platzhalter, steht das Formular vor dem Container-Inhalt. Container-Templates zusätzlich `view.count`, `view.navigation`, `view.filters`, `view.entries`. Eintrags-Templates (Beitragsquellen):

| Platzhalter | Typ |
| --- | --- |
| `entry.id` | Zahl |
| `entry.title`, `entry.excerpt` | Text |
| `entry.content` | HTML (bereinigt, Shortcodes und dynamische Blöcke werden nicht ausgeführt) |
| `entry.date`, `entry.modified` | Datum (Zeitzone der Site) |
| `entry.url`, `entry.author.url` | URL |
| `entry.image` | Bild (als URL im `src`, als `<img>` mit `…\|image:"size"`) |
| `entry.author.name` | Text |
| `entry.terms.{taxonomy}` | Liste (nur Taxonomien der Quelle) |
| `entry.meta.{key}` | Text, **nur freigegebene Schlüssel** (Einstellung `meta_keys` je Quelle oder Filter `sw_postlist_allowed_meta_keys`; Schlüssel mit führendem `_` nie) |

## Formatter

| Formatter | Eingabe → Ausgabe | Zweck |
| --- | --- | --- |
| `date:"fmt"` | Datum → Text | PHP-Datumsformat, Zeitzone der Site; ohne Format die Site-Einstellung |
| `relative_date` | Datum → Text | „vor 3 Tagen“ |
| `number:decimals`, `currency:"EUR"` | Zahl → Text | Zahlenformat der Site-Sprache |
| `truncate:n`, `words:n` | Text → Text | auf Zeichen/Wörter kürzen (`…`) |
| `strip_tags`, `upper`, `lower`, `capitalize` | Text → Text | Textumformung |
| `nl2br` | Text → HTML | Zeilenumbrüche (Text wird escaped) |
| `default:"…"` | Text/Zahl → Text | Fallback bei leerem Wert |
| `join:", "` | Liste → Text | Listen verbinden |
| `link:", "` | Liste → HTML | Listenelemente als Links |
| `initials` | Text → Text. Großbuchstaben der ersten Wörter („Anna Keller“ → „AK“), Parameter `count` (1–3, Standard 2). |
| `chips` | Liste → HTML. Jede Bezeichnung als eigenes `<span class="swp-chip">`, optional mit Zusatzklasse (nur Buchstaben, Ziffern, `-`, `_`). |
| `map` | Liste → Liste. Wendet eine **benannte Map** aus den Einstellungen an (Ausblenden, Texte ersetzen, Präfix entfernen) und gibt die Liste zurück, danach folgen `join`, `link` oder `chips`: `{{entry.terms.category|map:"kategorien"|chips}}`. Der Name wird beim Speichern des Templates geprüft. |
| `esc_url` | URL/Text → URL | URL-Normalisierung (unsichere Werte werden leer) |
| `image:"size"` | Bild → HTML | WordPress-Bildgröße mit `srcset`/`sizes` |

Weitere Formatter registriert der Filter `sw_postlist_formatters`; weitere Platzhalter `sw_postlist_placeholders`. Unbekannte Formatter, falsche Parameter und inkompatible Ketten werden beim Speichern gemeldet.

## Visueller Builder und Presets (M5)

Der Builder (Tab „Builder“ beim Eintrags-Template) erzeugt **nur** Template und CSS in der oben beschriebenen Sprache; beides bleibt im Code-Tab frei bearbeitbar. Elemente: Bild, Titel, Excerpt, Datum, Autor, Taxonomie, Custom Field, Weiterlesen, Freitext, Container (verschachtelbar, Richtung/Abstand/Innenabstand/Hintergrund) und Abstandshalter. Freitext, Beschriftungen und Ersatztexte werden escaped, `{{` im Freitext wird nie zu einem Platzhalter.

Das Modell steckt im erzeugten HTML (`data-swp-builder`, base64url-JSON). Beim Öffnen gilt:

- Code entspricht exakt der Builder-Ausgabe → Builder wird geöffnet.
- Code trägt ein Modell, wurde aber von Hand geändert → Hinweis; „Builder-Stand wiederherstellen“ überschreibt die Änderungen, „Code behalten“ nicht.
- Kein Modell (von Hand geschrieben) → Hinweis; der Builder ersetzt den Code erst nach ausdrücklicher Wahl.

Die Builder-Ausgabe wird durch gemeinsame Fixtures (`tests/contract/builder`) in PHP kompiliert und mit echten Einträgen gerendert. Presets liegen als JSON in `templates/presets/` (Schema: `schema_version`, `id`, `title`, `description`, `layout`, `container`, `entry`, optional `builder`); eigene Presets (z. B. „Als Preset speichern“) liegen in der Option `sw_postlist_user_presets` und überschreiben bei gleicher ID das Original, bis sie zurückgesetzt werden.


## CSS-Klassen der Layouts

Der Wrapper jeder Ansicht ist `.swp-view` mit `swp-layout-{typ}` (`grid`, `list`, `masonry`, `hover_grid`, `slider`, eigene Typen) und dem Scope `.swp-tpl-{id}` des Container-Templates. Eintragselemente tragen `.swp-item`. Eingebaute Container: `.swp-grid`, `.swp-list`, `.swp-masonry`, `.swp-hover-grid`, `.swp-slider` mit `.swp-slider__track`. CSS-Variablen (`--swp-cols-*`, `--swp-gap-*`, `--swp-font-*`, `--swp-slides-*`, `--swp-aspect`, `--swp-accent`) setzt das Layout je Gerätebreite (`desktop`, `tablet`, `mobile`). Filter und Pagination: `.swp-filters`, `.swp-filter`, `.swp-pagination`, `.swp-load-more`, `.swp-status`. Siehe `assets/css/frontend.css`.


## Presets und positionsabhängige Layouts

Mitgeliefert sind zehn Presets, darunter `teaser-pills`, `magazine-cards`, `editorial-hero`, `dark-overlay` und `neo-brutalist`. Sie nutzen nur, was die Engine ohnehin erlaubt:

- **Erstes, gerades, jedes n-te Element anders gestalten:** Das CSS des **Container-Templates** darf die Einträge über ihre Position ansprechen, zum Beispiel `.swp-item:first-child { grid-column: 1 / -1 }`, `.swp-item:nth-child(even) .karte { flex-direction: row-reverse }` oder `.swp-item:nth-child(3n+1) .platte { background: … }`. Der Container steht um die Einträge herum, deshalb erreicht sein Scope auch die Kinder. Dafür braucht es keinen eigenen Platzhalter.
- **Breite des Containers statt des Bildschirms:** `container-type: inline-size` am Wrapper und `@container (max-width: …)` im CSS. Das ist wichtig, weil Presets in schmalen Theme-Spalten sitzen.
- **Gitter oder Liste einsetzen:** Ein Preset mit Layout `grid` setzt `{{view.entries}}` in ein `<div class="swp-grid">`, eines mit `list` in ein `<div class="swp-list">`. Sonst fehlt das Gitter.
- **Bilder ohne Beitragsbild:** `{{#entry.image}}…{{/entry.image}}{{^entry.image}}<span class="…--ph"></span>{{/entry.image}}` zeigt eine Platzhalterfläche.
- **Taxonomie-Filter:** `{{view.filters}}` im Container setzt das Filterformular. Der Filter selbst wird in der Ansicht (Tab „Filter“, Schaltflächen) konfiguriert. Ein Preset enthält nur Layout, Container und Eintrag, keine Filter der Ansicht.
- **Schriften:** nur Systemschriften. Das CSS darf weder `url()` noch `@import` enthalten, also keine Webfonts.


## Maps: Anzeigewerte getrennt konfigurieren

Unter **Einstellungen → Maps für Anzeigewerte** legst du benannte Maps an (Name `[a-z0-9_-]`, höchstens 40 Zeichen, bis zu 30 Maps). Jede Map hat drei Regeln, die in dieser Reihenfolge wirken:

1. **Ausblenden:** Name oder Slug eines Wertes, zum Beispiel `Uncategorized`. Groß- und Kleinschreibung ist egal.
2. **Ersetzen:** `Original = Anzeige`, zum Beispiel `Kategorie Marktplatz = Markt`. Das Original ist der Name oder der Slug.
3. **Präfix entfernen:** zum Beispiel `Kategorie ` macht aus „Kategorie Feldstraße“ den Text „Feldstraße“.

Im Template nutzt du die Map ausdrücklich: `{{entry.terms.category|map:"kategorien"|chips}}`. Dasselbe Template-Paar kann also in einer Ansicht die Roh-Kategorien zeigen und in einer anderen eine Map nutzen; nichts wirkt implizit. Ein unbekannter Name ist ein Fehler beim Speichern des Templates (Tippfehler fallen auf). Die Map verändert nur die Anzeige: Links und Filter arbeiten weiter mit den echten Begriffen. Die mitgelieferten Presets nutzen keine Map, weil sie ohne vorherige Einstellung funktionieren müssen; ein Eintrag wie `{{entry.terms.category|chips}}` lässt sich nach dem Anlegen einer Map zu `{{entry.terms.category|map:"kategorien"|chips}}` ändern.
