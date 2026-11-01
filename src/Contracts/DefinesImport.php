<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Contracts;

/**
 * Implemented by a model (or by a separate class registered in `filament-xlsx-import.definitions`
 * or with `XlsxImportAction::definition()`) to predefine how a file is imported into it. Everything
 * returned here is only the starting point of the mapping screen: the user may change any of it
 * for one run, and a «reset to model rule» button brings it back.
 *
 * ```php
 * public static function importRules(): array
 * {
 *     return [
 *         'update_by' => 'email',                       // upsert key (an attribute target)
 *         'fields' => [
 *             'email' => ['aliases' => ['e-mail', 'пошта'], 'casts' => ['trim', 'lower'], 'rules' => ['email'], 'required' => true],
 *             'price' => ['casts' => [['type' => 'decimal', 'arg' => ',']]],
 *             'category.name' => ['mode' => 'create'],  // find-or-create the related record
 *             'tags.name' => ['many' => 'add', 'casts' => ['split:;']],
 *         ],
 *     ];
 * }
 * ```
 */
interface DefinesImport
{
    /**
     * @return array{update_by?: string|null, fields?: array<string, array<string, mixed>>}
     */
    public static function importRules(): array;
}
