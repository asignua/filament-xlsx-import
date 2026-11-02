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

Security hardening (from an independent review, before the first tag):

- Zero config no longer offers the primary key, timestamps, `$hidden` attributes, secrets, privilege flags or the tenant column; `$fillable` restricts; `->only()` / `->except()` / `DefinesImport` `targets`; the key and every target are re-checked server-side.
- `belongsToMany` only when allowlisted, role/permission relations never automatic; `mode` is a request (rule `'lookup'` locks it), creating related records needs the related `create` policy or `->allowCreatingRelated()`; tenant-scoped lookups, creates and upserts; `->relatedQueryUsing()`.
- `authorizeRecords()` checks after the new values are filled and defaults to on when the model has a policy.
- Dry run and row transactions use the model's (and related models') connection; an implicit commit aborts the preview.
- A crashed run reports how many rows were already saved; `queue.after` hook; the worker's URL root is restored; run ids are bound to user, guard, panel and target; presets are scoped by guard, panel and tenant and restore the key and mode; no SQL/bindings in row errors; row limit enforced while streaming and a column cap.
