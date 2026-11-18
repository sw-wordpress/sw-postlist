# SW Postlist – Code-Stil

Stand: 18.11.2026

Diese Richtlinie gilt für PHP, Admin-SPA, Frontend, Gutenberg-Block, Tests und Build von SW Postlist. Sie beschreibt, wie der Code geschrieben und geprüft wird. Produktverhalten und Architektur stehen im [Plan](PLAN.md), die Teilaufgaben in der [Checkliste](TODO.md).

## 1. Verbindlichkeit und Arbeitsweise

**Ziel ist Code, dessen Verhalten ein anderer Entwickler zuverlässig erklären, ändern und überprüfen kann.** Kurzer Code ist kein Selbstzweck. Ein Sicherheitscheck, eine Zustandsbedingung oder eine verständliche Fehlerbehandlung darf nicht für eine elegante Abstraktion verschwinden.

Die Wörter **MUSS**, **DARF NICHT** und **SOLL** kennzeichnen Pflicht, Verbot und begründet abweichbare Empfehlung. Explizite Aufgaben und geltende AGENTS.md-Anweisungen sind zu beachten. Bei einem inhaltlichen Konflikt zwischen Produktanforderungen, Datenvertrag und dieser Datei MUSS der Konflikt benannt und dokumentiert aufgelöst werden; keine stillschweigende Änderung von Sicherheits- oder Veröffentlichungsregeln.

- PLAN.md legt Verhalten und Architektur fest. CODE-STYLE.md legt Implementierungsqualität und Konventionen fest. TODO.md dokumentiert den tatsächlichen Umsetzungsstand.
- Bestehende öffentliche Verträge sind bei Änderungen zu erhalten oder ausdrücklich zu migrieren.
- Eine Ausnahme MUSS eng begrenzt, begründet und überprüfbar sein. Suppressions benötigen den konkreten Regelcode und den Grund.
- Diese Datei führt keine zusätzliche pauschale Genehmigungspflicht für normale Implementierungsschritte ein. Veröffentlichungen und destruktive Aktionen richten sich nach dem konkreten Auftrag.
- Der Agent MUSS vor größeren Änderungen betroffene Verträge, Aufrufer und Tests lesen. Er DARF nicht gleichzeitig eine neue Architektur erfinden und sie als bestehende Projektkonvention ausgeben.
- Nicht durchgeführte Prüfungen und verbleibende Einschränkungen MÜSSEN im Abschluss genannt werden. Geplanter Code, ein erfolgreicher Build oder ein Screenshot allein beweisen keine vollständige Funktion.

## 2. Stilentscheidung und automatische Durchsetzung

### 2.1 Eine PHP-Konvention für dieses Plugin

Sämtlicher eigener PHP-Code folgt dem WordPress-PHP-Stil, einschließlich Domain, Application, Template-Engine und Tests.

Klassen, Interfaces, Enums, Namespace-Segmente und PSR-4-Dateien verwenden PascalCase, etwa `ViewDocument.php` für `SW\Postlist\Domain\ViewDocument`. Der Dateipfad muss zur PSR-4-Zuordnung passen. Eigene Funktionen, Methoden, Eigenschaften, Parameter und lokale Variablen verwenden `snake_case`. Implementierungen fremder Interfaces übernehmen die vorgegebenen Namen und Signaturen.

Dies ist eine dokumentierte Abweichung von den nativen WordPress-Klassen-/Dateinamenskonventionen. Sie ist keine Abweichung von Sicherheits-, Übersetzungs- oder Interoperabilitätsanforderungen. Es darf keine Behauptung einer ausnahmslosen WPCS-Konformität geben.

| Bereich | Konvention | Automatische Prüfung |
| --- | --- | --- |
| Eigener PHP-Code | WordPress-Stil, PSR-4-Namensausnahme, explizite Typen | PHPCS/WordPressCS, PHPStan, PHPCompatibilityWP |
| Editor, Runtime, gemeinsame TS-Module | TypeScript strict, zwei Leerzeichen, Semikolons, einfache Quotes | ESLint, Prettier, TypeScript |
| Vue-Komponenten | Composition API, `<script setup lang="ts">` | ESLint/Vue, vue-tsc |
| Gutenberg-Adapter | WordPress-Pakete, WordPress-JS-Konventionen | Eigenes ESLint-Override, passender Formatter |
| CSS/SCSS | Zwei Leerzeichen, Namespacing, gemeinsame Tokens | Stylelint, Prettier soweit kompatibel |
| Tests | Stil des jeweiligen Bereichs | Dieselben Parser/Lints, Testwerkzeuge |
| Generierter/Fremdcode | Nicht manuell umformatieren | Build-/Dependency-Prüfung statt eigener Stilregeln |

Formatter dürfen sich nicht gegenseitig widersprechen. Falls der Gutenberg-Bereich tabbasierte WordPress-JS-Formatierung nutzt, MUSS ein pfadbezogenes Override dies von den Vue-/Runtime-Dateien trennen. Keine abwechselnden Formatänderungen durch konkurrierende Tools.

### 2.2 Repository-Grundregeln

- UTF-8 ohne BOM, LF, abschließender Zeilenumbruch, keine nachgestellten Leerzeichen.
- `.editorconfig`, PHPCS-, ESLint-, TypeScript- und Formatter-Konfiguration MÜSSEN eingecheckt sein.
- PHP: Tabs zur Einrückung, Spaces zur Ausrichtung. TS/Vue/CSS außerhalb des Gutenberg-Overrides: zwei Spaces.
- Richtwert für Lesbarkeit: etwa 100–120 Zeichen pro Zeile; URLs, Typen und verständliche Tabellen dürfen länger sein.
- Keine Dateigrößen- oder Methodenlängen-Grenze als Selbstzweck. Große Dateien mit mehreren Verantwortlichkeiten MÜSSEN aufgeteilt werden; sinnvolle zusammengehörige Logik darf nicht für einen Zahlenwert zerlegt werden.
- `vendor/`, `node_modules/`, generierte Bundles und Third-Party-Quellen nicht manuell ändern. Patches nur über einen reproduzierbaren, dokumentierten Mechanismus.
- Testdaten aus synthetischen Beispielen erstellen. Zugangsdaten, personenbezogene Inhalte und fremdes Material ohne passende Lizenz aus dem Repository fernhalten.

## 3. Modulgrenzen und Architektur

### 3.1 Verantwortlichkeiten

| Pfad | Verantwortung | Verbotene Kopplung |
| --- | --- | --- |
| `src/Domain/` | Dokumentregeln, Werte, Zustände, fachliche Fehler | WordPress, HTTP, SQL, Dateisystem, aktuelle Uhrzeit |
| `src/Application/` | Anwendungsfälle und benötigte Ports | Konkrete Controller, `$wpdb`, globale Service-Locator |
| `src/Infrastructure/` | WordPress-Persistenz, Query- und Cache-Adapter | Fachentscheidungen in technischen Hilfsmethoden |
| `src/Presentation/` | REST, Block, Shortcode und Abbildung von Antworten | Vollständige Anwendungsfälle im Controller |
| `src/TemplateEngine/` | Parser, Validator, AST und kontextgerechtes Rendering | WordPress-Funktionen und Postlist-Fachklassen |
| `apps/admin/` | Bearbeitungszustand, Eingaben und Vorschau | Eigene Ausgaberegeln, die vom PHP-Renderer abweichen |
| Frontend-Module | Interaktion und Navigation je Einbindung | Admin-API und Editorbibliotheken |

