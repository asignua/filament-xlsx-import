<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Commands;

use Asignua\FilamentXlsxImport\Support\RunStore;
use Illuminate\Console\Command;

/**
 * Deletes import runs (the upload, the preview and the failed-rows report) older than the
 * retention. Report links expire at the same moment, so nothing points at a deleted file.
 */
class PruneCommand extends Command
{
    protected $signature = 'xlsx-import:prune {--hours= : Keep runs this many hours instead of filament-xlsx-import.storage.retention_hours}';

    protected $description = 'Delete XLSX import runs and failed-rows reports older than the retention period';

    public function handle(): int
    {
        $option = $this->option('hours');
        $hours = is_numeric($option) ? (int) $option : RunStore::retentionHours();

        if ($hours <= 0) {
            $this->components->info('Retention is off (hours = 0), nothing deleted.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours)->getTimestamp();
        $disk = RunStore::disk();
        $deleted = 0;

        foreach ($disk->directories(RunStore::directory()) as $directory) {
            $newest = 0;

            foreach ($disk->allFiles($directory) as $file) {
                $newest = max($newest, $disk->lastModified($file));
            }

            if ($newest < $cutoff) {
                $disk->deleteDirectory($directory);
                $deleted++;
            }
        }

        $this->components->info("Deleted {$deleted} import run(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}
