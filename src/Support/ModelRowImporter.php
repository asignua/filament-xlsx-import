<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Data\Definition;
use Asignua\FilamentXlsxImport\Data\TargetField;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
     * @param array<string, mixed>                                                                                                                                              $spec
     * @param Closure(array<string, mixed>, ?Model, ImportContext): (Model|null)|null                                                                                           $saveUsing
     */
    public function __construct(
        private readonly Definition $definition,
        private readonly array $mapping,
        private readonly array $spec,
        private readonly ?Authenticatable $user,
        private readonly bool $dryRun,
        private readonly ?Closure $saveUsing = null,
    ) {}

    public function finish(): void {}

    public function process(array $row, int $number): RowOutcome
    {
        try {
            [$attributes, $relations] = $this->extract($row);
            $key = is_string($this->spec['update_by'] ?? null) ? $this->spec['update_by'] : null;
            $existing = null;
            $duplicate = false;

            if ($key !== null) {
                $value = $attributes[$key] ?? null;

                if ($value === null || $value === '') {
                    return RowOutcome::failed(__('filament-xlsx-import::filament-xlsx-import.row.key_empty', ['field' => $this->definition->target($key)?->label ?? $key]));
                }

                $existing = $this->definition->model::query()->where($key, $value)->first();
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

            if (($this->spec['authorize'] ?? false) === true && $this->user !== null
                && !Gate::forUser($this->user)->allows($existing !== null ? 'update' : 'create', $existing ?? $this->definition->model)) {
                return RowOutcome::failed(__('filament-xlsx-import::filament-xlsx-import.row.unauthorized'));
            }

            $changes = $existing !== null ? $this->diff($existing, $attributes, $relations) : $this->newValues($attributes, $relations);

            if ($existing !== null && $changes === []) {
                return new RowOutcome(RowOutcome::SKIPPED, __('filament-xlsx-import::filament-xlsx-import.row.unchanged'), [], $label, $duplicate);
            }

            DB::transaction(fn () => $this->write($existing, $attributes, $relations, $number));

            return new RowOutcome($existing !== null ? RowOutcome::UPDATED : RowOutcome::CREATED, '', $changes, $label, $duplicate);
        } catch (RowFailed|CastException $e) {
            return RowOutcome::failed($e->getMessage());
        } catch (ValidationException $e) {
            return RowOutcome::failed(implode(' ', array_merge(...array_values($e->errors()))));
        } catch (Throwable $e) {
            report($e);

            return RowOutcome::failed(mb_strimwidth($e->getMessage(), 0, 300, '…'));
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
                $value = CastPipeline::apply($row[$entry['header']] ?? '', $entry['casts']);
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
     * @param array<string, mixed>                                                                                    $attributes
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

            if ($list !== []) {
                $rules[$field] = $list;
            }
        }

        if ($rules !== []) {
            Validator::validate($data, $rules, [], $names);
        }
    }

    /**
     * @param array<string, mixed>                                                                                    $attributes
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
            $oldText = self::display($old, $target?->type ?? 'string');
            $newText = self::display($new, $target?->type ?? 'string');

            if (!self::same($oldText, $newText)) {
                $changes[$target?->label ?? $name] = [$oldText, $newText];
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
     * @param array<string, mixed>                                                                                    $attributes
     * @param array<string, array{target: TargetField, fields: array<string, mixed>, mode: string, many: string}> $relations
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    private function newValues(array $attributes, array $relations): array
    {
        $values = [];

        foreach ($attributes as $name => $value) {
            $target = $this->definition->target($name);
            $values[$target?->label ?? $name] = [null, self::display($value, $target?->type ?? 'string')];
        }

        foreach ($relations as $info) {
            foreach ($info['fields'] as $value) {
                $values[$info['target']->label] = [null, is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value];
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed>                                                                                    $attributes
     * @param array<string, array{target: TargetField, fields: array<string, mixed>, mode: string, many: string}> $relations
     */
    private function write(?Model $existing, array $attributes, array $relations, int $number): void
    {
        if ($this->saveUsing !== null) {
            $data = $attributes;

            foreach ($relations as $name => $info) {
                $data[$name] = $info['fields'];
            }

            ($this->saveUsing)($data, $existing, new ImportContext($this->user, $number, $this->dryRun, $this->spec));

            return;
        }

        $model = $existing ?? new ($this->definition->model);

        foreach ($relations as $name => $info) {
            if ($info['target']->relationType !== 'belongsTo') {
                continue;
            }

            $related = $this->resolve($info);
            $relation = $model->{$name}();

            if ($relation instanceof BelongsTo && $related !== null) {
                $relation->associate($related);
            }
        }

        $model->forceFill($attributes)->save();

        foreach ($relations as $name => $info) {
            $relation = $model->{$name}();

            if ($relation instanceof HasOne) {
                $one = $relation->first() ?? $relation->getRelated()->newInstance();
                $one->forceFill($info['fields']);
                $relation->save($one);
            } elseif ($relation instanceof BelongsToMany) {
                $field = array_key_first($info['fields']);
                $ids = [];

                foreach ((array) $info['fields'][$field] as $value) {
                    $record = $this->resolve(['target' => $info['target'], 'fields' => [$field => $value], 'mode' => $info['mode'], 'many' => $info['many']]);

                    if ($record !== null) {
                        $ids[] = $record->getKey();
                    }
                }

                $info['many'] === 'replace' ? $relation->sync($ids) : $relation->syncWithoutDetaching($ids);
            }
        }
    }

    /**
     * @param array{target: TargetField, fields: array<string, mixed>, mode: string, many: string} $info
     */
    private function resolve(array $info): ?Model
    {
        $class = $info['target']->relatedModel;
        $found = $class::query()->where($info['fields'])->first();

        if ($found instanceof Model) {
            return $found;
        }

        if ($info['mode'] !== 'create') {
            $pairs = implode(', ', array_map(static fn ($k, $v): string => $k.' = '.(is_scalar($v) ? (string) $v : json_encode($v)), array_keys($info['fields']), $info['fields']));

            throw new RowFailed(__('filament-xlsx-import::filament-xlsx-import.row.related_missing', ['relation' => $info['target']->label, 'value' => $pairs]));
        }

        $record = new $class;
        $record->forceFill($info['fields'])->save();

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
