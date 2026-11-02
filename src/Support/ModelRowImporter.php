<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Data\Definition;
use Asignua\FilamentXlsxImport\Data\TargetField;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Writes one row into a model with no Importer class: casts, validation, upsert by key, related
 * records (find-or-create), a transaction per row. Attributes are assigned explicitly
 * (`forceFill`), so models with mass assignment off just work; `saveUsing` replaces the write
 * for apps that go through a repository.
 */
final class ModelRowImporter implements RowImporter
{
    /** @var array<string, true> */
    private array $seen = [];

    /**
     * @param list<array{header: string, target: string|null, casts: list<array{type: string, arg: string, arg2: string, map: array<string, string>}>, mode: string, many: string}> $mapping
     * @param array<string, mixed>                                                                                                                                                  $spec
     * @param Relation<Model, Model, mixed>|null                                                                                                                                    $owner
     * @param Closure(array<string, mixed>, ?Model, ImportContext): (Model|null)|null                                                                                               $saveUsing
     */
    public function __construct(
        private readonly Definition $definition,
        private readonly array $mapping,
        private readonly array $spec,
        private readonly ?Authenticatable $user,
        private readonly bool $dryRun,
        private readonly ?Closure $saveUsing = null,
        private readonly ?Closure $relatedQuery = null,
        private readonly ?Closure $fixed = null,
        private readonly ?Relation $owner = null,
    ) {
        $this->connections = Connections::names($definition);
    }

    /** @var list<string> */
    private array $connections;

    public function finish(): void {}

    public function process(array $row, int $number): RowOutcome
    {
        try {
            [$attributes, $relations] = $this->extract($row);
            $this->applyFixed($attributes, $relations, $number);
            $key = is_string($this->spec['update_by'] ?? null) ? $this->spec['update_by'] : null;
            $existing = null;
            $duplicate = false;

            if ($key !== null) {
                $value = $attributes[$key] ?? null;

                if ($value === null || $value === '') {
                    return RowOutcome::failed(__('filament-xlsx-import::filament-xlsx-import.row.key_empty', ['field' => $this->definition->target($key)->label ?? $key]));
                }

                $existing = $this->scopeToOwner(TenantScope::scope($this->definition->model::query(), new ($this->definition->model)))->where($key, $value)->first();
                $token = $key.'='.(is_scalar($value) ? (string) $value : json_encode($value));
                $duplicate = isset($this->seen[$token]);
                $this->seen[$token] = true;
            }

            $label = $this->labelFor($attributes, $key);
            $mode = (string) ($this->spec['existing'] ?? 'update');

            if ($existing !== null && $mode === 'skip') {
                return new RowOutcome(RowOutcome::SKIPPED, __('filament-xlsx-import::filament-xlsx-import.row.exists'), [], $label, $duplicate);
            }

            if ($existing === null && $key !== null && $mode === 'only') {
                return new RowOutcome(RowOutcome::SKIPPED, __('filament-xlsx-import::filament-xlsx-import.row.not_found'), [], $label, $duplicate);
            }

            $this->validate($attributes, $relations, $existing === null);

            $changes = match (true) {
                $existing === null => $this->newValues($attributes, $relations),
                $this->attachOnly() => [],
                default => $this->diff($existing, $attributes, $relations),
            };

            // A record found by its key that is not yet attached to the owner (relation manager, many-to-many) still has to be attached.
            if ($existing !== null && $changes === [] && $this->owner instanceof BelongsToMany && !$this->isAttached($existing)) {
                $changes = [__('filament-xlsx-import::filament-xlsx-import.row.attached') => [null, '✓']];
            }

            if ($existing !== null && $changes === []) {
                return new RowOutcome(RowOutcome::SKIPPED, __('filament-xlsx-import::filament-xlsx-import.row.unchanged'), [], $label, $duplicate);
            }

            Connections::transaction($this->connections, fn () => $this->write($existing, $attributes, $relations, $number));

            return new RowOutcome($existing !== null ? RowOutcome::UPDATED : RowOutcome::CREATED, '', $changes, $label, $duplicate);
        } catch (Throwable $e) {
            return RowOutcome::failed(RowErrors::message($e));
        }
    }

