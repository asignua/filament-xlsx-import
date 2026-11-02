<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Asignua\FilamentXlsxImport\Support\RunStore;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Contacts\ContactResource;
use Workbench\App\Filament\Resources\Contacts\Pages\ListContacts;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Filament\Resources\Products\ProductResource;

/**
 * Walks the import wizard the way a browser does: open → upload → columns → preview → apply.
 */
trait DrivesImport
{
    protected string $lastRunId = '';

    protected function tearDownDrivesImport(): void
    {
        ProductResource::$header = null;
        ContactResource::$header = null;
    }

    protected function products(): Testable
    {
        return Livewire::test(ListProducts::class);
    }

    protected function contacts(): Testable
    {
        return Livewire::test(ListContacts::class);
    }

    /**
     * Opens the modal, uploads and moves to the columns step.
     *
     * @param array<string, mixed> $form
     */
    protected function upload(Testable $page, UploadedFile $file, array $form = [], mixed $action = 'xlsxImport'): Testable
    {
        return $page->mountAction($action)->fillForm(['file' => $file] + $form)->goToWizardStep(2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function mapping(Testable $page): array
    {
        return array_values((array) $page->get('mountedActions.0.data.mapping'));
    }

    /**
     * Changes entries of the mapping by header: `['Price' => ['target' => 'price', 'casts' => [...]]]`.
     *
     * @param array<string, array<string, mixed>> $changes
     */
    protected function remap(Testable $page, array $changes): Testable
    {
        foreach ($this->mapping($page) as $i => $entry) {
            if (!isset($changes[$entry['header']])) {
                continue;
            }

            // The target first: choosing it resets the casts to the target's defaults (as it does for a user).
            $change = $changes[$entry['header']];
            uksort($change, static fn (string $a, string $b): int => ($b === 'target') <=> ($a === 'target'));

            foreach ($change as $key => $value) {
                $page->set("mountedActions.0.data.mapping.{$i}.{$key}", $value);
            }
        }

        return $page;
    }

    protected function runId(Testable $page): string
    {
        return (string) $page->get('mountedActions.0.data.run_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function preview(Testable $page): array
    {
        return (array) (RunStore::meta($this->runId($page))['preview'] ?? []);
    }

    /**
     * From the columns step to done: the dry run, then the real import.
     */
    protected function apply(Testable $page): Testable
    {
        $page->goToWizardStep(3);
        $this->lastRunId = $this->runId($page);

        return $page->callMountedAction();
    }
}
