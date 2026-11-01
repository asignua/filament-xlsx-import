<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Jobs;

use Asignua\FilamentXlsxImport\Support\ImportNotifier;
use Asignua\FilamentXlsxImport\Support\ImportRunner;
use Asignua\FilamentXlsxImport\Support\QueuedImports;
use Asignua\FilamentXlsxImport\Support\RunContext;
use Asignua\FilamentXlsxImport\Support\RunStore;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports one uploaded file in the background as the person who clicked it: rows are read as a
 * stream and committed every `chunk_size`, progress is written next to the file, and the user is
 * told through a database notification. One try — a retry would import the first chunks twice.
 */
final class ImportXlsxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param array<string, mixed> $spec
     */
    public function __construct(public readonly array $spec)
    {
        $this->timeout = QueuedImports::timeoutFor(null);
    }

    public function handle(): void
    {
        $id = (string) $this->spec['run_id'];

        try {
            RunContext::within($this->spec, function (?Authenticatable $user) use ($id): void {
                if ($user === null) {
                    RunStore::put($id, ['status' => 'dropped']);
                    Log::warning("filament-xlsx-import: import {$id} dropped: its user or panel is gone, or the user can no longer open the panel.");

                    return;
                }

                RunStore::put($id, ['status' => 'running', 'progress' => ['processed' => 0, 'total' => (int) (RunStore::meta($id)['rows'] ?? 0)]]);

                $result = ImportRunner::run(
                    $this->spec,
                    false,
                    $this->saveUsing(),
                    static fn (int $processed, int $total) => RunStore::put($id, ['progress' => ['processed' => $processed, 'total' => $total]]),
                );

                RunStore::put($id, ['status' => 'done', 'result' => $result]);
                RunStore::dropSource($id);

                $root = $this->spec['root'] ?? null;
                ImportNotifier::toDatabase(ImportNotifier::done($id, $result, is_string($root) ? $root : null), $user, "import {$id} is done");
            });
        } finally {
            QueuedImports::release($this->spec, $id);
        }
    }

    public function failed(Throwable $exception): void
    {
        $id = (string) $this->spec['run_id'];
        QueuedImports::release($this->spec, $id);
        RunStore::put($id, ['status' => 'failed']);

        RunContext::within($this->spec, function (?Authenticatable $user) use ($id): void {
            if ($user !== null) {
                ImportNotifier::toDatabase(ImportNotifier::failed(), $user, "import {$id} failed");
            }
        });
    }

    private function saveUsing(): ?Closure
    {
        $callable = $this->spec['save_using'] ?? null;

        if (!is_string($callable) || $callable === '') {
            return null;
        }

        $handler = str_contains($callable, '@') ? [app(explode('@', $callable)[0]), explode('@', $callable)[1]] : app($callable);

        return is_callable($handler) ? $handler(...) : null;
    }
}
