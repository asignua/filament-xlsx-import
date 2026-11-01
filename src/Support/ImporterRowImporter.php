<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs rows through an existing Filament `Importer` class (its columns, `resolveRecord()`,
 * validation and hooks) with our mapping and casts in front of it. No `imports` table is needed:
 * the `Import` handed to the importer is an unsaved in-memory model that only carries the user.
 */
final class ImporterRowImporter implements RowImporter
{
    /** @var array{importer: Importer, exists: bool, dirty: array<string, array{0: mixed, 1: mixed}>, saved: bool, authorize: bool, user: ?Authenticatable}|null */
    public static ?array $capture = null;

    /** @var \WeakMap<object, array<string, true>>|null */
    private static ?\WeakMap $listening = null;

    private Importer $importer;

    /** @var array<string, string> */
    private array $columnMap = [];

    /**
     * @param class-string<Importer>                                                                                                                                                $importerClass
     * @param list<array{header: string, target: string|null, casts: list<array{type: string, arg: string, arg2: string, map: array<string, string>}>, mode: string, many: string}> $mapping
     * @param array<string, mixed>                                                                                                                                                  $options
     * @param array<string, mixed>                                                                                                                                                  $spec
     */
    public function __construct(
        private readonly string $importerClass,
        private readonly array $mapping,
        array $options,
        private readonly array $spec,
        private readonly ?Authenticatable $user,
        string $fileName,
    ) {
        foreach ($mapping as $entry) {
            if ($entry['target'] !== null) {
                $this->columnMap[$entry['target']] = $entry['header'];
            }
        }

        $import = new Import;
        $import->forceFill(['importer' => $importerClass, 'file_name' => $fileName, 'file_path' => $fileName, 'processed_rows' => 0, 'total_rows' => 0, 'successful_rows' => 0]);

        if ($user instanceof Model) {
            $import->setRelation('user', $user);
        }

        $this->importer = app($importerClass, ['import' => $import, 'columnMap' => $this->columnMap, 'options' => $options]);
        self::listen($importerClass::getModel());
    }

    private static function listen(string $model): void
    {
        // Per dispatcher: a test (or an Octane worker) can swap the application, and with it the listeners.
        $dispatcher = Event::getFacadeRoot();
        self::$listening ??= new \WeakMap;
        $known = self::$listening[$dispatcher] ?? [];

        if (isset($known[$model])) {
            return;
        }

        $known[$model] = true;
        self::$listening[$dispatcher] = $known;

        // Observers share this event name, so the listener is added once and made inert (not removed) between runs.
        Event::listen("eloquent.saving: {$model}", static function (Model $record): void {
            $capture = &self::$capture;

            if ($capture === null || $capture['importer']->getRecord() !== $record) {
                return;
            }

            $capture['saved'] = true;
            $capture['exists'] = $record->exists;

            foreach ($record->getDirty() as $key => $value) {
                $capture['dirty'][$key] = [$record->getOriginal($key), $value];
            }

            if ($capture['authorize'] && $capture['user'] !== null
                && !Gate::forUser($capture['user'])->allows($record->exists ? 'update' : 'create', $record)) {
                throw new RowImportFailedException(__('filament-xlsx-import::filament-xlsx-import.row.unauthorized'));
            }
        });
    }

    public function process(array $row, int $number): RowOutcome
    {
        try {
            foreach ($this->mapping as $entry) {
                if ($entry['target'] === null || !array_key_exists($entry['header'], $row)) {
                    continue;
                }

                $value = CastPipeline::apply($row[$entry['header']], $entry['casts']);
                $row[$entry['header']] = match (true) {
                    $value === null => '',
                    is_array($value) => implode(',', array_map('strval', $value)),
                    is_bool($value) => $value ? '1' : '0',
                    default => (string) $value,
                };
            }
        } catch (CastException $e) {
            return RowOutcome::failed($e->getMessage());
        }

        self::$capture = ['importer' => $this->importer, 'exists' => false, 'dirty' => [], 'saved' => false, 'authorize' => ($this->spec['authorize'] ?? false) === true, 'user' => $this->user];

        try {
            DB::transaction(fn () => ($this->importer)($row));
            $capture = self::captured();
        } catch (RowImportFailedException $e) {
            return RowOutcome::failed($e->getMessage());
        } catch (ValidationException $e) {
            return RowOutcome::failed(implode(' ', array_merge(...array_values($e->errors()))));
        } catch (Throwable $e) {
            report($e);

            return RowOutcome::failed(mb_strimwidth($e->getMessage(), 0, 300, '…'));
        } finally {
            self::$capture = null;
        }

        if (!$capture['saved']) {
            return new RowOutcome(RowOutcome::SKIPPED, __('filament-xlsx-import::filament-xlsx-import.row.not_resolved'));
        }

        $changes = [];

        foreach ($capture['dirty'] as $key => [$old, $new]) {
            $changes[$key] = [is_scalar($old) ? (string) $old : null, is_scalar($new) ? (string) $new : null];
        }

        if ($capture['exists'] && $changes === []) {
            return new RowOutcome(RowOutcome::SKIPPED, __('filament-xlsx-import::filament-xlsx-import.row.unchanged'));
        }

        return new RowOutcome($capture['exists'] ? RowOutcome::UPDATED : RowOutcome::CREATED, '', $capture['exists'] ? $changes : [], '');
    }

    /**
     * @return array{importer: Importer, exists: bool, dirty: array<string, array{0: mixed, 1: mixed}>, saved: bool, authorize: bool, user: ?Authenticatable}
     */
    private static function captured(): array
    {
        return self::$capture ?? throw new \LogicException('No row is being imported.');
    }

    public function finish(): void
    {
        self::$capture = null;
    }
}