Abhängigkeitsrichtung: Domain kennt keine äußere Schicht. Application kennt Domain und eigene Ports. Adapter implementieren diese Ports. Der Composition Root verbindet konkrete Implementierungen. Die Registrierung eines Hooks darf nicht beim Import einer beliebigen Klassendatei passieren.

### 3.2 Abstraktionen mit Zweck

- Konstruktor-Injektion für erforderliche Abhängigkeiten; keine `get_instance()`-Ketten und kein Containerzugriff innerhalb fachlicher Services.
- Interfaces an tatsächlichen Grenzen einsetzen, etwa für Persistenz, Eintragsquellen, Cache oder Uhr.
- Keine generischen `BaseService`, `AbstractManager` oder `Repository<T>` ohne konkreten Nutzen.
- Vererbung nur für ein echtes austauschbares Verhalten; Komposition bevorzugen. Traits nicht als versteckte Sammlung fachlicher Abhängigkeiten verwenden.
- Konstruktoren initialisieren und prüfen Invarianten. Sie registrieren keine Hooks, laden keine Dateien und starten keine Netzwerkverbindung.
- Klassen mit alleiniger interner Implementierungsrolle SOLLEN `final` sein. Dokumentierte Erweiterungspunkte bleiben bewusst offen.
- Keine WordPress-Typen wie `WP_Error`, `WP_Post`, `WP_REST_Request` oder `wpdb` in Domain-/Application-Verträgen.
- Benenne Methoden nach dem fachlichen Vorgang, etwa `publish_template()` oder `load_view()`.

### 3.3 Verantwortlichkeit pro Funktion

Eine Methode hat ein benennbares Ergebnis. Ein Controller validiert den Request, prüft Rechte, ruft den Anwendungsfall auf und übersetzt dessen Ergebnis in eine Antwort.

Frühe Rückgaben sind für ungültige Bedingungen sinnvoll. Stark verschachtelte Bedingungen SOLLEN in klar benannte Prädikate oder Anwendungsfälle aufgelöst werden. Keine doppelte Negation und keine Boolean-Parameter, deren Aufruf unverständlich ist: `publish( $id, true, false )` ist durch einen benannten Befehl oder ein Optionsobjekt zu ersetzen.

## 4. PHP: Sprache, Typen und Benennung

### 4.1 Laufzeit und Typisierung

- Sprachmerkmale und Abhängigkeiten gegen die im Plan festgelegten PHP- und WordPress-Versionen prüfen.
- Eigene PHP-Dateien verwenden `declare(strict_types=1);`. Die genaue Formatierung dieses Declare-Statements wird als eng begrenzte Projektkonvention im Linter hinterlegt, falls erforderlich.
- Strikte Typen ersetzen keine Eingabevalidierung. Insbesondere kann ein aus WordPress aufgerufener Callback weiterhin anders typisierte Rohwerte erhalten.
- Eigene APIs MÜSSEN Parameter- und Rückgabetypen besitzen. `mixed` ist an unkontrollierten Grenzen zulässig; nach Validierung wird es in einen eindeutigen Typ überführt.
- Externe Callback-Signaturen MÜSSEN zum tatsächlichen WordPress-/Library-Vertrag passen. Keine zu enge Signatur, die bei einem üblichen Filterwert einen TypeError erzeugt.
- Listen und strukturierte Arrays mit PHPDoc präzisieren, etwa `list<Entry>`, `array<string, EntrySourceInterface>` oder ein dokumentiertes Array Shape.
- Quellen-ID, Ansichts-ID und Template-ID an Schnittstellen eindeutig benennen. Wertobjekte einsetzen, wenn sie eine konkrete Verwechslung verhindern.
- `readonly` für tatsächlich unveränderliche Daten; beachten, dass eine readonly-Eigenschaft ein referenziertes Objekt nicht tief unveränderlich macht.
- Keine dynamischen Properties, implizit nullable Parameter oder `#[AllowDynamicProperties]` als Reparatur eigener Modellfehler.
- Einheiten im Namen kenntlich machen, etwa `size_bytes` oder `timeout_ms`.

### 4.2 Namen und Format

- Klassen/Interfaces/Enums: PascalCase; Interfaces erhalten bei Bedarf das Suffix `Interface`, ohne wechselnde `I`-Präfixe.
- PHP-Funktionen/Methoden/Eigenschaften/Variablen: `snake_case`; Konstanten: `UPPER_SNAKE_CASE`.
- Keine unklaren Abkürzungen wie `$d`, `$cfg`, `$tmp2`, `$obj`. Etablierte Begriffe wie ID, URL und UUID sind erlaubt.
- Ein primärer Typ pro Datei, Dateiname exakt wie Typ. Keine zusätzliche freie Funktion in einer Klassendatei.
- Imports explizit; keine unnötig vollqualifizierten langen Namen im Methodenkörper und keine Namespace-Wildcards.
- WordPress-Spacing: `get_option( 'sw_postlist_settings' )`, `if ( $is_valid ) { ... }`, lange Array-Syntax `array( ... )`.
- Strings ohne Interpolation mit einfachen Quotes; mehrzeilige Arrays mit abschließendem Komma.
- Explizite Sichtbarkeit für Methoden, Properties und Klassenkonstanten.
- Vergleich bevorzugt `===`/`!==`. Bei Vergleich mit Literalen wird WordPress-konform die Konstante links gesetzt: `if ( 0 === $view_id )`.
- `empty()` DARF nicht als pauschale Validierung verwendet werden, wenn `0`, `'0'`, `false`, leer und fehlend verschiedene Bedeutungen haben.
- `isset()` und `array_key_exists()` entsprechend ihrer unterschiedlichen Null-Semantik verwenden.
- Kein verschachtelter ternärer Operator, keine schwer lesbare Mutation innerhalb einer Bedingung.
- Reine PHP-Dateien ohne schließendes `?>`. Bearbeitbare Templates verwenden ausschließlich die Template-Sprache gemäß Plan.

### 4.3 Präfixe und öffentliche Verträge

Projektweite Namen richten sich nach dem Plan.

| Gegenstand | Vorgabe |
| --- | --- |
| PHP-Namespace | `SW\Postlist` |
| Textdomain und Plugin-Slug | `sw-postlist` |
| Globale Funktionen, Hooks und Optionen | `sw_postlist_` |
| CSS-Klassen und Custom Properties | `swp-` und `--swp-` |

Öffentliche Bezeichner für REST, Block und Datenmodell gemäß dem jeweiligen dokumentierten Vertrag verwenden.

