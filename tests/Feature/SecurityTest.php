<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Asignua\FilamentXlsxImport\Jobs\ImportXlsxJob;
use Asignua\FilamentXlsxImport\Models\ImportPreset;
use Asignua\FilamentXlsxImport\Support\Connections;
use Asignua\FilamentXlsxImport\Support\DefinitionResolver;
use Asignua\FilamentXlsxImport\Support\ModelIntrospector;
use Asignua\FilamentXlsxImport\Support\Presets;
use Asignua\FilamentXlsxImport\Support\RunStore;
use Asignua\FilamentXlsxImport\Support\SourceFile;
use Asignua\FilamentXlsxImport\Tests\Support\ContactPolicy;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\FillableAccount;
use Asignua\FilamentXlsxImport\Tests\Support\FillableContact;
use Asignua\FilamentXlsxImport\Tests\Support\GuardedThing;
use Asignua\FilamentXlsxImport\Tests\Support\HiddenContact;
use Asignua\FilamentXlsxImport\Tests\Support\OtherWidget;
use Asignua\FilamentXlsxImport\Tests\Support\RecordingHandlers;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\Support\UnguardedProduct;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Workbench\App\Filament\Resources\Contacts\ContactResource;
use Workbench\App\Filament\Resources\Products\ProductResource;
use Workbench\App\Models\Category;
use Workbench\App\Models\Contact;
use Workbench\App\Models\Employee;
use Workbench\App\Models\Product;
use Workbench\App\Models\Role;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Regression tests for the findings of the security review: every one reproduces the exploit.
 */
