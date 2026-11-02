<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Data\TargetField;
use Filament\Facades\Filament;
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
     * What a file may write to, decided from the model alone — and never more than a user-facing form would offer:
     * the primary key, timestamps, `$hidden` attributes, secrets (`password`, `*_token`, `two_factor_*`…), privilege
     * flags (`is_admin`, `role`) and the tenant ownership column are never offered; a model with a non-empty `$fillable`
     * is restricted to it. `belongsTo` / `hasOne` targets are discovered (never to role/permission models);
     * `belongsToMany` only when named in `$scope['relations']`. `$scope['only']` is an explicit allowlist (it may
     * name the primary key or a secret: that is the developer's decision), `$scope['except']` removes targets.
     *
     * @param class-string<Model>                                                                   $modelClass
     * @param array{only?: list<string>|null, except?: list<string>, relations?: list<string>|null} $scope
     *
     * @return array<string, TargetField>
     */
    public static function targets(string $modelClass, array $scope = []): array
    {
        $model = new $modelClass;
        $only = $scope['only'] ?? null;
        $except = $scope['except'] ?? [];
        $relationAllowlist = $scope['relations'] ?? [];
        $targets = [];
        $casts = $model->getCasts();
        $foreignKeys = [];

        foreach (self::relations($model) as [$kind, , $foreignKey]) {
            if ($kind === 'belongsTo' && $foreignKey !== null) {
                $foreignKeys[] = $foreignKey;
            }
        }

        foreach (self::columns($model) as $column) {
            $name = $column['name'];

            $type = self::columnKind($column);
            $cast = is_string($casts[$name] ?? null) ? $casts[$name] : null;
            $enum = $cast !== null && enum_exists($cast) ? $cast : null;
            $suggested = CastPipeline::suggest($cast, $type);

            // «json» may come from the cast only (SQLite stores json as text): the cast counts.
            if (!self::attributeAllowed($model, $name, $only, $except, self::kindOf($suggested, $type), $foreignKeys)) {
                continue;
            }

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
            if (!self::relationAllowed($relationName, $relationType, $related, $only, $except, $relationAllowlist)) {
                continue;
            }

            // The related model's own declaration decides what may be written into it (same rules as for the main model),
            // unless the developer listed `relation.field` explicitly.
            $columns = array_values(array_filter(
                self::columns($related),
                static fn (array $c): bool => self::columnKind($c) === 'string'
                    && ($only !== null
                        ? in_array($relationName.'.'.$c['name'], $only, true)
                        : self::attributeAllowed($related, $c['name'], null, [], self::columnKind($c))),
            ));

            foreach (array_slice($columns, 0, 8) as $column) {
                $key = $relationName.'.'.$column['name'];

                if ($only !== null && !in_array($key, $only, true)) {
                    continue;
                }

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
        }

        return $targets;
    }

    /**
     * Follows what the developer declared: `$fillable` if there is one; a totally guarded model (the Eloquent default, or
     * `$guarded = ['*']`) offers NOTHING until `->only()` / `targets` say so; otherwise every column that is not guarded.
     * The deny-list (key, hidden, secrets, tenant, JSON, `*_id` keys) applies on top as defence in depth.
     *
     * @param list<string>|null $only
     * @param list<string>      $except
     * @param list<string>      $foreignKeys columns that are the foreign key of one of the model's belongsTo relations
     */
    private static function attributeAllowed(Model $model, string $name, ?array $only, array $except, string $kind = 'string', array $foreignKeys = []): bool
    {
        if (in_array($name, $except, true)) {
            return false;
        }

        if ($only !== null) {
            return in_array($name, $only, true);
        }

        if ($name === $model->getKeyName() || in_array($name, self::SKIPPED, true) || in_array($name, $model->getHidden(), true)
            || self::sensitiveName($name) || in_array($name, self::tenantColumns($model), true)) {
            return false;
        }

        // Structured data and foreign keys are reached through the relation lookup, not as raw attributes.
        // Whatever its type: an integer, a ULID or a UUID string, a column called boss_ref that a belongsTo points at.
        if ($kind === 'json' || in_array($name, $foreignKeys, true) || preg_match('/_(id|uuid|ulid)$/i', $name) === 1) {
            return false;
        }

        $fillable = $model->getFillable();

        if ($fillable !== []) {
            return in_array($name, $fillable, true);
        }

        return !$model->totallyGuarded() && !in_array($name, $model->getGuarded(), true);
    }

    /**
     * @param list<string>|null $only
     * @param list<string>      $except
     * @param list<string>      $allowlist
     */
    private static function relationAllowed(string $name, string $type, Model $related, ?array $only, array $except, array $allowlist): bool
    {
        if (in_array($name, $except, true)) {
            return false;
        }

        $named = in_array($name, $allowlist, true);

        if (self::privilegeModel($name, $related) && !$named) {
            return false;
        }

        if ($only !== null) {
            return $named || array_filter($only, static fn (string $key): bool => str_starts_with($key, $name.'.')) !== [];
        }

        return $type !== 'belongsToMany' || $named;
    }

    private static function privilegeModel(string $relation, Model $related): bool
    {
        foreach (['Spatie\Permission\Models\Role', 'Spatie\Permission\Models\Permission', 'Spatie\Permission\Contracts\Role', 'Spatie\Permission\Contracts\Permission'] as $class) {
            if ($related instanceof $class) {
                return true;
            }
        }

        // The account model of the app (what people log in as) is as privileged as a role.
        foreach ((array) config('auth.providers', []) as $provider) {
            $class = is_array($provider) ? ($provider['model'] ?? null) : null;

            if (is_string($class) && $class !== '' && $related instanceof $class) {
                return true;
            }
        }

        return in_array(strtolower($relation), ['roles', 'role', 'permissions', 'permission'], true)
            || in_array($related->getTable(), ['roles', 'permissions'], true);
    }

    public static function sensitiveName(string $name): bool
    {
        // Hyphens and spaces read as underscores; the name is tested as it is, lower-cased, and — only when it has lower-case
        // letters, i.e. is camelCase (apiToken) — in its snake_case form (Str::snake('PASSWORD') is «p_a_s_s_w_o_r_d»: useless).
        $normal = str_replace(['-', ' '], '_', trim($name));
        $candidates = [mb_strtolower($normal)];

        if (preg_match('/[a-z]/', $normal) === 1) {
            $candidates[] = Str::snake($normal);
        }

        foreach ($candidates as $candidate) {
            if (preg_match('/password|passwd|^secret$|_secret$|^token$|_token$|^api_key$|^two_factor|^otp|^remember_token$|^(is_)?(super_?)?admin$|^is_(staff|root|owner)$|^roles?(_id|_uuid|_ulid)?$|^permissions?$/', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The column that ties a record to the current Filament tenant (the panel's ownership relationship), if the panel has tenancy.
     *
     * @return list<string>
     */
    public static function tenantColumns(Model $model): array
    {
        try {
            if (!Filament::hasTenancy()) {
                return [];
            }

            $relation = Filament::getTenantOwnershipRelationshipName();

            if (!method_exists($model, $relation)) {
                return [];
            }

            $relation = $model->{$relation}();

            return $relation instanceof BelongsTo ? [$relation->getForeignKeyName()] : [];
        } catch (Throwable) {
            return [];
        }
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
            in_array($name, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'int2', 'int4', 'int8', 'serial', 'bigserial'], true) => 'integer',
            in_array($name, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8'], true) => 'decimal',
            $name === 'date' => 'date',
            in_array($name, ['datetime', 'timestamp', 'timestamptz'], true) => 'datetime',
            $name === 'json', $name === 'jsonb' => 'json',
            str_contains($name, 'char'), str_contains($name, 'text'), $name === 'varchar', $name === 'string' => 'string',
            default => 'other',
        };
    }

    /**
     * @return array<string, array{0: string, 1: Model, 2: string|null}>
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
                    $found[$method->getName()] = [$kind, $relation->getRelated(), $relation instanceof BelongsTo ? $relation->getForeignKeyName() : null];
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
