# Changelog

All notable changes to `asignua/filament-xlsx-import` are documented here.

## v1.0.0

First release.

- `XlsxImportAction` (page header and table header action): upload → columns → dry run → import, for any model with no configuration, or through an existing Filament `Importer`.
- `.xlsx`, `.ods` and `.csv` (BOM, UTF-16/32, Windows-1251/1252 detected, delimiter sniffed, `->csvEncoding()`), sheet picker.
- Mapping step driven by the file: its columns with sample values; targets from database columns, `$casts` and `belongsTo` / `hasOne` / `belongsToMany` relations; automatic header matching (names, labels, aliases, built-in en/uk/de/pl/fr/es synonyms, near misses).
- Casts per column, chained: trim, case, integer, decimal (`,` / `.`), boolean (so/ni, yes/no…), date and datetime with a source format, enum value tables, JSON, split, default, regex.
- `DefinesImport` model rules (aliases, casts, validation, upsert key, relation modes) shown pre-filled and marked «from model rules»; per-run overrides with «Reset to model rule».
- Saved presets (own or shared through a gate), matched by header; publishable migration.
- Dry run in a rolled-back transaction: created / updated / skipped / failed, field-level diff, duplicate keys in the file.
- Upsert by key (`->updateBy()`, `->createOnly()`, `->updateOnly()`); a transaction per row, so a failed row leaves no half-written relations.
- Queue (`->queue()`, `->queueWhenMoreThan()`): one chunked job as the user (locale, guard, panel restored and undone), progress file, database notification with a signed link; `xlsx-import:prune`.
- Failed-rows report as `.xlsx` (source row, original columns, reason), text-only cells, signed per-user link.
- Template download with real dropdowns for enum / `in:` columns.
- `->saveUsing()` for repository-only apps, `->authorizeRecords()`, file size and row limits.
- English and Ukrainian translations. Tested on Filament 5.11.
