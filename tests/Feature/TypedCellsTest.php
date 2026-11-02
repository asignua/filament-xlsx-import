<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Asignua\FilamentXlsxImport\Support\CastException;
use Asignua\FilamentXlsxImport\Support\CastPipeline;
use Asignua\FilamentXlsxImport\Support\DefinitionResolver;
use Asignua\FilamentXlsxImport\Support\Mapping;
use Asignua\FilamentXlsxImport\Support\ModelIntrospector;
use Asignua\FilamentXlsxImport\Support\SourceFile;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\Support\UnguardedProduct;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Filament\Resources\Products\ProductResource;
use Workbench\App\Models\Product;

/**
 * The data bugs of the UX review: numbers and dates must survive as what the spreadsheet says they are.
 */
class TypedCellsTest extends TestCase
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function formats(): iterable
    {
        yield 'xlsx' => ['xlsx'];
        yield 'ods' => ['ods'];
    }

    // ---- D1: numbers

    #[DataProvider('formats')]
    public function test_a_numeric_cell_is_not_read_with_a_separator_rule(string $format): void
    {
        // The model's rule says decimal comma; a REAL number cell is 12.5, not «125».
        $file = Sheet::typed([['Артикул', 'Назва', 'Ціна'], ['A', 'Alpha', 12.5], ['B', 'Beta', 1234.75], ['C', 'Gamma', 7]], $format);

        $this->apply($this->upload($this->products(), $file));

        $this->assertSame('12.50', Product::query()->where('sku', 'A')->value('price'));
        $this->assertSame('1234.75', Product::query()->where('sku', 'B')->value('price'));
        $this->assertSame('7.00', Product::query()->where('sku', 'C')->value('price'));
    }

    public function test_the_same_text_numbers_are_read_by_the_declared_separator(): void
    {
        $file = Sheet::typed([['Артикул', 'Назва', 'Ціна'], ['A', 'Alpha', '12,5'], ['B', 'Beta', '1.234,75']]);

        $this->apply($this->upload($this->products(), $file));

        $this->assertSame('12.50', Product::query()->where('sku', 'A')->value('price'));
        $this->assertSame('1234.75', Product::query()->where('sku', 'B')->value('price'));
    }

    public function test_a_text_number_with_the_wrong_separator_fails_the_row_instead_of_being_guessed(): void
    {
        $file = Sheet::typed([['Артикул', 'Назва', 'Ціна'], ['A', 'Alpha', '12.5'], ['B', 'Beta', '1.234']]);
        $preview = $this->preview($this->upload($this->products(), $file)->goToWizardStep(3));

        $this->assertSame(2, $preview['failed']);
        $this->assertStringContainsString(__('filament-xlsx-import::filament-xlsx-import.cast.wrong_decimal_separator', ['value' => '12.5', 'separator' => ',']), $preview['samples']['errors'][0]['message']);
    }

    /**
     * @return iterable<string, array{mixed, list<mixed>, string|null}>
     */
    public static function decimals(): iterable
    {
        yield 'native float, comma rule' => [12.5, [['type' => 'decimal', 'arg' => ',']], '12.5'];
        yield 'native float, dot rule' => [1234.75, [['type' => 'decimal', 'arg' => '.']], '1234.75'];
        yield 'native int' => [7, ['decimal'], '7'];
        yield 'auto: lone comma' => ['12,50', ['decimal'], '12.50'];
        yield 'auto: lone dot' => ['12.5', ['decimal'], '12.5'];
        yield 'auto: both, last is the decimal' => ['1.234,50', ['decimal'], '1234.50'];
        yield 'auto: both, dot last' => ['1,234.50', ['decimal'], '1234.50'];
        yield 'auto: grouping repeated' => ['1.234.567', ['decimal'], '1234567'];
        yield 'declared comma, thousands dot' => ['1.234', [['type' => 'decimal', 'arg' => ',', 'arg2' => '.']], '1234'];
        yield 'declared dot, thousands comma' => ['1,234', [['type' => 'decimal', 'arg' => '.', 'arg2' => ',']], '1234'];
        yield 'declared comma, no thousands' => ['1,234', [['type' => 'decimal', 'arg' => ',', 'arg2' => 'none']], '1.234'];
        yield 'space grouping' => ['1 234,5', [['type' => 'decimal', 'arg' => ',']], '1234.5'];
        yield 'blank' => ['', ['decimal'], null];
    }

    /**
     * @param list<mixed> $steps
     */
    #[DataProvider('decimals')]
    public function test_decimal_cast(mixed $in, array $steps, ?string $expected): void
    {
        $this->assertSame($expected, CastPipeline::apply($in, $steps));
    }

    /**
     * @return iterable<string, array{mixed, list<mixed>}>
     */
    public static function ambiguous(): iterable
    {
        yield 'auto: 1,234' => ['1,234', ['decimal']];
        yield 'auto: 1.234' => ['1.234', ['decimal']];
        yield 'comma rule, a lone dot' => ['12.5', [['type' => 'decimal', 'arg' => ',']]];
        yield 'dot rule, a lone comma' => ['12,5', [['type' => 'decimal', 'arg' => '.']]];
        yield 'dot rule, dot twice' => ['1.2.3', [['type' => 'decimal', 'arg' => '.']]];
        yield 'both but the wrong one last' => ['1,234.5', [['type' => 'decimal', 'arg' => ',']]];
    }

    /**
     * @param list<mixed> $steps
     */
    #[DataProvider('ambiguous')]
    public function test_an_ambiguous_text_number_is_refused(mixed $in, array $steps): void
    {
        $this->expectException(CastException::class);

        CastPipeline::apply($in, $steps);
    }

    public function test_integer_casts_take_native_numbers(): void
    {
        $this->assertSame(5, CastPipeline::apply(5.0, ['integer']));
        $this->assertSame(1234, CastPipeline::apply(1234, ['integer']));
        $this->expectException(CastException::class);
        CastPipeline::apply(5.5, ['integer']);
    }

    // ---- D2: dates

    #[DataProvider('formats')]
    public function test_a_real_date_cell_is_read_whatever_the_text_format_of_the_rule(string $format): void
    {
        // The rule reads TEXT dates as d.m.Y; a date cell arrives as a date.
        $file = Sheet::typed([['Артикул', 'Назва', 'Дата виходу'], ['A', 'Alpha', new DateTimeImmutable('2026-03-07')], ['B', 'Beta', '15.10.2026']], $format);

        $this->apply($this->upload($this->products(), $file));

        $this->assertSame('2026-03-07', Product::query()->where('sku', 'A')->firstOrFail()->released_at?->toDateString());
        $this->assertSame('2026-10-15', Product::query()->where('sku', 'B')->firstOrFail()->released_at?->toDateString());
    }

    public function test_iso_text_and_excel_serials_are_accepted_before_the_format_is_applied(): void
    {
        $steps = [['type' => 'date', 'arg' => 'd.m.Y']];

        $this->assertSame('2026-01-31', CastPipeline::apply('2026-01-31', $steps));
        $this->assertSame('2026-01-31', CastPipeline::apply(46053, $steps));
        $this->assertSame('2026-01-31', CastPipeline::apply(46053.0, $steps));
        $this->assertSame('2026-01-31', CastPipeline::apply('31.01.2026', $steps));
        $this->assertSame('2026-01-31 09:30:00', CastPipeline::apply(new DateTimeImmutable('2026-01-31 09:30:00'), [['type' => 'datetime', 'arg' => 'd.m.Y H:i']]));
        $this->assertSame('2026-01-31', CastPipeline::apply('31/01/2026', [['type' => 'date', 'arg' => 'custom', 'arg2' => 'd/m/Y']]));
    }

    // ---- D3: template

    public function test_the_template_leaves_out_foreign_keys_and_json_columns(): void
    {
        $headers = Sheet::read($this->templateFor(UnguardedProduct::class))[0];

        $this->assertNotContains('Category Id', $headers);
        $this->assertNotContains('Team Id', $headers);
        $this->assertNotContains('Meta', $headers);
        $this->assertContains('Sku', $headers);
    }

    private function templateFor(string $model): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl-').'.xlsx';
        \Asignua\FilamentXlsxImport\Support\TemplateBuilder::build(DefinitionResolver::resolve($model, null), $path);

        return $path;
    }

    // ---- D4: blanks

    public function test_a_blank_cell_does_not_fail_the_rules_of_its_field(): void
    {
        // `price` has numeric|min:0 and no nullable: an empty cell is «leave as is», not an error.
        $file = Sheet::typed([['Артикул', 'Назва', 'Ціна', 'Кількість'], ['A', 'Alpha', null, null], ['B', 'Beta', 5, 2]]);

        $preview = $this->preview($this->upload($this->products(), $file)->goToWizardStep(3));

        $this->assertSame(0, $preview['failed']);
        $this->assertSame(2, $preview['created']);
    }

    public function test_required_still_fails_a_blank_cell(): void
    {
        $file = Sheet::typed([['Артикул', 'Назва'], ['A', null]]);

        $this->assertSame(1, $this->preview($this->upload($this->products(), $file)->goToWizardStep(3))['failed']);
    }

    // ---- D6

    public function test_a_column_picked_by_hand_still_shows_the_rule_of_its_target(): void
    {
        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва,Memo\nA,Alpha,5\n"));
        $page->set('mountedActions.0.data.mapping.2.target', 'price');

        $this->assertSame('price', $page->get('mountedActions.0.data.mapping.2.rule.target'), '«from model rules» and «reset» follow the chosen target.');

        $page->set('mountedActions.0.data.mapping.2.target', 'notes');
        $this->assertNull($page->get('mountedActions.0.data.mapping.2.rule'), 'No rule for a target the model says nothing about.');
    }

    public function test_a_preset_that_overrides_a_rule_is_shown_as_changed(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);
        $preset = [['header' => 'Ціна', 'target' => 'price', 'casts' => [['type' => 'trim']], 'mode' => 'lookup', 'many' => 'add']];

        $entry = Mapping::initial($definition, ['Ціна'], preset: $preset)[0];

        $this->assertSame('preset', $entry['origin']);
        $this->assertSame('price', $entry['rule']['target'], 'The rule is still known, so the screen can say «changed» and offer the reset.');
        $this->assertSame(['trim', 'decimal'], array_column($entry['rule']['casts'], 'type'));
    }

    public function test_a_three_letter_alias_matches_exactly_and_as_a_prefix(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);

        $this->assertSame('sku', Mapping::initial($definition, ['Код'])[0]['target']);
        $this->assertSame('sku', Mapping::initial($definition, ['Код товару'])[0]['target']);
    }

    // ---- D7

    public function test_enum_values_are_guessed_case_insensitively_by_name_value_and_label(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);

        $mapping = Mapping::initial($definition, ['status'], ['status' => ['ACTIVE', 'Draft', 'archived ']], ['status' => ['ACTIVE', 'Draft', 'archived ']]);

        $this->assertSame(['active', 'draft', 'archived'], array_column($mapping[0]['casts'][0]['map'], 'to'));
    }

    // ---- D9

    public function test_create_is_not_offered_unless_the_policy_or_the_switch_allows_it(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);
        $target = $definition->targets['category.name'];
        $can = new \ReflectionMethod(XlsxImportAction::class, 'canCreateRelated');

        $this->assertFalse($can->invoke(XlsxImportAction::make(), $target), 'No policy, no switch.');
        $this->assertTrue($can->invoke(XlsxImportAction::make()->allowCreatingRelated(), $target));

        $locked = DefinitionResolver::resolve(Product::class, null, null, ['fields' => ['category.name' => ['mode' => 'lookup']]])->targets['category.name'];
        $this->assertFalse($can->invoke(XlsxImportAction::make()->allowCreatingRelated(), $locked), 'A lookup rule is a lock.');
    }

    public function test_a_default_create_mode_is_brought_down_to_lookup_when_creating_is_not_allowed(): void
    {
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)];

        $page = $this->upload($this->products(), Sheet::csv("Артикул,Назва,Категорія\nA,Alpha,Fruit\n"));
        $category = $this->mapping($page)[2];

        $this->assertSame('category.name', $category['target']);
        $this->assertSame('lookup', $category['mode']);
        $this->assertSame('lookup', $category['rule']['mode'], 'The rule is clamped too, so the column is not shown as «changed».');
    }

    // ---- D10

    public function test_the_columns_step_shows_the_detected_csv_encoding_and_delimiter(): void
    {
        $page = $this->upload($this->products(), Sheet::csv(mb_convert_encoding("Артикул;Назва\nA;Яблуко\n", 'Windows-1251', 'UTF-8')));

        $summary = (string) $page->get('mountedActions.0.data.file_summary');

        $this->assertStringContainsString('WINDOWS-1251', strtoupper($summary));
        $this->assertStringContainsString(';', $summary);
        $this->assertSame('auto', $page->get('mountedActions.0.data.csv_encoding'));
    }

    // ---- D11

    public function test_the_required_fields_still_unmapped_are_listed(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);
        $missing = new \ReflectionMethod(XlsxImportAction::class, 'unmappedRequired');
        $action = XlsxImportAction::make()->model(Product::class);

        $this->assertSame(['Sku'], $missing->invoke($action, $definition, [['target' => null], ['target' => 'name']], null));
        $this->assertSame([], $missing->invoke($action, $definition, [['target' => 'sku']], null));
    }

    // ---- D12

    public function test_postgres_small_integers_are_integers(): void
    {
        foreach (['int2', 'smallint', 'int4', 'int8', 'serial'] as $type) {
            $this->assertSame('integer', ModelIntrospector::columnKind(['type_name' => $type, 'type' => $type]), $type);
        }
    }

    public function test_dates_in_samples_are_shown_as_text_and_numbers_stay_numbers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xi-').'.xlsx';
        file_put_contents($path, Sheet::typed([['d', 'n'], [new DateTimeImmutable('2026-03-07'), 12.5]])->getContent());

        $samples = (new SourceFile($path, 'xlsx'))->scan()['samples'];

        $this->assertSame(['2026-03-07'], $samples['d']);
        $this->assertSame([12.5], $samples['n']);
    }
}
