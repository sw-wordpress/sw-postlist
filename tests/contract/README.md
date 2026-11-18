# Vertragsfixtures (PHP und TypeScript)

Gemeinsame JSON-Fälle, die **beide** Seiten gegen dieselbe Erwartung prüfen:

- `view-document/*.json`: Ansichtsdokument, `valid` oder erwarteter Fehlercode (M1).
- `template-engine/*.json`: Template, Profil, Daten, erwartetes HTML bzw. erwarteter Fehler (M2). Werden später 1:1 ins Engine-Paket übernommen (M10).

Jede Datei enthält `name`, `type`, `input`, `expect`. Die Loader-Tests `tests/Unit/ContractFixturesTest.php` und `apps/admin/tests/contract.test.ts` prüfen, dass alle Fixtures wohlgeformt sind. Neue Prüfer binden sich an diese Struktur.
