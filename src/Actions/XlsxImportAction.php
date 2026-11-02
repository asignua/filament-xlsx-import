<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Actions;

use Asignua\FilamentXlsxImport\Data\Definition;
use Asignua\FilamentXlsxImport\Data\TargetField;
use Asignua\FilamentXlsxImport\Support\CastPipeline;
use Asignua\FilamentXlsxImport\Support\CsvNormalizer;
use Asignua\FilamentXlsxImport\Support\DefinitionResolver;
use Asignua\FilamentXlsxImport\Support\DryRunAborted;
use Asignua\FilamentXlsxImport\Support\ImportNotifier;
use Asignua\FilamentXlsxImport\Support\ImportRunner;
use Asignua\FilamentXlsxImport\Support\Mapping;
use Asignua\FilamentXlsxImport\Support\ModelIntrospector;
use Asignua\FilamentXlsxImport\Support\Presets;
use Asignua\FilamentXlsxImport\Support\QueuedImports;
use Asignua\FilamentXlsxImport\Support\RunContext;
use Asignua\FilamentXlsxImport\Support\RunStore;
use Asignua\FilamentXlsxImport\Support\SourceFile;
use Asignua\FilamentXlsxImport\Support\TemplateBuilder;
use Asignua\FilamentXlsxImport\Support\TenantScope;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Imports\Importer;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Import a spreadsheet (.xlsx / .ods / .csv) into a model: upload → map the file's columns (with
 * casts, related records, presets) → dry run → apply, inline or in a queued job.
 *
 * ```php
 * XlsxImportAction::make()->model(Product::class);                 // zero config
 * XlsxImportAction::make()->importer(ProductImporter::class);      // an existing Filament Importer
 * ```
 */
class XlsxImportAction extends Action
{
    /** @var class-string<Importer>|null */
    protected ?string $importerClass = null;

    /** @var class-string|null */
    protected ?string $definitionClass = null;

    protected ?string $updateByKey = null;

    protected string $existingMode = 'update';

    protected bool $queueAlways = false;

    protected ?int $queueThreshold = null;

    protected ?string $queueConnection = null;

    protected ?string $queueName = null;

    protected ?bool $dryRunEnabled = null;

    protected ?int $maxKilobytes = null;

    protected ?int $maxRowCount = null;

    protected ?bool $authorizeRecords = null;

    /** @var list<string>|null */
    protected ?array $onlyTargets = null;

    /** @var list<string> */
    protected array $exceptTargets = [];

    /** @var list<string> */
    protected array $manyRelations = [];

    protected bool $allowCreatingRelated = false;

    protected bool $allowUpdatingRelatedWithoutPolicy = false;

    protected bool $updateAttachedRecords = false;

    protected string|Closure|null $relatedQueryCallback = null;

    protected string|Closure|null $fixedCallback = null;

    protected string|Closure|null $saveUsingCallback = null;

    /** @var array<string, list<string>> */
    protected array $extraAliases = [];

    protected bool $templateExample = true;

    protected ?string $forcedEncoding = null;

    /** @var array<string, array<string, mixed>> */
    protected array $fieldRules = [];

    private ?Definition $resolved = null;

    public static function getDefaultName(): ?string
    {
        return 'xlsxImport';
    }

    /**
     * @param class-string<Importer> $importer
     */
    public function importer(string $importer): static
    {
        $this->importerClass = $importer;

        return $this;
    }

    /**
     * @param class-string $definition a class implementing DefinesImport
     */
    public function definition(string $definition): static
    {
        $this->definitionClass = $definition;

        return $this;
    }

    /**
     * Existing rows are found by this attribute and updated; others are created. Without it every row creates a record.
     */
    public function updateBy(string $attribute): static
    {
        $this->updateByKey = $attribute;

        return $this;
    }

    /**
     * Rows whose key already exists are skipped, not updated.
     */
    public function createOnly(): static
    {
        $this->existingMode = 'skip';

        return $this;
    }

    /**
     * Rows whose key does not exist are skipped, not created.
     */
    public function updateOnly(): static
    {
        $this->existingMode = 'only';

        return $this;
    }

    public function queue(?string $connection = null, ?string $queue = null): static
    {
        $this->queueAlways = true;
        $this->queueConnection = $connection;
        $this->queueName = $queue;

        return $this;
    }

    public function queueWhenMoreThan(int $rows, ?string $connection = null, ?string $queue = null): static
    {
        $this->queueThreshold = $rows;
        $this->queueConnection = $connection;
        $this->queueName = $queue;

        return $this;
    }

    public function dryRun(bool $condition = true): static
    {
        $this->dryRunEnabled = $condition;

        return $this;
    }

    public function maxFileSize(?int $kilobytes): static
    {
        $this->maxKilobytes = $kilobytes;

        return $this;
    }

    public function maxRows(?int $rows): static
    {
        $this->maxRowCount = $rows;

        return $this;
    }

    /**
     * Check the model's policy (`create` / `update`) for every row — `update` after the new values are in the model,
     * before it is saved; a denied row fails with its reason in the report. When a policy is registered for the model
     * this is ON unless you pass `false`; without a policy it is off (Filament's own importer does no per-row checks).
     */
    public function authorizeRecords(bool $condition = true): static
    {
        $this->authorizeRecords = $condition;

        return $this;
    }

    /**
     * An explicit allowlist of what a file may write to: attributes and `relation.field`. May name the primary key or a
     * secret — that is then your decision. Recommended for user-like and role-like models.
     *
     * @param list<string> $targets
     */
    public function only(array $targets): static
    {
        $this->onlyTargets = $targets;

        return $this;
    }

    /**
     * @param list<string> $targets attributes, `relation.field` or a relation name
     */
    public function except(array $targets): static
    {
        $this->exceptTargets = $targets;

        return $this;
    }

    /**
     * belongsToMany relations to offer (never discovered automatically; role/permission relations need an explicit entry too).
     *
     * @param list<string> $relations
     */
    public function relations(array $relations): static
    {
        $this->manyRelations = $relations;

        return $this;
    }

    /**
     * «Create it» for a missing related record needs the related model's `create` policy for the user; without a policy it
     * is refused — unless you say so here. A rule `'mode' => 'lookup'` stays a lock either way.
     */
    public function allowCreatingRelated(bool $condition = true): static
    {
        $this->allowCreatingRelated = $condition;

        return $this;
    }

