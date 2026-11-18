# Template-Engine

Die Engine liegt in `src/TemplateEngine/` (PHP, Namespace `SW\Postlist\TemplateEngine`) und `apps/admin/src/template-engine/` (TypeScript-Gegenstück für den Editor). Sie ist WordPress- und domänenfrei und wird in M10 als Composer-/npm-Paket ausgelagert. Der Architekturtest (`tests/Unit/TemplateEngine/ArchitectureTest.php`) schlägt fehl, wenn die Engine etwas außerhalb ihres Namespace importiert oder eine Funktion aufruft, die ohne WordPress nicht existiert.

Die Sprache für Template-Autoren steht in [templates.md](templates.md) (Syntax, Platzhalter, Formatter). Dieses Dokument beschreibt die Schnittstelle für Integratoren.

## Aufbau

| Stufe | PHP | TypeScript |
| --- | --- | --- |
| Lexer (HTML-bewusst, Attributwerte werden mit tokenisiert) | `Syntax/Lexer` | `lexer.ts` |
| Parser (Baum, Elemente und Abschnitte) | `Syntax/Parser`, `Syntax/ExpressionParser` | `parser.ts`, `expression.ts` |
| Validator (Profil, Schema, Formatterketten) | `Validator`, `ChainTyper` | `validator.ts`, `typer.ts` |
| AST-Bauer (statisches Markup zusammengefasst) | `AstBuilder` | `astBuilder.ts` |
| CSS-Begrenzung | `CssScoper` | `css.ts` |
| Renderer (Kontext-Escaping) | `Renderer` | `renderer.ts` |
| Fassade mit AST-Cache | `Engine`, `Compiler` | `compileTemplate()` |

## Ports (vom Host implementiert)

`PlaceholderSchemaInterface`, `ValueResolverInterface`, `FormatterRegistryInterface` / `FormatterInterface`, `EscaperInterface`, `AstCacheInterface`, `ClockInterface`, `LocaleInterface`. Postlist liefert die WordPress-Adapter unter `src/Infrastructure/WordPress/Engine/` (`WpEscaper`, `WpLocale`, `WpClock`, `WpAstCache`, `PlaceholderRegistry`, `EntryValueResolver`, `ImageFormatter`).

## Profil

`EngineProfile` legt erlaubte Elemente und Attribute, das Klassenpräfix (`swp-tpl-`, bei CloudCalendar `swc-tpl-`) und die Formatter-Freigabe fest. `EngineProfile::cloudcalendar()` (Formatter aus) bleibt syntaktisch kompatibel zu sw-cloudcalendar. Das Profil geht per Fingerabdruck in den Cache-Schlüssel ein.

## Typen und Kontexte

`text`, `number`, `date`, `list`, `url`, `html`, `flag`, `color`, `image`. Der Compiler prüft jede Formatterkette typweise und die Verwendbarkeit des Endtyps im Ausgabekontext:

| Kontext | erlaubte Endtypen |
| --- | --- |
| Textknoten | text, number, date, url, color, html (vertrauenswürdige Komponente, nur hier) |
| normales Attribut | text, number, date, url, color |
| `href`/`src`/`cite` | genau ein Platzhalter vom Typ url oder image, oder eine statische sichere URL |
| `style` | nur color (Wert wird zur Laufzeit als `#rrggbb` geprüft) |
| Abschnitt `{{#x}}` | jeder Typ, kein Formatter; leer/`false`/`0`/leere Liste blenden aus |

Fehlercodes (stabil, in beiden Implementierungen gleich): `unknown_placeholder`, `unknown_formatter`, `formatters_disabled`, `incompatible_formatter`, `missing_parameter`, `invalid_parameter`, `too_many_parameters`, `invalid_formatter_syntax`, `section_formatter`, `type_not_allowed_in_context`, `html_only_in_text`, `url_attribute_mixed`, `unsafe_url`, `invalid_style`, `style_section`, `disallowed_tag`, `disallowed_attribute`, `event_handler_attribute`, `boolean_attribute_dynamic`, `invalid_attribute_value`, `duplicate_attribute`, `unclosed_element`, `mismatched_end_tag`, `void_end_tag`, `self_closing_element`, `unclosed_section`, `unexpected_section_end`, `section_crosses_element`, `unsupported_syntax`, `unclosed_placeholder`, `invalid_placeholder`, `placeholder_in_tag`, `unquoted_placeholder`, `malformed_tag`, `unclosed_comment`, `disallowed_markup`, `too_deep`, `too_long`, `invalid_encoding`, `invalid_css`.

## Versionierung

`EngineProfile::ENGINE_VERSION` (PHP) und `ENGINE_VERSION` (`profile.ts`) sind Teil jedes Cache-Schlüssels. **Jede Änderung am AST-Format oder am kompilierten Verhalten erhöht sie.** Die öffentliche API gilt ab M2 als stabilitätsrelevant (AGENTS.md): keine Breaking Changes ohne Abstimmung; neue Formatter/Fehlercodes sind additiv. Öffentliche Klassen und Funktionen tragen `@since`.

## Cache

`AstCacheKey::build()` = SHA-256 über Template, CSS, Engine-Version, Profil, Schema-Fingerabdruck (inkl. erlaubter Meta-Schlüssel und Taxonomien), Formatter-Signaturen, Rolle, Template-ID und die Cache-Generationen des Hosts. Ein beschädigter Eintrag (`CompiledTemplate::from_array()` prüft Struktur, Tiefe, Namen und Typen) wird neu kompiliert. Fehler werden nie gecacht.

Postlist invalidiert über Generationszähler (`AstGenerations`), nicht durch Löschen: Ereignisse (`wp_after_insert_post`, `deleted_post`, Meta- und Term-Hooks, veröffentlichtes Template, `sw_postlist_invalidate_template_ast`) erhöhen den Zähler der Quelle oder des Templates, das nächste Rendern kompiliert neu. Der Filter `sw_postlist_ast_invalidation_scope` kann die Invalidierung bei Inhaltsänderungen je Quelle abschalten; da der AST keinen Inhalt enthält, erscheinen aktuelle Titel auch bei einem Cache-Treffer.

## Verträge zwischen PHP und TypeScript

- `tests/contract/template-engine/*.json`: 144 Fälle (Template, CSS, Profil, Rolle, Daten, erwartetes HTML/CSS oder Fehlercodes). `TemplateEngineContractTest` (PHP) und `templateEngine.test.ts` (TS) führen dieselben Dateien aus.
- `tests/contract/formatter-signatures.json`: Signaturen der eingebauten Formatter; beide Seiten vergleichen dagegen (`SWP_UPDATE_CONTRACT=1` schreibt die Datei nach einer bewussten Änderung neu).
- Datums-, Zeit- und Zahlenformatter (`date`, `relative_date`, `number`, `currency`) hängen von Locale und Zeitzone des Hosts ab und werden in PHP mit festen Zeiten geprüft (`FormatterTest`), nicht im gemeinsamen Vertrag.
- Der WordPress-Formatter `image` existiert nur serverseitig; `IMAGE_SIGNATURE` beschreibt ihn für den Editor.