Öffentliche Methodennamen, Hooknamen, Parameter und JSON-Felder sind Verträge. Umbenennungen können auch wegen PHP Named Arguments brechend sein. Bei extern implementierten Interfaces gelten deren Namen und Signaturen.

## 5. WordPress-Integration und Lebenszyklus

- Der Plugin-Bootstrap bleibt klein: Header, Voraussetzungen, Autoloader, Composition Root und Lifecycle-Registrierung.
- Ausführbare Einstiegspunkte und direkt ladbare Templates schützen den direkten Zugriff mit dem passenden WordPress-Kontextcheck. Ein ABSPATH-Guard ist kein Ersatz für Rechteprüfungen. Reine Domain-Klassendateien benötigen keinen WordPress-Bootstrap, um getestet zu werden.
- Kein rekursives `require`-Scanning. Composer/PSR-4 verwenden; fehlenden Autoloader als Installationsfehler verständlich behandeln.
- Hooks erst zum passenden Zeitpunkt registrieren: beispielsweise CPT auf `init`, REST auf `rest_api_init`, Admin-Assets ausschließlich auf eigenen Adminseiten.
- Registrierung zentral und ausdrücklich; Priorität und Argumentzahl bewusst wählen. Abmeldbare Methoden gegenüber anonymen langlebigen Callbacks bevorzugen.
- Filter MÜSSEN einen Wert mit dem dokumentierten Typ zurückgeben. Ein Filter verändert nicht nebenbei Datenbank oder Dateisystem, sofern das nicht sein ausdrücklich dokumentierter Vertrag ist.
- Fremde Filterwerte bleiben unkontrollierte Eingaben; eine Erweiterung darf keine ungültigen Werte am Schema vorbei einschleusen.
- Keine globale Änderung fremder Queries, Upload-MIMEs, Script-Ladezeiten, Übersetzungen oder Editorstyles als Nebenwirkung.
- Assets über WordPress-APIs registrieren/einreihen; nicht per hart kodiertem Script-Tag aus einem Controller ausgeben.
- Block-Metadaten über `block.json`; nur APIs verwenden, die zur unterstützten WordPress-Version passen.
- Gutenberg nutzt die WordPress-Pakete und die vorhandene React-Laufzeit. Kein eigenes zweites React oder pauschal das gesamte Adminframework ins Frontend bündeln.
- `is_admin()` ist weder Authentifizierung noch Berechtigung und sagt nicht, ob ein Besucher eine vertrauenswürdige Aktion ausführt.
- Bei Deaktivierung eigene zeitgesteuerte Aufgaben stoppen und gespeicherte Ansichten, Templates und Revisionen erhalten.
- Uninstall prüft seinen vorgesehenen Einstiegskontext und löscht nur nach gewählter Policy eigene Daten; Site-/Netzwerkgrenzen erhalten.
- Upgrades versioniert und wiederholbar implementieren. Reguläre Seitenaufrufe sollen nur die nötige Versionsprüfung ausführen.

## 6. Eingabegrenzen, Berechtigungen und Ausgabe

### 6.1 Eingaben

**Validieren, gezielt normalisieren, am Ausgabepunkt kontextgerecht escapen.** Jede dieser Maßnahmen hat einen eigenen Zweck.

- Nur erwartete Requestfelder auslesen. Keine vollständigen Superglobal-Arrays durch einen generischen Sanitizer laufen lassen.
- Bei klassischen slashed WordPress-Superglobals einmal passend `wp_unslash()` verwenden. Bereits geparste REST-JSON-Daten **nicht pauschal erneut unslashen**.
- JSON-Fehler, Typfehler und Bereichsfehler getrennt behandeln. Ein TypeScript-Cast macht Netzwerkdaten nicht gültig.
- Identitäten nicht mit `absint()` oder `parseInt()` in scheinbar gültige Werte umformen, wenn ein negativer Wert oder `123abc` ausdrücklich ungültig ist.
- Template-Elemente und Attribute nach der freigegebenen Allowlist validieren. Beim Rendern von Platzhaltern den tatsächlichen Ausgabekontext beachten.
- URLs getrennt nach Zweck validieren: Navigation darf andere Ziele erlauben als serverseitige Downloads. Eine syntaktisch gültige URL ist kein SSRF-Schutz.
- Template-CSS gemäß dem Engine-Profil prüfen, Selektoren auf die Ansicht begrenzen und dynamische Werte nach Property validieren. Externe Importe und ungeprüfte URLs ablehnen.

### 6.2 Berechtigungen

- Jede privilegierte Operation prüft die konkrete Capability und bei objektbezogenen Aktionen das konkrete Objekt.
- Keine Rollenvergleiche wie „ist Administrator“, keine alleinige Prüfung „eingeloggt“ und kein Vertrauen in ausgeblendete UI-Schalter.
- Für Cookie-authentifizierte REST-Aufrufe den vorgesehenen REST-Nonce-Mechanismus verwenden. Klassische Adminaktionen benötigen den passenden CSRF-Schutz.
- Nonces sind keine Autorisierung, kein Ersatz für Idempotenz und keine garantiert einmal verwendbaren Tokens.
- Lesen eines Entwurfs, Bearbeiten, Veröffentlichen und Importieren anhand der jeweiligen Capability und des Objekts prüfen.
- Leseanfragen müssen frei von privilegierten Schreiboperationen bleiben.
- Eine Wiederholung nach abgelaufenem Nonce darf einen Schreibauftrag nicht unbemerkt doppelt ausführen.

### 6.3 Ausgabe nach Kontext

| Kontext | Verbindliches Verfahren |
| --- | --- |
| Normaler HTML-Text | `esc_html()` bzw. übersetzte Variante |
| HTML-Attribut | Vollständigen Attributwert mit `esc_attr()` escapen |
| `href` / `src` | Zweckgebundene URL-Policy, am HTML-Ausgang `esc_url()` |
| Textarea | `esc_textarea()` |
| Erlaubtes Rich Text | `wp_kses()` mit projektspezifischer Allowlist |
| REST-JSON | Daten an WordPress-Response übergeben; keine HTML-Escapes in Datenwerte einbauen |
| JSON in einem Script-Datenblock | `wp_json_encode()` mit kontextsicheren HEX-Flags; Fehler behandeln |
| CSS | Nur aus validierten Typen/Werten durch einen eigenen Serializer erzeugen |

Keine Inline-Eventhandler, kein `eval`, keine ungeprüfte `innerHTML`-/`v-html`-Ausgabe. Ein notwendiger Rich-Text-Ausgabepunkt wird zentral gekapselt und nutzt denselben Allowlist-Vertrag wie der Server. Der Name `safe_html` allein ist kein Sicherheitsnachweis.

HTML-Escaping wird nicht dauerhaft in das Dokument gespeichert. Andernfalls entstehen doppelte Entities und inkonsistente Wiederbearbeitung. Bereits gerendertes, intern abgesichertes HTML besitzt dagegen einen klar dokumentierten Rückgabevertrag; eine gezielte PHPCS-Ausnahme an dessen Ausgabe muss auf diesen Vertrag verweisen.