    /**
     * Many-to-many relation manager: records that already exist are only ATTACHED to the owner by default — their attributes are
     * shared with every other owner and are not rewritten from a file. This opts in to updating them too (they still need their own policy).
     */
    public function updateAttachedRecords(bool $condition = true): static
    {
        $this->updateAttachedRecords = $condition;

        return $this;
    }

    /**
     * DANGEROUS: lets a file change an EXISTING related record (a `hasOne` such as an account) when its model has no `update`
     * policy. `allowCreatingRelated()` never does that; this does, for every row. Prefer a policy.
     */
    public function allowUpdatingRelatedWithoutPolicy(bool $condition = true): static
    {
        $this->allowUpdatingRelatedWithoutPolicy = $condition;

        return $this;
    }

    /**
     * Values merged into EVERY row, server-side: `fn (ImportContext $ctx): array => ['team_id' => $ctx->user?->team_id, 'status' => 'imported']`.
     * Not mappable by the user and stronger than the file; keys are attributes or `relation.field` of a relation the import offers.
     * To run queued, give an invokable class name.
     *
     * @param class-string|Closure $callback
     */
    public function fixedAttributes(string|Closure $callback): static
    {
        $this->fixedCallback = $callback;

        return $this;
    }

    /**
     * Narrow every lookup/create of a related record, e.g. to the tenant's own: `fn (Builder $query, string $relatedClass) => $query->where(…)`.
     * To run queued, give an invokable class name.
     *
     * @param class-string|Closure $callback
     */
    public function relatedQueryUsing(string|Closure $callback): static
    {
        $this->relatedQueryCallback = $callback;

        return $this;
    }

    /**
     * Take over the write: `fn (array $data, ?Model $existing, ImportContext $ctx)`. Needed for apps that
     * go through a repository. To run queued, give a class-string of an invokable class (a closure cannot be queued).
     *
     * @param class-string|Closure $callback
     */
    public function saveUsing(string|Closure $callback): static
    {
        $this->saveUsingCallback = $callback;

        return $this;
    }

    /**
     * Header synonyms for a target: `->aliases(['email' => ['e-mail', 'пошта']])`.
     *
     * @param array<string, list<string>> $aliases
     */
    public function aliases(array $aliases): static
    {
        $this->extraAliases = $aliases;

        return $this;
    }

    /**
     * Same shape as DefinesImport's `fields`, for a single action: `->rules(['email' => ['casts' => ['lower']]])`.
     *
     * @param array<string, array<string, mixed>> $fields
     */
    public function fieldRules(array $fields): static
    {
        $this->fieldRules = $fields;

        return $this;
    }

    public function templateExample(bool $condition = true): static
    {
        $this->templateExample = $condition;

        return $this;
    }

    public function csvEncoding(string $encoding): static
    {
        $this->forcedEncoding = $encoding;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-xlsx-import::filament-xlsx-import.import'));
        $this->icon(Heroicon::OutlinedArrowUpTray);
        $this->modalHeading(__('filament-xlsx-import::filament-xlsx-import.import_heading'));
        $this->modalWidth(Width::SevenExtraLarge);
        $this->modalSubmitActionLabel(__('filament-xlsx-import::filament-xlsx-import.apply'));
        $this->color('gray');
        $this->steps(fn (): array => $this->buildSteps());

        $this->action(function (array $data, ?HasTable $livewire = null): void {
            $this->finishImport($data);
        });
    }

