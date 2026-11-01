<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Contacts;

use Closure;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\Contacts\Pages\ListContacts;
use Workbench\App\Models\Contact;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    public static ?Closure $header = null;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('email'),
            TextColumn::make('phone'),
            TextColumn::make('city'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListContacts::route('/')];
    }
}