## 7. REST- und Fehlerverträge

- Jede Route erhält eine ausdrückliche `permission_callback`, eine Methodenauswahl und begrenzte Parameter.
- GET hat keine fachlichen Schreibnebenwirkungen. Listen sind paginiert und begrenzt. Requestgrößen und Ressourcenverbrauch begrenzen.
- `WP_REST_Response` und `WP_Error` an der WordPress-Grenze verwenden; keine selbst mit `echo` ausgegebenen JSON-Fragmente oder `exit` im Anwendungsfall.
- REST- und JSON-Felder gemäß dem versionierten Schema benennen. PHP mappt externe Feldnamen ausdrücklich auf seine internen Namen.
- Fehlercodes sind stabile maschinenlesbare Werte, etwa `sw_postlist_revision_conflict`. Nutzermeldungen sind übersetzbar und dürfen sich ändern.
- Übliche Statuscodes: 400 für unlesbaren Request, 401 für fehlende Authentifizierung soweit passend, 403 für fehlende Rechte, 404 für fehlendes/nicht sichtbares Objekt, 409 für Versionskonflikt, 413 für zu große Anfrage, 422 für lesbares, aber fachlich ungültiges Dokument. Die exakte Zuordnung wird in API-Tests fixiert.
- Feldfehler enthalten einen eindeutigen Dokumentpfad und einen sicheren Fehlergrund; keine vollständigen vertraulichen Dokumente als Fehlerkontext zurückgeben.
- Unerwartete technische Fehler erhalten eine sichere allgemeine Meldung plus Korrelationskennung. Interne Ursache nur im begrenzten technischen Log.
- Ein Anwendungsfall prüft seine fachlichen Voraussetzungen auch bei Aufruf außerhalb von REST, etwa über CLI oder einen Job. Benutzerautorisierung bleibt an einem ausdrücklich definierten Zugangspunkt; keine widersprüchlichen Kopien in mehreren Schichten.

Erwartete Ergebnisse wie „kein Suchtreffer“ sind keine Exceptions. Fachliche Ablehnungen können typisierte Resultate oder konkrete Exceptions sein; pro Anwendungsfall einen klaren Vertrag wählen. Technische Ausfälle werden nicht in `false`, leere Listen oder scheinbaren Erfolg umgewandelt.

`catch ( Throwable $error )` ist an einem definierten äußersten Schutzpunkt zulässig, etwa einem Queue-Worker. Im Inneren konkrete Fehlertypen fangen. Ursache erhalten, Ressourcen freigeben und genau einmal sinnvoll protokollieren. Keine leeren Catch-Blöcke, pauschale `@`-Unterdrückung oder `console.error` als alleinige Fehlerbehandlung.

## 8. Persistenz, JSON und konkurrierende Änderungen

### 8.1 Kanonische Daten

- Für eine Ansicht oder ein Template eine maßgebliche Dokumentfassung führen. Abgeleitete Daten und Caches müssen sich daraus wiederherstellen lassen.
- Datenbank, Browserstorage, Importpakete und ältere Revisionen gelten als prüfpflichtige Eingaben.
- `schemaVersion` und serverseitige Validierung sind Pflicht. Unbekannte neuere Versionen nicht still reduzieren.
- Paketlokale Importschlüssel und WordPress-IDs als unterschiedliche Identitäten behandeln. Doppelte Schlüssel und gebrochene Template-Referenzen ablehnen.
- Nichtendliche Zahlen, ungültige UTF-8-Daten, übergroße Werte und übermäßige Verschachtelung ablehnen.
- JSON-Kanonisierung und Hashbildung sind ein definierter Serververtrag. Nicht davon ausgehen, dass PHP und JavaScript beliebige Fließkommazahlen und Objektfelder bytegleich serialisieren.
- Medien über stabile WordPress-Referenzen auflösen. Installationsspezifische URLs aus portablen Beispielen und Konfigurationsdokumenten heraushalten.

### 8.2 WordPress-Fallen ausdrücklich behandeln

- `wp_insert_post()`/`wp_update_post()` und Metadaten-APIs haben historische Slashing-Verträge. Ein dedizierter Persistenzadapter übernimmt diese Behandlung genau einmal.
- JSON mit Backslashes, Quotes und HTML-ähnlichem Text MUSS nach Speichern/Laden semantisch unverändert sein. Nicht das vollständige JSON durch `sanitize_text_field()`, KSES oder `the_content` schicken.
- Da Core-Save-Filter `post_content` verändern können, MUSS die gewählte JSON-Kodierung dagegen nachweislich robust sein. Ein möglicher Adapter kodiert `<`, `>`, `&` und Quotes mit JSON-HEX-Flags und beachtet den Slashing-Vertrag. Der Roundtrip ist mit allen unterstützten Rollen und WordPress-Filtern zu testen.
- Keine globale Abschaltung von KSES oder fremden Contentfiltern, um das Speicherformat zu reparieren. Ein unvermeidbarer lokaler Hook-Eingriff benötigt genaue Eingrenzung, `finally`-Cleanup und Integrationsnachweis.
- `false` von `update_post_meta()` kann auch „Wert unverändert“ bedeuten. Rückgabewerte gemäß tatsächlichem API-Vertrag interpretieren.
- Große Kataloge, Artefakte und Dokumente nicht als autoloaded Optionen speichern. Transients dürfen vor Ablauf verschwinden und sind kein dauerhafter Speicher.
- WP-Cron ist kein pünktlicher Scheduler. UI und Jobsystem dürfen keine sekundengenaue Ausführung voraussetzen.

### 8.3 Schreibschutz und Veröffentlichung

- Versionsprüfungen beim Aktualisieren bestehender Dokumente müssen auch zwei gleichzeitig eintreffende Schreibvorgänge berücksichtigen.
- Ein Repository MUSS konkurrierende Änderungen mit einem nachgewiesenen atomaren Compare-and-Swap oder geeigneter Serialisierung verhindern. Eine transientbasierte „Lockdatei“ ohne atomaren Erwerb reicht nicht.
- Verwendete Sperren benötigen Eigentümerkennung, Ablauf-/Recovery-Regeln und Schutz davor, dass ein alter Worker nach Ablauf seiner Sperre einen neuen Stand überschreibt.
- Eine Template-Änderung vor dem Veröffentlichen vollständig validieren. Referenzierende Ansichten lösen beim Rendern die veröffentlichte Fassung auf.
- Dokument und abgeleitete Referenzen bei Teilfehlern konsistent halten. Transaktionsgrenzen und die Wiederholung unterbrochener Schreibvorgänge im Persistenzadapter berücksichtigen.
- Ein Fehler nach erfolgreichem Commit DARF nicht als sicher fehlgeschlagene Veröffentlichung präsentiert werden. Bei unklarem Ausgang Status nachlesen, bevor ein nicht idempotenter Auftrag wiederholt wird.
- Hooks mit extern sichtbaren Folgen erst nach dauerhaftem fachlichem Commit auslösen. Ein fehlgeschlagener nachgelagerter Hook rollt den bereits veröffentlichten Stand nicht automatisch zurück.
- Vor dem Entfernen eines Templates seine Verwendung prüfen, auch bei Zugriff über WordPress-Verwaltungswege.

