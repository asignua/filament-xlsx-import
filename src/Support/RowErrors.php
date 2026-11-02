<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What a user may read about a failed row: validation and cast messages as they are; a database error as a
 * generic sentence («duplicate value in email»), never the SQL, the bindings or the database name — those go to the log.
 */
final class RowErrors
{
    public static function message(Throwable $e): string
    {
        if ($e instanceof RowFailed || $e instanceof CastException) {
            return $e->getMessage();
        }

        if ($e instanceof ValidationException) {
            return implode(' ', array_merge(...array_values($e->errors())));
        }

        if (class_exists(\Filament\Actions\Imports\Exceptions\RowImportFailedException::class) && $e instanceof \Filament\Actions\Imports\Exceptions\RowImportFailedException) {
            return $e->getMessage();
        }

        report($e);

        if ($e instanceof QueryException) {
            $code = (string) $e->getCode();
            $text = $e->getMessage();

            if (in_array($code, ['23000', '23505', '1062'], true) || str_contains($text, 'UNIQUE constraint') || str_contains($text, 'Duplicate entry') || str_contains($text, 'unique constraint')) {
                $column = self::column($text);

                return $column !== null
                    ? __('filament-xlsx-import::filament-xlsx-import.row.duplicate_in', ['column' => $column])
                    : __('filament-xlsx-import::filament-xlsx-import.row.duplicate');
            }

            return __('filament-xlsx-import::filament-xlsx-import.row.database_error');
        }

        return __('filament-xlsx-import::filament-xlsx-import.row.unexpected');
    }

    private static function column(string $text): ?string
    {
        foreach ([
            '/UNIQUE constraint failed: [\w$]+\.([\w$]+)/',      // SQLite
            '/Key \(([\w$]+)\)=/',                               // PostgreSQL
            '/for key \'[\w$]*?\.?[\w$]*?_([\w$]+?)_unique\'/',   // MySQL, Laravel's index name: table_column_unique
        ] as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
