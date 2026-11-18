# Hooks und Erweiterungspunkte

Stand M8, abgeglichen mit allen `apply_filters`/`do_action` im Code. Alle Hooks sind Verträge: abwärtskompatibel halten, Umbenennungen nur nach Abstimmung.

| Hook | Art | Seit | Zweck |
| --- | --- | --- | --- |
| `sw_postlist_entry_sources` | Filter | 0.1.0 | Entry Sources (`EntrySourceInterface`) ergänzen. Das Ergebnis wird von `SourceRegistry` geprüft (Interface, eindeutige ID, `query_fields()`-Schema). Fehler erscheinen in `Services::diagnostics()`, nie als Fatal Error. |
| `sw_postlist_query_args` | Filter | 0.1.0 | WP_Query-Argumente der eingebauten Quelle vor der Abfrage einschränken. Danach erzwingt der Adapter Post Type, `publish` und `has_password=false` und entfernt `fields`, `p`, `page_id`, `name`, `pagename`, `perm`. Ein Nicht-Array wird ignoriert. |
| `sw_postlist_query_args_{view_id}` | Filter | 0.1.0 | Wie oben, nur für eine Ansicht. |
| `sw_postlist_entries` | Filter | 0.1.0 | Darstellungsdaten (`Entry::with_data()`) ergänzen. Identität, Quelle, Mitgliedschaft und Reihenfolge werden geprüft; bei Verstoß liefert der Query-Service einen neutralen Fehler ohne Trefferzahl (`entries_contract_violation`) und protokolliert. Einträge ausblenden: in `sw_postlist_query_args` einschränken. |
| `sw_postlist_formatters` | Filter | 0.1.0 | Formatter (`FormatterInterface` mit typisierter Signatur) ergänzen. Fehlerhafte Registrierungen erscheinen in der Diagnose. |
| `sw_postlist_placeholders` | Filter | 0.1.0 | Platzhalter der Quelle ergänzen (`$placeholders` nach Name, `$source_id`). Ungültige Einträge erscheinen in der Diagnose. |
| `sw_postlist_allowed_meta_keys` | Filter | 0.1.0 | Meta-Schlüssel, die Templates einer Quelle lesen dürfen (`$keys`, `$source_id`). Schlüssel mit führendem Unterstrich bleiben ausgeschlossen. |
| `sw_postlist_ast_invalidation_scope` | Filter | 0.1.0 | Ob eine Inhaltsänderung kompilierte Templates der Quelle invalidiert (`$invalidate`, `$reason`: `post_saved`, `post_deleted`, `meta_changed`, `terms_changed`, `$source_id`). Template-, CSS-, Engine-, Schema- und Formatteränderungen wirken unabhängig davon über den Cache-Schlüssel. |
| `sw_postlist_invalidate_template_ast` | Action | 0.1.0 | Löst die Invalidierung aus Integrationen aus (`$reason`, `$source_or_template_id`, optional weiterer Kontext). |
| `sw_postlist_template_saved` | Action | 0.1.0 | Nach dem Schreiben eines Templates (`$template_id`); invalidiert die kompilierten Fassungen. |
| `sw_postlist_filter_types` | Filter | 0.7.0 | Liste von `SW\Postlist\Application\Filter\FilterTypeDefinition` (Besucherfilter-Typen mit Optionsschema, Parse- und Render-Callback). Fehler erscheinen in der Diagnose, eingebaute Typen bleiben. Siehe [Filtertypen erweitern](#filtertypen-erweitern). |
| `sw_postlist_public_cache_control` | Filter | 0.7.0 | `Cache-Control`-Header der öffentlichen Nachlade-Route (Standard `no-cache`), zweiter Parameter ist die Ansichts-ID. |
| `sw_postlist_layouts` | Filter | 0.6.0 | Liste von `SW\Postlist\Application\Layout\LayoutDefinition` (ID, Label, Optionsschema, optionales Frontend-Modul, optionaler Render-Callback, Item-Tag). Ersetzt den nie veröffentlichten Filter `sw_postlist_layout_types`. Fehler werden in der Diagnose gemeldet, eingebaute Layouts bleiben erhalten. Siehe [Layouts erweitern](#layouts-erweitern). |
| `sw_postlist_manage_capability` | Filter | 0.1.0 | Capability zur Verwaltung (Standard `manage_options`). Wer sie besitzt, hat implizit die typspezifischen Capabilities der internen Post Types. |
| `sw_postlist_before_render` | Action | 0.1.0 | Vor dem Rendern einer Ansicht (`$view_id`, `RenderRequest`). |
| `sw_postlist_render_html` | Filter | 0.1.0 | Markup einer gerenderten Ansicht (`$html`, `$view_id`, `RenderResult`). Ein Nicht-String wird ignoriert. |
| `sw_postlist_after_render` | Action | 0.1.0 | Nach dem Rendern, auch bei Fehlern (`$view_id`, `RenderResult`). |
| `sw_postlist_presets` | Filter | 0.1.0 | Presets (Dokumente wie `templates/presets/*.json`, Schlüssel = ID) ergänzen. Ungültige Einträge erscheinen in der Diagnose und werden ignoriert; ein eigenes Preset mit gleicher ID überschreibt sie. |
| `sw_postlist_log` | Action | 0.1.0 | Spiegelt Diagnoseeinträge (`'warning', $code, $message, $context`) für externe Logger. |

## Werte eigener Quellen

Eine Quelle liefert die Werte der Platzhalter in `Entry::data`, entweder unter dem vollen Namen (`entry.title`) oder ohne Präfix (`title`). Akzeptiert werden Text, Ganzzahlen, Gleitkommazahlen (als Text), `DateTimeImmutable`, `ImageValue`, `TrustedHtml` und Listen von Texten; alles andere ergibt einen leeren Wert. Die Engine escaped Werte wie bei Beiträgen. Die Platzhalter deklariert `EntrySourceInterface::placeholders()`. Ein lauffähiges Beispiel mit Quelle, Formatter, Layout und Filtertyp liegt in `tests/Fixtures/example-extension/` und wird von `ExtensionContractTest` ausgeführt.

## Zählvertrag für eigene Quellen

`query_fields()['count_mode']`:

- `exact`: `EntryResult::total` ist die sichtbare Gesamtzahl **nach** Startoffset, `has_more` ist exakt.
- `continuation`: `total` bleibt `null`; `has_more` ist geprüft (ein weiterer sichtbarer Treffer wurde gelesen). Siehe `docs/decisions.md` ADR-05.

`can_view()` ist die letzte Prüfung; ein Widerspruch zur Abfrage ergibt `visibility_violation` ohne Zahl.


## Layouts erweitern

Eigene Layouts werden über `sw_postlist_layouts` registriert. Eingebaut sind `grid`, `list`, `masonry`, `hover_grid` und `slider`.

```php
use SW\Postlist\Application\Layout\LayoutDefinition;

add_filter( 'sw_postlist_layouts', static function ( array $layouts ): array {
	$layouts[] = new LayoutDefinition(
		'timeline',                         // [a-z0-9_-]{1,40}
		'Timeline',                         // Anzeigename im Editor
		array(                              // Optionsschema: nur diese Schlüssel (plus `type`) sind erlaubt
			'dense' => array( 'type' => 'bool' ),
			'mark'  => array( 'type' => 'enum', 'values' => array( 'dot', 'line' ) ),
		),
		null,                               // optionales Frontend-Modul: assets/js/{name}.js des Plugins
		static fn ( string $items, array $options ): string => '<ol class="acme-timeline">' . $items . '</ol>'
	);
	return $layouts;
} );
```

- Schematypen: `int` (`min`, `max`), `bool`, `string` (`pattern`, `null`), `enum` (`values`), `device_int` und `device_bool` (Schlüssel `desktop`, `tablet`, `mobile`).
- Der Render-Callback erhält die gerenderten Einträge und die Layout-Optionen und muss einen String zurückgeben. Wirft er oder liefert er etwas anderes, erscheint eine neutrale Fehlermeldung ohne Details und das Ereignis `layout_callback_failed` im Protokoll.
- Eigene Layouts nutzen die Standard-Templates des Layouttyps `grid`, solange der Ersteller keine eigenen Templates zuweist.
- Frontend-Module liegen unter `assets/js/` des Plugins und werden nur geladen, wenn eine Ansicht des Layouts auf der Seite gerendert wird (`defer`, Footer, Plugin-Version als Cache-Buster). Der Wrapper `.swp-view` trägt `data-swp-module` und `data-swp-options` (JSON).
- Im Editor erscheinen eigene Layouts in der Auswahl. Ihre Optionen werden ausschließlich serverseitig validiert; für sie gibt es noch keine eigenen Formularfelder.
- Route `GET /admin/layouts` (siehe [rest-api.md](rest-api.md)) liefert IDs, Labels, Module und Schemas.


## Filtertypen erweitern

Eingebaut sind `taxonomy`, `author`, `search` und `sort` (Optionen siehe [Schema](../schemas/view-document.v1.schema.json) und `BuiltinFilterTypes`). Eigene Typen registriert `sw_postlist_filter_types`:

```php
use SW\Postlist\Application\Filter\FilterTypeDefinition;

add_filter( 'sw_postlist_filter_types', static function ( array $types ): array {
	$types[] = new FilterTypeDefinition(
		'region',                                   // [a-z0-9_-]{1,40}, nicht taxonomy/author/search/sort
		'Region',
		array( 'label' => array( 'type' => 'string', 'null' => true ) ), // Optionsschema wie bei Layouts
		// Parse: rohe Werte des Besuchers (Liste von Strings) -> Einschränkung der Auswahl.
		static fn ( array $config, array $values ): array => array( 'terms' => array( 'region' => array_map( 'intval', $values ) ) ),
		// Render: HTML des Feldes. Der Callback escaped selbst. $name ist der Feldname, $id ein eindeutiges Element-ID.
		static fn ( array $config, array $values, string $name, string $id ): string => '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $values[0] ?? '' ) . '">'
	);
	return $types;
} );
```

- Schematypen: wie bei Layouts, zusätzlich `id_list` (positive Ganzzahlen, höchstens 50) und `enum_list` (eindeutige Werte aus `values`, höchstens 50).
- Der Parse-Callback darf `terms` (Taxonomie => Term-IDs), `authors`, `search`, `orderby` und `order` zurückgeben. Das Ergebnis wird erneut bereinigt (Typen, Längen, Allowlist der Sortierfelder). Ein Filter kann die Auswahl nur einschränken: die Quelle bildet die Schnittmenge mit der festen Auswahl, Sichtbarkeit gilt davor.
- Wirft ein Callback oder liefert er den falschen Typ, wird das Feld nicht gezeigt bzw. der Filter nicht angewendet und `filter_callback_failed` bzw. `filter_render_failed` protokolliert; die Seite bleibt intakt.
- Im Editor erscheinen eigene Filter als „(Erweiterung)“: Optionen werden nur serverseitig validiert und bleiben beim Speichern erhalten.
- Der URL-Parameter ist `swp[{Instanz}][f][{id}]`.