## 9. SQL, Dateien, Archive und Jobs

### 9.1 Datenbank

- WordPress-APIs bevorzugen; SQL nur, wenn die benötigte Semantik es verlangt.
- Werte mit `$wpdb->prepare()` binden. Identifier nur aus eigener Allowlist; `%i` nur bei passender WordPress-Mindestversion und korrektem Einsatzzweck.
- Tabellenpräfixe aus WordPress ableiten, nicht `wp_` fest einbauen. Site- und Netzwerkdaten unterscheiden.
- SQL-Migrationen versionieren, wiederholbar und unter konkurrierendem Start geschützt ausführen. Kein `dbDelta()` auf jedem normalen Seitenaufruf.
- Direkte SQL-Schreibvorgänge MÜSSEN Revisionen, WordPress-Objektcache und Hookverhalten ausdrücklich berücksichtigen. Ein CAS-Fix darf keine inkonsistenten Cachewerte erzeugen.

### 9.2 Dateisystem und Archive

- URL und lokaler Pfad sind verschiedene Typen/Konzepte. Nie aus einer Besucher-URL ungeprüft einen lokalen Pfad ableiten.
- Schreiboperationen auf definierte Wurzeln begrenzen. `realpath()` allein schützt nicht bei neuen Pfaden, Symlinks oder Rennen.
- Temporäre Namen serverseitig erzeugen, nicht aus Originaldateinamen übernehmen. Dateinamen sind keine Rechteprüfung.
- Atomare Umbenennung nur innerhalb eines geeigneten Dateisystems annehmen; Speicheradapter und Fehlerverhalten nachweisen. DB-Commit und Rename bilden keine gemeinsame Atomizität.
- WordPress-Uploadfunktionen für Medien verwenden. Zulässige Dateitypen auf den jeweiligen Einstiegspunkt begrenzen.
- Bei der Verarbeitung von Build-Archiven Pfade, Symlinks, Namenskollisionen, Größen und Anzahl der Einträge prüfen. Keine ungeprüfte Extraktion.
- Importdaten als JSON mit Größenlimit und Schema prüfen. Kein `unserialize()` auf fremden Daten ausführen.
- Hashprüfung erkennt veränderte Bytes, macht einen fremden Import aber nicht vertrauenswürdig. Alle Inhalte weiterhin validieren.
- Nicht öffentliche Stagingdaten außerhalb erreichbarer Webpfade halten oder mit nachgewiesenem Zugriffsschutz sichern. `index.php` oder `.htaccess` allein schützt nicht in jeder Serverkonfiguration.
- Ressourcen mit `try/finally` schließen, temporäre Dateien bei Abbruch bereinigen. Keine vom Import nicht selbst angelegten Dateien pauschal löschen.

### 9.3 Regeln für Code, der im Hintergrund läuft

- Arbeit in begrenzten, wiederaufnehmbaren Schritten mit persistiertem Zustand ausführen. Kein Request lädt beliebig große Archive vollständig in den RAM.
- Zustandswechsel explizit modellieren: beispielsweise queued → running → completed/failed/cancelled.
- Jobs benötigen Idempotenz-/Besitzerschutz, Fortschrittsdaten und definierte Abbruchpunkte.
- Nur vorübergehende Fehler wiederholen, mit Backoff, Jitter und begrenztem Budget. Validierungsfehler und fehlende Rechte nicht endlos wiederholen.
- Nutzerabbruch ist ein eigener Zustand. Ein Browser-Abort beweist nicht, dass der Server die Mutation abgebrochen hat.
- Keine Erfolgsanzeige vor dauerhaftem Abschluss. Fortschritt nicht künstlich simulieren; unbekannter Gesamtumfang bleibt unbekannt.

## 10. Netzwerkzugriffe und Abhängigkeiten

- Laufzeit-Assets lokal bündeln. Im Browser prüfen, welche Requests Editor und Frontend tatsächlich auslösen.
- Für serverseitige Abrufe durch eine Datenquelle die WordPress Safe HTTP API verwenden. HTTPS, Zertifikate, erlaubte Ziele und Redirects bei jedem Schritt prüfen. Private, Loopback-, Link-local- und Metadatenziele gegen unberechtigten Zugriff schützen.
- Zeit, Antwortgröße und Parallelität begrenzen. Größenlimits auch beim Streaming durchsetzen. Fremde Antworten anhand ihres Formats validieren und vertrauliche Inhalte aus URLs und Logs heraushalten.

## 11. TypeScript: Verträge vor Bequemlichkeit

- `strict: true`, `noUncheckedIndexedAccess` und `exactOptionalPropertyTypes` für eigene neue TS-Module. Etwaige Library-Konflikte an Adaptergrenzen lösen, nicht projektweit abschalten.
- Keine ungeprüften `any`, doppelten Casts `as unknown as T`, Non-null Assertions oder pauschalen `@ts-ignore`.
- Rohdaten als `unknown` behandeln und zur Laufzeit validieren. `response.json() as ViewDocument` ist kein Parser.
- `type` für Unions/Datenformen, `interface` für bewusst implementierbare Objektverträge; nicht allein aus Geschmacksgründen bestehende Typen umschreiben.
- Discriminated Unions für Platzhaltertypen, Zustände und Ergebnisse. Exhaustive Switches für fachliche Varianten; unbekannte neue Werte nicht still ignorieren.
- `camelCase` für Funktionen/Variablen/JSON-Felder, PascalCase für Typen/Komponenten. Zeit- und Größenfelder tragen Einheiten wie `durationMs`.
- `readonly`/Readonly-Datenstrukturen an Übergaben verwenden, wo Mutation nicht erlaubt ist; wissen, dass TypeScript dies zur Laufzeit nicht erzwingt.
- Gleichheit mit `===`; `null`, `undefined`, `false` und `0` nach Vertrag unterscheiden. `||` DARF nicht gültige Null-/Leerwerte unabsichtlich überschreiben.
- Zentrale API-Clients mit Timeout-/Abbruchbehandlung, Response-Parsern und typisierten Fehlern; keine verstreuten fetch-Aufrufe in Komponenten.
- Keine unkontrollierten Floating Promises. Hintergrundaufrufe ausdrücklich starten und Fehler behandeln; erwartete Abbrüche separat erkennen.
- Nicht idempotente Mutationen nicht automatisch wiederholen. Bei Timeout zuerst Zustand beziehungsweise Idempotenzkennung prüfen.
- `Promise.all()` nur für unabhängige Arbeiten, deren gemeinsames Fehlerverhalten passt. Teilfehler dürfen nicht unbemerkt Ressourcen verwaisen lassen.

Beispiel für einen eindeutigen Vertrag; beide Varianten sind fachlich zu behandeln:

