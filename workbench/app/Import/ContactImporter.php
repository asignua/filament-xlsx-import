<?php

declare(strict_types=1);

namespace Workbench\App\Import;

use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Contact;

/**
 * An ordinary Filament Importer: the plugin runs it as it is.
 */
class ContactImporter extends Importer
{
    protected static ?string $model = Contact::class;

    /** Hook calls recorded for the tests. */
    public static array $hooks = [];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')->requiredMapping()->rules(['required', 'max:255'])->example('Ada Lovelace'),
            ImportColumn::make('email')->requiredMapping()->rules(['required', 'email'])->guesses(['e-mail', 'mail'])->example('ada@example.com'),
            ImportColumn::make('phone')->rules(['nullable', 'max:30']),
            ImportColumn::make('city')->rules(['nullable', 'in:Lviv,Kyiv,Odesa']),
        ];
    }

    public function resolveRecord(): ?Model
    {
        return Contact::query()->firstWhere('email', $this->data['email']) ?? new Contact;
    }

    protected function afterSave(): void
    {
        static::$hooks[] = 'afterSave:'.$this->record->email;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return 'done';
    }
}
