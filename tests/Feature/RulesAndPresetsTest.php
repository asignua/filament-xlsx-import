<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Asignua\FilamentXlsxImport\Models\ImportPreset;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Contact;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

class RulesAndPresetsTest extends TestCase
{
    use DrivesImport;

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

    private function badges(array $data): string
    {
        return view('filament-xlsx-import::mapping-source', $data + ['header' => 'H', 'samples' => ['s'], 'origin' => 'none', 'rule' => null, 'modified' => false])->render();
    }

    public function test_the_mapping_screen_marks_what_came_from_the_model_rules(): void
    {
        $rule = ['target' => 'sku', 'casts' => [], 'mode' => 'lookup', 'many' => 'add'];

        $this->assertStringContainsString(__('filament-xlsx-import::filament-xlsx-import.badge_rule'), $this->badges(['rule' => $rule, 'origin' => 'rules']));
        $this->assertStringContainsString(__('filament-xlsx-import::filament-xlsx-import.badge_changed'), $this->badges(['rule' => $rule, 'origin' => 'rules', 'modified' => true]));
        $this->assertStringContainsString(__('filament-xlsx-import::filament-xlsx-import.badge_preset'), $this->badges(['origin' => 'preset']));
        $this->assertStringContainsString(__('filament-xlsx-import::filament-xlsx-import.badge_auto'), $this->badges(['origin' => 'auto']));
        $this->assertStringNotContainsString('<span class="xi-badge', $this->badges([]));
    }

    public function test_a_column_is_modified_only_when_it_differs_from_its_rule(): void
    {
        $action = XlsxImportAction::make()->model(Product::class);
        $action->definitionFor(null);
        $modified = new \ReflectionMethod($action, 'isModified');
        $rule = ['target' => 'price', 'casts' => [['type' => 'decimal', 'arg' => ',', 'arg2' => '', 'map' => []]], 'mode' => 'lookup', 'many' => 'add'];
        $item = fn (array $over = []): array => $over + ['target' => 'price', 'casts' => [['type' => 'decimal', 'arg' => ',', 'arg2' => '', 'map' => []]], 'mode' => 'lookup', 'many' => 'add', 'rule' => $rule];

        $this->assertFalse($modified->invoke($action, $item()));
        $this->assertTrue($modified->invoke($action, $item(['target' => 'stock'])));
        $this->assertTrue($modified->invoke($action, $item(['casts' => [['type' => 'decimal', 'arg' => '.', 'arg2' => '', 'map' => []]]])));
        $this->assertTrue($modified->invoke($action, $item(['casts' => []])));
        $this->assertFalse($modified->invoke($action, $item(['rule' => null])), 'No model rule, nothing to be modified from.');
    }

    public function test_a_rule_can_be_changed_for_one_run(): void
    {
        $file = fn () => Sheet::xlsx([['Артикул', 'Назва', 'Ціна'], ['A', 'Alpha', '1234.5'], ['B', 'Beta', '1,234.5']]);

        // The model's rule reads «,» as the decimal separator, so 1234.5 would silently become 12345.
        $this->apply($this->upload($this->products(), $file()));
        $this->assertSame('12345.00', Product::query()->where('sku', 'A')->value('price'));

        // This run's file uses «.»: the override fixes it for this run only.
        $page = $this->upload($this->products(), $file());
        $this->remap($page, ['Ціна' => ['casts' => [['type' => 'decimal', 'arg' => '.', 'arg2' => '', 'map' => []]]]]);
        $this->apply($page);

        $this->assertSame('1234.50', Product::query()->where('sku', 'A')->value('price'));
        $this->assertSame('1234.50', Product::query()->where('sku', 'B')->value('price'));
    }

    public function test_changing_a_rule_for_one_run_does_not_touch_the_next_one(): void
    {
        $file = fn () => Sheet::xlsx([['Артикул', 'Назва', 'Ціна'], ['A', 'Alpha', '1,5']]);

        $page = $this->upload($this->products(), $file());
        $this->remap($page, ['Ціна' => ['target' => null, 'casts' => []]]);
        $this->apply($page);
        $this->assertSame('0.00', Product::query()->value('price'));

        $again = $this->upload($this->products(), $file());
        $price = collect($this->mapping($again))->firstWhere('header', 'Ціна');

        $this->assertSame('price', $price['target'], 'The model rule is back for the next import.');
        $this->assertSame('decimal', $price['casts'][1]['type']);
    }

    public function test_choosing_another_target_resets_the_casts_to_that_targets_defaults(): void
    {
        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва', 'Memo'], ['A', 'Alpha', '5']]));
        $this->remap($page, ['Memo' => ['target' => 'stock']]);

        $memo = collect($this->mapping($page))->firstWhere('header', 'Memo');

        $this->assertSame(['trim', 'integer'], array_column($memo['casts'], 'type'));
    }

    public function test_a_mapping_can_be_saved_as_a_preset_and_applied_to_a_file_with_shuffled_columns(): void
    {
        $first = Sheet::xlsx([['Code', 'Title', 'Cost', 'Where'], ['x-1', 'One', '1,5', 'Lviv']]);
        $page = $this->upload($this->products(), $first);
        $this->remap($page, [
            'Code' => ['target' => 'sku'],
            'Title' => ['target' => 'name'],
            'Cost' => ['target' => 'price'],
            'Where' => ['target' => 'category.name'],
        ]);
        $page->fillForm(['preset_name' => 'Supplier A']);
        $this->apply($page);

        $preset = ImportPreset::query()->firstOrFail();
        $this->assertSame(Product::class, $preset->target);
        $this->assertSame('Supplier A', $preset->name);
        $this->assertSame((string) auth()->id(), $preset->user_id);
        $this->assertFalse($preset->shared);
        $this->assertSame(['Code', 'Title', 'Cost', 'Where'], array_column($preset->mapping, 'header'));

        // Another supplier file: the same headers, in another order, plus a new column.
        $shuffled = Sheet::xlsx([['Extra', 'Where', 'Cost', 'Code', 'Title'], ['?', 'Kyiv', '2,5', 'x-2', 'Two']]);
        $page = $this->upload($this->products(), $shuffled, ['preset_id' => $preset->id]);

        $mapping = $this->mapping($page);
        $this->assertSame([null, 'category.name', 'price', 'sku', 'name'], array_column($mapping, 'target'));
        $this->assertSame('preset', $mapping[2]['origin']);

        $this->apply($page);
        $two = Product::query()->where('sku', 'X-2')->firstOrFail();
        $this->assertSame('2.50', $two->price);
        $this->assertSame('Kyiv', $two->category?->name);
    }