```ts
export type SaveDraftResult =
  | { status: 'saved'; version: number }
  | { status: 'conflict'; currentVersion: number };
```

Ein Netzwerkfehler ist nicht automatisch dieselbe Variante wie ein Versionskonflikt und muss vom Client entsprechend gesondert behandelt werden.

## 12. Vue-Editor: Zustand und Nebenwirkungen

### 12.1 Komponenten und Stores

- Vue 3 Composition API mit `<script setup lang="ts">`. Props und Emits explizit typisieren.
- Komponenten nach Verantwortung gliedern, etwa Quellenauswahl, Template-Eingabe, Platzhalter-Panel und Vorschau.
- Kleine rein visuelle Zustände lokal halten. Pinia für tatsächlich geteilten Editor-/Dokumentzustand, nicht für jedes Tooltip.
- Dokumentzustand, ausgewählte Ansicht, geladene Serverversion und Netzwerkstatus getrennt modellieren.
- Props nicht direkt mutieren. Komponenten senden Absichten, etwa `updateTemplate`, statt über verschachtelte Referenzen heimlich das Dokument zu ändern.
- `computed` für abgeleitete Werte; keine Requests, Dateizugriffe oder Dokumentmutationen in Computed-Gettern.
- Watcher nur für echte Seiteneffekte. Keine Kette gegenseitiger Watcher als Ersatz für ein Zustandsmodell.
- Listen mit stabilen IDs keyen, nicht mit Index bei umsortierbaren Einträgen.
- Bedienzustände für Laden, leer, Fehler, Konflikt, Speichern und fehlende Rechte ausdrücklich darstellen.
- `v-html` nur an einem zentral geprüften Rich-Text-Ausgabepunkt; keine beliebigen Importdaten direkt rendern.

### 12.2 Bearbeiten und Speichern

- Dokumentierte Bearbeitungsaktionen verwenden. Auswahl, Vorschaugröße und Netzwerkstatus getrennt vom gespeicherten Dokument halten.
- Speicheraufträge ordnen oder versionieren. Eine verspätete Antwort darf neuere lokale Änderungen weder überschreiben noch als gespeichert markieren.
- Konflikte mit der betroffenen Dokumentversion melden und lokale Eingaben für die weitere Bearbeitung erhalten.
- Bei lokaler Zwischenspeicherung Site, Nutzer und Dokument unterscheiden. Umfang begrenzen und Zugangsdaten aus dem Browserspeicher heraushalten.

### 12.3 Cleanup und asynchrone Rennen

- Watcher, Timer, Observer, Eventlistener, Pointer-Capture und laufende Requests beim Wechsel/Unmount sauber auflösen.
- Bei wechselnder Quellenauswahl darf eine verspätete alte Antwort nicht die neue Auswahl überschreiben. AbortController, Requestkennung oder Generation prüfen.
- Cleanup in `finally`, Watcher-Cleanup oder `onUnmounted` entsprechend der installierten Vue-Version; keine erst in neueren Versionen verfügbare API ungeprüft nutzen.
- Pointer-Events nutzen und `pointercancel` behandeln. Vertikales Scrollen auf Touchgeräten nicht pauschal verhindern.
- Größenänderungen in der Vorschau anhand der verfügbaren Containerbreite ermitteln. Observer beim Schließen der Vorschau abmelden.

## 13. Frontend und Layout

- Frontend-Module unabhängig von Vue, Pinia und Editorbibliotheken halten. Importgrenzen oder Bundle-Berichte dafür prüfen.
- Layoutregeln für Vorschau und Ausgabe aus demselben Dokument ableiten. Ein fehlendes responsives Override erbt den übergeordneten Wert. Die verfügbare Containerbreite berücksichtigen.
- Jede Einbindung verwaltet ihre IDs, URL-Parameter, Requests und Listener eigenständig. Bei Navigation veraltete Antworten verwerfen und beim Entfernen der Einbindung ihre Ressourcen freigeben.
- Den ersten Zustand serverseitig rendern und Platz für Bilder reservieren. Ladeprioritäten nach der Sichtbarkeit auf der Seite wählen.
- DOM-Lese- und Schreibzugriffe bündeln und ResizeObserver-Schleifen vermeiden. Reduzierte Bewegung auch bei einer Änderung der Systemeinstellung berücksichtigen.

## 14. CSS, Barrierefreiheit und Übersetzungen

### 14.1 Styles

- Frontend-CSS beginnt unter dem jeweiligen `swp-`-Container; Admin-CSS unter dem eigenen Editor-Root. Keine globalen Regeln für `button`, `img`, `h2`, `body` oder `.active`.
- Vue scoped Styles allein schützen globale Portals/Overlays nicht. Deren Zielcontainer und Klassen ausdrücklich festlegen.
- Gemeinsame Design-Tokens für Abstände, Farben, Ebenen und Fokus. Keine verstreuten `z-index: 999999`-Reparaturen.
- Validierte dynamische Werte über eigene Custom Properties oder erzeugte Styles ausgeben. Keine unkontrollierten Styleblöcke aus Dokumenttext.
- Eigenschaften möglichst logisch formulieren, beispielsweise `margin-inline-start`, wenn dies zum Layoutvertrag passt.
- Keine pauschalen `!important`-Regeln oder globalen Theme-Resets. Ein unvermeidbarer lokaler Konfliktfix braucht Begründung und Regressionstest.
- Admin- und Frontend-Styles separat bündeln. Die Website lädt keine Editor-Icons oder Framework-Styles für die Bearbeitung.

### 14.2 Zugänglichkeit

- Native Buttons und Links benutzen, keine klickbaren Divs als Standard.
- Jedes Eingabefeld hat eine programmatisch zugeordnete Bezeichnung. Icon-Buttons brauchen einen verständlichen Namen.
- Drag-and-drop-Funktionen benötigen eine Tastatur-/Eigenschaftenalternative. Visuelle Auswahl darf nicht allein durch Farbe vermittelt werden.
- Dialoge behandeln Initialfokus, Fokusbegrenzung, Escape soweit sinnvoll und Rückkehr zum auslösenden Element.
- Tastenkürzel auf die aktive Komponente begrenzen und Texteingaben respektieren. Verborgene Bedienelemente auch aus der Fokusreihenfolge nehmen.
- Ladezustände, Fehler und Änderungen der Trefferzahl so ankündigen, dass wiederholte Aktualisierungen die Bedienung mit Screenreader erlauben.
- Fokusdarstellung, Kontrast, Textvergrößerung und reduzierte Bewegung praktisch prüfen. ARIA korrigiert keine unpassende Grundsemantik.

### 14.3 Internationalisierung

