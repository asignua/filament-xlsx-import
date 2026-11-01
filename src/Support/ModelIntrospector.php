<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Data\TargetField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Builds the list of fillable targets from a model alone — the database columns, `$casts`, and the
 * `belongsTo` / `hasOne` / `belongsToMany` relations found by reflecting on return types — so an
 * import works with no configuration.
 */
final class ModelIntrospector
{
    private const array SKIPPED = ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];

    private const array RELATIONS = [BelongsTo::class => 'belongsTo', HasOne::class => 'hasOne', BelongsToMany::class => 'belongsToMany'];

    /**
     * @param class-string<Model> $modelClass
     *
     * @return array<string, TargetField>
     */
    public static function targets(string $modelClass): array
    {
        $model = new $modelClass;
        $targets = [];
        $casts = $model->getCasts();

        foreach (self::columns($model) as $column) {
            $name = $column['name'];

            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }

            $type = self::columnKind($column);
            $cast = isset($casts[$name]) && is_string($casts[$name]) ? $casts[$name] : null;
            $enum = $cast !== null && enum_exists($cast) ? $cast : null;
            $suggested = CastPipeline::suggest($cast, $type);

            $targets[$name] = new TargetField(
                key: $name,
                label: self::label($name),
                casts: $suggested,
                required: !$column['nullable'] && $column['default'] === null && !$column['auto_increment'],
                options: $enum !== null ? self::enumValues($enum) : null,
                type: $enum !== null ? 'enum' : self::kindOf($suggested, $type),
            );
        }

        foreach (self::relations($model) as $relationName => [$relationType, $related]) {
            $relatedTable = $related->getTable();
            $columns = array_values(array_filter(
                self::columns($related),
                static fn (array $c): bool => in_array(self::columnKind($c), ['string'], true)
                    && !in_array($c['name'], [...self::SKIPPED, 'password'], true),
            ));

            foreach (array_slice($columns, 0, 8) as $column) {
                $key = $relationName.'.'.$column['name'];
                $aliases = [$relationName.' '.$column['name'], $relationName.'_'.$column['name']];

                if (in_array($column['name'], ['name', 'title'], true)) {
                    $aliases[] = $relationName;
                }

                $targets[$key] = new TargetField(
                    key: $key,
                    label: self::label($relationName).' → '.self::label($column['name']),
                    relationType: $relationType,
                    relation: $relationName,
                    field: $column['name'],
                    relatedModel: $related::class,
                    casts: CastPipeline::steps(['trim']),
                    aliases: $aliases,
                    type: 'string',
                );
            }

            unset($relatedTable);
        }

        return $targets;
    }

    /**
     * @return list<array{name: string, type_name: string, type: string, nullable: bool, default: mixed, auto_increment: bool}>
     */
    public static function columns(Model $model): array
    {
        try {
            /** @var list<array{name: string, type_name: string, type: string, nullable: bool, default: mixed, auto_increment: bool}> */
            return Schema::connection($model->getConnectionName())->getColumns($model->getTable());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<array{type: string}> $casts
     */
    private static function kindOf(array $casts, string $fallback): string
    {
        foreach ($casts as $cast) {
            if (in_array($cast['type'], ['integer', 'decimal', 'boolean', 'date', 'datetime', 'json'], true)) {
                return $cast['type'];
            }
        }

        return $fallback;
    }

    /**
     * @param array{type_name: string, type: string} $column
     */
    public static function columnKind(array $column): string
    {
        $name = strtolower($column['type_name']);
        $type = strtolower($column['type']);

        return match (true) {
            str_contains($type, 'tinyint(1)'), in_array($name, ['bool', 'boolean'], true) => 'boolean',
            in_array($name, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'int4', 'int8'], true) => 'integer',
            in_array($name, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8'], true) => 'decimal',
            $name === 'date' => 'date',
            in_array($name, ['datetime', 'timestamp', 'timestamptz'], true) => 'datetime',
            $name === 'json', $name === 'jsonb' => 'json',
            str_contains($name, 'char'), str_contains($name, 'text'), $name === 'varchar', $name === 'string' => 'string',
            default => 'other',
        };
    }

    /**
     * @return array<string, array{0: string, 1: Model}>
     */
    private static function relations(Model $model): array
    {
        $found = [];

        foreach ((new \ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0 || $method->getDeclaringClass()->getName() === Model::class) {
                continue;
            }

            $returns = $method->getReturnType();

            if (!$returns instanceof ReflectionNamedType || !is_a($returns->getName(), Relation::class, true)) {
                continue;
            }

            $kind = null;

            foreach (self::RELATIONS as $class => $name) {
                if (is_a($returns->getName(), $class, true)) {
                    $kind = $name;
                }
            }

            if ($kind === null) {
                continue;
            }

            try {
                $relation = $method->invoke($model);

                if ($relation instanceof Relation) {
                    $found[$method->getName()] = [$kind, $relation->getRelated()];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function enumValues(string $class): array
    {
        /** @var list<\UnitEnum> $cases */
        $cases = $class::cases();

        return array_map(static fn (\UnitEnum $case): string => (string) ($case instanceof \BackedEnum ? $case->value : $case->name), $cases);
    }

    public static function label(string $name): string
    {
        $key = 'validation.attributes.'.$name;
        $translated = __($key);

        return $translated !== $key ? (string) $translated : Str::headline($name);
    }
}
