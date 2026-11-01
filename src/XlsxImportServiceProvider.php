<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class XlsxImportServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-xlsx-import';

    public function configurePackage(Package $package): void
    {
        // Translations live in resources/lang/<locale>/filament-xlsx-import.php and are read as
        // `__('filament-xlsx-import::filament-xlsx-import.<key>')`. Publish tag: `filament-xlsx-import-translations`.
        $package->name(static::$name)
            ->hasTranslations()
            ->hasViews();

        // Add a config file only when the plugin really has options: create config/filament-xlsx-import.php and
        // chain `->hasConfigFile()` here (publish tag `filament-xlsx-import-config`). Prefer fluent setters on the Plugin.
    }
}