- Codebezeichner, technische Kommentare und API-Dokumentation auf Englisch; dieses Richtliniendokument bleibt deutsch.
- Benutzerseitige PHP-Strings mit WordPress-i18n und fester Textdomain `sw-postlist`; keine dynamisch gebauten Übersetzungsschlüssel.
- Ganze Sätze übersetzen, nicht Fragmente zusammenkleben. Pluralformen korrekt modellieren, Platzhalter bei Bedarf nummerieren und Übersetzerkommentare ergänzen.
- In der Admin-SPA vue-i18n für Deutsch und Englisch verwenden. WordPress-Skripte mit übersetzbaren Texten an die WordPress-Skriptübersetzungen anbinden.
- Übersetzen und Escapen sind verschiedene Schritte. Übersetzte Strings enthalten keine ungeprüften HTML-/URL-Fragmente.
- Datums- und Zahlenwerte gemäß Locale formatieren. Gespeicherte Zeitpunkte und die Zeitzone für ihre Darstellung ausdrücklich unterscheiden.

## 15. Abhängigkeiten, Build und Distribution

- Laufzeitabhängigkeiten begründen: benötigte Funktion, Größe, Lizenz, Wartbarkeit, unterstützte Browser/PHP-Versionen und Kollisionsrisiko.
- Keine neue Library für eine triviale Hilfsfunktion. Umgekehrt keine selbst entwickelte ZIP-, Sanitizing- oder Kryptobibliothek aus vermeintlicher Unabhängigkeit.
- Composer- und npm-Lockfiles einchecken. Reproduzierbarer Build über festgelegte Skripte, nicht die lokale globale Werkzeugversion voraussetzen.
- Das gebaute Plugin muss auf dem Produktionssystem ohne Entwicklungswerkzeuge lauffähig sein.
- Gebündelte PHP-Abhängigkeiten auf Konflikte mit anderen Plugins prüfen. Falls Präfixierung etwa mit Mozart eingesetzt wird: Autoloading, Reflection, Callables, Ressourcenpfade und Lizenzdateien im gebauten Paket testen. Keine Composer-Metadaten oder Fremdquellen manuell nachbearbeiten.
- Gutenberg-Abhängigkeiten korrekt externalisieren. Eine Vue-Editorabhängigkeit darf nicht durch einen gemeinsamen Barrel-Export in die Runtime geraten.
- Buildmanifest ist maßgeblich für gehashte Assets und WordPress-Abhängigkeiten. Keine geratenen Bundle-Dateinamen.
- Keine CDN-Imports, Online-Icons oder Remote-Fonts für Standardfunktionen. Keine nachgeladenen ausführbaren Fremdmodule.
- Paket enthält Laufzeitcode, gebaute Assets, Übersetzungen und erforderliche Lizenztexte; keine Testzugangsdaten, privaten Sourcemaps, Entwicklungsserver-URLs oder `node_modules`.
- Pluginheader, Paketversion, Changelog und dokumentierte Schema-/Migrationsversionen konsistent halten. Nicht alle Versionsnummern müssen identisch sein; ihre Bedeutung muss eindeutig sein.

## 16. Tests und Qualitätssicherung

### 16.1 Werkzeuge

- PHP_CodeSniffer mit `wp-coding-standards/wpcs` und dokumentierten PSR-4-/Strict-Types-Ausnahmen.
- PHPStan mit passender WordPress-Typunterstützung; Level 8 für eigene PHP-Quellen ohne Baseline oder Ignores.
- PHPCompatibilityWP auf den tatsächlichen unterstützten PHP-Bereich konfigurieren; keine ungetestete offene Versionsspanne als Kompatibilitätsbeweis ausgeben.
- PHPUnit für reine Regeln und WordPress-Integrationstests für die tatsächlichen API-Eigenheiten.
- ESLint, Prettier, TypeScript und `vue-tsc`; Vitest für Unit- und Playwright für Browser-Tests.
- Abhängigkeitsprüfungen mit Composer/npm. Befunde nach tatsächlicher Betroffenheit bewerten und beheben oder mit nachvollziehbarer Begründung behandeln.

Konkrete Sniff-Namen und Konfigurationen MÜSSEN mit den installierten Werkzeugversionen geprüft werden. Diese Datei liefert Regeln, keinen Nachweis, dass eine noch nicht angelegte CI bereits funktioniert.

### 16.2 Welche Tests erforderlich sind

Tests prüfen beobachtbares Verhalten und wichtige Fehlerfälle. Keine pauschale Pflicht zu einem neuen Test für jede triviale oder rein visuelle Änderung. Für Datenverlust, Rechte, Import und veröffentlichte Zustände sind relevante Regressionstests Pflicht.

| Bereich | Mindestnachweise |
| --- | --- |
| Dokument/Schema | Gültig/ungültig, doppelte IDs, fehlende Ressourcen, neuere Schema-Version |
| Layout | Breakpointgrenzen, Vererbung, Zurücksetzen und Größenänderung des Containers |
| Query | Eine Quelle pro Abfrage, Sichtbarkeit, Trefferzahl, Offset und stabile Reihenfolge |
| WordPress-Speicherung | Anführungszeichen, Backslashes, Unicode, Template-HTML und verschiedene Berechtigungen |
| Veröffentlichung | Gemeinsam verwendetes Template bei gefülltem Cache aktualisieren und fehlerhafte Fassung ablehnen |
| Template-Engine | Escaping je Kontext, ungültige Formatter-Ketten und gezielte AST-Invalidierung |
| JSON-Import | Größenlimits, Schema-Version, Duplikate, Referenzzuordnung und portabler Roundtrip |
| Frontend | Mehrere Einbindungen, Navigation ohne JS, verspätete Antworten, Cleanup und reduzierte Bewegung |
| Sicherheit | Objektfremder Zugriff, CSRF-Kontext, XSS in allen Ausgabepfaden, private Vorschau |

Reine Tests nutzen kontrollierbare Uhr, IDs und Providerfixtures. Keine echten Netzwerkdienste als Voraussetzung einer Unit-Testsuite. Reale Providerchecks separat und ausdrücklich ausführen.

Visuelle Vergleiche verwenden festgelegte lokale Fonts und warten auf deren Laden. Animationen zu festen Zeitpunkten anhalten. Keine willkürlichen langen Sleeps als Ersatz für erkennbare Zustandsbedingungen.

Browser-E2E prüft zusammenhängende Bedienabläufe über die regulären Einstiegspunkte. Für Postlist eignen sich etwa eine im Admin bearbeitete Ansicht, ihre Ausgabe über Block und Shortcode sowie ein anschließender Export und Import.

### 16.3 Lint-Ausnahmen

- Keine projektweite Abschaltung von Security-, Nonce-, Escaping- oder Prepared-SQL-Prüfungen.
- Eine bekannte sichere Renderer-Ausgabe oder ein begründeter eigener Serializer erhält eine punktuelle Ausnahme mit Regelcode und Verweis auf den geprüften Vertrag.
- Keine `eslint-disable`-Dateiköpfe oder breiten PHPStan-Ignores zur Beschleunigung eines Features.
- Suppressions bei geänderter Implementierung erneut bewerten. Veraltete Ausnahmen entfernen.

## 17. Logging und Diagnose

