<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products;

use Closure;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Models\Product;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    

    /** Replaces the header actions of the list page in a test. */
    public static ?Closure $header = null;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sku')->searchable()->sortable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('category.name')->label('Category'),
            TextColumn::make('tags.name')->badge(),
            TextColumn::make('price')->money('USD')->alignEnd(),
            TextColumn::make('stock')->alignEnd(),
            TextColumn::make('status')->badge(),
            IconColumn::make('is_featured')->boolean(),
            TextColumn::make('released_at')->date(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListProducts::route('/')];
    }
}
