<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Asignua\FilamentXlsxImport\Jobs\ImportXlsxJob;
use Asignua\FilamentXlsxImport\Support\CastPipeline;
use Asignua\FilamentXlsxImport\Support\DefinitionResolver;
use Asignua\FilamentXlsxImport\Support\ImportContext;
use Asignua\FilamentXlsxImport\Support\Mapping;
use Asignua\FilamentXlsxImport\Support\ModelIntrospector;
use Asignua\FilamentXlsxImport\Support\SourceFile;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\GuardedThing;
use Asignua\FilamentXlsxImport\Tests\Support\RelatedContact;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Workbench\App\Enums\ProductStatus;
use Workbench\App\Filament\Resources\Categories\Pages\EditCategory;
use Workbench\App\Filament\Resources\Categories\RelationManagers\ProductsRelationManager;
use Workbench\App\Filament\Resources\Contacts\ContactResource;
use Workbench\App\Filament\Resources\Products\Pages\EditProduct;
use Workbench\App\Filament\Resources\Products\ProductResource;
use Workbench\App\Filament\Resources\Products\RelationManagers\TagsRelationManager;
use Workbench\App\Models\Category;
use Workbench\App\Models\Contact;
use Workbench\App\Models\Employee;
use Workbench\App\Models\Product;
use Workbench\App\Models\Tag;
use Workbench\App\Models\User;

/**
 * The last review round.
 */
