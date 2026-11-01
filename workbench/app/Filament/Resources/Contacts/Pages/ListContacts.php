<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Contacts\Pages;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Contacts\ContactResource;
use Workbench\App\Import\ContactImporter;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        if (ContactResource::$header !== null) {
            return (ContactResource::$header)();
        }

        return [XlsxImportAction::make()->importer(ContactImporter::class)];
    }
}
