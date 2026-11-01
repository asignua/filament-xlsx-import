<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Actions\XlsxImportAction;
use Asignua\FilamentXlsxImport\Jobs\ImportXlsxJob;
use Asignua\FilamentXlsxImport\Support\RunStore;
use Asignua\FilamentXlsxImport\Tests\Support\DrivesImport;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Filament\Resources\Products\ProductResource;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

class QueuedImportTest extends TestCase
{
    use DrivesImport;

    /** @var list<array{int|string|null, string, string|null}> */
    public static array $seen = [];

    public static function before(object $user, ?object $panel): void
    {
        self::$seen[] = [$user->getKey(), app()->getLocale(), $panel?->getId()];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        self::$seen = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownDrivesImport();
        User::$denyPanel = false;

        parent::tearDown();
    }

    private function queued(?int $threshold = null): void
    {
        ProductResource::$header = fn (): array => [
            $threshold === null
                ? XlsxImportAction::make()->model(Product::class)->queue(connection: 'database', queue: 'imports')
                : XlsxImportAction::make()->model(Product::class)->queueWhenMoreThan($threshold),
        ];
    }

    private function file(int $rows = 3): \Illuminate\Http\UploadedFile
    {
        $data = [['Артикул', 'Назва']];

        for ($i = 1; $i <= $rows; $i++) {
            $data[] = ['SKU-'.$i, 'Product '.$i];
        }

        return Sheet::xlsx($data);
    }

    private function job(): ImportXlsxJob
    {
        $dispatched = Bus::dispatched(ImportXlsxJob::class);
        $this->assertCount(1, $dispatched);

        return $dispatched->first();
    }

    public function test_queue_dispatches_the_job_instead_of_importing(): void
    {
        $this->queued();
        Bus::fake([ImportXlsxJob::class]);

        $this->apply($this->upload($this->products(), $this->file()));

        Bus::assertDispatchedTimes(ImportXlsxJob::class, 1);
        $job = $this->job();
        $this->assertSame('database', $job->connection);
        $this->assertSame('imports', $job->queue);
        $this->assertSame(auth()->id(), $job->spec['user']);
        $this->assertSame('admin', $job->spec['panel']);
        $this->assertSame(app()->getLocale(), $job->spec['locale']);
        $this->assertSame(['Артикул', 'Назва'], array_column($job->spec['mapping'], 'header'));
        $this->assertSame(0, Product::count());
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.queued_title'));
        $this->assertSame('queued', RunStore::meta($job->spec['run_id'])['status']);
        $this->assertTrue(RunStore::disk()->exists(RunStore::directory().'/'.$job->spec['run_id'].'/source.xlsx'), 'The upload stays for the worker.');
    }

    public function test_the_threshold_queues_only_bigger_files(): void
    {
        $this->queued(2);
        Bus::fake([ImportXlsxJob::class]);

        $this->apply($this->upload($this->products(), $this->file(2)));
        Bus::assertNothingDispatched();
        $this->assertSame(2, Product::count());

        $this->apply($this->upload($this->products(), $this->file(3)));
        Bus::assertDispatchedTimes(ImportXlsxJob::class, 1);
    }

    public function test_the_queue_can_be_switched_off_for_every_action(): void
    {
        $this->queued();
        config(['filament-xlsx-import.queue.enabled' => false]);
        Bus::fake([ImportXlsxJob::class]);

        $this->apply($this->upload($this->products(), $this->file()));

        Bus::assertNothingDispatched();
        $this->assertSame(3, Product::count());
    }

    public function test_the_preview_tells_a_big_file_will_run_in_the_background(): void
    {
        $this->queued();
        $page = $this->upload($this->products(), $this->file())->goToWizardStep(3);

        $this->assertSame(3, $this->preview($page)['created']);
        $this->assertSame(0, Product::count());
    }

    public function test_the_job_imports_as_the_user_reports_progress_and_notifies_with_a_signed_link(): void
    {
        config(['filament-xlsx-import.chunk_size' => 2]);
        $this->queued();
        Bus::fake([ImportXlsxJob::class]);
        $this->apply($this->upload($this->products(), Sheet::xlsx([['Артикул', 'Назва'], ['A', 'a'], ['B', 'b'], ['', 'no key'], ['D', 'd'], ['E', 'e']])));
        $job = $this->job();
        $id = $job->spec['run_id'];

        $job->handle();

        $this->assertSame(['A', 'B', 'D', 'E'], Product::orderBy('sku')->pluck('sku')->all());
        $meta = RunStore::meta($id);
        $this->assertSame('done', $meta['status']);
        $this->assertSame(['processed' => 5, 'total' => 5], $meta['progress']);
        $this->assertSame(4, $meta['result']['created']);
        $this->assertSame(1, $meta['result']['failed']);
        $this->assertFalse(RunStore::disk()->exists(RunStore::directory().'/'.$id.'/source.xlsx'));

        $notification = auth()->user()->notifications()->get();
        $this->assertCount(1, $notification);
        $data = $notification->first()->data;
        $this->assertSame(__('filament-xlsx-import::filament-xlsx-import.done_title'), $data['title']);
        $this->assertStringContainsString('4', $data['body']);
        $url = $data['actions'][0]['url'];
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString("filament-xlsx-import/report/{$id}", $url);
    }