class SecurityTest extends TestCase
{
    use DrivesImport;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        RecordingHandlers::reset();
    }

    protected function tearDown(): void
    {
        $this->tearDownDrivesImport();

        parent::tearDown();
    }

    private function product(string $sku, array $extra = []): Product
    {
        $product = new Product;
        $product->forceFill($extra + ['sku' => $sku, 'name' => 'Old '.$sku])->save();

        return $product;
    }

    private function contactsAction(XlsxImportAction $action): void
    {
        ContactResource::$header = fn (): array => [$action];
    }

    // ---------------------------------------------------------------------------------------------------- H1

    public function test_a_users_table_does_not_offer_its_password_key_or_secrets(): void
    {
        $targets = array_keys(DefinitionResolver::resolve(User::class, null)->targets);

        $this->assertNotContains('password', $targets);
        $this->assertNotContains('remember_token', $targets);
        $this->assertNotContains('id', $targets);
        $this->assertNotContains('created_at', $targets);
        $this->assertContains('email', $targets);
    }

    public function test_a_csv_cannot_change_the_admins_password_by_upserting_on_email(): void
    {
        $admin = User::factory()->create(['email' => 'boss@example.com']);
        $before = $admin->fresh()->password;
        $this->contactsAction(XlsxImportAction::make()->model(User::class)->updateBy('email'));

        $page = $this->upload($this->contacts(), Sheet::csv("email,password,name\nboss@example.com,hacked,Boss\n"));

        $this->assertSame(['email', null, 'name'], array_column($this->mapping($page), 'target'), 'The password column is not mapped.');

        $this->apply($page);

        $this->assertSame($before, $admin->fresh()->password);
        $this->assertSame('Boss', $admin->fresh()->name);
    }

    public function test_a_forged_mapping_to_the_password_is_refused_server_side(): void
    {
        $admin = User::factory()->create(['email' => 'boss@example.com']);
        $before = $admin->fresh()->password;
        $this->contactsAction(XlsxImportAction::make()->model(User::class)->updateBy('email'));

        $page = $this->upload($this->contacts(), Sheet::csv("email,password\nboss@example.com,hacked\n"));
        $page->set('mountedActions.0.data.mapping.1.target', 'password')->goToWizardStep(3);

        $this->assertSame([], $this->preview($page), 'The step did not advance: the field is not one the import offers.');
        $page->callMountedAction();
        $this->assertSame($before, $admin->fresh()->password);
    }

    public function test_an_id_column_does_not_rekey_a_record(): void
    {
        $product = $this->product('A-1');

        $page = $this->upload($this->products(), Sheet::csv("ID,Артикул,Назва\n999,A-1,Renamed\n"));

        $this->assertNull(collect($this->mapping($page))->firstWhere('header', 'ID')['target']);
        $this->apply($page);

        $this->assertSame($product->id, Product::query()->where('sku', 'A-1')->value('id'));
        $this->assertSame('Renamed', $product->fresh()->name);
    }

    public function test_a_forged_key_or_target_on_the_primary_key_is_refused(): void
    {
        $this->product('A-1');
        $page = $this->upload($this->products(), Sheet::csv("ID,Артикул,Назва\n999,A-1,Renamed\n"));

        $page->set('mountedActions.0.data.update_by', 'id')->goToWizardStep(3);
        $this->assertSame([], $this->preview($page));

        $page = $this->upload($this->products(), Sheet::csv("ID,Артикул,Назва\n999,A-1,Renamed\n"));
        $page->set('mountedActions.0.data.mapping.0.target', 'id')->goToWizardStep(3);
        $this->assertSame([], $this->preview($page));
    }

    public function test_the_update_key_is_always_one_of_the_offered_targets(): void
    {
        $this->assertNull(DefinitionResolver::resolve(Contact::class, null, null, ['update_by' => 'id'])->updateBy);
        $this->assertNull(DefinitionResolver::resolve(User::class, null, null, ['update_by' => 'password'])->updateBy);
        $this->assertSame('email', DefinitionResolver::resolve(Contact::class, null, null, ['update_by' => 'email'])->updateBy);
    }

    public function test_a_model_with_fillable_is_restricted_to_it(): void
    {
        $this->assertSame(['name', 'email'], array_keys(ModelIntrospector::targets(FillableContact::class)));
    }

    public function test_hidden_attributes_are_never_offered(): void
    {
        $this->assertNotContains('phone', array_keys(ModelIntrospector::targets(HiddenContact::class)));
    }

    public function test_only_and_except_shape_the_targets(): void
    {
        $this->assertSame(['name'], array_keys(ModelIntrospector::targets(Contact::class, ['only' => ['name']])));
        $this->assertNotContains('city', array_keys(ModelIntrospector::targets(Contact::class, ['except' => ['city']])));
        $this->assertContains('id', array_keys(ModelIntrospector::targets(Contact::class, ['only' => ['id', 'email']])), 'An explicit allowlist may name the key: it is the developer\'s decision.');

        $action = XlsxImportAction::make()->model(Contact::class)->only(['name', 'email'])->updateBy('email');
        $this->assertSame(['name', 'email'], array_keys($action->definitionFor(null)->targets));
    }

    public function test_the_definition_can_declare_the_allowed_targets(): void
    {
        $definition = DefinitionResolver::resolve(Contact::class, null, \Workbench\App\Import\ContactDefinition::class);
        $this->assertContains('city', array_keys($definition->targets));

        config(['filament-xlsx-import.definitions' => [Contact::class => AllowlistedDefinition::class]]);

        $this->assertSame(['email'], array_keys(DefinitionResolver::resolve(Contact::class, null)->targets));
    }

    public function test_the_tenant_column_is_never_offered_with_tenancy(): void
    {
        $this->assertSame([], ModelIntrospector::tenantColumns(new Product));
        // an explicit allowlist can name it (the developer's decision) — but the tenant column is what ties a record to its tenant:
        Filament::getPanel('admin')->tenant(Team::class, ownershipRelationship: 'team');

        $this->assertSame(['team_id'], ModelIntrospector::tenantColumns(new Product));
        $this->assertNotContains('team_id', array_keys(ModelIntrospector::targets(UnguardedProduct::class)));
    }

    public function test_update_is_authorized_after_the_new_values_are_in_the_model(): void
    {
        Gate::policy(Contact::class, ContactPolicy::class);
        $contact = new Contact;
        $contact->forceFill(['name' => 'Ada', 'email' => 'ada@example.com', 'city' => 'Lviv'])->save();
        $this->contactsAction(XlsxImportAction::make()->model(Contact::class)->updateBy('email')->authorizeRecords());

        // The stored record (Lviv) passes the policy; the record as it is about to be saved (Paris) does not.
        $page = $this->upload($this->contacts(), Sheet::csv("email,city\nada@example.com,Paris\n"));
        $this->assertSame(1, $this->preview($page->goToWizardStep(3))['failed']);
        $page->callMountedAction();

        $this->assertSame('Lviv', $contact->fresh()->city);
    }

    public function test_authorization_is_on_by_default_when_the_model_has_a_policy(): void
    {
        Gate::policy(Contact::class, ContactPolicy::class);
        $contact = new Contact;
        $contact->forceFill(['name' => 'Ada', 'email' => 'ada@example.com', 'city' => 'Lviv'])->save();
        $this->contactsAction(XlsxImportAction::make()->model(Contact::class)->updateBy('email'));

        $page = $this->upload($this->contacts(), Sheet::csv("email,city\nada@example.com,Paris\n"))->goToWizardStep(3);

        $this->assertSame(1, $this->preview($page)['failed']);
    }

    public function test_authorization_can_be_switched_off_explicitly(): void
    {
        Gate::policy(Contact::class, ContactPolicy::class);
        $contact = new Contact;
        $contact->forceFill(['name' => 'Ada', 'email' => 'ada@example.com', 'city' => 'Lviv'])->save();
        $this->contactsAction(XlsxImportAction::make()->model(Contact::class)->updateBy('email')->authorizeRecords(false));

        $page = $this->upload($this->contacts(), Sheet::csv("email,city\nada@example.com,Paris\n"))->goToWizardStep(3);

        $this->assertSame(1, $this->preview($page)['updated']);
    }

    // ---------------------------------------------------------------------------------------------------- H2

    public function test_role_relations_are_never_offered_automatically(): void
    {
        $this->assertSame([], array_filter(array_keys(ModelIntrospector::targets(User::class)), fn (string $k): bool => str_starts_with($k, 'roles.')));
        // not even when everything else is allowed
        $this->assertSame([], array_filter(array_keys(ModelIntrospector::targets(User::class, ['relations' => ['tags']])), fn (string $k): bool => str_starts_with($k, 'roles.')));
    }

    public function test_a_super_admin_role_cannot_be_attached_from_a_spreadsheet(): void
    {
        $role = new Role;
        $role->forceFill(['name' => 'Super Admin'])->save();
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $this->contactsAction(XlsxImportAction::make()->model(User::class)->updateBy('email'));

        $page = $this->upload($this->contacts(), Sheet::csv("email,roles\nvictim@example.com,Super Admin\n"));
        $this->assertNull(collect($this->mapping($page))->firstWhere('header', 'roles')['target']);

        $page->set('mountedActions.0.data.mapping.1.target', 'roles.name')->goToWizardStep(3);
        $page->callMountedAction();

        $this->assertSame([], $this->preview($page));
        $this->assertCount(0, $victim->fresh()->roles);
    }

    public function test_many_to_many_is_offered_only_when_allowlisted(): void
    {
        $this->assertNotContains('tags.name', array_keys(ModelIntrospector::targets(UnguardedProduct::class)), 'Never discovered automatically, even with an open related model.');
        $this->assertContains('tags.name', array_keys(ModelIntrospector::targets(UnguardedProduct::class, ['relations' => ['tags']])));
        $this->assertContains('tags.name', array_keys(DefinitionResolver::resolve(Product::class, null)->targets), 'Product::importRules() allowlists it.');
        $this->assertContains('roles.name', array_keys(ModelIntrospector::targets(User::class, ['only' => ['email', 'roles.name'], 'relations' => ['roles']])), 'An explicit allowlist is the developer\'s call.');
    }

    public function test_a_lookup_rule_is_a_lock_the_user_cannot_turn_into_create(): void
    {
        $file = Sheet::csv("Артикул,Назва,Категорія\nA-1,Alpha,Brand new\n");
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->allowCreatingRelated()->fieldRules(['category.name' => ['mode' => 'lookup']])];

        $page = $this->upload($this->products(), $file);

        // The screen does not offer «create» for a locked relation...
        $page->set('mountedActions.0.data.mapping.2.mode', 'create')->goToWizardStep(3);
        $this->assertSame([], $this->preview($page));

        // ...and the server does not trust the screen: a forged state that says «create» still only looks up.
        $mapping = $this->mapping($page);
        $mapping[2]['mode'] = 'create';
        $spec = [
            'run_id' => $this->runId($page), 'model' => Product::class, 'importer' => null, 'definition' => null,
            'overrides' => ['update_by' => null, 'fields' => ['category.name' => ['mode' => 'lookup']]],
            'mapping' => \Asignua\FilamentXlsxImport\Support\Mapping::fromState($mapping), 'update_by' => 'sku', 'existing' => 'update',
            'authorize' => false, 'allow_create_related' => true, 'options' => [], 'sheet' => 0,
        ];
        $result = \Asignua\FilamentXlsxImport\Support\ImportRunner::run($spec, false);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Product::count());
    }

    public function test_a_lock_can_be_lifted_by_the_definition(): void
    {
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->allowCreatingRelated()->fieldRules(['category.name' => ['mode' => 'lookup', 'allow_create' => true]])];

        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва,Категорія\nA-1,Alpha,Brand new\n"));
        $page->set('mountedActions.0.data.mapping.2.mode', 'create');
        $this->apply($page);

        $this->assertSame(1, Category::count());
    }

    public function test_creating_a_related_record_needs_the_related_models_create_policy_or_an_explicit_switch(): void
    {
        $file = fn () => Sheet::csv("Артикул,Назва,Категорія\nA-1,Alpha,Brand new\n");
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)];

        // no policy, no switch: the category is not created, the row fails
        $page = $this->upload($this->products(), $file());
        $this->assertSame(1, $this->preview($page->goToWizardStep(3))['failed']);
        $page->callMountedAction();
        $this->assertSame(0, Category::count());

        // a policy that allows creating
        Gate::policy(Category::class, AllowCreatePolicy::class);
        $this->apply($this->upload($this->products(), $file()));
        $this->assertSame(1, Category::count());
    }

    public function test_a_policy_that_denies_creating_blocks_it_even_with_a_policy_present(): void
    {
        Gate::policy(Category::class, \Asignua\FilamentXlsxImport\Tests\Support\DenyCreatePolicy::class);
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)];

        $this->apply($this->upload($this->products(), Sheet::csv("Артикул,Назва,Категорія\nA-1,Alpha,Brand new\n")));

        $this->assertSame(0, Category::count());
    }

    public function test_tenancy_scopes_lookups_and_stamps_what_is_created(): void
    {
        Filament::getPanel('admin')->tenant(Team::class, ownershipRelationship: 'team');
        $mine = new Team;
        $mine->forceFill(['name' => 'Mine'])->save();
        $other = new Team;
        $other->forceFill(['name' => 'Other'])->save();
        $theirCategory = new Category;
        $theirCategory->forceFill(['name' => 'Secret category', 'team_id' => $other->id])->save();
        $theirProduct = $this->product('SHARED', ['team_id' => $other->id, 'name' => 'Theirs']);
        Filament::setTenant($mine, true);
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->allowCreatingRelated()];

        // 1. lookup-only cannot see the other tenant's category: «not found», and nothing of theirs is revealed
        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва,Категорія\nA-1,Alpha,Secret category\n"));
        $this->remap($page, ['Категорія' => ['mode' => 'lookup']]);
        $preview = $this->preview($page->goToWizardStep(3));
        $this->assertSame(1, $preview['failed']);
        $this->assertSame(0, Category::query()->where('team_id', $mine->id)->count(), 'Looking up must not create anything.');

        // 2. create: a new category for MY team; the product is stamped with my team
        $this->apply($this->upload($this->products(), Sheet::csv("Артикул,Назва,Категорія\nA-1,Alpha,Secret category\n")));
        $product = Product::query()->where('sku', 'A-1')->firstOrFail();
        $this->assertSame($mine->id, $product->team_id);
        $this->assertSame($mine->id, $product->category->team_id);
        $this->assertNotSame($theirCategory->id, $product->category_id);

        // 3. the upsert does not reach the other tenant's record (and the clash is reported without SQL)
        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва\nSHARED,Hijacked\n"))->goToWizardStep(3);
        $preview = $this->preview($page);
        $this->assertSame('Theirs', $theirProduct->fresh()->name);
        $this->assertSame(1, $preview['failed']);
        $this->assertStringNotContainsString('SQL', $preview['samples']['errors'][0]['message']);
    }

    public function test_related_query_using_narrows_lookups(): void
    {
        $hidden = new Category;
        $hidden->forceFill(['name' => 'Restricted', 'slug' => 'private'])->save();
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->relatedQueryUsing(fn (Builder $query, string $class) => $query->where('slug', '!=', 'private'))];

        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва,Категорія\nA-1,Alpha,Restricted\n"));
        $this->remap($page, ['Категорія' => ['mode' => 'lookup']]);

        $this->assertSame(1, $this->preview($page->goToWizardStep(3))['failed']);
    }

    // ---------------------------------------------------------------------------------------------------- re-review: S1 / S2 / S3

    public function test_a_fully_guarded_model_offers_nothing_until_the_developer_says_what(): void
    {
        $definition = DefinitionResolver::resolve(GuardedThing::class, null);

        $this->assertSame([], $definition->targets);
        $this->assertNotNull($definition->notice);
        $this->assertStringContainsString('GuardedThing', (string) $definition->notice);

        // ...and the developer's declaration opens exactly that
        $this->assertSame(['name', 'email'], array_keys(DefinitionResolver::resolve(GuardedThing::class, null, null, ['only' => ['name', 'email']])->targets));
    }

    public function test_fillable_is_the_whole_list_and_a_fillable_password_is_still_not_offered(): void
    {
        $targets = array_keys(ModelIntrospector::targets(FillableAccount::class));

        $this->assertSame(['name', 'email', 'is_active'], $targets);
    }

    public function test_camel_case_secrets_are_recognised(): void
    {
        foreach (['apiToken', 'isAdmin', 'twoFactorSecret', 'rememberToken', 'passwordHash', 'stripe_secret', 'role_id'] as $name) {
            $this->assertTrue(ModelIntrospector::sensitiveName($name), $name);
        }

        $this->assertFalse(ModelIntrospector::sensitiveName('email'));
    }

    public function test_json_columns_and_foreign_keys_are_not_raw_attribute_targets(): void
    {
        $targets = array_keys(ModelIntrospector::targets(UnguardedProduct::class));

        $this->assertNotContains('meta', $targets);
        $this->assertNotContains('category_id', $targets);
        $this->assertNotContains('team_id', $targets);
        $this->assertContains('sku', $targets);
        $this->assertContains('meta', array_keys(ModelIntrospector::targets(UnguardedProduct::class, ['only' => ['meta']])), 'An explicit allowlist can still name them.');
    }

    public function test_the_related_model_follows_the_same_declaration_rules(): void
    {
        // Category is fully guarded: «category.name» is not offered by itself, only when the developer lists it.
        $this->assertNotContains('category.name', array_keys(ModelIntrospector::targets(UnguardedProduct::class)));
        $this->assertContains('category.name', array_keys(ModelIntrospector::targets(UnguardedProduct::class, ['only' => ['sku', 'category.name']])));
    }

    public function test_the_login_model_is_never_offered_as_a_relation_target(): void
    {
        $automatic = array_keys(ModelIntrospector::targets(Employee::class));

        $this->assertSame([], array_filter($automatic, fn (string $k): bool => str_starts_with($k, 'account.') || str_starts_with($k, 'owner.')), 'hasOne and belongsTo into the auth user model need an explicit relations() entry.');

        // not even an `only` entry opens it by itself
        $this->assertSame([], array_filter(array_keys(ModelIntrospector::targets(Employee::class, ['only' => ['code', 'account.email', 'owner.email']])), fn (string $k): bool => str_contains($k, '.')));

        $named = array_keys(ModelIntrospector::targets(Employee::class, ['only' => ['code', 'account.email'], 'relations' => ['account']]));
        $this->assertContains('account.email', $named);
    }

    public function test_a_has_one_cannot_rewrite_the_admins_login_when_the_user_policy_denies_everything(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $employee = new Employee;
        $employee->forceFill(['name' => 'Eve', 'code' => 'E-1'])->save();
        $admin->forceFill(['employee_id' => $employee->id])->save();
        Gate::policy(User::class, DenyEverythingPolicy::class);
        $this->contactsAction(XlsxImportAction::make()->model(Employee::class)->only(['code', 'account.email'])->relations(['account'])->updateBy('code')->authorizeRecords(false));

        $page = $this->upload($this->contacts(), Sheet::csv('code,account email
E-1,attacker@example.com
'));
        $this->assertSame(['code', 'account.email'], array_column($this->mapping($page), 'target'));

        $page->goToWizardStep(3);
        $this->assertSame(1, $this->preview($page)['failed'], 'The related model\'s update policy said no.');
        $page->callMountedAction();

        $this->assertSame('admin@example.com', $admin->fresh()->email);
    }

    public function test_a_has_one_into_a_model_without_a_policy_is_refused_unless_the_dangerous_switch_is_on(): void
    {
        $user = User::factory()->create(['email' => 'u@example.com']);
        $employee = new Employee;
        $employee->forceFill(['name' => 'Eve', 'code' => 'E-1'])->save();
        $user->forceFill(['employee_id' => $employee->id])->save();
        $make = fn (bool $allow) => XlsxImportAction::make()->model(Employee::class)->only(['code', 'account.email'])->relations(['account'])->updateBy('code')->authorizeRecords(false)->allowUpdatingRelatedWithoutPolicy($allow);

        $this->contactsAction($make(false));
        $page = $this->upload($this->contacts(), Sheet::csv('code,account email
E-1,new@example.com
'));
        $this->apply($page);
        $this->assertSame('u@example.com', $user->fresh()->email);

        $this->contactsAction($make(true));
        $this->apply($this->upload($this->contacts(), Sheet::csv('code,account email
E-1,new@example.com
')));
        $this->assertSame('new@example.com', $user->fresh()->email, 'With the explicit switch the write goes through.');
    }

    public function test_a_has_one_with_an_allowing_policy_writes(): void
    {
        $user = User::factory()->create(['email' => 'u@example.com']);
        $employee = new Employee;
        $employee->forceFill(['name' => 'Eve', 'code' => 'E-1'])->save();
        $user->forceFill(['employee_id' => $employee->id])->save();
        Gate::policy(User::class, AllowEverythingPolicy::class);
        $this->contactsAction(XlsxImportAction::make()->model(Employee::class)->only(['code', 'account.email'])->relations(['account'])->updateBy('code')->authorizeRecords(false));

        $this->apply($this->upload($this->contacts(), Sheet::csv('code,account email
E-1,new@example.com
')));

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    // ---------------------------------------------------------------------------------------------------- H3

    private function otherConnection(): void
    {
        config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        // A relation takes its parent's connection when the related model names none: the table must exist there too.
        Schema::connection('other')->create('categories', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });
        Schema::connection('other')->create('widgets', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_a_dry_run_on_a_model_with_its_own_connection_writes_nothing(): void
    {
        $this->otherConnection();
        $this->contactsAction(XlsxImportAction::make()->model(OtherWidget::class)->updateBy('code'));

        $page = $this->upload($this->contacts(), Sheet::csv("code,name\nW-1,One\nW-2,Two\n"));
        $page->goToWizardStep(3);

        $this->assertSame(2, $this->preview($page)['created']);
        $this->assertSame(0, DB::connection('other')->table('widgets')->count(), 'The rehearsal was rolled back on the model\'s own connection.');

        $page->callMountedAction();
        $this->assertSame(2, DB::connection('other')->table('widgets')->count());
    }

    public function test_a_failing_row_on_another_connection_rolls_back_on_that_connection(): void
    {
        $this->otherConnection();
        DB::connection('other')->table('widgets')->insert(['name' => 'Existing', 'code' => 'W-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->contactsAction(XlsxImportAction::make()->model(OtherWidget::class));

        // no key: the second row repeats an existing code → the unique index refuses it; the first one stays
        $page = $this->upload($this->contacts(), Sheet::csv("code,name\nW-2,Two\nW-1,Dup\n"));
        $this->apply($page);

        $this->assertSame(['W-1', 'W-2'], DB::connection('other')->table('widgets')->orderBy('code')->pluck('code')->all());
    }

    public function test_the_connections_of_a_model_and_its_related_models_are_all_involved(): void
    {
        $this->otherConnection();
        $definition = DefinitionResolver::resolve(OtherWidget::class, null, null, ['only' => ['name', 'category.name']]);

        $this->assertEqualsCanonicalizing(['other', (string) config('database.default')], Connections::names($definition));
    }

    public function test_a_dry_run_that_loses_its_transaction_is_reported_as_aborted(): void
    {
        $this->contactsAction(XlsxImportAction::make()->model(Contact::class)->saveUsing(function (array $data): void {
            DB::commit(); // what DDL / TRUNCATE do on MySQL, behind the transaction's back
        }));

        $page = $this->upload($this->contacts(), Sheet::csv("name,email\nAda,ada@example.com\n"))->goToWizardStep(3);

        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.dry_run_aborted'));
        $this->assertSame([], $this->preview($page));
    }

    // ---------------------------------------------------------------------------------------------------- M2 / M3

    public function test_a_crashed_job_says_how_many_rows_are_already_in(): void
    {
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->queue()];
        Bus::fake([ImportXlsxJob::class]);
        $this->apply($this->upload($this->products(), Sheet::csv("Артикул,Назва\nA,a\nB,b\n")));
        $job = Bus::dispatched(ImportXlsxJob::class)->first();
        RunStore::put($job->spec['run_id'], ['progress' => ['processed' => 400, 'total' => 900]]);

        $job->failed(new \RuntimeException('boom'));

        $body = auth()->user()->notifications()->first()->data['body'];
        $this->assertStringContainsString('400', $body);
        $this->assertStringContainsString(__('filament-xlsx-import::filament-xlsx-import.failed_body_partial', ['count' => 400]), $body);
    }

    public function test_the_after_hook_undoes_the_before_hook_even_when_the_job_crashes(): void
    {
        config([
            'filament-xlsx-import.queue.before' => [RecordingHandlers::class, 'before'],
            'filament-xlsx-import.queue.after' => [RecordingHandlers::class, 'after'],
        ]);
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->queue()];
        Bus::fake([ImportXlsxJob::class]);
        $this->apply($this->upload($this->products(), Sheet::csv("Артикул,Назва\nA,a\n")));
        $job = Bus::dispatched(ImportXlsxJob::class)->first();
        RecordingHandlers::reset();
        RunStore::dropSource($job->spec['run_id']); // the worker will crash reading it

        try {
            $job->handle();
            $this->fail('The job should have crashed.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(1, RecordingHandlers::$before);
        $this->assertSame(1, RecordingHandlers::$after);
    }

    public function test_signing_a_link_puts_back_the_workers_own_url_root(): void
    {
        $id = RunStore::create(['user' => 1]);
        URL::forceRootUrl('https://worker.example');
        URL::forceScheme('https');

        $link = RunStore::url($id, 'http://panel.test');

        $this->assertStringStartsWith('http://panel.test/', $link);
        $this->assertSame('https://worker.example/x', url('/x'), 'Not nulled out: the worker keeps what it had.');
    }

    // ---------------------------------------------------------------------------------------------------- L1 / L2 / L3 / L4

    public function test_somebody_elses_run_id_is_not_accepted(): void
    {
        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва\nA,a\n"))->goToWizardStep(3);
        $victimRun = $this->runId($page);
        $preview = $this->preview($page);
        $this->assertNotSame([], $preview);

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('admin');
        $attacker = $this->products()->mountAction('xlsxImport');
        $attacker->fillForm(['file' => Sheet::csv("Артикул,Назва\nB,b\n"), 'run_id' => $victimRun]);

        // the attacker re-stages: the victim's run must survive (it is not the attacker's to delete)
        $attacker->goToWizardStep(2);
        $this->assertTrue(RunStore::exists($victimRun));

        // ...and cannot run, preview or apply it
        $attacker->set('mountedActions.0.data.run_id', $victimRun)->goToWizardStep(3);
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.run_gone'));
        $this->assertSame($preview, RunStore::meta($victimRun)['preview'], 'The victim\'s preview was not touched.');

        $attacker->callMountedAction();
        $this->assertSame(0, Product::count());
    }

    public function test_a_run_of_another_import_target_is_not_accepted(): void
    {
        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва\nA,a\n"));
        $productRun = $this->runId($page);

        $this->contactsAction(XlsxImportAction::make()->model(Contact::class));
        $other = $this->upload($this->contacts(), Sheet::csv("name,email\nAda,ada@example.com\n"));
        $other->set('mountedActions.0.data.run_id', $productRun)->goToWizardStep(3);

        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.run_gone'));
        $this->assertSame(0, Contact::count());
    }

    public function test_presets_are_scoped_to_the_panel_and_the_tenant(): void
    {
        $context = Presets::context();
        $preset = new ImportPreset;
        $preset->forceFill($context + ['target' => Contact::class, 'name' => 'Shared here', 'user_id' => '999', 'shared' => true, 'mapping' => []])->save();

        $visible = fn (array $ctx): int => ImportPreset::query()->visibleTo(Contact::class, auth()->id(), $ctx)->count();

        $this->assertSame(1, $visible($context));
        $this->assertSame(0, $visible(['panel' => 'another'] + $context));
        $this->assertSame(0, $visible(['guard' => 'api'] + $context));
        $this->assertSame(0, $visible(['tenant' => 'App\\Team:2'] + $context), 'Shared within a tenant, not across tenants.');
    }

    public function test_a_loaded_preset_restores_its_key_and_existing_mode(): void
    {
        $this->contactsAction(XlsxImportAction::make()->model(Contact::class));
        $page = $this->upload($this->contacts(), Sheet::csv("name,email\nAda,ada@example.com\n"));
        $page->fillForm(['update_by' => 'email', 'existing' => 'skip', 'preset_name' => 'Keyed']);
        $this->apply($page);
        $preset = ImportPreset::query()->where('name', 'Keyed')->firstOrFail();

        $again = $this->upload($this->contacts(), Sheet::csv("name,email\nAda,ada@example.com\n"), ['preset_id' => $preset->id]);

        $this->assertSame('email', $again->get('mountedActions.0.data.update_by'));
        $this->assertSame('skip', $again->get('mountedActions.0.data.existing'));
    }

    public function test_a_row_error_never_shows_sql_or_bindings(): void
    {
        $contact = new Contact;
        $contact->forceFill(['name' => 'Ada', 'email' => 'ada@example.com'])->save();
        $this->contactsAction(XlsxImportAction::make()->model(Contact::class));

        $page = $this->upload($this->contacts(), Sheet::csv("name,email\nAda again,ada@example.com\n"))->goToWizardStep(3);
        $message = $this->preview($page)['samples']['errors'][0]['message'];

        $this->assertSame(__('filament-xlsx-import::filament-xlsx-import.row.duplicate_in', ['column' => 'email']), $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('insert into', $message);

        $id = $this->runId($page);
        $page->callMountedAction();
        $report = Sheet::read(RunStore::path($id, 'report.xlsx'));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($report));
    }

    public function test_an_unexpected_exception_is_generic_and_logged(): void
    {
        $this->contactsAction(XlsxImportAction::make()->model(Contact::class)->saveUsing(function (): void {
            throw new \RuntimeException('secret path /var/www/.env password=hunter2');
        }));

        $page = $this->upload($this->contacts(), Sheet::csv("name,email\nAda,ada@example.com\n"))->goToWizardStep(3);
        $message = $this->preview($page)['samples']['errors'][0]['message'];

        $this->assertStringNotContainsString('hunter2', $message);
        $this->assertSame(__('filament-xlsx-import::filament-xlsx-import.row.unexpected'), $message);
    }

    public function test_a_row_may_fail_with_its_own_message_from_save_using(): void
    {
        $this->contactsAction(XlsxImportAction::make()->model(Contact::class)->saveUsing(function (): void {
            throw new \Asignua\FilamentXlsxImport\Support\RowFailed('City is not served');
        }));

        $page = $this->upload($this->contacts(), Sheet::csv("name,email\nAda,ada@example.com\n"))->goToWizardStep(3);

        $this->assertSame('City is not served', $this->preview($page)['samples']['errors'][0]['message']);
    }

    public function test_the_row_limit_stops_the_scan_early(): void
    {
        $rows = [['a']];

        for ($i = 0; $i < 50; $i++) {
            $rows[] = ['v'.$i];
        }

        $path = tempnam(sys_get_temp_dir(), 'xi-').'.xlsx';
        file_put_contents($path, Sheet::xlsx($rows)->getContent());

        $scan = (new SourceFile($path, 'xlsx'))->scan(maxRows: 5);

        $this->assertSame(6, $scan['rows'], 'It stopped one row past the limit instead of counting all 50.');
    }

    public function test_a_sheet_wider_than_the_column_cap_is_refused(): void
    {
        config(['filament-xlsx-import.max_columns' => 3]);

        $page = $this->upload($this->products(), Sheet::csv("a,b,c,d,e\n1,2,3,4,5\n"));

        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.too_many_columns', ['limit' => 3]));
        $this->assertSame('', $this->runId($page));
    }

    public function test_cells_beyond_the_column_cap_are_not_read_into_memory(): void
    {
        config(['filament-xlsx-import.max_columns' => 2]);
        $path = tempnam(sys_get_temp_dir(), 'xi-').'.xlsx';
        file_put_contents($path, Sheet::xlsx([range(1, 500)])->getContent());

        $first = (new SourceFile($path, 'xlsx'))->rows()->current();

        $this->assertCount(3, $first);
    }
}

final class DenyEverythingPolicy
{
    public function create(object $user): bool
    {
        return false;
    }

    public function update(object $user): bool
    {
        return false;
    }
}

final class AllowEverythingPolicy
{
    public function create(object $user): bool
    {
        return true;
    }

    public function update(object $user): bool
    {
        return true;
    }
}

final class AllowCreatePolicy
{
    public function create(object $user): bool
    {
        return true;
    }
}

final class AllowlistedDefinition implements \Asignua\FilamentXlsxImport\Contracts\DefinesImport
{
    public static function importRules(): array
    {
        return ['targets' => ['email'], 'update_by' => 'email'];
    }
}
