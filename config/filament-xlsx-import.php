<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | `max_file_size` is in kilobytes, `max_rows` counts data rows (not the header). Override per
    | action with ->maxFileSize() / ->maxRows(). null = no limit.
    |
    */
    'max_file_size' => 10_240,
    'max_rows' => 100_000,
    'max_columns' => 200,

    /*
    |--------------------------------------------------------------------------
    | Dry run
    |--------------------------------------------------------------------------
    |
    | The preview step runs the whole import inside a transaction that is rolled back. It reads at most
    | `dry_run.max_rows` rows (the summary says so when the file is longer); the real import always
    | reads every row. Hooks of an Importer class, observers and `saveUsing` run in a dry run too, on the model's own
    | connection(s), inside a transaction that is rolled back. What is not the database is NOT undone: queued jobs that
    | are not `afterCommit`, Scout/search indexing with `after_commit = false`, cache writes, files, mail, auto-increment
    | gaps. A statement that commits implicitly (DDL, TRUNCATE on MySQL) ends the preview with «the data may have been
    | written». Switch it off per action with ->dryRun(false).
    |
    */
    'dry_run' => [
        'enabled' => true,
        'max_rows' => 5_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Files with more rows than ->queueWhenMoreThan() (or every file after ->queue()) are imported in
    | one background job that commits every `chunk_size` rows and writes its progress next to the
    | file; the user gets a database notification (the panel needs ->databaseNotifications()).
    | `enabled` = false makes every queued action synchronous. `timeout` is in seconds and is kept
    | 10 s under the connection's `retry_after`. `before` is a callable `fn (User $user, ?Panel $panel)`
    | run in the worker first (panel middleware does not run there); `after` (same signature) undoes it, and runs even when the job failed.
    |
    */
    'queue' => [
        'enabled' => true,
        'connection' => null,
        'queue' => null,
        'timeout' => 3600,
        'max_per_user' => 2,
        'before' => null,
        'after' => null,
    ],

    'chunk_size' => 200,

    /*
    | Where uploads, previews and failed-rows reports live (a local-driver disk) and for how long.
    | `xlsx-import:prune` deletes older runs (scheduled daily). 0 = never prune (links then live 30 days).
    */
    'storage' => [
        'disk' => 'local',
        'directory' => 'filament-xlsx-imports',
        'retention_hours' => 24,
    ],

    'report' => [
        'register_route' => true,
        'path' => 'filament-xlsx-import/report/{id}',
        'middleware' => ['web'],
    ],

    'schedule' => [
        'enabled' => true,
        'time' => '03:55',
    ],

    /*
    | CSV: encodings tried (in this order of preference) when a file without a BOM is not valid UTF-8,
    | and the delimiters sniffed.
    */
    'csv' => [
        'fallback_encodings' => ['Windows-1251', 'Windows-1252'],
        'delimiters' => [',', ';', "\t", '|'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Saved presets (mapping + casts + options)
    |--------------------------------------------------------------------------
    |
    | Run `php artisan vendor:publish --tag=filament-xlsx-import-migrations` and migrate. `share_ability`
    | is a Gate ability: only users it allows can share a preset with everybody (undefined = nobody).
    |
    */
    'presets' => [
        'enabled' => true,
        'table' => 'xlsx_import_presets',
        'share_ability' => 'shareXlsxImportPresets',
    ],

    /*
    | Model => definition class (implements Contracts\DefinesImport) for models you cannot edit.
    */
    'definitions' => [],

    /*
    | Header synonyms by canonical field name, on top of the built-in ones (see resources/aliases.php).
    */
    'aliases' => [],
];
