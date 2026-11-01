<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Support\RunStore;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Workbench\App\Models\User;

class ReportAndPruneTest extends TestCase
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
        User::$denyPanel = false;

        parent::tearDown();
    }

    /**
     * Runs an import with two bad rows and returns the run id.
     */
    private function runWithFailures(): string
    {
        $file = Sheet::xlsx([
            ['Артикул', 'Назва', 'Ціна', 'Memo'],
            ['A', 'Good', '1', 'fine'],
            ['', '=HYPERLINK("http://evil","click")', '2', '+cmd|calc'],
            ['C', '', '3', '@SUM(1)'],
        ]);
        $this->apply($this->upload($this->products(), $file));

        return $this->lastRunId;
    }

    private function report(string $id): array
    {
        return Sheet::read(RunStore::path($id, 'report.xlsx'));
    }

    public function test_the_report_lists_failed_rows_with_their_source_row_numbers_and_the_reason(): void
    {
        $rows = $this->report($this->runWithFailures());

        $this->assertSame(['Source row', 'Артикул', 'Назва', 'Ціна', 'Memo', 'Error'], $rows[0]);
        $this->assertSame('3', $rows[1][0]);
        $this->assertSame('4', $rows[2][0]);
        $this->assertStringContainsString('Sku', $rows[1][5]);
        $this->assertCount(3, $rows, 'The good row is not in the report.');
    }

    public function test_every_cell_of_the_report_is_text_so_formulas_in_the_upload_stay_inert(): void
    {
        $id = $this->runWithFailures();
        $rows = $this->report($id);
        $xml = Sheet::zipEntry(RunStore::path($id, 'report.xlsx'), 'xl/worksheets/sheet1.xml');

        $this->assertSame('=HYPERLINK("http://evil","click")', $rows[1][2]);
        $this->assertSame('+cmd|calc', $rows[1][4]);
        $this->assertSame('@SUM(1)', $rows[2][4]);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringNotContainsString('<f ', $xml);
        $this->assertDoesNotMatchRegularExpression('/<c [^>]*t="n"/', $xml, 'Even the numbers are written as text.');
    }

    public function test_the_owner_downloads_the_report_through_the_signed_link(): void
    {
        $id = $this->runWithFailures();

        $response = $this->get(RunStore::url($id));

        $response->assertOk();
        $this->assertStringContainsString('failed rows.xlsx', (string) $response->headers->get('content-disposition'));
        $this->assertSame('PK', substr($response->streamedContent(), 0, 2));
    }

    public function test_the_link_is_per_user(): void
    {
        $id = $this->runWithFailures();
        $url = RunStore::url($id);

        $this->actingAs(User::factory()->create());

        $this->get($url)->assertRedirect();
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.link_unusable'));
    }

    public function test_a_guest_gets_nothing(): void
    {
        $id = $this->runWithFailures();
        $url = RunStore::url($id);

        auth()->logout();

        $this->get($url)->assertRedirect();
    }

    public function test_a_forged_or_unsigned_link_is_a_403(): void
    {
        $id = $this->runWithFailures();

        $this->get(url("filament-xlsx-import/report/{$id}"))->assertForbidden();
        $this->get(RunStore::url($id).'x')->assertForbidden();
    }

    public function test_an_expired_link_sends_the_user_back_with_a_notification(): void
    {
        $id = $this->runWithFailures();
        $url = URL::temporarySignedRoute(RunStore::ROUTE, now()->subMinute(), ['id' => $id]);

        $this->get($url)->assertRedirect();
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.link_expired'));
    }

    public function test_a_pruned_report_is_reported_as_gone(): void
    {
        $id = $this->runWithFailures();
        $url = RunStore::url($id);
        RunStore::forget($id);

        $this->get($url)->assertRedirect();
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.file_gone'));
    }

    public function test_somebody_who_lost_the_panel_cannot_download(): void
    {
        $id = $this->runWithFailures();
        $url = RunStore::url($id);
        User::$denyPanel = true;

        $this->get($url)->assertRedirect();
    }

    public function test_a_bad_run_id_does_not_reach_the_disk(): void
    {
        $this->get('filament-xlsx-import/report/../../x')->assertNotFound();
        $this->get('filament-xlsx-import/report/NOTAULID')->assertNotFound();
    }

    public function test_prune_deletes_old_runs_and_keeps_recent_ones(): void
    {
        $old = $this->runWithFailures();
        $recent = $this->runWithFailures();
        $dir = RunStore::disk()->path(RunStore::directory().'/'.$old);

        foreach (glob($dir.'/*') ?: [] as $file) {
            touch($file, time() - 48 * 3600);
        }

        touch($dir, time() - 48 * 3600);

        $this->artisan('xlsx-import:prune')->assertSuccessful();

        $this->assertFalse(RunStore::exists($old));
        $this->assertTrue(RunStore::exists($recent));
    }

    public function test_prune_with_retention_off_deletes_nothing(): void
    {
        config(['filament-xlsx-import.storage.retention_hours' => 0]);
        $id = $this->runWithFailures();

        foreach (glob(RunStore::disk()->path(RunStore::directory().'/'.$id).'/*') ?: [] as $file) {
            touch($file, time() - 999 * 3600);
        }

        $this->artisan('xlsx-import:prune')->assertSuccessful();

        $this->assertTrue(RunStore::exists($id));
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression);

        $this->assertTrue($events->contains(fn (string $e): bool => str_contains($e, 'xlsx-import:prune') && str_contains($e, '55 3 * * *')));
    }
}