    /**
     * @param array<string, string> $row
     *
     * @return array{0: array<string, mixed>, 1: array<string, array{target: TargetField, fields: array<string, mixed>, mode: string, many: string}>}
     */
    private function extract(array $row): array
    {
        $attributes = [];
        $relations = [];

        foreach ($this->mapping as $entry) {
            $target = $entry['target'] !== null ? $this->definition->target($entry['target']) : null;

            if ($target === null) {
                continue;
            }

            try {
                $value = CastPipeline::apply($row[$entry['header']] ?? '', Mapping::withEnumClass($entry['casts'], $target));
            } catch (CastException $e) {
                throw new CastException($target->label.': '.$e->getMessage());
            }

            if ($value === '' || $value === null || $value === []) {
                continue;
            }

            if ($target->isRelation()) {
                $relations[$target->relation] ??= ['target' => $target, 'fields' => [], 'mode' => $entry['mode'], 'many' => $entry['many']];
                $relations[$target->relation]['fields'][$target->field] = $value;
            } else {
                $attributes[$target->key] = $value;
            }
        }

        return [$attributes, $relations];
    }

    /**
     * @param array<string, mixed>                                                                                $attributes
     * @param array<string, array{target: TargetField, fields: array<string, mixed>, mode: string, many: string}> $relations
     */
    private function validate(array $attributes, array $relations, bool $isNew): void
    {
        $data = [];
        $rules = [];
        $names = [];

        foreach ($this->mapping as $entry) {
            $target = $entry['target'] !== null ? $this->definition->target($entry['target']) : null;

            if ($target === null) {
                continue;
            }

            $field = str_replace('.', '__', $target->key);
            $data[$field] = $target->isRelation() ? ($relations[$target->relation]['fields'][$target->field] ?? null) : ($attributes[$target->key] ?? null);
            $names[$field] = $target->label;
            $list = $target->rules;

            if ($target->required && $isNew && !in_array('required', $list, true)) {
                array_unshift($list, 'required');
            }

            // A blank cell means «leave it as it is» (it was never put in the attributes): the rules of a field with no value
            // do not run — except `required`, which stays what it says.
            if ($data[$field] === null && !in_array('required', $list, true)) {
                continue;
            }

            if ($list !== []) {
                $rules[$field] = $list;
            }
        }

        if ($rules !== []) {
            Validator::validate($data, $rules, [], $names);
        }
    }

    /**
     * @param array<string, mixed>                                                                                $attributes
     * @param array<string, array{target: TargetField, fields: array<string, mixed>, mode: string, many: string}> $relations
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    private function diff(Model $existing, array $attributes, array $relations): array
    {
        $changes = [];

        foreach ($attributes as $name => $new) {
            $old = $existing->getAttribute($name);
            $target = $this->definition->target($name);
            $oldText = self::display($old, $target->type ?? 'string');
            $newText = self::display($new, $target->type ?? 'string');

            if (!self::same($oldText, $newText)) {
                $changes[$target->label ?? $name] = [$oldText, $newText];
            }
        }

        foreach ($relations as $name => $info) {
            $current = $existing->{$name};

            foreach ($info['fields'] as $field => $new) {
                $label = $info['target']->label;

                if ($info['target']->isMany()) {
                    $have = $existing->{$name}->pluck($field)->map(fn ($v): string => (string) $v)->all();
                    $want = array_map('strval', (array) $new);
                    $missing = array_diff($want, $have);

                    if ($missing !== [] || ($info['many'] === 'replace' && array_diff($have, $want) !== [])) {
                        $changes[$label] = [implode(', ', $have), implode(', ', $info['many'] === 'replace' ? $want : [...$have, ...$missing])];
                    }

                    continue;
                }

                $old = $current instanceof Model ? (string) $current->getAttribute($field) : '';

                if ($old !== (string) $new) {
                    $changes[$label] = [$old === '' ? null : $old, (string) $new];
                }
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed>                                                                                $attributes
     * @param array<string, array{target: TargetField, fields: array<string, mixed>, mode: string, many: string}> $relations
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    private function newValues(array $attributes, array $relations): array
    {
        $values = [];

        foreach ($attributes as $name => $value) {
            $target = $this->definition->target($name);
            $values[$target->label ?? $name] = [null, self::display($value, $target->type ?? 'string')];
        }

        foreach ($relations as $info) {
            foreach ($info['fields'] as $value) {
                $values[$info['target']->label] = [null, is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value];
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed>                                                                                $attributes
     * @param array<string, array{target: TargetField, fields: array<string, mixed>, mode: string, many: string}> $relations
     */
    private function write(?Model $existing, array $attributes, array $relations, int $number): void
    {
        if ($this->saveUsing !== null) {
            // The write is not ours here, so the check can only see the stored record (or the class, for a new one).
            $this->authorize($existing ?? $this->definition->model);

            $data = $attributes;

            foreach ($relations as $name => $info) {
                $data[$name] = $info['fields'];
            }

            ($this->saveUsing)($data, $existing, new ImportContext($this->user, $number, $this->dryRun, $this->spec));

            return;
        }

        // Many-to-many relation manager: an existing record is shared with other owners — it is attached, not rewritten.
        if ($existing !== null && $this->attachOnly()) {
            $this->authorizeAttach($existing);

            if ($this->owner instanceof BelongsToMany) {
                $this->owner->syncWithoutDetaching([$existing->getKey()]);
            }

            return;
        }

        $model = $existing ?? new ($this->definition->model);

        foreach ($relations as $name => $info) {
            if ($info['target']->relationType !== 'belongsTo') {
                continue;
            }

            $related = $this->resolve($info);
            $relation = $model->{$name}();

            if ($relation instanceof BelongsTo) {
                $relation->associate($related);
            }
        }

        $model->forceFill($attributes);

        if ($existing === null) {
            TenantScope::assign($model);
        }

        // The owner's key is applied to every record, existing or new, after anything else ran: defence in depth.
        $this->ownRecord($model);

        // After the new values are in the model: a policy that looks at the record sees what is about to be saved.
        $this->authorize($model);
        $model->save();

        if ($this->owner instanceof BelongsToMany) {
            $this->authorizeAttach($model);
            $this->owner->syncWithoutDetaching([$model->getKey()]);
        }

        foreach ($relations as $name => $info) {
            $relation = $model->{$name}();

            if ($relation instanceof HasOne) {
                $one = $relation->first();
                $creating = $one === null;
                $one ??= $relation->getRelated()->newInstance();
                $one->forceFill($info['fields']);
                $this->authorizeRelatedWrite($one, $info['target'], $creating);
                $relation->save($one);
            } elseif ($relation instanceof BelongsToMany) {
                $field = array_key_first($info['fields']);
                $ids = [];

                foreach ((array) $info['fields'][$field] as $value) {
                    $record = $this->resolve(['target' => $info['target'], 'fields' => [$field => $value], 'mode' => $info['mode'], 'many' => $info['many']]);

                    $ids[] = $record->getKey();
                }

                $info['many'] === 'replace' ? $relation->sync($ids) : $relation->syncWithoutDetaching($ids);
            }
        }
    }

