<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Support\RunStore;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Enums\ProductStatus;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;
use Workbench\App\Models\Tag;

class ImportFlowTest extends TestCase
{
    use DrivesImport;

    /** @var list<array{string, int|null, bool}> */
    public static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->tearDownDrivesImport();

        parent::tearDown();
    }

    private function sample(): \Illuminate\Http\UploadedFile
    {
        return Sheet::xlsx([
            ['Артикул', 'Назва', 'Ціна', 'Категорія', 'Теги', 'Рекомендований', 'Дата виходу', 'Status'],
            [' ab-1 ', 'Apple', '10,50', 'Fruit', 'red;sweet', 'так', '31.01.2026', 'Active'],
            ['ab-2', 'Bread', '3,2', 'Bakery', '', 'ні', '01.02.2026', 'draft'],
        ]);
    }

    public function test_upload_shows_the_files_columns_with_samples(): void
    {
        $page = $this->upload($this->products(), $this->sample());
        $mapping = $this->mapping($page);

        $this->assertSame(['Артикул', 'Назва', 'Ціна', 'Категорія', 'Теги', 'Рекомендований', 'Дата виходу', 'Status'], array_column($mapping, 'header'));
        $this->assertSame(['ab-1', 'ab-2'], array_map('trim', $mapping[0]['samples']));
        $this->assertSame(['10,50', '3,2'], $mapping[2]['samples']);
    }

    public function test_headers_are_matched_automatically_through_the_models_aliases(): void
    {
        $page = $this->upload($this->products(), $this->sample());

        $this->assertSame(
            ['sku', 'name', 'price', 'category.name', 'tags.name', 'is_featured', 'released_at', 'status'],
            array_column($this->mapping($page), 'target'),
        );
    }

    public function test_the_whole_flow_creates_records_with_mass_assignment_off(): void
    {
        $page = $this->upload($this->products(), $this->sample());
        $page = $this->apply($page);

        $page->assertHasNoActionErrors();
        $this->assertSame(2, Product::count());

        $apple = Product::query()->where('sku', 'AB-1')->firstOrFail();
        $this->assertSame('Apple', $apple->name);
        $this->assertSame('10.50', $apple->price);
        $this->assertTrue($apple->is_featured);
        $this->assertSame('2026-01-31', $apple->released_at?->toDateString());
        $this->assertSame(ProductStatus::Active, $apple->status);
        $this->assertSame('Fruit', $apple->category?->name);
        $this->assertEqualsCanonicalizing(['red', 'sweet'], $apple->tags->pluck('name')->all());

        $bread = Product::query()->where('sku', 'AB-2')->firstOrFail();
        $this->assertFalse($bread->is_featured);
        $this->assertSame(ProductStatus::Draft, $bread->status);
        $this->assertCount(0, $bread->tags);
    }

    public function test_the_dry_run_counts_everything_and_writes_nothing(): void
    {
        $existing = new Product;
        $existing->forceFill(['sku' => 'AB-1', 'name' => 'Old apple', 'price' => 1])->save();

        $page = $this->upload($this->products(), $this->sample())->goToWizardStep(3);
        $preview = $this->preview($page);

        $this->assertSame(1, $preview['created']);
        $this->assertSame(1, $preview['updated']);
        $this->assertSame(0, $preview['failed']);
        $this->assertSame(1, Product::count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Tag::count());
        $this->assertSame('Old apple', $existing->fresh()->name);
    }

    public function test_the_dry_run_shows_field_level_changes_for_updates(): void
    {
        $existing = new Product;
        $existing->forceFill(['sku' => 'AB-1', 'name' => 'Old apple', 'price' => '10.5', 'status' => 'active'])->save();

        $preview = $this->preview($this->upload($this->products(), $this->sample())->goToWizardStep(3));
        $update = $preview['samples']['updated'][0];

        $this->assertSame(2, $update['row']);
        $this->assertSame('AB-1', $update['label']);
        $this->assertSame(['Old apple', 'Apple'], $update['changes']['Name']);
        $this->assertArrayNotHasKey('Price', $update['changes'], 'The price is the same number, 10.5 = 10.50.');
        $this->assertArrayHasKey('Category → Name', $update['changes']);
    }

    public function test_upsert_updates_by_key_and_never_duplicates(): void
    {
        $page = $this->apply($this->upload($this->products(), $this->sample()));
        $this->assertSame(2, Product::count());

        $again = Sheet::xlsx([['Артикул', 'Назва'], ['AB-1', 'Apple v2'], ['ab-2', 'Bread']]);
        $page = $this->upload($this->products(), $again);
        $preview = $this->preview($page->goToWizardStep(3));

        $this->assertSame(1, $preview['updated']);
        $this->assertSame(1, $preview['skipped'], 'Bread is unchanged.');
        $page->callMountedAction();

        $this->assertSame(2, Product::count());
        $this->assertSame('Apple v2', Product::query()->where('sku', 'AB-1')->value('name'));
    }

    public function test_a_blank_cell_does_not_wipe_the_record(): void
    {
        $this->apply($this->upload($this->products(), $this->sample()));

        $this->apply($this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва', 'Ціна'], ['AB-1', 'Apple', '']])));

        $this->assertSame('10.50', Product::query()->where('sku', 'AB-1')->value('price'));
    }

    public function test_create_only_and_update_only_modes(): void
    {
        $this->apply($this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha']])));

        $file = Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha 2'], ['B', 'Beta']]);

        $skip = $this->upload($this->products(), $file)->fillForm(['existing' => 'skip'])->goToWizardStep(3);
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 1], array_intersect_key($this->preview($skip), array_flip(['created', 'updated', 'skipped'])));

        $only = $this->upload($this->products(), $file)->fillForm(['existing' => 'only'])->goToWizardStep(3);
        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 1], array_intersect_key($this->preview($only), array_flip(['created', 'updated', 'skipped'])));
    }

    public function test_a_repeated_key_in_the_file_is_reported_and_updates_the_same_record(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва'], ['A', 'First'], ['A', 'Second']]);
        $page = $this->upload($this->products(), $file)->goToWizardStep(3);
        $preview = $this->preview($page);

        $this->assertSame(1, $preview['created']);
        $this->assertSame(1, $preview['updated']);
        $this->assertSame(1, $preview['duplicates']);

        $page->callMountedAction();

        $this->assertSame(1, Product::count());
        $this->assertSame('Second', Product::query()->value('name'));
    }

    public function test_an_empty_key_fails_the_row_not_the_import(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва'], ['', 'Nameless'], ['OK', 'Fine']]);
        $page = $this->upload($this->products(), $file)->goToWizardStep(3);
        $preview = $this->preview($page);

        $this->assertSame(1, $preview['failed']);
        $this->assertSame(1, $preview['created']);
        $this->assertStringContainsString('Sku', $preview['samples']['errors'][0]['message']);
    }

    public function test_validation_rules_fail_rows_with_a_reason(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва', 'Ціна'], ['A', 'Alpha', '-5'], ['B', '', '1'], ['C', 'Gamma', '2']]);
        $page = $this->upload($this->products(), $file)->goToWizardStep(3);
        $preview = $this->preview($page);

        $this->assertSame(2, $preview['failed']);
        $this->assertSame([2, 3], array_column($preview['samples']['errors'], 'row'));
        $this->assertStringContainsString('0', $preview['samples']['errors'][0]['message']);

        $page->callMountedAction();

        $this->assertSame(['C'], Product::pluck('sku')->all());
    }

    public function test_a_cast_error_names_the_field_and_the_value(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва', 'Кількість'], ['A', 'Alpha', 'lots']]);
        $preview = $this->preview($this->upload($this->products(), $file)->goToWizardStep(3));

        $this->assertSame(1, $preview['failed']);
        $this->assertStringContainsString('lots', $preview['samples']['errors'][0]['message']);
    }

    public function test_a_failed_row_leaves_no_half_written_relations(): void
    {
        // The category would be created first; the row then fails on the unknown tag (lookup only for tags).
        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва', 'Категорія', 'Теги'], ['A', 'Alpha', 'Brand new', 'ghost']]));
        $this->remap($page, ['Теги' => ['mode' => 'lookup']])->goToWizardStep(3);

        $this->assertSame(1, $this->preview($page)['failed']);

        $page->callMountedAction();

        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count(), 'The category created for the failed row was rolled back with it.');
    }

    public function test_related_record_lookup_only_fails_the_row_when_missing(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва', 'Категорія'], ['A', 'Alpha', 'Nowhere']]);
        $page = $this->upload($this->products(), $file);
        $this->remap($page, ['Категорія' => ['mode' => 'lookup']])->goToWizardStep(3);

        $preview = $this->preview($page);
        $this->assertSame(1, $preview['failed']);
        $this->assertStringContainsString('Nowhere', $preview['samples']['errors'][0]['message']);
    }

    public function test_related_record_is_found_not_duplicated(): void
    {
        $category = new Category;
        $category->forceFill(['name' => 'Fruit'])->save();

        $this->apply($this->upload($this->products(), $this->sample()));

        $this->assertSame(2, Category::count());
        $this->assertSame($category->id, Product::query()->where('sku', 'AB-1')->value('category_id'));
    }

    public function test_many_to_many_adds_without_detaching_unless_told_to_replace(): void
    {
        $this->apply($this->upload($this->products(), $this->sample()));
        $file = Sheet::xlsx([['Артикул', 'Теги'], ['AB-1', 'new']]);

        $this->apply($this->upload($this->products(), $file));
        $this->assertEqualsCanonicalizing(['red', 'sweet', 'new'], Product::query()->where('sku', 'AB-1')->firstOrFail()->tags->pluck('name')->all());

        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Теги'], ['AB-1', 'only']]));
        $this->remap($page, ['Теги' => ['many' => 'replace']]);
        $this->apply($page);

        $this->assertSame(['only'], Product::query()->where('sku', 'AB-1')->firstOrFail()->tags->pluck('name')->all());
    }

    public function test_an_enum_value_table_maps_what_the_file_says(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва', 'Status'], ['A', 'Alpha', 'Активний'], ['B', 'Beta', 'В архіві']]);
        $page = $this->upload($this->products(), $file);
        $status = collect($this->mapping($page))->firstWhere('header', 'Status');

        $this->assertSame('status', $status['target']);
        $this->assertSame('enum', $status['casts'][0]['type']);
        $this->assertSame([['from' => 'Активний', 'to' => ''], ['from' => 'В архіві', 'to' => '']], array_map(fn (array $row): array => ['from' => $row['from'], 'to' => $row['to']], $status['casts'][0]['map']), 'Every distinct value of the file is listed for the user to map.');

        $this->remap($page, ['Status' => ['casts' => [['type' => 'enum', 'arg' => ProductStatus::class, 'arg2' => '', 'map' => [['from' => 'Активний', 'to' => 'active'], ['from' => 'В архіві', 'to' => 'archived']]]]]]);
        $this->apply($page);

        $this->assertSame(ProductStatus::Active, Product::query()->where('sku', 'A')->firstOrFail()->status);
        $this->assertSame(ProductStatus::Archived, Product::query()->where('sku', 'B')->firstOrFail()->status);
    }

    public function test_a_column_can_be_skipped_and_a_different_attribute_picked(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва', 'Ціна', 'Memo'], ['A', 'Alpha', '5', 'secret']]);
        $page = $this->upload($this->products(), $file);
        $this->remap($page, ['Ціна' => ['target' => null, 'casts' => []], 'Memo' => ['target' => 'notes', 'casts' => [['type' => 'trim', 'arg' => '', 'arg2' => '', 'map' => []]]]]);
        $this->apply($page);

        $product = Product::query()->where('sku', 'A')->firstOrFail();
        $this->assertSame('0.00', $product->price);
        $this->assertSame('secret', $product->notes);
    }

    public function test_the_same_target_twice_is_refused(): void
    {
        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва', 'Title'], ['A', 'Alpha', 'Other']]));
        $this->remap($page, ['Title' => ['target' => 'name']]);

        $page->goToWizardStep(3);

        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.duplicate_target', ['field' => 'Name']));
        $this->assertSame([], $this->preview($page));
    }

    public function test_the_key_column_must_be_mapped(): void
    {
        $page = $this->upload($this->products(), Sheet::xlsx([['Назва'], ['Alpha']]));
        $page->goToWizardStep(3);

        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.required_unmapped', ['fields' => 'Sku']));
    }

    public function test_the_sheet_can_be_picked(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва'], ['A', 'First sheet']], more: ['Other' => [['Артикул', 'Назва'], ['B', 'Second sheet']]]);
        $page = $this->upload($this->products(), $file, ['sheet' => 1, 'sheet_names' => ['Sheet1', 'Other']]);
        $page->goToWizardStep(3)->callMountedAction();

        $this->assertSame(['B'], Product::pluck('sku')->all());
    }

    public function test_csv_with_semicolons_and_cyrillic_windows_1251(): void
    {
        $csv = mb_convert_encoding("Артикул;Назва;Ціна\nA;Яблуко;10,5\n", 'Windows-1251', 'UTF-8');
        $this->apply($this->upload($this->products(), Sheet::csv($csv)));

        $product = Product::query()->where('sku', 'A')->firstOrFail();
        $this->assertSame('Яблуко', $product->name);
        $this->assertSame('10.50', $product->price);
    }

    public function test_limits_refuse_a_file_with_too_many_rows(): void
    {
        config(['filament-xlsx-import.max_rows' => 2]);
        $file = Sheet::xlsx([['Артикул'], ['A'], ['B'], ['C']]);

        $page = $this->upload($this->products(), $file);

        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.too_many_rows', ['limit' => 2]));
        $this->assertSame('', $this->runId($page));
    }

    public function test_an_unreadable_or_empty_file_is_refused(): void
    {
        $this->upload($this->products(), Sheet::xlsx([]));
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.empty_file'));

        $this->upload($this->products(), Sheet::csv('', 'garbage.csv'));
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.empty_file'));
    }

    public function test_the_file_size_limit_is_enforced_by_the_upload_field(): void
    {
        config(['filament-xlsx-import.max_file_size' => 1]);

        $big = Sheet::csv(str_repeat("a,b\n", 2000));

        $page = $this->products()->mountAction('xlsxImport')->fillForm(['file' => $big])->goToWizardStep(2);

        $this->assertSame('', $this->runId($page), 'The step did not advance: nothing was staged.');
    }

    public function test_a_wrong_file_type_is_refused(): void
    {
        $page = $this->products()->mountAction('xlsxImport')->fillForm(['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('virus.exe', 'MZ')])->goToWizardStep(2);

        $this->assertSame('', $this->runId($page));
    }

    public function test_authorize_records_checks_the_policy_per_row(): void
    {
        \Illuminate\Support\Facades\Gate::policy(Product::class, \Asignua\FilamentXlsxImport\Tests\Support\DenyCreatePolicy::class);
        \Workbench\App\Filament\Resources\Products\ProductResource::$header = fn (): array => [
            \Asignua\FilamentXlsxImport\Actions\XlsxImportAction::make()->model(Product::class)->authorizeRecords(),
        ];

        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha']]))->goToWizardStep(3);

        $this->assertSame(1, $this->preview($page)['failed']);
        $page->callMountedAction();
        $this->assertSame(0, Product::count());
    }

    public function test_save_using_takes_over_the_write_for_repository_only_apps(): void
    {
        self::$calls = [];
        \Workbench\App\Filament\Resources\Products\ProductResource::$header = fn (): array => [
            \Asignua\FilamentXlsxImport\Actions\XlsxImportAction::make()->model(Product::class)->saveUsing(
                function (array $data, ?Product $existing, \Asignua\FilamentXlsxImport\Support\ImportContext $ctx): Product {
                    self::$calls[] = [$data['sku'], $existing?->id, $ctx->dryRun];
                    $product = $existing ?? new Product;
                    $product->forceFill(['sku' => $data['sku'], 'name' => strtoupper($data['name'])])->save();

                    return $product;
                },
            ),
        ];

        $this->apply($this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha']])));

        $this->assertSame([['A', null, true], ['A', null, false]], self::$calls, 'Once in the dry run (rolled back), once for real.');
        $this->assertSame('ALPHA', Product::query()->value('name'));
        $this->assertSame(1, Product::count());
    }

    public function test_dry_run_can_be_switched_off(): void
    {
        \Workbench\App\Filament\Resources\Products\ProductResource::$header = fn (): array => [
            \Asignua\FilamentXlsxImport\Actions\XlsxImportAction::make()->model(Product::class)->dryRun(false),
        ];

        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha']]));
        $page->callMountedAction();

        $this->assertSame(1, Product::count());
    }

    public function test_the_dry_run_reads_at_most_the_configured_rows_and_says_so(): void
    {
        config(['filament-xlsx-import.dry_run.max_rows' => 2]);
        $file = Sheet::xlsx([['Артикул', 'Назва'], ['A', 'a'], ['B', 'b'], ['C', 'c'], ['D', 'd']]);

        $page = $this->upload($this->products(), $file)->goToWizardStep(3);
        $preview = $this->preview($page);

        $this->assertTrue($preview['truncated']);
        $this->assertSame(2, $preview['processed']);

        $page->callMountedAction();
        $this->assertSame(4, Product::count(), 'The real import reads every row.');
    }

    public function test_finishing_notifies_with_the_counts_and_a_link_to_the_failed_rows(): void
    {
        $file = Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha'], ['', 'Bad']]);
        $page = $this->apply($this->upload($this->products(), $file));
        $id = $this->lastRunId;

        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.done_title'));
        $this->assertSame(1, RunStore::meta($id)['result']['failed']);
        $this->assertTrue(RunStore::disk()->exists(RunStore::directory().'/'.$id.'/report.xlsx'));
        $this->assertFalse(RunStore::disk()->exists(RunStore::directory().'/'.$id.'/source.xlsx'), 'The upload is deleted once imported.');
    }
}
