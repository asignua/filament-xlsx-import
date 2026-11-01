<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport;

use Asignua\FilamentXlsxImport\Commands\PruneCommand;
use Asignua\FilamentXlsxImport\Http\ReportDownloadController;
use Asignua\FilamentXlsxImport\Support\RunStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class XlsxImportServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-xlsx-import';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('create_xlsx_import_presets_table')
            ->hasCommand(PruneCommand::class);
    }

    public function packageBooted(): void
    {
        if ((bool) config('filament-xlsx-import.report.register_route', true)) {
            // No `signed` middleware: the controller checks the signature itself (a forged link gets a 403,
            // an expired one a notification back in the panel).
            Route::middleware((array) config('filament-xlsx-import.report.middleware', ['web']))
                ->get((string) config('filament-xlsx-import.report.path', 'filament-xlsx-import/report/{id}'), ReportDownloadController::class)
                ->where('id', RunStore::ID_PATTERN)
                ->name(RunStore::ROUTE);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (!(bool) config('filament-xlsx-import.schedule.enabled', true)) {
                return;
            }

            $time = config('filament-xlsx-import.schedule.time', '03:55');

            $schedule->command('xlsx-import:prune')->dailyAt(is_string($time) ? $time : '03:55');
        });
    }
}
