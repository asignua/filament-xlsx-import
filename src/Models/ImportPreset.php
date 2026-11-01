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
     * @param Builder<ImportPreset> $query
     *
     * @return Builder<ImportPreset>
     */
    public function scopeVisibleTo(Builder $query, string $target, int|string|null $userId): Builder
    {
        return $query->where('target', $target)->where(function (Builder $q) use ($userId): void {
            $q->where('shared', true);

            if ($userId !== null) {
                $q->orWhere('user_id', (string) $userId);
            }
        });
    }
}
