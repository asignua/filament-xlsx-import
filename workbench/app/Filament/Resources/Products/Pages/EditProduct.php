<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\EditRecord;
use Workbench\App\Filament\Resources\Products\ProductResource;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;
}
