<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products\RelationManagers;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Closure;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A many-to-many relation manager: «Import» here attaches tags to the product.
 */
class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public static ?Closure $header = null;

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->headerActions(static::$header !== null ? (static::$header)() : [
                XlsxImportAction::make()->updateBy('name')->only(['name']),
            ]);
    }
}
