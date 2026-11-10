# Changelog

All notable changes to `asignua/filament-xlsx-import` are documented here.

## v1.1.0 - 2026-11-10

- Requires Filament ^5.11.2; replaced deprecated Filament API calls: Requires Filament ^5.11.2; replaced deprecated Filament API calls (ImportColumn::getExample() → getExamples(), ::guess() → guesses() in the workbench).

## v1.0.1 - 2026-11-02

- Formula cells import the result the spreadsheet app cached next to the formula, not the formula text (`=+380501112233`, `=COUNTIF(…)`); a formula with no cached result is an empty cell. Duration cells (`[h]:mm:ss`) read as `36:30:00` instead of blank.
- The sheet picker lists and searches every sheet of the workbook.
- The failed-row preview tallies the reasons and says when rows are hidden («showing N of M»).
- README: note that PHP's `upload_max_filesize` / `post_max_size` and Livewire's temporary-upload rule must be at least as large as `maxFileSize`.
- Translations: German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese, Turkish.

## v1.0.0

First release.

- `XlsxImportAction` (page header, table header and relation-manager action): upload → columns → dry run → import, for a model that declares what a file may write to (`$fillable`, `$guarded = []`, `->only()` / `targets`), or through an existing Filament `Importer`.
- `.xlsx`, `.ods` and `.csv` (BOM, UTF-16/32, Windows-1251/1252 detected, delimiter sniffed, `->csvEncoding()`), sheet picker.
- Mapping step driven by the file: its columns with sample values; targets from the model's declaration (`$fillable` / guarded), `$casts` and its `belongsTo` / `hasOne` relations (`belongsToMany` only when named); automatic header matching (names, labels, aliases, built-in en/uk/de/pl/fr/es synonyms, near misses).
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

Second review round (before the first tag):

- Targets follow what the developer declared: `$fillable` is the whole list, a fully guarded model offers nothing until `->only()` / `targets`; JSON columns, `*_id` keys and camelCase secrets are never offered; related fields follow the related model's declaration; the login model is never an automatic relation target; `hasOne` writes need the related model's `update` / `create` policy.
- Casts see the cell's own type: a number cell is never read with a thousands rule (12.5 stayed 12.5, not 125), text decimals are declared (decimal + thousands separator) or refused when ambiguous, real date cells and ISO/Excel-serial dates are taken before the text format, blanks skip field rules, the template examples follow the rules.
- Mapping screen: «from model rules» and reset for whatever target is chosen, enum value table as a select of the cases with labels, date format picker, live «sample → result» line, «create» offered only when allowed (with the reason), «Auto» for CSV encoding/delimiter and the detected values shown, «show only unmapped columns» and a sticky list of the required fields still unmapped.
- README: fair comparison with eightynine/filament-excel-import and a migration table of its API.

Final review round (before the first tag):

- Secret and privilege names are recognised in any spelling (`PASSWORD`, `IS_ADMIN`, `is-admin`, `apiToken`); every belongsTo foreign key, `*_id` / `*_uuid` / `*_ulid` column and JSON-cast column is left out of the raw attribute targets.
- `->allowCreatingRelated()` only concerns creating: an existing related record (a `hasOne` account) always needs the related model's `update` policy; `->allowUpdatingRelatedWithoutPolicy()` is the explicit, dangerous opt-out.
- Value tables: distinct values are deduplicated ignoring case and an empty row never cancels a filled one; the enum class survives presets and the browser; the sticky «still to map» summary sticks and lists each field once; a wrong decimal separator is named in the message.
- A model with nothing to offer hides the button (a developer error with `app.debug`), logged once per class.
- Relation managers: records are created/looked up/attached through the owner's relationship (hasMany, hasOne, morphMany, belongsToMany), the owner's key is never a target.
- `->fixedAttributes()`: server-side values merged into every row.

Focused security round:

- Relation manager (hasMany): the inverse `belongsTo` of the owner relation is no target and the owner's key is re-applied to existing records — a file cannot move a record to another owner.
- The button is hidden through `isHidden()` (a model with no ATTRIBUTE targets, relation-only targets included); the upload refuses by the same rule.
- Relation manager (belongsToMany): existing shared records are attached, not rewritten (`->updateAttachedRecords()` opts in); attaching needs the owner policy's `attach` / `attachAny` / `update`.
- `fixedAttributes()`: plain keys are trusted developer input (documented).

Polish:

- Relation manager: a key that exists under another owner fails with «<key> belongs to another <Owner> — not changed» instead of falling through to create.
- The «still to map» summary has an opaque panel (light/dark) and is pinned only while required fields are unmapped.
- The preview shows incoming and stored values through the model's cast (`decimal:2` → «12.50 → 13.00»).

