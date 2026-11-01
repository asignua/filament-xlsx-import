<?php

declare(strict_types=1);

namespace Workbench\App\Import;

use Asignua\FilamentXlsxImport\Contracts\DefinesImport;

/**
 * Import rules kept apart from the model (registered in `filament-xlsx-import.definitions`).
 */
class ContactDefinition implements DefinesImport
{
    public static function importRules(): array
    {
        return [
            'update_by' => 'email',
            'fields' => [
                'email' => ['casts' => ['trim', 'lower'], 'rules' => ['email'], 'required' => true],
            ],
        ];
    }
}
