<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved mapping (targets, casts, relation modes) plus the run options, kept for one import target
 * (a model or an Importer class). Matching a file to it is done by header, so a changed column order does not matter.
 *
 * @property int                       $id
 * @property string                    $target
 * @property string                    $name
 * @property string|null               $user_id
 * @property string|null               $guard
 * @property string|null               $panel
 * @property string|null               $tenant
 * @property bool                      $shared
 * @property list<array<string, mixed>> $mapping
 * @property array<string, mixed>|null $options
 */
class ImportPreset extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-xlsx-import.presets.table', 'xlsx_import_presets');
    }

    protected function casts(): array
    {
        return ['shared' => 'boolean', 'mapping' => 'array', 'options' => 'array'];
    }

    /**
     * Presets of this import target the user may use: their own, and the shared ones — within the same guard, panel and tenant.
     *
     * @param Builder<ImportPreset>                                              $query
     * @param array{guard: string|null, panel: string|null, tenant: string|null} $context
     *
     * @return Builder<ImportPreset>
     */
    public function scopeVisibleTo(Builder $query, string $target, int|string|null $userId, array $context): Builder
    {
        foreach ($context as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query->where('target', $target)->where(function (Builder $q) use ($userId): void {
            $q->where('shared', true);

            if ($userId !== null) {
                $q->orWhere('user_id', (string) $userId);
            }
        });
    }
}
