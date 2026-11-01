<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Actions;

use Asignua\FilamentXlsxImport\Data\Definition;
use Asignua\FilamentXlsxImport\Data\TargetField;
use Asignua\FilamentXlsxImport\Support\CastPipeline;
use Asignua\FilamentXlsxImport\Support\CsvNormalizer;
use Asignua\FilamentXlsxImport\Support\DefinitionResolver;
use Asignua\FilamentXlsxImport\Support\ImportNotifier;
use Asignua\FilamentXlsxImport\Support\ImportRunner;
use Asignua\FilamentXlsxImport\Support\Mapping;
use Asignua\FilamentXlsxImport\Support\Presets;
use Asignua\FilamentXlsxImport\Support\QueuedImports;
use Asignua\FilamentXlsxImport\Support\RunContext;
use Asignua\FilamentXlsxImport\Support\RunStore;
use Asignua\FilamentXlsxImport\Support\SourceFile;
use Asignua\FilamentXlsxImport\Support\TemplateBuilder;
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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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

    protected bool $authorizeRecords = false;

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
     * Check the model's policy (`create` / `update`) for every row; a denied row fails with its reason in the report.
     */
    public function authorizeRecords(bool $condition = true): static
    {
        $this->authorizeRecords = $condition;

        return $this;
    }

    /**
     * Take over the write: `fn (array $data, ?Model $existing, ImportContext $ctx)`. Needed for apps that
     * go through a repository. To run queued, give a class-string of an invokable class (a closure cannot be queued).
     *
     * @param Closure|class-string $callback
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

    public function definitionFor(mixed $livewire = null): Definition
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $model = $this->importerClass === null ? $this->getModel(false) : null;

        if ($model === null && $this->importerClass === null && $livewire instanceof HasTable) {
            $model = $livewire->getTable()->getModel();
        }

        $overrides = ['update_by' => $this->updateByKey, 'fields' => $this->fieldRules];

        foreach ($this->extraAliases as $key => $aliases) {
            $overrides['fields'][$key]['aliases'] = $aliases;
        }

        return $this->resolved = DefinitionResolver::resolve(is_string($model) ? $model : null, $this->importerClass, $this->definitionClass, $overrides);
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
                View::make('filament-xlsx-import::preview')->viewData(fn (Get $get): array => [
                    'preview' => $get('run_id') ? (RunStore::meta((string) $get('run_id'))['preview'] ?? null) : null,
                    'rows' => $get('run_id') ? (int) (RunStore::meta((string) $get('run_id'))['rows'] ?? 0) : 0,
                    'queued' => $this->willQueue((int) (RunStore::meta((string) $get('run_id'))['rows'] ?? 0)),
                ]),
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
                    ->options(['' => $t('auto'), 'UTF-8' => 'UTF-8', 'Windows-1251' => 'Windows-1251', 'Windows-1252' => 'Windows-1252', 'UTF-16' => 'UTF-16', 'ISO-8859-2' => 'ISO-8859-2'])
                    ->default($this->forcedEncoding ?? '')
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::isCsv($get('file'))),
                Select::make('csv_delimiter')
                    ->label($t('csv_delimiter'))
                    ->options(['' => $t('auto'), ',' => ',', ';' => ';', "\t" => 'Tab', '|' => '|'])
                    ->default('')
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

        $name = $file->getClientOriginalName();
        $extension = SourceFile::extensionOf($name);

        if (!in_array($extension, SourceFile::EXTENSIONS, true)) {
            $this->stop('bad_type');
        }

        $sheet = (int) ($get('sheet') ?? 0);
        $encoding = (string) ($get('csv_encoding') ?? '');
        $delimiter = (string) ($get('csv_delimiter') ?? '');
        $presetId = $get('preset_id');
        $signature = md5(implode('|', [$file->getFilename(), $sheet, $encoding, $delimiter, (string) $presetId]));

        if ($get('stage_sig') === $signature && is_string($get('run_id')) && RunStore::exists((string) $get('run_id'))) {
            return;
        }

        if (is_string($get('run_id')) && $get('run_id') !== '') {
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
            if ($extension === 'csv') {
                $raw = RunStore::path($id, 'upload.raw');
                $this->copyUpload($file, $raw);
                $info = CsvNormalizer::normalize($raw, RunStore::path($id, 'source.csv'), $encoding !== '' ? $encoding : $this->forcedEncoding, $delimiter !== '' ? $delimiter : null);
                @unlink($raw);
                RunStore::put($id, ['delimiter' => $info['delimiter'], 'csv_encoding' => $info['encoding']]);
            } else {
                $this->copyUpload($file, RunStore::path($id, 'source.'.$extension));
            }

            $scan = RunStore::source($id)->scan($sheet);
        } catch (Throwable $e) {
            RunStore::forget($id);
            report($e);
            $this->stop('unreadable');
        }

        if ($scan['headers'] === [] || $scan['rows'] === 0) {
            RunStore::forget($id);
            $this->stop('empty_file');
        }

        $max = $this->limitRows();

        if ($max !== null && $scan['rows'] > $max) {
            RunStore::forget($id);
            $this->stop('too_many_rows', ['count' => number_format($scan['rows']), 'limit' => number_format($max)]);
        }

        RunStore::put($id, ['headers' => $scan['headers'], 'rows' => $scan['rows']]);

        $preset = $presetId ? Presets::find($presetId, $definition->key(), Filament::auth()->id()) : null;

        $set('run_id', $id);
        $set('stage_sig', $signature);
        $set('mapping', Mapping::initial($definition, $scan['headers'], $scan['samples'], $scan['distinct'], $preset?->mapping));
        $set('update_by', $this->updateByKey ?? $definition->updateBy);
        $set('existing', $this->existingMode);
        $set('options', is_array($preset?->options) ? ($preset->options['importer'] ?? []) : []);
        $set('file_summary', $name.' · '.__('filament-xlsx-import::filament-xlsx-import.summary_rows', ['rows' => number_format($scan['rows']), 'columns' => count($scan['headers'])]));
    }

    /**
     * @return array<int, mixed>
     */
    private function mappingSchema(Definition $definition): array
    {
        $t = static fn (string $key): string => __('filament-xlsx-import::filament-xlsx-import.'.$key);
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

        return [
            Text::make(fn (Get $get): string => (string) $get('file_summary'))->color('gray'),
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
                    View::make('filament-xlsx-import::mapping-source')->viewData(fn (Get $get): array => [
                        'header' => (string) $get('header'),
                        'samples' => (array) $get('samples'),
                        'origin' => (string) $get('origin'),
                        'rule' => $get('rule'),
                        'modified' => $this->isModified([
                            'target' => $get('target'), 'casts' => (array) $get('casts'), 'mode' => $get('mode'), 'many' => $get('many'), 'rule' => $get('rule'),
                        ]),
                    ]),
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        Select::make('target')
                            ->label($t('import_into'))
                            ->options($definition->options())
                            ->placeholder($t('skip_column'))
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($definition): void {
                                $target = is_string($state) ? $definition->target($state) : null;
                                $set('casts', $target !== null ? Mapping::defaults($target, array_map('strval', (array) $get('distinct')))['casts'] : []);
                                $set('mode', $target?->mode ?? 'lookup');
                                $set('many', $target?->many ?? 'add');
                            }),
                        Select::make('mode')
                            ->label($t('relation_mode'))
                            ->options(['lookup' => $t('mode_lookup'), 'create' => $t('mode_create')])
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
                    Repeater::make('casts')
                        ->label($t('casts'))
                        ->addActionLabel($t('add_cast'))
                        ->default([])
                        ->live()
                        ->visible(fn (Get $get): bool => filled($get('target')))
                        ->schema([
                            Grid::make(['default' => 1, 'md' => 4])->schema([
                                Select::make('type')->label($t('cast_label'))->options($castTypes)->native(false)->required()->live(),
                                TextInput::make('arg')
                                    ->label(fn (Get $get): string => $t('cast_arg.'.($get('type') ?: 'none')))
                                    ->placeholder(fn (Get $get): string => $t('cast_hint.'.($get('type') ?: 'none')))
                                    ->live(onBlur: true)
                                    ->visible(fn (Get $get): bool => in_array($get('type'), ['decimal', 'date', 'datetime', 'enum', 'split', 'default', 'regex', 'boolean'], true)),
                                TextInput::make('arg2')
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
                                    Grid::make(['default' => 2])->schema([
                                        TextInput::make('from')->label($t('value_in_file'))->readOnly(),
                                        TextInput::make('to')->label($t('value_imported'))->live(onBlur: true),
                                    ]),
                                ])
                                ->visible(fn (Get $get): bool => $get('type') === 'enum' && (array) $get('map') !== []),
                        ]),
                    Actions::make([
                        Action::make('resetRule')
                            ->label($t('reset_rule'))
                            ->icon(Heroicon::OutlinedArrowUturnLeft)
                            ->link()
                            ->visible(fn (Get $get): bool => $this->isModified([
                                'target' => $get('target'), 'casts' => (array) $get('casts'), 'mode' => $get('mode'), 'many' => $get('many'), 'rule' => $get('rule'),
                            ]))
                            ->action(function (Get $get, Set $set): void {
                                $rule = (array) $get('rule');
                                $set('target', $rule['target'] ?? null);
                                $set('casts', $rule['casts'] ?? []);
                                $set('mode', $rule['mode'] ?? 'lookup');
                                $set('many', $rule['many'] ?? 'add');
                            }),
                    ]),
                ])
                ->itemLabel(fn (array $state): string => (string) ($state['header'] ?? '')),
            Group::make($this->importerOptions($definition))->statePath('options')->visible($definition->importer !== null && $this->importerOptions($definition) !== []),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('preset_name')->label($t('save_preset'))->helperText($t('save_preset_help'))->maxLength(120)->visible(fn (): bool => Presets::enabled()),
                Toggle::make('preset_shared')->label($t('preset_share'))->inline(false)->visible(fn (): bool => Presets::enabled() && Presets::canShare()),
            ]),
        ];
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

        if (count($mapped) !== count(array_unique($mapped))) {
            $duplicates = array_keys(array_filter(array_count_values($mapped), static fn (int $n): bool => $n > 1));
            $this->stop('duplicate_target', ['field' => implode(', ', array_map(fn (string $k): string => $definition->target($k)?->label ?? $k, $duplicates))]);
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
            $this->stop('key_unmapped', ['field' => $definition->target($updateBy)?->label ?? $updateBy]);
        }

        if ($mapped === []) {
            $this->stop('nothing_mapped');
        }

    }

    private function checkImportMapping(Get $get, Set $set, Definition $definition): void
    {
        $this->assertMappingValid($definition, $this->importFormState($get));

        if (!$this->dryRunOn()) {
            return;
        }

        $spec = $this->importSpec($this->importFormState($get), $definition);

        try {
            $result = RunContext::within($spec, fn () => ImportRunner::run($spec, true, $this->saveUsingClosure()));
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
            'overrides' => ['update_by' => $this->updateByKey, 'fields' => $this->fieldRules],
            'mapping' => Mapping::fromState((array) ($data['mapping'] ?? [])),
            'update_by' => $updateBy,
            'existing' => $updateBy !== null && in_array($data['existing'] ?? null, ['update', 'skip', 'only'], true) ? $data['existing'] : 'update',
            'authorize' => $this->authorizeRecords,
            'options' => (array) ($data['options'] ?? []),
            'save_using' => is_string($this->saveUsingCallback) ? $this->saveUsingCallback : null,
            'sheet' => (int) ($meta['sheet'] ?? 0),
            'user' => Filament::auth()->id(),
            'guard' => Filament::getAuthGuard(),
            'panel' => Filament::getCurrentPanel()?->getId(),
            'locale' => app()->getLocale(),
        ];
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

        if (!RunStore::exists($id)) {
            $this->stop('run_gone');
        }

        $this->assertMappingValid($definition, $data);

        $spec = $this->importSpec($data, $definition);
        $rows = (int) (RunStore::meta($id)['rows'] ?? 0);

        if (Presets::enabled() && filled($data['preset_name'] ?? null)) {
            Presets::save($definition->key(), Filament::auth()->id(), (string) $data['preset_name'], $spec['mapping'], ['importer' => $spec['options'], 'update_by' => $spec['update_by'], 'existing' => $spec['existing']], (bool) ($data['preset_shared'] ?? false));
        }

        if ($this->willQueue($rows)) {
            if ($this->saveUsingCallback instanceof Closure) {
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

        $result = ImportRunner::run($spec, false, $this->saveUsingClosure());
        RunStore::put($id, ['status' => 'done', 'result' => $result]);
        RunStore::dropSource($id);

        ImportNotifier::done($id, $result)->send();
    }
}
