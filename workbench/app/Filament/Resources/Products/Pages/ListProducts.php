<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products\Pages;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Products\ProductResource;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        if (ProductResource::$header !== null) {
            return (ProductResource::$header)();
        }

        // Zero config: the model carries its own rules (Product::importRules()).
        return [XlsxImportAction::make()->model(\Workbench\App\Models\Product::class)->queueWhenMoreThan(500)];
    }
}
