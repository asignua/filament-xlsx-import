<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * Multi-tenant panels: what a file looks up, updates or creates belongs to the current tenant. Records without the
 * panel's ownership relationship are left alone (use `relatedQueryUsing()` for those).
 */
final class TenantScope
{
    public static function current(): ?Model
    {
        try {
            return Filament::hasTenancy() ? Filament::getTenant() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    public static function scope(Builder $query, Model $model): Builder
    {
        $tenant = self::current();
        $relation = $tenant !== null ? self::relation($model) : null;

        if ($tenant === null || $relation === null) {
            return $query;
        }

        return $query->where($model->qualifyColumn($relation->getForeignKeyName()), $tenant->getKey());
    }

    public static function assign(Model $model): void
    {
        $tenant = self::current();
        $relation = $tenant !== null ? self::relation($model) : null;

        $relation?->associate($tenant);
    }

    /**
     * @return BelongsTo<Model, Model>|null
     */
    private static function relation(Model $model): ?BelongsTo
    {
        try {
            $name = Filament::getTenantOwnershipRelationshipName();
            $relation = method_exists($model, $name) ? $model->{$name}() : null;

            return $relation instanceof BelongsTo ? $relation : null;
        } catch (Throwable) {
            return null;
        }
    }
}
