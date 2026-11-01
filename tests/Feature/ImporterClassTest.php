<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Filament\Resources\Contacts\ContactResource;
use Workbench\App\Import\ContactImporter;
use Workbench\App\Models\Contact;

class ImporterClassTest extends TestCase
{
    use DrivesImport;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        ContactImporter::$hooks = [];
        ContactResource::$header = fn (): array => [XlsxImportAction::make()->importer(ContactImporter::class)];
    }

    protected function tearDown(): void
    {
        $this->tearDownDrivesImport();

        parent::tearDown();
    }

    public function test_the_importers_columns_are_the_targets_and_its_guesses_map_the_headers(): void
    {
        $page = $this->upload($this->contacts(), Sheet::xlsx([['Name', 'E-Mail', 'Tel'], ['Ada', 'ada@example.com', '1']]));

        $this->assertSame(['name', 'email', 'phone'], array_column($this->mapping($page), 'target'));
    }

    public function test_rows_run_through_resolve_record_validation_and_hooks(): void
    {
        $file = Sheet::xlsx([['name', 'email', 'phone', 'city'], ['Ada', 'ada@example.com', '+1', 'Lviv'], ['Bad', 'not-an-email', '', ''], ['Eve', 'eve@example.com', '', 'Paris']]);
        $page = $this->upload($this->contacts(), $file)->goToWizardStep(3);
        $preview = $this->preview($page);

        $this->assertSame(1, $preview['created']);
        $this->assertSame(2, $preview['failed']);
        $this->assertSame(0, Contact::count(), 'A dry run writes nothing.');
        $this->assertSame([3, 4], array_column($preview['samples']['errors'], 'row'));

        ContactImporter::$hooks = [];
        $page->callMountedAction();

        $this->assertSame(['ada@example.com'], Contact::pluck('email')->all());
        $this->assertSame(['afterSave:ada@example.com'], ContactImporter::$hooks);
    }

    public function test_an_existing_record_found_by_resolve_record_is_updated_and_an_unchanged_one_skipped(): void
    {
        $contact = new Contact;
        $contact->forceFill(['name' => 'Old', 'email' => 'ada@example.com'])->save();

        $page = $this->upload($this->contacts(), Sheet::xlsx([['name', 'email'], ['Ada', 'ada@example.com']]))->goToWizardStep(3);
        $preview = $this->preview($page);

        $this->assertSame(1, $preview['updated']);
        $this->assertSame(['Old', 'Ada'], $preview['samples']['updated'][0]['changes']['name']);

        $page->callMountedAction();
        $again = $this->preview($this->upload($this->contacts(), Sheet::xlsx([['name', 'email'], ['Ada', 'ada@example.com']]))->goToWizardStep(3));

        $this->assertSame(1, $again['skipped']);
        $this->assertSame(0, $again['updated']);
        $this->assertSame(1, Contact::count());
    }

    public function test_a_required_importer_column_must_be_mapped(): void
    {
        $this->upload($this->contacts(), Sheet::xlsx([['name', 'phone'], ['Ada', '1']]))->goToWizardStep(3);

        \Filament\Notifications\Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.required_unmapped', ['fields' => 'Email']));
    }

    public function test_casts_run_before_the_importer(): void
    {
        $file = Sheet::xlsx([['name', 'email'], ['Ada', '  ADA@Example.COM ']]);
        $page = $this->upload($this->contacts(), $file);
        $this->remap($page, ['email' => ['casts' => [['type' => 'trim', 'arg' => '', 'arg2' => '', 'map' => []], ['type' => 'lower', 'arg' => '', 'arg2' => '', 'map' => []]]]]);
        $this->apply($page);

        $this->assertSame(['ada@example.com'], Contact::pluck('email')->all());
    }

    public function test_the_importer_works_without_an_imports_table(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('imports'));

        $this->apply($this->upload($this->contacts(), Sheet::xlsx([['name', 'email'], ['Ada', 'ada@example.com']])));

        $this->assertSame(1, Contact::count());
    }

    public function test_authorization_applies_to_importer_rows_too(): void
    {
        \Illuminate\Support\Facades\Gate::policy(Contact::class, \Asignua\FilamentXlsxImport\Tests\Support\DenyCreatePolicy::class);
        ContactResource::$header = fn (): array => [XlsxImportAction::make()->importer(ContactImporter::class)->authorizeRecords()];

        $page = $this->upload($this->contacts(), Sheet::xlsx([['name', 'email'], ['Ada', 'ada@example.com']]))->goToWizardStep(3);

        $this->assertSame(1, $this->preview($page)['failed']);
        $page->callMountedAction();
        $this->assertSame(0, Contact::count());
    }
}