    /**
     * Values the developer fixes for every row (`->fixedAttributes()`): server-side, not mappable, and they win over the file.
     * Keys are attributes, or `relation.field` of a relation target the definition has.
     *
     * @param array<string, mixed>                                                                                $attributes
     * @param array<string, array{target: TargetField, fields: array<string, mixed>, mode: string, many: string}> $relations
     */
    private function applyFixed(array &$attributes, array &$relations, int $number): void
    {
        if ($this->fixed === null) {
            return;
        }

        $values = ($this->fixed)(new ImportContext($this->user, $number, $this->dryRun, $this->spec));

        foreach ((array) $values as $key => $value) {
            $key = (string) $key;

            if (!str_contains($key, '.')) {
                $attributes[$key] = $value;

                continue;
            }

            $target = $this->definition->target($key);

            if ($target === null || !$target->isRelation()) {
                throw new RowFailed(__('filament-xlsx-import::filament-xlsx-import.row.fixed_unknown', ['key' => $key]));
            }

            $relations[$target->relation] ??= ['target' => $target, 'fields' => [], 'mode' => $target->mode, 'many' => $target->many];
            $relations[$target->relation]['fields'][$target->field] = $value;
        }
    }

    /**
     * Relation manager: an existing record is looked up among the owner's own (hasMany); a many-to-many attaches existing ones.
     *
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private function scopeToOwner(Builder $query): Builder
    {
        if ($this->owner instanceof HasOneOrMany) {
            $query->where($this->owner->getQualifiedForeignKeyName(), $this->owner->getParentKey());

            if ($this->owner instanceof MorphOneOrMany) {
                $query->where($this->owner->getQualifiedMorphType(), $this->owner->getMorphClass());
            }
        }

        return $query;
    }

    private function ownRecord(Model $model): void
    {
        if (!$this->owner instanceof HasOneOrMany) {
            return;
        }

        $model->setAttribute($this->owner->getForeignKeyName(), $this->owner->getParentKey());

        if ($this->owner instanceof MorphOneOrMany) {
            $model->setAttribute($this->owner->getMorphType(), $this->owner->getMorphClass());
        }
    }

    private function attachOnly(): bool
    {
        return $this->owner instanceof BelongsToMany && ($this->spec['update_attached'] ?? false) !== true;
    }

    /**
     * Attaching needs what Filament's AttachAction needs: the owner policy's `attach` (or `attachAny`) when it defines one,
     * else the owner's `update`. An owner without a policy is not restricted, as everywhere in Filament.
     */
    private function authorizeAttach(Model $related): void
    {
        if (!$this->owner instanceof BelongsToMany || $this->user === null) {
            return;
        }

        $owner = $this->owner->getParent();
        $policy = Gate::getPolicyFor($owner);

        if ($policy === null) {
            return;
        }

        $gate = Gate::forUser($this->user);
        $allowed = match (true) {
            method_exists($policy, 'attach') => $gate->allows('attach', [$owner, $related]),
            method_exists($policy, 'attachAny') => $gate->allows('attachAny', $owner),
            default => $gate->allows('update', $owner),
        };

        if (!$allowed) {
            throw new RowFailed(__('filament-xlsx-import::filament-xlsx-import.row.attach_unauthorized'));
        }
    }