    public function test_the_same_name_by_the_same_owner_overwrites_the_preset(): void
    {
        foreach ([1, 2] as $run) {
            $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A'.$run, 'Alpha']]));
            $page->fillForm(['preset_name' => 'Mine']);
            $this->apply($page);
        }

        $this->assertSame(1, ImportPreset::query()->count());
    }

    public function test_presets_are_private_unless_shared_and_sharing_needs_the_gate(): void
    {
        $other = User::factory()->create();
        $mine = new ImportPreset;
        $mine->forceFill(['target' => Product::class, 'name' => 'Private', 'user_id' => (string) auth()->id(), 'shared' => false, 'mapping' => []])->save();
        $theirs = new ImportPreset;
        $theirs->forceFill(['target' => Product::class, 'name' => 'Theirs', 'user_id' => (string) $other->id, 'shared' => false, 'mapping' => []])->save();
        $shared = new ImportPreset;
        $shared->forceFill(['target' => Product::class, 'name' => 'Team', 'user_id' => (string) $other->id, 'shared' => true, 'mapping' => []])->save();
        $elsewhere = new ImportPreset;
        $elsewhere->forceFill(['target' => Contact::class, 'name' => 'Other model', 'user_id' => (string) auth()->id(), 'shared' => true, 'mapping' => []])->save();

        $visible = ImportPreset::query()->visibleTo(Product::class, auth()->id())->orderBy('name')->pluck('name')->all();

        $this->assertSame(['Private', 'Team'], $visible);

        // Sharing: without the ability nothing is shared even when asked to.
        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha']]));
        $page->fillForm(['preset_name' => 'Wants to share', 'preset_shared' => true]);
        $this->apply($page);
        $this->assertFalse(ImportPreset::query()->where('name', 'Wants to share')->firstOrFail()->shared);

        Gate::define('shareXlsxImportPresets', fn () => true);
        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['B', 'Beta']]));
        $page->fillForm(['preset_name' => 'Allowed to share', 'preset_shared' => true]);
        $this->apply($page);
        $this->assertTrue(ImportPreset::query()->where('name', 'Allowed to share')->firstOrFail()->shared);
    }

    public function test_somebody_elses_private_preset_cannot_be_applied(): void
    {
        $other = User::factory()->create();
        $theirs = new ImportPreset;
        $theirs->forceFill(['target' => Product::class, 'name' => 'Theirs', 'user_id' => (string) $other->id, 'shared' => false, 'mapping' => [['header' => 'Назва', 'target' => 'notes', 'casts' => [], 'mode' => 'lookup', 'many' => 'add']]])->save();

        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha']]), ['preset_id' => $theirs->id]);

        $this->assertSame('name', collect($this->mapping($page))->firstWhere('header', 'Назва')['target'], 'The foreign preset was ignored.');
    }

    public function test_presets_can_be_switched_off(): void
    {
        config(['filament-xlsx-import.presets.enabled' => false]);

        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A', 'Alpha']]));
        $page->fillForm(['preset_name' => 'Nope']);
        $this->apply($page);

        $this->assertSame(0, ImportPreset::query()->count());
    }

    public function test_a_zero_config_model_imports_with_only_what_the_database_says(): void
    {
        // No rules, no importer: the key is picked in the UI.
        $page = $this->upload($this->contacts(), Sheet::xlsx([['Name', 'E-mail', 'Phone'], ['Ada', 'ada@example.com', '+1'], ['Bob', 'bob@example.com', '']]));
        $page->fillForm(['update_by' => 'email']);
        $this->apply($page);

        $this->assertSame(['ada@example.com', 'bob@example.com'], Contact::orderBy('email')->pluck('email')->all());
        $this->assertSame('+1', Contact::query()->where('email', 'ada@example.com')->value('phone'));

        $again = $this->upload($this->contacts(), Sheet::xlsx([['Name', 'E-mail'], ['Ada L.', 'ada@example.com']]));
        $again->fillForm(['update_by' => 'email']);
        $this->apply($again);

        $this->assertSame(2, Contact::count());
        $this->assertSame('Ada L.', Contact::query()->where('email', 'ada@example.com')->value('name'));
    }

    public function test_without_a_key_every_row_creates_and_the_preview_says_so(): void
    {
        $file = fn () => Sheet::xlsx([['Name', 'Email'], ['Ada', 'ada@example.com']]);
        $page = $this->upload($this->contacts(), $file());
        $this->assertSame(1, $this->preview($page->goToWizardStep(3))['created']);
    }

    public function test_the_key_target_must_be_mapped_when_one_is_chosen(): void
    {
        $page = $this->upload($this->contacts(), Sheet::xlsx([['Name'], ['Ada']]));
        $page->fillForm(['update_by' => 'email'])->goToWizardStep(3);

        \Filament\Notifications\Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.key_unmapped', ['field' => 'Email']));
    }
}
