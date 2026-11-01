<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Support\CastPipeline;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Illuminate\Support\Arr;
use Symfony\Component\Finder\Finder;

class TranslationKeysTest extends TestCase
{
    public function test_every_key_the_code_uses_exists_in_every_locale(): void
    {
        $used = [];

        foreach ((new Finder)->files()->in([__DIR__.'/../../src', __DIR__.'/../../resources/views'])->name('*.php') as $file) {
            $code = $file->getContents();
            preg_match_all('/filament-xlsx-import::filament-xlsx-import\\.([a-z_0-9.]+)/', $code, $a);
            preg_match_all("/\\\$t\\('([a-z_0-9.]+)'/", $code, $b);
            $used = [...$used, ...$a[1], ...$b[1]];
        }

        foreach (CastPipeline::TYPES as $type) {
            foreach (['cast_type', 'cast_arg', 'cast_arg2', 'cast_hint'] as $group) {
                $used[] = "{$group}.{$type}";
            }
        }

        foreach (['en', 'uk'] as $locale) {
            $have = Arr::dot(require __DIR__.'/../../resources/lang/'.$locale.'/filament-xlsx-import.php');

            foreach (array_unique($used) as $key) {
                if (str_ends_with($key, '.')) {
                    continue; // a prefix of a computed key, covered by the type loop above
                }

                $this->assertArrayHasKey($key, $have, "[{$locale}] is missing «{$key}».");
            }
        }
    }

    public function test_the_ukrainian_interface_is_used_when_the_app_is_ukrainian(): void
    {
        app()->setLocale('uk');

        $this->assertSame('Імпорт', __('filament-xlsx-import::filament-xlsx-import.import'));
    }
}