    private function isAttached(Model $model): bool
    {
        return $this->owner instanceof BelongsToMany && $this->owner->newPivotStatement()
            ->where($this->owner->getForeignPivotKeyName(), $this->owner->getParent()->getAttribute($this->owner->getParentKeyName()))
            ->where($this->owner->getRelatedPivotKeyName(), $model->getKey())
            ->exists();
    }

    /**
     * @param class-string<Model>|Model $subject
     */
    private function authorize(Model|string $subject): void
    {
        if (($this->spec['authorize'] ?? false) !== true || $this->user === null) {
            return;
        }

        $ability = $subject instanceof Model ? ($subject->exists ? 'update' : 'create') : 'create';

        if (!Gate::forUser($this->user)->allows($ability, $subject)) {
            throw new RowFailed(__('filament-xlsx-import::filament-xlsx-import.row.unauthorized'));
        }
    }

    /**
     * Writing into a related record (hasOne) is a write to that model: its `update` policy for an existing one (never waived by
     * `->allowCreatingRelated()`), the create rules for a new one.
     */
    private function authorizeRelatedWrite(Model $related, TargetField $target, bool $creating): void
    {
        $allowed = $creating
            ? $this->mayCreate($target)
            // `allowCreatingRelated()` is about CREATING; changing an existing related record always needs its own `update` policy,
            // unless the developer named the (dangerous) switch that says otherwise.
            : (($this->spec['allow_update_related_without_policy'] ?? false) === true
                || ($this->user !== null && Gate::getPolicyFor($related) !== null && Gate::forUser($this->user)->allows('update', $related)));

        if (!$allowed) {
            throw new RowFailed(__('filament-xlsx-import::filament-xlsx-import.row.related_unauthorized', ['relation' => $target->label]));
        }
    }

    /**
     * Creating a related record needs the definition's blessing (`mode` is the user's request, not a permission), the
     * related model's `create` policy for this user — or `->allowCreatingRelated()`.
     */
    private function mayCreate(TargetField $target): bool
    {
        if (!$target->allowCreate) {
            return false;
        }

        if (($this->spec['allow_create_related'] ?? false) === true) {
            return true;
        }

        return $this->user !== null && Gate::getPolicyFor($target->relatedModel) !== null && Gate::forUser($this->user)->allows('create', $target->relatedModel);
    }

    /**
     * @param array{target: TargetField, fields: array<string, mixed>, mode: string, many: string} $info
     */
    private function resolve(array $info): Model
    {
        $class = $info['target']->relatedModel;
        /** @var Model $related */
        $related = new $class;
        $query = TenantScope::scope($class::query(), $related);

        if ($this->relatedQuery !== null) {
            $query = ($this->relatedQuery)($query, $class) ?? $query;
        }

        $found = $query->where($info['fields'])->first();

        if ($found instanceof Model) {
            return $found;
        }

        if ($info['mode'] !== 'create' || !$this->mayCreate($info['target'])) {
            $pairs = implode(', ', array_map(static fn ($k, $v): string => $k.' = '.(is_scalar($v) ? (string) $v : json_encode($v)), array_keys($info['fields']), $info['fields']));

            throw new RowFailed(__('filament-xlsx-import::filament-xlsx-import.row.related_missing', ['relation' => $info['target']->label, 'value' => $pairs]));
        }

        /** @var Model $record */
        $record = new $class;
        $record->forceFill($info['fields']);
        TenantScope::assign($record);
        $record->save();

        return $record;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function labelFor(array $attributes, ?string $key): string
    {
        $value = $key !== null ? ($attributes[$key] ?? null) : (reset($attributes) ?: null);

        return is_scalar($value) ? mb_strimwidth((string) $value, 0, 60, '…') : '';
    }

    private static function display(mixed $value, string $type): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    private static function same(?string $old, ?string $new): bool
    {
        if ($old === $new) {
            return true;
        }

        return $old !== null && $new !== null && is_numeric($old) && is_numeric($new) && (float) $old === (float) $new;
    }
}
