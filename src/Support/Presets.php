<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Models\ImportPreset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Saved mappings. Visible to their owner and, when shared, to everybody who can run the import;
 * only a user the `share_ability` gate allows can share one.
 */
final class Presets
{
    public static function enabled(): bool
    {
        if (!(bool) config('filament-xlsx-import.presets.enabled', true)) {
            return false;
        }

        try {
            return Schema::hasTable((string) config('filament-xlsx-import.presets.table', 'xlsx_import_presets'));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int|string, string>
     */
    public static function options(string $target, int|string|null $userId): array
    {
        if (!self::enabled()) {
            return [];
        }

        $options = [];

        foreach (ImportPreset::query()->visibleTo($target, $userId)->orderBy('name')->get() as $preset) {
            $options[$preset->getKey()] = $preset->name.($preset->shared ? ' ('.__('filament-xlsx-import::filament-xlsx-import.preset_shared_tag').')' : '');
        }

        return $options;
    }

    public static function find(int|string $id, string $target, int|string|null $userId): ?ImportPreset
    {
        return self::enabled() ? ImportPreset::query()->visibleTo($target, $userId)->whereKey($id)->first() : null;
    }

    public static function canShare(): bool
    {
        $ability = config('filament-xlsx-import.presets.share_ability');

        return is_string($ability) && Gate::has($ability) && Gate::allows($ability);
    }

    /**
     * Saves (or overwrites the owner's own preset of the same name).
     *
     * @param list<array<string, mixed>> $mapping
     * @param array<string, mixed>       $options
     */
    public static function save(string $target, int|string|null $userId, string $name, array $mapping, array $options, bool $shared): ?ImportPreset
    {
        $name = trim($name);

        if ($name === '' || !self::enabled()) {
            return null;
        }

        $preset = ImportPreset::query()->where('target', $target)->where('name', $name)->where('user_id', $userId === null ? null : (string) $userId)->first() ?? new ImportPreset;

        $preset->forceFill([
            'target' => $target,
            'name' => $name,
            'user_id' => $userId === null ? null : (string) $userId,
            'shared' => $shared && self::canShare(),
            'mapping' => array_map(static fn (array $e): array => [
                'header' => $e['header'], 'target' => $e['target'], 'casts' => $e['casts'], 'mode' => $e['mode'], 'many' => $e['many'],
            ], $mapping),
            'options' => $options,
        ])->save();

        return $preset;
    }
}
