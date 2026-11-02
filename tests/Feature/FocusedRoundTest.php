<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Asignua\FilamentXlsxImport\Support\ImportRunner;
use Asignua\FilamentXlsxImport\Support\Mapping;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\GuardedThing;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Categories\Pages\EditCategory;
use Workbench\App\Filament\Resources\Categories\RelationManagers\ProductsRelationManager;
use Workbench\App\Filament\Resources\Contacts\ContactResource;
use Workbench\App\Filament\Resources\Products\Pages\EditProduct;
use Workbench\App\Filament\Resources\Products\ProductResource;
use Workbench\App\Filament\Resources\Products\RelationManagers\TagsRelationManager;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;
use Workbench\App\Models\Tag;

/**
 * The focused security round on the relation-manager import.
 */
class FocusedRoundTest extends TestCase
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

    private function category(string $name): Category
    {
        $category = new Category;
        $category->forceFill(['name' => $name])->save();

        return $category;
    }

    private function product(string $sku, Category $category): Product
    {
        $product = new Product;
        $product->forceFill(['sku' => $sku, 'name' => 'Old '.$sku, 'category_id' => $category->id])->save();

        return $product;
    }

    private function categoryManager(Category $owner): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditCategory::class]);
    }

    private function table(): TestAction
    {
        return TestAction::make('xlsxImport')->table();
    }

    // ---- 1. the inverse belongsTo of the owner relation

    public function test_a_relation_manager_import_cannot_move_a_record_to_another_category(): void
    {
        $mine = $this->category('Mine');
        $other = $this->category('Other');
        $product = $this->product('R-1', $mine);
        // the default Product definition offers category.name and its header alias «Категорія» matches it
        ProductsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('sku')->allowCreatingRelated()];

        $page = $this->upload($this->categoryManager($mine), Sheet::csv("Артикул,Назва,Категорія\nR-1,Renamed,Other\n"), action: $this->table());

        $this->assertNull(collect($this->mapping($page))->firstWhere('header', 'Категорія')['target'], 'The inverse belongsTo is not a target inside the owner\'s relation manager.');

        $page->goToWizardStep(3)->callMountedAction();

        $this->assertSame($mine->id, $product->fresh()->category_id);
        $this->assertSame('Renamed', $product->fresh()->name);
        $this->assertSame(2, Category::count(), 'No category was created from the file either.');
        $this->assertSame($other->id, Category::query()->where('name', 'Other')->value('id'));
    }

    public function test_the_owners_key_is_applied_to_existing_records_even_if_a_relation_target_slipped_through(): void
    {
        $mine = $this->category('Mine');
        $other = $this->category('Other');
        $product = $this->product('R-1', $mine);
        ProductsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('sku')->allowCreatingRelated()];
        $page = $this->upload($this->categoryManager($mine), Sheet::csv("Артикул,Назва,Категорія\nR-1,Renamed,Other\n"), action: $this->table());

        // A spec whose definition still has the inverse relation (no `except`) and a mapping that uses it: the key still wins.
        $mapping = Mapping::fromState($this->mapping($page));
        $mapping[2]['target'] = 'category.name';
        $mapping[2]['mode'] = 'lookup';
        $result = ImportRunner::run([
            'run_id' => $this->runId($page), 'model' => Product::class, 'importer' => null, 'definition' => null,
            'overrides' => ['update_by' => null, 'fields' => []], 'mapping' => $mapping, 'update_by' => 'sku', 'existing' => 'update',
            'authorize' => false, 'allow_create_related' => true, 'options' => [], 'sheet' => 0,
            'owner' => ['class' => Category::class, 'key' => $mine->id, 'relation' => 'products'],
        ], false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame($mine->id, $product->fresh()->category_id, 'Re-pointed by the associate, put back by the owner\'s key.');
        $this->assertNotSame($other->id, $product->fresh()->category_id);
    }

    // ---- 2. hidden means hidden

    public function test_a_model_with_no_attribute_targets_has_no_import_button(): void
    {
        config(['app.debug' => false]);
        ContactResource::$header = fn (): array => [XlsxImportAction::make()->model(GuardedThing::class)];

        Livewire::test(\Workbench\App\Filament\Resources\Contacts\Pages\ListContacts::class)->assertActionHidden('xlsxImport');
    }

    public function test_relation_only_targets_are_not_an_import(): void
    {
        config(['app.debug' => false]);
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->only(['category.name'])];

        Livewire::test(\Workbench\App\Filament\Resources\Products\Pages\ListProducts::class)->assertActionHidden('xlsxImport');

        $action = XlsxImportAction::make()->model(Product::class)->only(['category.name']);
        $has = new \ReflectionMethod($action, 'hasAttributeTargets');
        $this->assertFalse($has->invoke($action, $action->definitionFor(null)));
    }

    public function test_the_table_header_action_of_a_relation_manager_is_hidden_too(): void
    {
        config(['app.debug' => false]);
        ProductsRelationManager::$header = fn (): array => [XlsxImportAction::make()->only(['category.name'])];

        $this->categoryManager($this->category('Mine'))->assertActionHidden($this->table());
    }

    public function test_the_visible_import_button_is_still_mountable(): void
    {
        ContactResource::$header = fn (): array => [XlsxImportAction::make()->model(\Workbench\App\Models\Contact::class)];

        Livewire::test(\Workbench\App\Filament\Resources\Contacts\Pages\ListContacts::class)->assertActionVisible('xlsxImport');
    }

    // ---- 3. many-to-many relation manager: attach, do not rewrite shared records

    private function tagManager(Product $owner): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(TagsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditProduct::class]);
    }

    private function newProduct(): Product
    {
        $product = new Product;
        $product->forceFill(['sku' => 'P-1', 'name' => 'Apple'])->save();

        return $product;
    }

    private function tag(string $name, ?string $color = null): Tag
    {
        $tag = new Tag;
        $tag->forceFill(['name' => $name, 'color' => $color])->save();

        return $tag;
    }

    public function test_an_existing_shared_record_is_attached_but_its_attributes_are_not_rewritten(): void
    {
        $product = $this->newProduct();
        $shared = $this->tag('organic', 'green');
        TagsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('name')->only(['name', 'color'])];

        $page = $this->upload($this->tagManager($product), Sheet::csv("name,color\norganic,red\nfresh,blue\n"), action: $this->table());
        $preview = $this->preview($page->goToWizardStep(3));
        $page->callMountedAction();

        $this->assertSame('green', $shared->fresh()->color, 'The tag is shared with other products: a file for this product does not repaint it.');
        $this->assertEqualsCanonicalizing(['organic', 'fresh'], $product->fresh()->tags->pluck('name')->all());
        $this->assertSame('blue', Tag::query()->where('name', 'fresh')->value('color'), 'A new tag is created with its values.');
        $this->assertSame(1, $preview['created']);
        $this->assertSame(1, $preview['updated'], 'The attach of the existing one.');
    }

    public function test_updating_attached_records_is_an_explicit_opt_in(): void
    {
        $product = $this->newProduct();
        $shared = $this->tag('organic', 'green');
        TagsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('name')->only(['name', 'color'])->updateAttachedRecords()];

        $page = $this->upload($this->tagManager($product), Sheet::csv("name,color\norganic,red\n"), action: $this->table());
        $page->goToWizardStep(3)->callMountedAction();

        $this->assertSame('red', $shared->fresh()->color);
        $this->assertSame(['organic'], $product->fresh()->tags->pluck('name')->all());
    }

    public function test_attaching_needs_the_owners_update_policy_when_it_has_none_for_attach(): void
    {
        $product = $this->newProduct();
        $this->tag('organic');
        Gate::policy(Product::class, OwnerPolicyDenyingUpdate::class);
        TagsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('name')->only(['name'])];

        $page = $this->upload($this->tagManager($product), Sheet::csv("name\norganic\nfresh\n"), action: $this->table());
        $preview = $this->preview($page->goToWizardStep(3));
        $page->callMountedAction();

        $this->assertSame(2, $preview['failed']);
        $this->assertCount(0, $product->fresh()->tags, 'Neither the existing nor the new tag was attached.');
        $this->assertSame(1, Tag::count(), 'The new tag created for the failed row was rolled back.');
    }

    public function test_the_owners_attach_policy_wins_over_update(): void
    {
        $product = $this->newProduct();
        Gate::policy(Product::class, OwnerPolicyAllowingAttach::class);
        TagsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('name')->only(['name'])];

        $this->apply($this->upload($this->tagManager($product), Sheet::csv("name\nfresh\n"), action: $this->table()));

        $this->assertSame(['fresh'], $product->fresh()->tags->pluck('name')->all());
    }

    public function test_the_owners_attach_policy_can_refuse_even_when_update_is_allowed(): void
    {
        $product = $this->newProduct();
        Gate::policy(Product::class, OwnerPolicyRefusingAttach::class);
        TagsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('name')->only(['name'])];

        $page = $this->upload($this->tagManager($product), Sheet::csv("name\nfresh\n"), action: $this->table());
        $page->goToWizardStep(3)->callMountedAction();

        $this->assertCount(0, $product->fresh()->tags);
    }

    public function test_an_owner_without_a_policy_is_not_restricted(): void
    {
        $product = $this->newProduct();
        TagsRelationManager::$header = fn (): array => [XlsxImportAction::make()->updateBy('name')->only(['name'])];

        $this->apply($this->upload($this->tagManager($product), Sheet::csv("name\nfresh\n"), action: $this->table()));

        $this->assertSame(['fresh'], $product->fresh()->tags->pluck('name')->all());
    }
}

final class OwnerPolicyDenyingUpdate
{
    public function update(object $user, Product $product): bool
    {
        return false;
    }
}

final class OwnerPolicyAllowingAttach
{
    public function update(object $user, Product $product): bool
    {
        return false;
    }

    public function attach(object $user, Product $product, object $related): bool
    {
        return true;
    }
}

final class OwnerPolicyRefusingAttach
{
    public function update(object $user, Product $product): bool
    {
        return true;
    }

    public function attach(object $user, Product $product, object $related): bool
    {
        return false;
    }
}