    public function test_the_worker_runs_as_the_user_in_their_language_and_is_put_back_afterwards(): void
    {
        $this->queued();
        config(['filament-xlsx-import.queue.before' => [self::class, 'before']]);
        Bus::fake([ImportXlsxJob::class]);
        app()->setLocale('uk');
        $this->apply($this->upload($this->products(), $this->file()));
        $job = $this->job();
        $this->assertSame('uk', $job->spec['locale']);

        // The worker: another process state — some other user, English, no panel.
        $someone = User::factory()->create();
        Auth::guard('web')->setUser($someone);
        app()->setLocale('en');
        Filament::setCurrentPanel(null);
        self::$seen = [];

        $job->handle();

        $this->assertSame([[$job->spec['user'], 'uk', 'admin']], self::$seen, 'Inside the job: the exporter, in their language, in their panel.');
        $this->assertSame($someone->getKey(), Auth::guard('web')->id(), 'The next job in this worker is not the user.');
        $this->assertSame('en', app()->getLocale());
        $this->assertNull(Filament::getCurrentPanel());
    }

    public function test_a_guest_worker_stays_a_guest(): void
    {
        $this->queued();
        Bus::fake([ImportXlsxJob::class]);
        $this->apply($this->upload($this->products(), $this->file()));
        $job = $this->job();

        Auth::guard('web')->forgetUser();
        $job->handle();

        $this->assertFalse(Auth::guard('web')->hasUser());
    }

    public function test_a_user_who_is_gone_or_lost_the_panel_gets_nothing_imported(): void
    {
        $this->queued();
        Bus::fake([ImportXlsxJob::class]);
        $this->apply($this->upload($this->products(), $this->file()));
        $job = $this->job();

        User::$denyPanel = true;
        $job->handle();
        $this->assertSame(0, Product::count());
        $this->assertSame('dropped', RunStore::meta($job->spec['run_id'])['status']);

        User::$denyPanel = false;
        User::query()->whereKey($job->spec['user'])->delete();
        $job->handle();
        $this->assertSame(0, Product::count());
    }

    public function test_a_failing_job_tells_the_user_and_frees_the_slot(): void
    {
        $this->queued();
        config(['filament-xlsx-import.queue.max_per_user' => 1]);
        Bus::fake([ImportXlsxJob::class]);
        $this->apply($this->upload($this->products(), $this->file()));
        $job = $this->job();

        // The slot is taken while the job is pending...
        $this->apply($this->upload($this->products(), $this->file()));
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.too_many_pending'));

        $job->failed(new \RuntimeException('boom'));

        $this->assertSame('failed', RunStore::meta($job->spec['run_id'])['status']);
        $this->assertSame(__('filament-xlsx-import::filament-xlsx-import.failed_title'), auth()->user()->notifications()->first()->data['title']);

        // ...and free again afterwards.
        $this->apply($this->upload($this->products(), $this->file()));
        Bus::assertDispatchedTimes(ImportXlsxJob::class, 2);
    }

    public function test_a_closure_save_using_cannot_be_queued(): void
    {
        ProductResource::$header = fn (): array => [
            XlsxImportAction::make()->model(Product::class)->queue()->saveUsing(fn (array $data) => null),
        ];
        Bus::fake([ImportXlsxJob::class]);

        $this->apply($this->upload($this->products(), $this->file()));

        Bus::assertNothingDispatched();
        Notification::assertNotified(__('filament-xlsx-import::filament-xlsx-import.closure_not_queueable'));
    }

    public function test_the_whole_path_works_on_a_real_sync_queue(): void
    {
        $this->queued();
        config(['queue.default' => 'sync', 'queue.connections.sync.driver' => 'sync']);
        ProductResource::$header = fn (): array => [XlsxImportAction::make()->model(Product::class)->queue(connection: 'sync')];

        $this->apply($this->upload($this->products(), $this->file()));

        $this->assertSame(3, Product::count());
        $this->assertCount(1, auth()->user()->notifications);
    }

    public function test_a_panel_without_database_notifications_still_imports(): void
    {
        $this->queued();
        Bus::fake([ImportXlsxJob::class]);
        $this->apply($this->upload($this->products(), $this->file()));
        $job = $this->job();
        Filament::getPanel('admin')->databaseNotifications(false);

        $job->handle();

        $this->assertSame(3, Product::count());
        $this->assertCount(0, auth()->user()->notifications);
    }
}
