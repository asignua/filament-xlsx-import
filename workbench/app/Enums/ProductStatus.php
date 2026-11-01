<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
