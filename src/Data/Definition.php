<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * Everything the mapping screen and the engines need to know about one import target: the model
 * (or the Importer class), the fields that can be filled and the rules the model predefined.
 */
final class Definition
{
    /**
     * @param class-string<Model>        $model
     * @param class-string|null          $importer a Filament Importer class when the host already has one
     * @param array<string, TargetField> $targets
     */
    public function __construct(
        public readonly string $model,
        public readonly ?string $importer,
        public readonly array $targets,
        public readonly ?string $updateBy = null,
        public readonly bool $hasRules = false,
    ) {}

    public function target(string $key): ?TargetField
    {
        return $this->targets[$key] ?? null;
    }

    /**
     * The preset / ownership key: presets belong to one importer or model.
     */
    public function key(): string
    {
        return $this->importer ?? $this->model;
    }

    /**
     * Select options grouped for the mapping dropdown.
     *
     * @return array<string, array<string, string>>
     */
    public function options(): array
    {
        $attributes = [];
        $related = [];

        foreach ($this->targets as $target) {
            if ($target->isRelation()) {
                $related[$target->key] = $target->label;
            } else {
                $attributes[$target->key] = $target->label;
            }
        }

        return array_filter([
            __('filament-xlsx-import::filament-xlsx-import.group_attributes') => $attributes,
            __('filament-xlsx-import::filament-xlsx-import.group_related') => $related,
        ]);
    }
}
