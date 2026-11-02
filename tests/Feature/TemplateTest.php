<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Asignua\FilamentXlsxImport\Support\DefinitionResolver;
use Asignua\FilamentXlsxImport\Support\SourceFile;
use Asignua\FilamentXlsxImport\Support\TemplateBuilder;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Workbench\App\Import\ContactImporter;
use Workbench\App\Models\Product;

class TemplateTest extends TestCase
{
    private function build(?string $model, ?string $importer = null, bool $example = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl-').'.xlsx';
        TemplateBuilder::build(DefinitionResolver::resolve($model, $importer), $path, null, $example);

        return $path;
    }

    public function test_the_template_has_a_header_per_column_and_an_example_row(): void
    {
        $rows = Sheet::read($this->build(Product::class));

        $this->assertSame('Sku', $rows[0][0]);
        $this->assertContains('Category → Name', $rows[0]);
        $this->assertNotContains('Id', $rows[0], 'The primary key is not part of a template.');
        $this->assertNotContains('Tags → Name', array_slice($rows[0], 0, 0));
        $this->assertSame(count($rows[0]), count($rows[1]));
        $this->assertSame('31.01.2026', $rows[1][array_search('Released At', $rows[0], true)], 'The example is written the way the rule reads it (d.m.Y).');
        $this->assertSame('12,50', $rows[1][array_search('Price', $rows[0], true)], 'A decimal-comma rule gets a decimal-comma example.');
    }

    public function test_the_example_row_can_be_left_out(): void
    {
        $this->assertCount(1, Sheet::read($this->build(Product::class, example: false)));
    }

    public function test_enum_columns_get_a_real_dropdown_pointing_at_a_lists_sheet(): void
    {
        $path = $this->build(Product::class);
        $sheet = Sheet::zipEntry($path, 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('<dataValidations count="1">', $sheet);
        $this->assertStringContainsString('type="list"', $sheet);
        $this->assertMatchesRegularExpression('/sqref="[A-Z]+2:[A-Z]+1001"/', $sheet);
        $this->assertStringContainsString("<formula1>'Lists'!\$A\$2:\$A\$4</formula1>", $sheet);

        $this->assertSame(['Sheet', 'Lists'], array_map(fn ($n) => $n === 'Template' ? 'Sheet' : $n, (new SourceFile($path, 'xlsx'))->sheets()));
        $lists = iterator_to_array((new SourceFile($path, 'xlsx'))->rows(1), false);
        $this->assertSame([['Status'], ['draft'], ['active'], ['archived']], $lists);
    }

    public function test_the_dropdowns_block_sits_before_the_page_margins_where_the_schema_wants_it(): void
    {
        $sheet = Sheet::zipEntry($this->build(Product::class), 'xl/worksheets/sheet1.xml');

        $this->assertLessThan(strpos($sheet, '</worksheet>'), strpos($sheet, '<dataValidations'));
        $this->assertGreaterThan(strpos($sheet, '</sheetData>'), strpos($sheet, '<dataValidations'));

        if (str_contains($sheet, '<pageMargins')) {
            $this->assertLessThan(strpos($sheet, '<pageMargins'), strpos($sheet, '<dataValidations'));
        }

        $this->assertNotFalse(simplexml_load_string($sheet), 'The sheet XML is still well formed.');
    }

    public function test_importer_columns_with_an_in_rule_get_a_dropdown_and_the_importers_examples(): void
    {
        $path = $this->build(null, ContactImporter::class);
        $rows = Sheet::read($path);

        $this->assertSame(['Name', 'Email', 'Phone', 'City'], $rows[0]);
        $this->assertSame('ada@example.com', $rows[1][1]);
        $this->assertStringContainsString('<dataValidations', Sheet::zipEntry($path, 'xl/worksheets/sheet1.xml'));
        $this->assertSame([['City'], ['Lviv'], ['Kyiv'], ['Odesa']], iterator_to_array((new SourceFile($path, 'xlsx'))->rows(1), false));
    }

    public function test_a_template_without_options_has_no_lists_sheet(): void
    {
        $path = $this->build(\Workbench\App\Models\Contact::class);

        $this->assertCount(1, (new SourceFile($path, 'xlsx'))->sheets());
        $this->assertStringNotContainsString('dataValidations', Sheet::zipEntry($path, 'xl/worksheets/sheet1.xml'));
    }

    public function test_the_template_imports_back_by_header_label(): void
    {
        $path = $this->build(\Workbench\App\Models\Contact::class, example: false);
        $definition = DefinitionResolver::resolve(\Workbench\App\Models\Contact::class, null);

        $headers = Sheet::read($path)[0];
        $mapping = \Asignua\FilamentXlsxImport\Support\Mapping::initial($definition, $headers);

        $this->assertSame(['name', 'email', 'phone', 'city'], array_column($mapping, 'target'));
    }

    public function test_the_action_streams_the_template_as_an_xlsx_download(): void
    {
        $action = XlsxImportAction::make()->model(Product::class);
        $definition = $action->definitionFor(null);
        $method = new \ReflectionMethod($action, 'templateResponse');

        $response = $method->invoke($action, $definition);

        $this->assertStringContainsString('template.xlsx', (string) $response->headers->get('content-disposition'));
        ob_start();
        $response->sendContent();
        $binary = (string) ob_get_clean();
        $this->assertSame('PK', substr($binary, 0, 2));
    }
}
