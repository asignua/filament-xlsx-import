<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Support\ImportRunner;
use Asignua\FilamentXlsxImport\Support\RowOutcome;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Filament\Forms\Components\Select;
use ReflectionMethod;

class BigWorkbookAndFailureSummaryTest extends TestCase
{
    use DrivesImport;

    protected function tearDown(): void
    {
        $this->tearDownDrivesImport();

        parent::tearDown();
    }

    public function test_a_sheet_past_the_first_fifty_can_be_picked(): void
    {
        $more = [];

        for ($i = 2; $i <= 60; $i++) {
            $more['S'.$i] = $i === 55
                ? [['Артикул', 'Назва'], ['X-55', 'From sheet 55']]
                : [['Other', 'Columns'], ['a', 'b']];
        }

        $page = $this->products()->mountAction('xlsxImport')->fillForm(['file' => Sheet::xlsx([['Other', 'Columns']], 'big.xlsx', $more)]);

        $select = $this->sheetSelect($page);
        $this->assertTrue($select->isSearchable());
        $this->assertGreaterThanOrEqual(60, $select->getOptionsLimit());
        $this->assertCount(60, $select->getOptions());
        $this->assertSame('S55', $select->getOptions()[54]);

        $page->fillForm(['sheet' => 54])->goToWizardStep(2);

        $headers = array_column($this->mapping($page), 'header');
        $this->assertSame(['Артикул', 'Назва'], $headers);
    }

    private function sheetSelect(\Livewire\Features\SupportTesting\Testable $page): Select
    {
        $schema = (new ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance());

        foreach ($schema->getFlatComponents(withHidden: true) as $component) {
            if ($component instanceof Select && $component->getName() === 'sheet') {
                return $component;
            }
        }

        $this->fail('No sheet select.');
    }

    public function test_failures_are_tallied_by_reason_and_the_preview_says_when_rows_are_hidden(): void
    {
        $rows = [['Артикул', 'Назва']];

        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['', 'Nameless '.$i];
        }

        $rows[] = ['OK', 'Fine'];

        $page = $this->upload($this->products(), Sheet::xlsx($rows))->goToWizardStep(3);
        $preview = $this->preview($page);

        $this->assertSame(30, $preview['failed']);
        $this->assertCount(ImportRunner::ERROR_SAMPLE, $preview['samples']['errors']);
        $this->assertSame([30], array_values($preview['samples']['error_reasons']));

        $html = view('filament-xlsx-import::preview', ['preview' => $preview, 'queued' => false])->render();
        $this->assertStringContainsString('30 × ', $html);
        $this->assertStringContainsString(__('filament-xlsx-import::filament-xlsx-import.preview_errors_more', ['shown' => 20, 'total' => 30]), $html);
    }

    public function test_the_note_is_absent_when_every_failed_row_is_shown(): void
    {
        $page = $this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['', 'Nameless']]))->goToWizardStep(3);
        $html = view('filament-xlsx-import::preview', ['preview' => $this->preview($page), 'queued' => false])->render();

        $this->assertStringContainsString('1 × ', $html);
        $this->assertStringNotContainsString(__('filament-xlsx-import::filament-xlsx-import.preview_errors_more', ['shown' => 1, 'total' => 1]), $html);
    }

    public function test_distinct_reasons_are_capped_and_the_rest_share_one_bucket(): void
    {
        $result = ['samples' => ['created' => [], 'updated' => [], 'errors' => [], 'error_reasons' => [], 'skipped' => []]];
        $sample = new ReflectionMethod(ImportRunner::class, 'sample');

        for ($i = 1; $i <= 50; $i++) {
            $sample->invokeArgs(null, [&$result, RowOutcome::failed('Bad value #'.$i), $i]);
        }

        for ($i = 0; $i < 3; $i++) {
            $sample->invokeArgs(null, [&$result, RowOutcome::failed('Bad value #1'), 100 + $i]);
        }

        $reasons = $result['samples']['error_reasons'];

        $this->assertCount(ImportRunner::ERROR_REASONS + 1, $reasons);
        $this->assertSame(4, $reasons['Bad value #1'], 'A known reason keeps counting after the cap.');
        $this->assertSame(30, $reasons[ImportRunner::OTHER_REASONS]);
        $this->assertSame(53, array_sum($reasons));
        $this->assertCount(ImportRunner::ERROR_SAMPLE, $result['samples']['errors']);
    }

    public function test_the_other_bucket_is_rendered_last_with_a_label(): void
    {
        $preview = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 9, 'processed' => 9, 'total' => 9, 'truncated' => false, 'duplicates' => 0, 'has_report' => true,
            'samples' => ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => [['row' => 2, 'message' => 'A']],
                'error_reasons' => [ImportRunner::OTHER_REASONS => 5, 'A' => 1, 'B' => 3]]];

        $html = view('filament-xlsx-import::preview', ['preview' => $preview, 'queued' => false])->render();

        $this->assertMatchesRegularExpression('/3 × B.*1 × A.*5 × '.preg_quote(__('filament-xlsx-import::filament-xlsx-import.preview_other_reasons'), '/').'/s', $html);
    }
}
