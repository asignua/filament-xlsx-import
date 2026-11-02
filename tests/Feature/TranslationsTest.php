<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Tests\TestCase;
use Illuminate\Support\Arr;

class TranslationsTest extends TestCase
{
    private const array LOCALES = ['en', 'de', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr', 'uk'];

    public function test_every_locale_has_the_same_keys_as_english(): void
    {
        $english = $this->keys('en');

        $this->assertNotEmpty($english);

        foreach (self::LOCALES as $locale) {
            $this->assertSame($english, $this->keys($locale), "Locale [{$locale}] is out of step with [en].");
        }
    }

    public function test_every_string_keeps_the_placeholders_of_english(): void
    {
        $english = $this->placeholders('en');

        $this->assertNotEmpty($english);

        foreach (self::LOCALES as $locale) {
            $this->assertSame($english, $this->placeholders($locale), "Locale [{$locale}] changes a :placeholder of [en].");
        }
    }

    /**
     * @return array<string, list<string>> key => sorted placeholders
     */
    private function placeholders(string $locale): array
    {
        $path = __DIR__.'/../../resources/lang/'.$locale.'/filament-xlsx-import.php';

        $this->assertFileExists($path);

        $found = [];

        foreach (Arr::dot(require $path) as $key => $text) {
            preg_match_all('/:[a-z_]+/i', (string) $text, $m);
            $names = array_unique($m[0]);
            sort($names);
            $found[$key] = $names;
        }

        ksort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private function keys(string $locale): array
    {
        $path = __DIR__.'/../../resources/lang/'.$locale.'/filament-xlsx-import.php';

        $this->assertFileExists($path);

        $keys = array_keys(Arr::dot(require $path));
        sort($keys);

        return $keys;
    }
}
