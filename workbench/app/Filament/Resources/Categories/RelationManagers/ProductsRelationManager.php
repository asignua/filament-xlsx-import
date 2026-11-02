<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Categories\RelationManagers;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Closure;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A hasMany relation manager: «Import» here creates the category's products.
 */
class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    /** Replaces the header actions in a test. */
    public static ?Closure $header = null;

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('sku'), TextColumn::make('name')])
            ->headerActions(static::$header !== null ? (static::$header)() : [
                XlsxImportAction::make()->updateBy('sku')->only(['sku', 'name', 'price'])->allowCreatingRelated(),
            ]);
    }
}
