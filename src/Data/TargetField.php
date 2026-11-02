<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Data;

/**
 * Something a file column can be written to: an attribute of the model (`email`) or a field of a
 * related record (`category.name`).
 */
final class TargetField
{
    /**
     * @param list<array{type: string, arg: string, arg2: string, map: array<string, string>}> $casts
     * @param list<mixed>                                                                      $rules
     * @param list<string>                                                                     $aliases
     * @param list<string>|null                                                                $options the allowed values (a dropdown in the template)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $relationType = '',
        public readonly string $relation = '',
        public readonly string $field = '',
        public readonly string $relatedModel = '',
        public readonly array $casts = [],
        public readonly array $rules = [],
        public readonly bool $required = false,
        public readonly array $aliases = [],
        public readonly ?array $options = null,
        public readonly string $mode = 'lookup',
        public readonly string $many = 'add',
        public readonly string $type = 'string',
        public readonly bool $fromRules = false,
        public readonly string $example = '',
        public readonly bool $mappingRequired = false,
        public readonly bool $allowCreate = true,
    ) {}

    public function isRelation(): bool
    {
        return $this->relation !== '';
    }

    public function isMany(): bool
    {
        return $this->relationType === 'belongsToMany';
    }
}