class FinalRoundTest extends TestCase
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
        ProductsRelationManager::$header = null;
        TagsRelationManager::$header = null;

        parent::tearDown();
    }

    // ---- 1. secrets in any spelling

    public function test_secret_and_privilege_names_are_recognised_in_any_spelling(): void
    {
        foreach (['PASSWORD', 'ROLE', 'IS_ADMIN', 'API_TOKEN', 'is-admin', 'Is Admin', 'password', 'apiToken', 'isAdmin', 'TWO_FACTOR_SECRET', 'Remember-Token', 'ROLE_ID', 'permissions'] as $name) {
            $this->assertTrue(ModelIntrospector::sensitiveName($name), $name);
        }

        foreach (['email', 'EMAIL', 'name', 'city', 'tokenizer_note'] as $name) {
            $this->assertFalse(ModelIntrospector::sensitiveName($name), $name);
        }
    }

    // ---- 2. allowCreatingRelated is about creating

    public function test_allow_creating_related_does_not_let_a_file_change_an_existing_related_record(): void
    {
        $user = User::factory()->create(['email' => 'u@example.com']);
        $employee = new Employee;
        $employee->forceFill(['name' => 'Eve', 'code' => 'E-1'])->save();
        $user->forceFill(['employee_id' => $employee->id])->save();
        ContactResource::$header = fn (): array => [XlsxImportAction::make()->model(Employee::class)->only(['code', 'account.email'])->relations(['account'])->updateBy('code')->authorizeRecords(false)->allowCreatingRelated()];

        $this->apply($this->upload($this->contacts(), Sheet::csv("code,account email\nE-1,takeover@example.com\n")));

        $this->assertSame('u@example.com', $user->fresh()->email, 'Creating is allowed; changing the existing account is not.');
    }

    public function test_the_update_policy_of_the_related_model_decides_even_with_allow_creating_related(): void
    {
        $user = User::factory()->create(['email' => 'u@example.com']);
        $employee = new Employee;
        $employee->forceFill(['name' => 'Eve', 'code' => 'E-1'])->save();
        $user->forceFill(['employee_id' => $employee->id])->save();
        Gate::policy(User::class, AllowAllUserPolicy::class);
        ContactResource::$header = fn (): array => [XlsxImportAction::make()->model(Employee::class)->only(['code', 'account.email'])->relations(['account'])->updateBy('code')->authorizeRecords(false)->allowCreatingRelated()];

        $this->apply($this->upload($this->contacts(), Sheet::csv("code,account email\nE-1,new@example.com\n")));

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    // ---- 3. foreign keys of any type, JSON as text

    public function test_string_keys_and_json_text_are_not_raw_targets(): void
    {
        $targets = array_keys(ModelIntrospector::targets(RelatedContact::class));

        $this->assertNotContains('boss_ref', $targets, 'The belongsTo foreign key, whatever its name or type.');
        $this->assertNotContains('owner_uuid', $targets, '*_uuid / *_ulid / *_id');
        $this->assertNotContains('payload', $targets, 'JSON declared by its cast, stored as text.');
        $this->assertContains('name', $targets);

        $this->assertContains('boss_ref', array_keys(ModelIntrospector::targets(RelatedContact::class, ['only' => ['boss_ref']])), 'An explicit allowlist still can.');
    }

    public function test_json_like_casts_are_recognised(): void
    {
        foreach (['array', 'json', 'collection', 'object', 'Illuminate\\Database\\Eloquent\\Casts\\AsArrayObject', 'Illuminate\\Database\\Eloquent\\Casts\\AsCollection', 'encrypted:array'] as $cast) {
            $this->assertSame(['json'], array_column(CastPipeline::suggest($cast, null), 'type'), $cast);
        }
    }

    // ---- 5. enum values that differ by case only

    public function test_distinct_values_are_collected_case_insensitively(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xi-').'.xlsx';
        file_put_contents($path, Sheet::typed([['Статус'], ['Активний'], ['активний'], ['АКТИВНИЙ'], ['Чернетка']])->getContent());

        $scan = (new SourceFile($path, 'xlsx'))->scan();

        $this->assertSame(['Активний', 'Чернетка'], $scan['distinct']['Статус']);
    }

    public function test_an_empty_mapping_does_not_cancel_a_filled_one_that_differs_only_in_case(): void
    {
        $step = [['type' => 'enum', 'arg' => ProductStatus::class, 'map' => ['Активний' => '', 'активний' => 'active']]];

        $this->assertSame('active', CastPipeline::apply('Активний', $step));
        $this->assertSame('active', CastPipeline::apply('АКТИВНИЙ', $step));

        $reverse = [['type' => 'enum', 'arg' => ProductStatus::class, 'map' => ['активний' => 'draft', 'Активний' => '']]];
        $this->assertSame('draft', CastPipeline::apply('Активний', $reverse));
    }

    public function test_a_file_with_values_differing_by_case_imports_every_row(): void
    {
        $file = Sheet::csv("Артикул,Назва,Статус\nA,a,Активний\nB,b,активний\nC,c,АКТИВНИЙ\n");
        $page = $this->upload($this->products(), $file);
        $map = $this->mapping($page)[2]['casts'][0]['map'];

        $this->assertCount(1, $map, 'One row in the value table, not three.');

        $page->set('mountedActions.0.data.mapping.2.casts.0.map.0.to', 'active')->goToWizardStep(3);
        $this->assertSame(3, $this->preview($page)['created']);
    }

    // ---- 6. the enum class survives a preset

    public function test_a_preset_without_the_enum_class_gets_it_back_from_the_target(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);
        $preset = [['header' => 'Status', 'target' => 'status', 'casts' => [['type' => 'enum', 'arg' => '', 'map' => ['Активний' => 'active']]], 'mode' => 'lookup', 'many' => 'add']];

        $entry = Mapping::initial($definition, ['Status'], ['Status' => ['Активний']], ['Status' => ['Активний']], preset: $preset)[0];

        $this->assertSame(ProductStatus::class, $entry['casts'][0]['arg']);
        $this->assertSame(['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'], $entry['casts'][0]['map'][0]['choices'], 'The value table is a select again, not free text.');
    }

    public function test_the_cast_resolves_enum_cases_even_when_the_browser_sent_no_class(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);
        $steps = Mapping::withEnumClass(CastPipeline::steps([['type' => 'enum', 'arg' => '']]), $definition->target('status'));

        $this->assertSame('archived', CastPipeline::apply('Archived', $steps));
    }

    // ---- 7. sticky summary

    public function test_a_key_that_is_also_required_is_listed_once(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);
        $missing = new \ReflectionMethod(XlsxImportAction::class, 'unmappedRequired');

        $this->assertSame(['Sku'], $missing->invoke(XlsxImportAction::make()->model(Product::class), $definition, [['target' => 'name']], 'sku'));
    }

    // ---- 8. the message names the declared separator

    public function test_a_lone_dot_under_a_comma_rule_says_which_separator_was_declared(): void
    {
        try {
            CastPipeline::apply('12.5', [['type' => 'decimal', 'arg' => ',']]);
            $this->fail('Expected a CastException.');
        } catch (\Asignua\FilamentXlsxImport\Support\CastException $e) {
            $this->assertSame(__('filament-xlsx-import::filament-xlsx-import.cast.wrong_decimal_separator', ['value' => '12.5', 'separator' => ',']), $e->getMessage());
            $this->assertStringContainsString('","', str_replace('"', '"', $e->getMessage()));
        }
    }

    // ---- 9. nothing to import into

    public function test_the_button_is_hidden_when_the_model_offers_no_targets(): void
    {
        config(['app.debug' => false]);
        $action = XlsxImportAction::make()->model(GuardedThing::class);

        $this->assertFalse($action->isVisible());
        $this->assertTrue(XlsxImportAction::make()->model(Contact::class)->isVisible());
    }

    public function test_with_debug_on_a_model_with_no_targets_is_a_loud_developer_error(): void
    {
        config(['app.debug' => true]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/fully guarded/');

        XlsxImportAction::make()->model(GuardedThing::class)->isVisible();
    }

    public function test_the_no_targets_warning_is_logged_once_per_class(): void
    {
        $property = new \ReflectionProperty(DefinitionResolver::class, 'warned');
        $property->setValue(null, []);
        Log::spy();

        foreach (range(1, 5) as $ignored) {
            DefinitionResolver::resolve(GuardedThing::class, null);
        }

        Log::shouldHaveReceived('warning')->once();
    }

    // ---- 12. fixed attributes

    public function test_fixed_attributes_are_merged_into_every_row_and_beat_the_file(): void
    {
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->allowCreatingRelated()->fixedAttributes(fn (ImportContext $ctx): array => ['notes' => 'imported by '.$ctx->user?->name, 'status' => 'archived'])];

        // the file even maps a column to notes and status: the fixed values still win
        $this->apply($this->upload($this->products(), Sheet::csv("Артикул,Назва,Примітка,Статус\nA,Alpha,from file,Active\nB,Beta,,\n")));

        $this->assertSame(['imported by '.auth()->user()->name, 'imported by '.auth()->user()->name], Product::orderBy('sku')->pluck('notes')->all());
        $this->assertSame(['archived', 'archived'], Product::orderBy('sku')->get()->map(fn (Product $p): string => $p->status->value)->all());
    }

    public function test_fixed_attributes_can_set_a_related_field_the_definition_offers(): void
    {
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->allowCreatingRelated()->fixedAttributes(fn (): array => ['category.name' => 'Imported'])];

        $this->apply($this->upload($this->products(), Sheet::csv("Артикул,Назва\nA,Alpha\n")));

        $this->assertSame('Imported', Product::query()->firstOrFail()->category?->name);
    }

    public function test_fixed_attributes_show_up_in_the_dry_run_and_an_unknown_relation_key_fails_the_row(): void
    {
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->fixedAttributes(fn (): array => ['ghost.name' => 'x'])];

        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва\nA,Alpha\n"))->goToWizardStep(3);

        $this->assertSame(1, $this->preview($page)['failed']);
    }

    public function test_a_fixed_attributes_closure_cannot_be_queued_but_a_class_can(): void
    {
        Bus::fake([ImportXlsxJob::class]);
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->queue()->fixedAttributes(fn (): array => ['notes' => 'x'])];
        $this->apply($this->upload($this->products(), Sheet::csv("Артикул,Назва\nA,Alpha\n")));
        Bus::assertNothingDispatched();

        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->queue()->fixedAttributes(FixedNotes::class)];
        $this->apply($this->upload($this->products(), Sheet::csv("Артикул,Назва\nA,Alpha\n")));
        Bus::assertDispatched(ImportXlsxJob::class, fn (ImportXlsxJob $job): bool => $job->spec['fixed'] === FixedNotes::class);

        $job = Bus::dispatched(ImportXlsxJob::class)->first();
        $job->handle();
        $this->assertSame('from the class', Product::query()->where('sku', 'A')->value('notes'));
    }

    // ---- 11. relation managers

    private function categoryManager(Category $category): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $category, 'pageClass' => EditCategory::class]);
    }

    private function newCategory(string $name = 'Fruit'): Category
    {
        $category = new Category;
        $category->forceFill(['name' => $name])->save();

        return $category;
    }

    public function test_a_relation_manager_import_creates_records_that_belong_to_the_owner(): void
    {
        $category = $this->newCategory();
        $other = $this->newCategory('Other');

        $page = $this->upload($this->categoryManager($category), Sheet::csv("sku,name,price\nR-1,Apple,\"1,5\"\nR-2,Pear,2\n"), action: TestAction::make('xlsxImport')->table());
        $page->goToWizardStep(3)->callMountedAction();

        $this->assertSame([$category->id, $category->id], Product::orderBy('sku')->pluck('category_id')->all());
        $this->assertSame(0, $other->products()->count());
        $this->assertSame(2, $category->products()->count());
    }

    public function test_the_owners_foreign_key_is_not_a_target_in_a_relation_manager(): void
    {
        $category = $this->newCategory();

        $page = $this->upload($this->categoryManager($category), Sheet::csv("sku,name,category_id\nR-1,Apple,999\n"), action: TestAction::make('xlsxImport')->table());

        $this->assertNull(collect($this->mapping($page))->firstWhere('header', 'category_id')['target']);
        $page->goToWizardStep(3)->callMountedAction();

        $this->assertSame($category->id, Product::query()->where('sku', 'R-1')->value('category_id'), 'Whatever the file says, the record belongs to the owner.');
    }

    public function test_a_relation_manager_update_only_finds_the_owners_own_records(): void
    {
        $category = $this->newCategory();
        $other = $this->newCategory('Other');
        $mine = new Product;
        $mine->forceFill(['sku' => 'R-1', 'name' => 'Old', 'category_id' => $category->id])->save();
        $theirs = new Product;
        $theirs->forceFill(['sku' => 'R-2', 'name' => 'Theirs', 'category_id' => $other->id])->save();

        $page = $this->upload($this->categoryManager($category), Sheet::csv("sku,name\nR-1,New\nR-2,Hijack\n"), action: TestAction::make('xlsxImport')->table());
        $preview = $this->preview($page->goToWizardStep(3));
        $page->callMountedAction();

        $this->assertSame(1, $preview['updated']);
        $this->assertSame(1, $preview['failed'], 'R-2 belongs to another category: it is not the owner\'s to update (and the sku is unique).');
        $this->assertSame('New', $mine->fresh()->name);
        $this->assertSame('Theirs', $theirs->fresh()->name);
        $this->assertSame($other->id, $theirs->fresh()->category_id);
    }

    public function test_a_many_to_many_relation_manager_attaches_new_and_existing_records(): void
    {
        $product = new Product;
        $product->forceFill(['sku' => 'P-1', 'name' => 'Apple'])->save();
        $existing = new Tag;
        $existing->forceFill(['name' => 'organic'])->save();
        $attached = new Tag;
        $attached->forceFill(['name' => 'sale'])->save();
        $product->tags()->attach($attached->id);

        $manager = fn () => Livewire::test(TagsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);
        $page = $this->upload($manager(), Sheet::csv("name\norganic\nsale\nfresh\n"), action: TestAction::make('xlsxImport')->table());
        $preview = $this->preview($page->goToWizardStep(3));
        $page->callMountedAction();

        $this->assertSame(2, $preview['created'] + $preview['updated'], 'organic is attached, fresh is created; sale was attached already.');
        $this->assertEqualsCanonicalizing(['organic', 'sale', 'fresh'], $product->fresh()->tags->pluck('name')->all());
        $this->assertSame(3, Tag::count(), 'organic was found, not duplicated.');
    }

    public function test_a_relation_manager_import_is_authorized_and_queued_with_its_owner(): void
    {
        $category = $this->newCategory();
        Bus::fake([ImportXlsxJob::class]);
        ProductsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('sku')->only(['sku', 'name'])->queue()];

        $page = $this->upload($this->categoryManager($category), Sheet::csv("sku,name\nR-1,Apple\n"), action: TestAction::make('xlsxImport')->table());
        $page->goToWizardStep(3)->callMountedAction();

        $job = Bus::dispatched(ImportXlsxJob::class)->first();
        $this->assertSame(['class' => Category::class, 'key' => $category->id, 'relation' => 'products'], $job->spec['owner']);

        $job->handle();
        $this->assertSame($category->id, Product::query()->where('sku', 'R-1')->value('category_id'));
    }
}

final class FixedNotes
{
    /**
     * @return array<string, string>
     */
    public function __invoke(): array
    {
        return ['notes' => 'from the class'];
    }
}

final class AllowAllUserPolicy
{
    public function update(object $user, object $model): bool
    {
        return true;
    }

    public function create(object $user): bool
    {
        return true;
    }
}