- Strukturierte Events mit Ereigniscode, Schwere, Site-ID, Ansichts- oder Template-ID, sicherem Fehlerkontext und Korrelationskennung.
- PSR-3-kompatibler Logger über Injektion; Domain selbst braucht nicht automatisch einen Logger.
- Keine Cookies, Nonces, Tokens, vollständigen Templates, Importdokumente oder ungefilterten Remoteantworten loggen.
- Normale Validierungsfehler nicht als Servercrash protokollieren. Wiederholte Folgefehler zusammenfassen.
- Dateipfade und URLs nur soweit nötig und gegebenenfalls redigiert; Querystrings können sensible Daten enthalten.
- Keine dauernde Frame-/Pointer-/Polling-Protokollierung im Produktivbetrieb. Aufbewahrung, maximale Größe und Logzugriff definieren.
- Diagnose darf klar sagen, dass ein Template fehlt oder eine Veröffentlichung scheiterte, ohne Besucher mit Stacktraces zu konfrontieren.

## 18. Dokumentation, Änderungen und Reviews

- Öffentliche Klassen, Methoden, Hooks und Ports beschreiben Zweck, Parameter, Ergebnis, Seiteneffekte und relevante Fehler. PHPDoc ergänzt native Typen statt sie nur wortreich zu wiederholen.
- Kurze Zusammenfassungen beschreiben das Verhalten; zusätzliche Kommentare erklären nicht offensichtliche Entscheidungen und Randbedingungen.
- `@since` mit tatsächlicher Einführungsversion; nicht automatisch das aktuelle Release auf bestehende APIs schreiben.
- Neue sicherheits-/datenrelevante Entscheidungen als kurze ADR dokumentieren: Problem, gewählte Lösung, Alternativen und Konsequenzen.
- TODO-Kommentare brauchen eine konkrete Folgeaufgabe oder Milestone-ID. Sicherheitsprüfungen dürfen nicht als späteres TODO hinter einem fertigen Feature fehlen.
- Refactorings fachlich zusammenhängend halten. Keine großflächige Umformatierung fremder Bereiche im selben Fehlerfix.
- Bei einem Versionskonflikt lokale Änderungen erhalten. Unvollständige Importe mit ihrem tatsächlichen Ergebnis melden.

## 19. Verbindliche Abschlussprüfung pro Änderung

### Vor der Umsetzung

- [ ] Betroffene Produktregel, Datenvertrag und Modulgrenze gelesen.
- [ ] Bestehende Implementierung und Aufrufer verstanden; keine unnötige Parallelstruktur geplant.
- [ ] Erfolgsfall, Fehlerfall und gegebenenfalls konkurrierender Zugriff konkret benannt.

### Vor dem Abschluss

- [ ] Eingaben, Rechte und Ausgabekontexte geprüft.
- [ ] Änderungen an Dokumenten berücksichtigen Referenzen, Revisionen, Vorschau, Ausgabe und Import/Export.
- [ ] Asynchrone Vorgänge berücksichtigen Abbruch, verspätete Antworten und wiederholte Ausführung.
- [ ] Kein Editorcode in der Frontend und keine externen Asset-Anfragen eingeführt.
- [ ] Relevante Format-/Typ-/Analyseprüfungen und aussagekräftige Tests ausgeführt.
- [ ] Bei Änderungen an Assets/Abhängigkeiten das gebaute Pluginpaket geprüft.
- [ ] Migrationen, öffentliche Verträge und zugehörige Dokumentation aktualisiert.
- [ ] Nicht ausführbare Prüfungen und verbleibende Einschränkungen ausdrücklich genannt.
- [ ] Keine Behauptung praktischer Abnahme ohne tatsächliche Prüfung durch Stefan.

Eine Änderung ist erst abgeschlossen, wenn ihr Verhalten überprüfbar ist. Ein Lint-Erfolg beweist Stil; ein Typcheck beweist bestimmte Typannahmen; ein E2E-Test beweist seinen konkreten Ablauf. Keine dieser Prüfungen ersetzt automatisch die anderen.

## 20. Typische Fehlmuster und verbindliche Alternative

| Fehlmuster | Stattdessen |
| --- | --- |
| „Es ist im Admin, also sicher.“ | Capability, Objektzugriff und CSRF-Kontext prüfen. |
| „Der Client hat das JSON validiert.“ | Server validiert unabhängig nach demselben Schema. |
| „absint macht jede ID sicher.“ | Erwarteten Typ, Format und Bereich prüfen. |
| „REST-Daten immer unslashen.“ | Slashing je Eingang/API-Vertrag genau einmal behandeln. |
| „HTML-Escaping schützt auch CSS.“ | CSS-Werte typisieren und selbst serialisieren. |
| „Das letzte Save gewinnt.“ | erwartete Version atomar prüfen, Konflikt erhalten. |
| „Der Request ist abgebrochen, also wurde nichts gespeichert.“ | Ergebnis bei unklarem Ausgang abfragen. |
| „Das Template ist gespeichert, der Cache wird schon passen.“ | Die betroffene Fassung nach erfolgreichem Schreiben invalidieren und mit gefülltem Cache testen. |
| „Ein Hash bedeutet vertrauenswürdig.“ | Herkunft/Rechte und Dateiinhalte zusätzlich prüfen. |
| „Die letzte eingetroffene Vorschau ist die aktuelle.“ | Antwort der angeforderten Dokumentfassung zuordnen und veraltete Ergebnisse verwerfen. |
| „aria-hidden entfernt alle Tastaturziele.“ | Fokusfähigkeit und Sichtbarkeit gemeinsam kontrollieren. |
| „Mehr Interfaces ergeben bessere Architektur.“ | Schnittstellen an echten Grenzen, direkte einfache Logik im Inneren. |

## 21. Referenzen und Reichweite

Die folgenden Referenzen beschreiben die verwendeten APIs und Standards.

- [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- [WordPress PHP Documentation Standards](https://developer.wordpress.org/coding-standards/inline-documentation-standards/php/)
- [WordPress: Escaping Data](https://developer.wordpress.org/apis/security/escaping/)
- [WordPress: Häufige Plugin-Review-Probleme](https://developer.wordpress.org/plugins/wordpress-org/common-issues/)
- [WordPress REST: Eigene Endpunkte](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/)
- [WordPress: wp_insert_post](https://developer.wordpress.org/reference/functions/wp_insert_post/)
- [WordPress: wp_slash](https://developer.wordpress.org/reference/functions/wp_slash/)
- [WordPress: update_post_meta und Slashing](https://developer.wordpress.org/reference/functions/update_post_meta/)
- [WordPress: block.json](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/)
- [WordPressCS](https://github.com/WordPress/WordPress-Coding-Standards)
- [Vue: Watchers und Cleanup](https://vuejs.org/guide/essentials/watchers.html)
- [TypeScript: strict](https://www.typescriptlang.org/tsconfig/strict.html)

**Anweisung an Coding-Agenten:** Wende diese Regeln auf die konkrete Änderung an. Erzeuge einfache, explizite und überprüfbare Lösungen innerhalb des aktuellen Milestones. Schließe keinen Task aufgrund einer vermuteten Funktion und verschweige keine nicht ausgeführte Prüfung.
