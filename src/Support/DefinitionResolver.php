<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Contracts\DefinesImport;
use Asignua\FilamentXlsxImport\Data\Definition;
use Asignua\FilamentXlsxImport\Data\TargetField;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use LogicException;
use ReflectionClass;

final class DefinitionResolver
{
    /**
     * @param class-string<Model>|null $model
     * @param class-string|null        $importer
     * @param class-string|null        $definition a DefinesImport class; defaults to the config registry, then the model itself
     * @param array<string, mixed>     $overrides  `update_by` and `fields` from the action (same shape as DefinesImport)
     */
    public static function resolve(?string $model, ?string $importer, ?string $definition = null, array $overrides = []): Definition
    {
        if ($importer !== null) {
            if (!is_subclass_of($importer, Importer::class)) {
                throw new LogicException("[{$importer}] is not a Filament Importer.");
            }

            $model = $importer::getModel();
        } elseif ($model === null || !is_subclass_of($model, Model::class)) {
            throw new LogicException('XlsxImportAction needs ->model(Model::class) or ->importer(Importer::class).');
        }

        $rules = [];
        $source = $definition
            ?? (is_string(config('filament-xlsx-import.definitions.'.$model)) ? config('filament-xlsx-import.definitions.'.$model) : null)
            ?? ($importer !== null && is_subclass_of($importer, DefinesImport::class) ? $importer : null)
            ?? (is_subclass_of($model, DefinesImport::class) ? $model : null);

        if (is_string($source) && is_subclass_of($source, DefinesImport::class)) {
            $rules = $source::importRules();
        }

        $scope = [
            'only' => self::list($overrides['only'] ?? $rules['targets'] ?? null),
            'except' => self::list($overrides['except'] ?? $rules['except'] ?? null) ?? [],
            'relations' => self::list($overrides['relations'] ?? $rules['relations'] ?? null) ?? [],
        ];

        $targets = $importer !== null ? self::fromImporter($importer) : ModelIntrospector::targets($model, $scope);

        $rules = [
            'update_by' => $overrides['update_by'] ?? $rules['update_by'] ?? null,
            'fields' => array_replace_recursive($rules['fields'] ?? [], (array) ($overrides['fields'] ?? [])),
        ];

        $targets = self::overlay($targets, $rules['fields']);
        // The key must be something the file may write to, never a hidden way to reach the primary key or a secret.
        $updateBy = is_string($rules['update_by']) && isset($targets[$rules['update_by']]) && !$targets[$rules['update_by']]->isRelation() ? $rules['update_by'] : null;

        $notice = null;

        if ($importer === null && array_filter($targets, static fn (TargetField $t): bool => !$t->isRelation()) === []) {
            // The developer declared nothing a file may write to (a fully guarded model): say so, to the developer, not guess.
            $notice = (string) __('filament-xlsx-import::filament-xlsx-import.notice_no_targets', ['model' => class_basename($model)]);
            Log::warning("filament-xlsx-import: no import targets for [{$model}]: the model is fully guarded. Declare ->only([...]) or 'targets' in DefinesImport::importRules().");
        }

        return new Definition($model, $importer, $targets, $updateBy, $source !== null || $rules['fields'] !== [], $notice);
    }

    /**
     * @return list<string>|null
     */
    private static function list(mixed $value): ?array
    {
        return is_array($value) ? array_values(array_map('strval', $value)) : null;
    }

    /**
     * @param array<string, TargetField> $targets
     * @param array<string, mixed>       $fields
     *
     * @return array<string, TargetField>
     */
    private static function overlay(array $targets, array $fields): array
    {
        $aliases = (array) config('filament-xlsx-import.aliases', []);

        foreach ($targets as $key => $target) {
            $rule = is_array($fields[$key] ?? null) ? $fields[$key] : null;
            $extra = isset($aliases[$key]) ? array_map('strval', (array) $aliases[$key]) : [];

            if ($rule === null) {
                $targets[$key] = $extra === [] ? $target : self::with($target, ['aliases' => [...$target->aliases, ...$extra]]);

                continue;
            }

            $targets[$key] = self::with($target, [
                'aliases' => [...array_map('strval', (array) ($rule['aliases'] ?? [])), ...$extra, ...$target->aliases],
                'casts' => array_key_exists('casts', $rule) ? CastPipeline::steps((array) $rule['casts']) : $target->casts,
                'rules' => array_key_exists('rules', $rule) ? array_values((array) $rule['rules']) : $target->rules,
                'required' => (bool) ($rule['required'] ?? $target->required),
                'mode' => in_array($rule['mode'] ?? null, ['lookup', 'create'], true) ? $rule['mode'] : $target->mode,
                // A rule that says `'mode' => 'lookup'` locks the relation to «find only» (the user cannot switch it to create) unless it also says `'allow_create' => true`.
                'allowCreate' => (bool) ($rule['allow_create'] ?? (($rule['mode'] ?? null) !== 'lookup')),
                'many' => in_array($rule['many'] ?? null, ['add', 'replace'], true) ? $rule['many'] : $target->many,
                'fromRules' => true,
                'mappingRequired' => (bool) ($rule['required'] ?? false),
            ]);
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function with(TargetField $t, array $changes): TargetField
    {
        $values = [];

        foreach ((new ReflectionClass($t))->getProperties() as $property) {
            $values[$property->getName()] = $property->getValue($t);
        }

        return new TargetField(...array_replace($values, $changes));
    }

    /**
     * @param class-string<Importer> $importer
     *
     * @return array<string, TargetField>
     */
    private static function fromImporter(string $importer): array
    {
        $targets = [];

        foreach ($importer::getColumns() as $column) {
            $rules = self::rulesOf($column);
            $targets[$column->getName()] = new TargetField(
                key: $column->getName(),
                label: $column->getLabel() ?? ModelIntrospector::label($column->getName()),
                rules: $rules['rules'],
                required: $column->isMappingRequired(),
                mappingRequired: $column->isMappingRequired(),
                aliases: array_values(array_map('strval', $column->getGuesses())),
                options: $rules['options'],
                type: $column->isBoolean() ? 'boolean' : ($column->isNumeric() ? 'decimal' : 'string'),
                example: self::example($column),
            );
        }

        return $targets;
    }

    private static function example(ImportColumn $column): string
    {
        $example = $column->getExample();

        return is_scalar($example) ? (string) $example : '';
    }

    /**
     * Values a column accepts (`in:` / `Rule::in()` / `Rule::enum()`), for the template's dropdown.
     *
     * @return array{rules: list<mixed>, options: list<string>|null}
     */
    private static function rulesOf(ImportColumn $column): array
    {
        $options = null;
        $rules = [];

        try {
            $raw = $column->getDataValidationRules();
        } catch (\Throwable) {
            $raw = [];
        }

        foreach ($raw as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                $options = array_map(static fn (?string $v): string => trim((string) $v, '"'), str_getcsv(substr($rule, 3), ',', '"', ''));
            } elseif ($rule instanceof In) {
                $options = array_map(static fn (?string $v): string => trim((string) $v, '"'), str_getcsv(substr((string) $rule, 3), ',', '"', ''));
            } elseif ($rule instanceof Enum) {
                $type = (new ReflectionClass($rule))->getProperty('type')->getValue($rule);
                $options = is_string($type) && enum_exists($type) ? array_map(
                    static fn (\UnitEnum $c): string => (string) ($c instanceof \BackedEnum ? $c->value : $c->name),
                    $type::cases(),
                ) : null;
            }

            $rules[] = $rule;
        }

        return ['rules' => $rules, 'options' => $options];
    }
}