    /**
     * With nothing a file may write to (a fully guarded model that was not given targets) the button is not shown at all — an
     * admin cannot fix that, a developer must: with `app.debug` on the page says so loudly instead.
     */
    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }

        $definition = $this->definitionFor($this->getLivewire());

        if ($this->hasAttributeTargets($definition)) {
            return false;
        }

        if ((bool) config('app.debug')) {
            throw new LogicException((string) ($definition->notice ?? __('filament-xlsx-import::filament-xlsx-import.notice_no_targets', ['model' => class_basename($definition->model)])));
        }

        return true;
    }

    /**
     * A file can only write to a record through attributes of its own: relation targets alone (a fully guarded model whose
     * only declared targets are `category.name`…) are not an import.
     */
    private function hasAttributeTargets(Definition $definition): bool
    {
        return $definition->importer !== null || array_filter($definition->targets, static fn (TargetField $t): bool => !$t->isRelation()) !== [];
    }

    public function definitionFor(mixed $livewire = null): Definition
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $model = $this->importerClass === null ? $this->getModel(false) : null;

        if ($model === null && $this->importerClass === null && $livewire instanceof HasTable) {
            $model = $livewire->getTable()->getModel();
        }

        return $this->resolved = DefinitionResolver::resolve(is_string($model) ? $model : null, $this->importerClass, $this->definitionClass, $this->overrides());
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        $overrides = ['update_by' => $this->updateByKey, 'fields' => $this->fieldRules];

        foreach ($this->extraAliases as $key => $aliases) {
            $overrides['fields'][$key]['aliases'] = $aliases;
        }

        if ($this->onlyTargets !== null) {
            $overrides['only'] = $this->onlyTargets;
        }

        if ($this->exceptTargets !== []) {
            $overrides['except'] = $this->exceptTargets;
        }

        if ($this->manyRelations !== []) {
            $overrides['relations'] = $this->manyRelations;
        }

        // In a relation manager the owner's key (and the morph type) is set by the plugin, never by the file.
        $owner = $this->ownerRelation();

        if ($owner instanceof HasOneOrMany) {
            // The inverse belongsTo of the owner relation (`category.name` inside the category's products) would re-point a record to
            // another owner: it is no target here, only the owner decides.
            $inverse = ModelIntrospector::belongsToByForeignKey($owner->getRelated()::class, $owner->getForeignKeyName());
            $overrides['except'] = array_values(array_unique([...($overrides['except'] ?? []), $owner->getForeignKeyName(), ...$inverse, ...($owner instanceof MorphOneOrMany ? [$owner->getMorphType()] : [])]));
        }

        return $overrides;
    }

    /**
     * The run id comes from a hidden form field: only the user's own run, in this panel, for this import, is accepted.
     */
    private function ownRun(?string $id, Definition $definition): bool
    {
        return is_string($id) && $id !== '' && RunStore::ownedBy($id, Filament::auth()->id(), Filament::getAuthGuard(), Filament::getCurrentPanel()?->getId(), $definition->key());
    }

    /**
     * The relation a relation manager (or a ManageRelatedRecords page) shows: new and updated records are attached to its owner.
     *
     * @return Relation<Model, Model, mixed>|null
     */
    private function ownerRelation(): ?Relation
    {
        $livewire = $this->getLivewire();

        if (!is_object($livewire) || !method_exists($livewire, 'getRelationship') || !method_exists($livewire, 'getOwnerRecord')) {
            return null;
        }

        $relation = $livewire->getRelationship();

        return $relation instanceof Relation ? $relation : null;
    }

    private function dryRunOn(): bool
    {
        return $this->dryRunEnabled ?? (bool) config('filament-xlsx-import.dry_run.enabled', true);
    }

    private function maxKilobytes(): ?int
    {
        return $this->maxKilobytes ?? (is_numeric(config('filament-xlsx-import.max_file_size')) ? (int) config('filament-xlsx-import.max_file_size') : null);
    }

    private function limitRows(): ?int
    {
        return $this->maxRowCount ?? (is_numeric(config('filament-xlsx-import.max_rows')) ? (int) config('filament-xlsx-import.max_rows') : null);
    }

    /**
     * @return array<int, Step>
     */
    protected function buildSteps(): array
    {
        $definition = $this->definitionFor($this->getLivewire());
        $t = static fn (string $key, array $replace = []): string => __('filament-xlsx-import::filament-xlsx-import.'.$key, $replace);

        $steps = [
            Step::make($t('step_upload'))
                ->schema($this->uploadSchema($definition))
                ->afterValidation(fn (Get $get, Set $set) => $this->stageUpload($get, $set, $definition)),
            Step::make($t('step_map'))
                ->schema($this->mappingSchema($definition))
                ->afterValidation(fn (Get $get, Set $set) => $this->checkImportMapping($get, $set, $definition)),
        ];

        if ($this->dryRunOn()) {
            $steps[] = Step::make($t('step_preview'))->schema([
                View::make('filament-xlsx-import::preview')->viewData(function (Get $get) use ($definition): array {
                    $meta = $this->ownRun($get('run_id'), $definition) ? RunStore::meta((string) $get('run_id')) : [];

                    return [
                        'preview' => $meta['preview'] ?? null,
                        'rows' => (int) ($meta['rows'] ?? 0),
                        'queued' => $this->willQueue((int) ($meta['rows'] ?? 0)),
                    ];
                }),
            ]);
        }

        return $steps;
    }

    /**
     * @return array<int, mixed>
     */
    private function uploadSchema(Definition $definition): array
    {
        $t = static fn (string $key): string => __('filament-xlsx-import::filament-xlsx-import.'.$key);
        $kb = $this->maxKilobytes();

        return [
            FileUpload::make('file')
                ->label($t('file'))
                ->helperText($t('file_help'))
                ->required()
                ->storeFiles(false)
                ->acceptedFileTypes([
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.oasis.opendocument.spreadsheet',
                    'application/vnd.ms-excel',
                    'text/csv', 'text/plain', 'application/csv', 'text/tab-separated-values', 'application/octet-stream',
                ])
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $file = self::uploaded($value);

                    if ($file !== null && !in_array(SourceFile::extensionOf($file->getClientOriginalName()), SourceFile::EXTENSIONS, true)) {
                        $fail(__('filament-xlsx-import::filament-xlsx-import.bad_type'));
                    }
                }])
                ->when($kb !== null, fn (FileUpload $upload) => $upload->maxSize($kb))
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    $file = self::uploaded($state);
                    $set('sheet_names', $file !== null ? $this->sheetNames($file) : []);
                    $set('sheet', 0);
                    $set('stage_sig', null);
                }),
            Hidden::make('sheet_names')->default([]),
            Hidden::make('stage_sig'),
            Hidden::make('run_id'),
            Grid::make(['default' => 1, 'md' => 3])->schema([
                Select::make('sheet')
                    ->label($t('sheet'))
                    ->options(fn (Get $get): array => array_values((array) $get('sheet_names')))
                    ->default(0)
                    ->native(false)
                    ->visible(fn (Get $get): bool => count((array) $get('sheet_names')) > 1),
                Select::make('csv_encoding')
                    ->label($t('csv_encoding'))
                    ->options(['auto' => $t('auto'), 'UTF-8' => 'UTF-8', 'Windows-1251' => 'Windows-1251', 'Windows-1252' => 'Windows-1252', 'UTF-16' => 'UTF-16', 'ISO-8859-2' => 'ISO-8859-2'])
                    ->default($this->forcedEncoding ?? 'auto')
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::isCsv($get('file'))),
                Select::make('csv_delimiter')
                    ->label($t('csv_delimiter'))
                    ->options(['auto' => $t('auto'), ',' => ',', ';' => ';', "\t" => 'Tab', '|' => '|'])
                    ->default('auto')
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::isCsv($get('file'))),
            ]),
            Select::make('preset_id')
                ->label($t('preset'))
                ->helperText($t('preset_help'))
                ->options(fn (): array => Presets::options($definition->key(), Filament::auth()->id()))
                ->placeholder($t('preset_none'))
                ->native(false)
                ->visible(fn (): bool => Presets::options($definition->key(), Filament::auth()->id()) !== []),
            Actions::make([
                Action::make('template')
                    ->label($t('download_template'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->link()
                    ->action(fn (): StreamedResponse => $this->templateResponse($definition)),
            ]),
        ];
    }

    private function templateResponse(Definition $definition): StreamedResponse
    {
        $path = RunStore::temporaryFile('template-');
        TemplateBuilder::build($definition, $path, $definition->updateBy ?? $this->updateByKey, $this->templateExample);
        $name = str(class_basename($definition->model))->snake('-')->toString().'-'.__('filament-xlsx-import::filament-xlsx-import.template.file').'.xlsx';

        return response()->streamDownload(static function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * @return list<string>
     */
    private function sheetNames(TemporaryUploadedFile $file): array
    {
        $extension = SourceFile::extensionOf($file->getClientOriginalName());

        if (!in_array($extension, ['xlsx', 'ods'], true)) {
            return [];
        }

        try {
            $copy = RunStore::temporaryFile('probe-');
            $this->copyUpload($file, $copy);

            return (new SourceFile($copy, $extension))->sheets();
        } catch (Throwable) {
            return [];
        } finally {
            isset($copy) && @unlink($copy);
        }
    }

    private function copyUpload(TemporaryUploadedFile $file, string $to): void
    {
        $in = $file->readStream();
        $out = fopen($to, 'wb');

        if (!is_resource($in) || $out === false) {
            throw new \RuntimeException('Cannot read the uploaded file.');
        }

        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);
    }

    public static function uploaded(mixed $state): ?TemporaryUploadedFile
    {
        if (is_array($state)) {
            $state = reset($state) ?: null;
        }

        if (is_string($state) && str_starts_with($state, 'livewire-file:')) {
            return TemporaryUploadedFile::createFromLivewire(substr($state, 14));
        }

        return $state instanceof TemporaryUploadedFile ? $state : null;
    }

    private static function isCsv(mixed $state): bool
    {
        $file = self::uploaded($state);

        return $file !== null && SourceFile::extensionOf($file->getClientOriginalName()) === 'csv';
    }

    /**
     * @param array<string, mixed> $replace
     */
    private function stop(string $messageKey, array $replace = []): never
    {
        Notification::make()->danger()->title(__('filament-xlsx-import::filament-xlsx-import.'.$messageKey, $replace))->send();

        throw new Halt;
    }

    /**
     * Step 1 → 2: copy the upload into a run, read its columns and build the first mapping.
     */
    private function stageUpload(Get $get, Set $set, Definition $definition): void
    {
        $file = self::uploaded($get('file'));

        if ($file === null) {
            $this->stop('no_file');
        }

        if (!$this->hasAttributeTargets($definition)) {
            Notification::make()->danger()->title($definition->notice ?? __('filament-xlsx-import::filament-xlsx-import.notice_no_targets', ['model' => class_basename($definition->model)]))->persistent()->send();

            throw new Halt;
        }

        $name = $file->getClientOriginalName();
        $extension = SourceFile::extensionOf($name);

        if (!in_array($extension, SourceFile::EXTENSIONS, true)) {
            $this->stop('bad_type');
        }

        $sheet = (int) ($get('sheet') ?? 0);
        $encoding = (string) ($get('csv_encoding') ?? '');
        $delimiter = (string) ($get('csv_delimiter') ?? '');
        $encoding = $encoding === 'auto' ? '' : $encoding;
        $delimiter = $delimiter === 'auto' ? '' : $delimiter;
        $presetId = $get('preset_id');
        $signature = md5(implode('|', [$file->getFilename(), $sheet, $encoding, $delimiter, (string) $presetId]));

        if ($get('stage_sig') === $signature && $this->ownRun($get('run_id'), $definition)) {
            return;
        }

        if ($this->ownRun($get('run_id'), $definition)) {
            RunStore::forget((string) $get('run_id'));
        }

        $panel = Filament::getCurrentPanel();
        $id = RunStore::create([
            'user' => Filament::auth()->id(),
            'guard' => Filament::getAuthGuard(),
            'panel' => $panel?->getId(),
            'file_name' => $name,
            'extension' => $extension,
            'sheet' => $sheet,
            'target' => $definition->key(),
        ]);

        try {
            $info = [];

            if ($extension === 'csv') {
                $raw = RunStore::path($id, 'upload.raw');
                $this->copyUpload($file, $raw);
                $info = CsvNormalizer::normalize($raw, RunStore::path($id, 'source.csv'), $encoding !== '' ? $encoding : $this->forcedEncoding, $delimiter !== '' ? $delimiter : null);
                @unlink($raw);
                RunStore::put($id, ['delimiter' => $info['delimiter'], 'csv_encoding' => $info['encoding']]);
            } else {
                $this->copyUpload($file, RunStore::path($id, 'source.'.$extension));
            }

            $scan = RunStore::source($id)->scan($sheet, maxRows: $this->limitRows());
        } catch (Throwable $e) {
            RunStore::forget($id);
            report($e);
            $this->stop('unreadable');
        }

        if ($scan['headers'] === [] || $scan['rows'] === 0) {
            RunStore::forget($id);
            $this->stop('empty_file');
        }

        if (count($scan['headers']) > SourceFile::maxColumns()) {
            RunStore::forget($id);
            $this->stop('too_many_columns', ['limit' => SourceFile::maxColumns()]);
        }

        $max = $this->limitRows();

        if ($max !== null && $scan['rows'] > $max) {
            RunStore::forget($id);
            $this->stop('too_many_rows', ['count' => number_format($max).'+', 'limit' => number_format($max)]);
        }

        RunStore::put($id, ['headers' => $scan['headers'], 'rows' => $scan['rows']]);

        $preset = $presetId ? Presets::find($presetId, $definition->key(), Filament::auth()->id()) : null;

        $set('run_id', $id);
        $set('stage_sig', $signature);
        $set('mapping', array_map(fn (array $entry): array => $this->clampEntry($definition, $entry), Mapping::initial($definition, $scan['headers'], $scan['samples'], $scan['distinct'], $preset?->mapping)));
        // What the preset remembered about the key and the existing-record mode wins over the defaults — when still valid.
        $saved = is_array($preset?->options) ? $preset->options : [];
        $savedKey = $saved['update_by'] ?? null;
        $key = is_string($savedKey) && ($definition->target($savedKey)?->isRelation() === false) ? $savedKey : ($this->updateByKey ?? $definition->updateBy);
        $savedExisting = $saved['existing'] ?? null;
        $set('update_by', $key);
        $set('existing', in_array($savedExisting, ['update', 'skip', 'only'], true) ? $savedExisting : $this->existingMode);
        $set('options', is_array($saved['importer'] ?? null) ? $saved['importer'] : []);
        $detected = $extension === 'csv'
            ? ' · '.$info['encoding'].' · '.($info['delimiter'] === "\t" ? 'Tab' : $info['delimiter'])
            : '';
        $set('file_summary', $name.' · '.__('filament-xlsx-import::filament-xlsx-import.summary_rows', ['rows' => number_format($scan['rows']), 'columns' => count($scan['headers'])]).$detected);
    }

    /**
     * @return array<int, mixed>
     */
    private function mappingSchema(Definition $definition): array
    {
        $t = static fn (string $key, array $replace = []): string => __('filament-xlsx-import::filament-xlsx-import.'.$key, $replace);
        $attributes = [];

        foreach ($definition->targets as $target) {
            if (!$target->isRelation()) {
                $attributes[$target->key] = $target->label;
            }
        }

        $castTypes = [];

        foreach (CastPipeline::TYPES as $type) {
            $castTypes[$type] = $t('cast_type.'.$type);
        }

        $dateFormats = static fn (array $formats): array => array_merge(
            array_combine($formats, array_map(static fn (string $f): string => Carbon::parse('2026-01-31 09:30:00')->format($f).'  ('.$f.')', $formats)),
            ['custom' => $t('format_custom')],
        );
        $stateOf = static fn (Get $get): array => [
            'target' => $get('target'), 'casts' => (array) $get('casts'), 'mode' => $get('mode'), 'many' => $get('many'), 'rule' => $get('rule'),
        ];

        return [
            Text::make($definition->notice ?? '')->color('danger')->visible($definition->notice !== null),
            Text::make(fn (Get $get): string => (string) $get('file_summary'))->color('gray'),
            Text::make(function (Get $get) use ($definition, $t): string {
                $missing = $this->unmappedRequired($definition, (array) $get('mapping'), $get('update_by'));

                return $missing === [] ? $t('required_all_mapped') : $t('required_still', ['fields' => implode(', ', $missing)]);
            })
                ->color(fn (Get $get): string => $this->unmappedRequired($definition, (array) $get('mapping'), $get('update_by')) === [] ? 'success' : 'danger')
                ->extraAttributes(['class' => 'xi-sticky'])
                ->visible($definition->importer !== null || $definition->updateBy !== null || array_filter($definition->targets, static fn (TargetField $f): bool => $f->mappingRequired) !== []),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Select::make('update_by')
                    ->label($t('update_by'))
                    ->helperText($t('update_by_help'))
                    ->options($attributes)
                    ->placeholder($t('update_by_none'))
                    ->native(false)
                    ->live()
                    ->visible($definition->importer === null),
                Select::make('existing')
                    ->label($t('existing'))
                    ->options(['update' => $t('existing_update'), 'skip' => $t('existing_skip'), 'only' => $t('existing_only')])
                    ->native(false)
                    ->default('update')
                    ->visible(fn (Get $get): bool => $definition->importer === null && filled($get('update_by'))),
            ]),
            Toggle::make('only_unmapped')->label($t('only_unmapped'))->live()->dehydrated(false)->inline(),
            Repeater::make('mapping')
                ->label($t('mapping'))
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->schema([
                    Hidden::make('header'),
                    Hidden::make('rule'),
                    Hidden::make('origin'),
                    Hidden::make('samples'),
                    Hidden::make('distinct'),
                    // Hidden with CSS, not with `visible()`: a server-hidden field would be dropped from the submitted state.
                    Group::make([
                        Grid::make(['default' => 1, 'lg' => 2])->schema([
                            Group::make([
                                View::make('filament-xlsx-import::mapping-source')->viewData(fn (Get $get): array => [
                                    'header' => (string) $get('header'),
                                    'samples' => array_map(static fn (mixed $v): string => SourceFile::stringify($v), (array) $get('samples')),
                                    'origin' => (string) $get('origin'),
                                    'rule' => $get('rule'),
                                    'modified' => $this->isModified($stateOf($get)),
                                ]),
                                Grid::make(1)->schema([
                                    Select::make('target')
                                        ->label($t('import_into'))
                                        ->options($definition->options())
                                        ->placeholder($t('skip_column'))
                                        ->searchable()
                                        ->native(false)
                                        ->live()
                                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($definition): void {
                                            $target = is_string($state) ? $definition->target($state) : null;
                                            $distinct = array_values(array_map('strval', (array) $get('distinct')));
                                            $defaults = $target !== null ? $this->clampMode($definition, Mapping::defaults($target, $distinct)) : null;
                                            $set('casts', $defaults['casts'] ?? []);
                                            $set('mode', $defaults['mode'] ?? 'lookup');
                                            $set('many', $defaults['many'] ?? 'add');
                                            // The rule of the NEW target, so «from model rules» / «reset» follow whatever was picked.
                                            $set('rule', $target !== null && $target->fromRules ? $defaults : null);
                                        }),
                                    Select::make('mode')
                                        ->label($t('relation_mode'))
                                        ->options(function (Get $get) use ($definition, $t): array {
                                            $target = $definition->target((string) $get('target'));

                                            return ['lookup' => $t('mode_lookup')] + ($target !== null && $this->canCreateRelated($target) ? ['create' => $t('mode_create')] : []);
                                        })
                                        ->helperText(function (Get $get) use ($definition, $t): ?string {
                                            $target = $definition->target((string) $get('target'));

                                            return match (true) {
                                                $target === null || $this->canCreateRelated($target) => null,
                                                !$target->allowCreate => $t('create_locked_by_rule'),
                                                default => $t('create_needs_policy'),
                                            };
                                        })
                                        ->default('lookup')
                                        ->native(false)
                                        ->live()
                                        ->visible(fn (Get $get): bool => $definition->target((string) $get('target'))?->isRelation() ?? false),
                                    Select::make('many')
                                        ->label($t('many_mode'))
                                        ->options(['add' => $t('many_add'), 'replace' => $t('many_replace')])
                                        ->default('add')
                                        ->native(false)
                                        ->live()
                                        ->visible(fn (Get $get): bool => $definition->target((string) $get('target'))?->isMany() ?? false),
                                ]),
                                Actions::make([
                                    Action::make('resetRule')
                                        ->label($t('reset_rule'))
                                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                                        ->link()
                                        ->visible(fn (Get $get): bool => $this->isModified($stateOf($get)))
                                        ->action(function (Get $get, Set $set): void {
                                            $rule = (array) $get('rule');
                                            $set('target', $rule['target'] ?? null);
                                            $set('casts', $rule['casts'] ?? []);
                                            $set('mode', $rule['mode'] ?? 'lookup');
                                            $set('many', $rule['many'] ?? 'add');
                                        }),
                                ]),
                            ]),
                            Group::make([
                                Repeater::make('casts')
                                    ->label($t('casts'))
                                    ->addActionLabel($t('add_cast'))
                                    ->reorderable(false)
                                    ->compact()
                                    ->extraAttributes(['class' => 'xi-casts'])
                                    ->default([])
                                    ->live()
                                    ->visible(fn (Get $get): bool => filled($get('target')))
                                    ->schema([
                                        Grid::make(2)->schema([
                                            Select::make('type')->label($t('cast_label'))->options($castTypes)->native(false)->required()->live(),
                                            // The enum class is not for the user, but it must travel with the pipeline (presets, the queue).
                                            Hidden::make('arg')->visible(fn (Get $get): bool => $get('type') === 'enum'),
                                            Select::make('arg')
                                                ->label(fn (Get $get): string => $t('cast_arg.'.($get('type') ?: 'none')))
                                                ->options([
                                                    '.' => $t('decimal_dot'),
                                                    ',' => $t('decimal_comma'),
                                                ])
                                                ->placeholder($t('decimal_auto'))
                                                ->native(false)
                                                ->live()
                                                ->visible(fn (Get $get): bool => $get('type') === 'decimal'),
                                            Select::make('arg')
                                                ->label(fn (Get $get): string => $t('cast_arg.'.($get('type') ?: 'none')))
                                                ->options(fn (Get $get): array => $dateFormats($get('type') === 'datetime' ? CastPipeline::DATETIME_FORMATS : CastPipeline::DATE_FORMATS))
                                                ->native(false)
                                                ->live()
                                                ->visible(fn (Get $get): bool => in_array($get('type'), ['date', 'datetime'], true)),
                                            TextInput::make('arg')
                                                ->label(fn (Get $get): string => $t('cast_arg.'.($get('type') ?: 'none')))
                                                ->placeholder(fn (Get $get): string => $t('cast_hint.'.($get('type') ?: 'none')))
                                                ->live(onBlur: true)
                                                ->visible(fn (Get $get): bool => in_array($get('type'), ['split', 'default', 'regex', 'boolean'], true)),
                                            Select::make('arg2')
                                                ->label($t('thousands_label'))
                                                ->options([
                                                    'none' => $t('thousands_none'),
                                                    'space' => $t('thousands_space'),
                                                    '.' => $t('thousands_dot'),
                                                    ',' => $t('thousands_comma'),
                                                ])
                                                ->placeholder($t('thousands_unknown'))
                                                ->native(false)
                                                ->live()
                                                ->visible(fn (Get $get): bool => $get('type') === 'decimal'),
                                            TextInput::make('arg2')
                                                ->label($t('format_custom_label'))
                                                ->placeholder('d.m.Y')
                                                ->live(onBlur: true)
                                                ->visible(fn (Get $get): bool => in_array($get('type'), ['date', 'datetime'], true) && $get('arg') === 'custom'),
                                            TextInput::make('arg2')
                                                ->columnSpanFull()
                                                ->label(fn (Get $get): string => $t('cast_arg2.'.($get('type') ?: 'none')))
                                                ->live(onBlur: true)
                                                ->visible(fn (Get $get): bool => in_array($get('type'), ['regex', 'boolean'], true)),
                                        ]),
                                        Repeater::make('map')
                                            ->label($t('value_table'))
                                            ->helperText($t('value_table_help'))
                                            ->addable(false)
                                            ->deletable(false)
                                            ->reorderable(false)
                                            ->compact()
                                            ->default([])
                                            ->schema([
                                                Hidden::make('from'),
                                                Hidden::make('choices'),
                                                Grid::make(['default' => 1, 'sm' => 2])->schema([
                                                    Text::make(fn (Get $get): string => (string) $get('from'))->weight('bold')->extraAttributes(['class' => 'xi-wrap']),
                                                    Select::make('to')
                                                        ->hiddenLabel()
                                                        ->options(fn (Get $get): array => (array) $get('choices'))
                                                        ->placeholder($t('reject_value'))
                                                        ->native(false)
                                                        ->live()
                                                        ->visible(fn (Get $get): bool => (array) $get('choices') !== []),
                                                    TextInput::make('to')
                                                        ->hiddenLabel()
                                                        ->placeholder($t('value_imported'))
                                                        ->live(onBlur: true)
                                                        ->visible(fn (Get $get): bool => (array) $get('choices') === []),
                                                ]),
                                            ])
                                            ->visible(fn (Get $get): bool => $get('type') === 'enum' && (array) $get('map') !== []),
                                    ]),
                                Text::make(fn (Get $get): string => $this->sampleLine($get)[0])
                                    ->color(fn (Get $get): string => $this->sampleLine($get)[1] ? 'gray' : 'danger')
                                    ->extraAttributes(['class' => 'xi-sample'])
                                    ->visible(fn (Get $get): bool => filled($get('target')) && (array) $get('samples') !== []),
                            ]),
                        ]),
                    ])->extraAttributes(fn (Get $get): array => $get('../../only_unmapped') && filled($get('target')) ? ['class' => 'xi-hidden'] : []),
                ]),
            Group::make($this->importerOptions($definition))->statePath('options')->visible($definition->importer !== null && $this->importerOptions($definition) !== []),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('preset_name')->label($t('save_preset'))->helperText($t('save_preset_help'))->maxLength(120)->visible(fn (): bool => Presets::enabled()),
                Toggle::make('preset_shared')->label($t('preset_share'))->inline(false)->visible(fn (): bool => Presets::enabled() && Presets::canShare()),
            ]),
        ];
    }

    /**
     * The first sample of the column run through its pipeline, as the import will see it («1,5 → 1.5»).
     *
     * @return array{0: string, 1: bool} text and whether it worked
     */
    private function sampleLine(Get $get): array
    {
        $samples = array_values((array) $get('samples'));
        $sample = $samples[0] ?? null;

        if ($sample === null) {
            return ['', true];
        }

        $entry = Mapping::fromState([['header' => 'x', 'target' => $get('target'), 'casts' => array_values((array) $get('casts'))]])[0] ?? null;
        $shown = SourceFile::stringify($sample);

        try {
            $result = CastPipeline::apply($sample, $entry['casts'] ?? []);
            $text = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (is_bool($result) ? ($result ? 'true' : 'false') : ($result === null ? 'null' : (string) $result));

            return [__('filament-xlsx-import::filament-xlsx-import.sample_line', ['sample' => $shown, 'result' => (string) $text]), true];
        } catch (Throwable $e) {
            return [$shown.' → '.$e->getMessage(), false];
        }
    }

    /**
     * Creating the related record is allowed by the definition (not locked by a `'mode' => 'lookup'` rule) and by the related
     * model's `create` policy for this user, or by `->allowCreatingRelated()`.
     */
    private function canCreateRelated(TargetField $target): bool
    {
        if (!$target->isRelation() || !$target->allowCreate) {
            return false;
        }

        if ($this->allowCreatingRelated) {
            return true;
        }

        $user = Filament::auth()->user();

        return $user !== null && Gate::getPolicyFor($target->relatedModel) !== null && Gate::forUser($user)->allows('create', $target->relatedModel);
    }

    /**
     * A default «create» mode is brought down to «lookup» when creating is not allowed — the screen must not offer what the server refuses.
     *
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function clampMode(Definition $definition, array $defaults): array
    {
        $target = is_string($defaults['target'] ?? null) ? $definition->target($defaults['target']) : null;

        if ($target !== null && ($defaults['mode'] ?? null) === 'create' && !$this->canCreateRelated($target)) {
            $defaults['mode'] = 'lookup';
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $entry a mapping entry
     *
     * @return array<string, mixed>
     */
    private function clampEntry(Definition $definition, array $entry): array
    {
        $entry = $this->clampMode($definition, $entry);

        if (is_array($entry['rule'] ?? null)) {
            $entry['rule'] = $this->clampMode($definition, $entry['rule']);
        }

        return $entry;
    }

    /**
     * @param array<mixed> $mapping
     *
     * @return list<string> labels of the required fields (and the key) no column is mapped to yet
     */
    private function unmappedRequired(Definition $definition, array $mapping, mixed $key): array
    {
        $mapped = array_filter(array_column(array_filter($mapping, 'is_array'), 'target'), 'is_string');
        $missing = [];

        foreach ($definition->targets as $target) {
            if ($target->mappingRequired && !in_array($target->key, $mapped, true)) {
                $missing[] = $target->label;
            }
        }

        if ($definition->importer === null && is_string($key) && $key !== '' && !in_array($key, $mapped, true)) {
            $missing[] = $definition->target($key)->label ?? $key;
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return array<int, mixed>
     */
    private function importerOptions(Definition $definition): array
    {
        return $definition->importer !== null ? $definition->importer::getOptionsFormComponents() : [];
    }

    /**
     * Whether the user changed what the model's rule had chosen.
     *
     * @param array{target: mixed, casts: array<mixed>, mode: mixed, many: mixed, rule: mixed} $item
     */
    private function isModified(array $item): bool
    {
        if (!is_array($item['rule']) || $item['rule'] === []) {
            return false;
        }

        $current = Mapping::fromState([['header' => 'x', 'target' => $item['target'], 'casts' => array_values($item['casts']), 'mode' => $item['mode'], 'many' => $item['many']]])[0] ?? null;
        $rule = Mapping::fromState([['header' => 'x'] + $item['rule']])[0] ?? null;

        if ($current === null || $rule === null) {
            return false;
        }

        $definition = $this->resolved;
        $isRelation = is_string($current['target']) && ($definition?->target($current['target'])?->isRelation() ?? false);
        $isMany = is_string($current['target']) && ($definition?->target($current['target'])?->isMany() ?? false);

        return $current['target'] !== $rule['target']
            || $current['casts'] !== $rule['casts']
            || ($isRelation && $current['mode'] !== $rule['mode'])
            || ($isMany && $current['many'] !== $rule['many']);
    }

    /**
     * Step 2 → 3: check the mapping and run the dry run.
     */
    /**
     * @param array<string, mixed> $data
     */
    private function assertMappingValid(Definition $definition, array $data): void
    {
        $mapping = Mapping::fromState((array) ($data['mapping'] ?? []));
        $mapped = array_values(array_filter(array_column($mapping, 'target')));

        // The mapping is client state: only targets the definition offers can be written to, and the key is one of them.
        foreach ($mapped as $target) {
            if ($definition->target($target) === null) {
                $this->stop('invalid_target');
            }
        }

        $key = filled($data['update_by'] ?? null) ? (string) $data['update_by'] : null;

        if ($definition->importer === null && $key !== null && ($definition->target($key) === null || $definition->target($key)->isRelation())) {
            $this->stop('invalid_target');
        }

        if (count($mapped) !== count(array_unique($mapped))) {
            $duplicates = array_keys(array_filter(array_count_values($mapped), static fn (int $n): bool => $n > 1));
            $this->stop('duplicate_target', ['field' => implode(', ', array_map(fn (string $k): string => $definition->target($k)->label ?? $k, $duplicates))]);
        }

        $missing = [];

        foreach ($definition->targets as $target) {
            if ($target->mappingRequired && !in_array($target->key, $mapped, true)) {
                $missing[] = $target->label;
            }
        }

        if ($missing !== []) {
            $this->stop('required_unmapped', ['fields' => implode(', ', $missing)]);
        }

        $updateBy = $definition->importer === null && filled($data['update_by'] ?? null) ? (string) $data['update_by'] : null;

        if ($updateBy !== null && !in_array($updateBy, $mapped, true)) {
            $this->stop('key_unmapped', ['field' => $definition->target($updateBy)->label ?? $updateBy]);
        }

        if ($mapped === []) {
            $this->stop('nothing_mapped');
        }
    }

    private function checkImportMapping(Get $get, Set $set, Definition $definition): void
    {
        if (!$this->ownRun($get('run_id'), $definition)) {
            $this->stop('run_gone');
        }

        $this->assertMappingValid($definition, $this->importFormState($get));

        if (!$this->dryRunOn()) {
            return;
        }

        $spec = $this->importSpec($this->importFormState($get), $definition);

        try {
            $result = RunContext::within($spec, fn () => ImportRunner::run($spec, true, $this->saveUsingClosure(), null, $this->relatedQueryClosure(), $this->fixedClosure()));
        } catch (DryRunAborted) {
            $this->stop('dry_run_aborted');
        } catch (Throwable $e) {
            report($e);
            $this->stop('dry_run_failed');
        }

        RunStore::put((string) $get('run_id'), ['preview' => $result]);
    }

    /**
     * @return array<string, mixed>
     */
    private function importFormState(Get $get): array
    {
        return [
            'run_id' => $get('run_id'),
            'mapping' => $get('mapping'),
            'update_by' => $get('update_by'),
            'existing' => $get('existing'),
            'options' => $get('options'),
        ];
    }

    /**
     * The plain-data description of a run: what the dry run, the inline run and the queued job all read.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function importSpec(array $data, Definition $definition): array
    {
        $updateBy = $definition->importer === null && filled($data['update_by'] ?? null) ? (string) $data['update_by'] : null;
        $meta = RunStore::meta((string) $data['run_id']);

        return [
            'run_id' => (string) $data['run_id'],
            'model' => $definition->model,
            'importer' => $definition->importer,
            'definition' => $this->definitionClass,
            'overrides' => $this->overrides(),
            'mapping' => Mapping::fromState((array) ($data['mapping'] ?? [])),
            'update_by' => $updateBy,
            'existing' => $updateBy !== null && in_array($data['existing'] ?? null, ['update', 'skip', 'only'], true) ? $data['existing'] : 'update',
            'authorize' => $this->authorizeRecords ?? Gate::getPolicyFor($definition->model) !== null,
            'allow_create_related' => $this->allowCreatingRelated,
            'fixed' => is_string($this->fixedCallback) ? $this->fixedCallback : null,
            'owner' => $this->ownerSpec(),
            'allow_update_related_without_policy' => $this->allowUpdatingRelatedWithoutPolicy,
            'update_attached' => $this->updateAttachedRecords,
            'related_query' => is_string($this->relatedQueryCallback) ? $this->relatedQueryCallback : null,
            'max_rows' => $this->limitRows(),
            'options' => (array) ($data['options'] ?? []),
            'save_using' => is_string($this->saveUsingCallback) ? $this->saveUsingCallback : null,
            'sheet' => (int) ($meta['sheet'] ?? 0),
            'user' => Filament::auth()->id(),
            'guard' => Filament::getAuthGuard(),
            'panel' => Filament::getCurrentPanel()?->getId(),
            'locale' => app()->getLocale(),
            'tenant' => ($tenant = TenantScope::current()) !== null ? ['class' => $tenant::class, 'key' => $tenant->getKey()] : null,
        ];
    }

    /**
     * @return array{class: class-string, key: mixed, relation: string}|null
     */
    private function ownerSpec(): ?array
    {
        $livewire = $this->getLivewire();

        if ($this->ownerRelation() === null || !is_object($livewire) || !method_exists($livewire, 'getOwnerRecord') || !method_exists($livewire, 'getRelationshipName')) {
            return null;
        }

        $owner = $livewire->getOwnerRecord();

        return $owner instanceof Model ? ['class' => $owner::class, 'key' => $owner->getKey(), 'relation' => (string) $livewire::getRelationshipName()] : null;
    }

    private function fixedClosure(): ?Closure
    {
        if ($this->fixedCallback instanceof Closure) {
            return $this->fixedCallback;
        }

        if (is_string($this->fixedCallback) && $this->fixedCallback !== '') {
            $handler = app($this->fixedCallback);

            return is_callable($handler) ? $handler(...) : null;
        }

        return null;
    }

    private function saveUsingClosure(): ?Closure
    {
        if ($this->saveUsingCallback instanceof Closure) {
            return $this->saveUsingCallback;
        }

        if (is_string($this->saveUsingCallback) && $this->saveUsingCallback !== '') {
            $handler = app($this->saveUsingCallback);

            return is_callable($handler) ? $handler(...) : null;
        }

        return null;
    }

    private function relatedQueryClosure(): ?Closure
    {
        if ($this->relatedQueryCallback instanceof Closure) {
            return $this->relatedQueryCallback;
        }

        if (is_string($this->relatedQueryCallback) && $this->relatedQueryCallback !== '') {
            $handler = app($this->relatedQueryCallback);

            return is_callable($handler) ? $handler(...) : null;
        }

        return null;
    }

    private function willQueue(int $rows): bool
    {
        if (!(bool) config('filament-xlsx-import.queue.enabled', true)) {
            return false;
        }

        return $this->queueAlways || ($this->queueThreshold !== null && $rows > $this->queueThreshold);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function finishImport(array $data): void
    {
        $definition = $this->definitionFor($this->getLivewire());
        $id = (string) ($data['run_id'] ?? '');

        if (!$this->ownRun($id, $definition)) {
            $this->stop('run_gone');
        }

        $this->assertMappingValid($definition, $data);

        $spec = $this->importSpec($data, $definition);
        $rows = (int) (RunStore::meta($id)['rows'] ?? 0);

        if (Presets::enabled() && filled($data['preset_name'] ?? null)) {
            Presets::save($definition->key(), Filament::auth()->id(), (string) $data['preset_name'], $spec['mapping'], ['importer' => $spec['options'], 'update_by' => $spec['update_by'], 'existing' => $spec['existing']], (bool) ($data['preset_shared'] ?? false));
        }

        if ($this->willQueue($rows)) {
            if ($this->saveUsingCallback instanceof Closure || $this->relatedQueryCallback instanceof Closure || $this->fixedCallback instanceof Closure) {
                $this->stop('closure_not_queueable');
            }

            if (!QueuedImports::hasFreeSlot($spec)) {
                $this->stop('too_many_pending');
            }

            QueuedImports::dispatch($spec, $this->queueConnection, $this->queueName);

            Notification::make()->success()
                ->title(__('filament-xlsx-import::filament-xlsx-import.queued_title'))
                ->body(__('filament-xlsx-import::filament-xlsx-import.queued_body', ['count' => number_format($rows)]))
                ->send();

            return;
        }

        RunStore::put($id, ['status' => 'running']);

        try {
            $result = ImportRunner::run(
                $spec,
                false,
                $this->saveUsingClosure(),
                static fn (int $processed, int $total) => RunStore::put($id, ['progress' => ['processed' => $processed, 'total' => $total]]),
                $this->relatedQueryClosure(),
                $this->fixedClosure(),
            );
        } catch (Throwable $e) {
            report($e);
            RunStore::put($id, ['status' => 'failed']);
            ImportNotifier::failed($id)->send();

            return;
        }

        RunStore::put($id, ['status' => 'done', 'result' => $result]);
        RunStore::dropSource($id);

        ImportNotifier::done($id, $result)->send();
    }
}
