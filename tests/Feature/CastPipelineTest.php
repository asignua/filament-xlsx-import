<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Support\CastException;
use Asignua\FilamentXlsxImport\Support\CastPipeline;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Enums\ProductStatus;

class CastPipelineTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<mixed>, mixed}>
     */
    public static function casts(): iterable
    {
        yield 'trim' => ["  a b \u{A0}", ['trim'], 'a b'];
        yield 'upper' => ['abc', ['upper'], 'ABC'];
        yield 'lower cyrillic' => ['ЛЬВІВ', ['lower'], 'львів'];
        yield 'chain' => [' Abc ', ['trim', 'upper'], 'ABC'];
        yield 'integer' => [' 1 234 ', ['integer'], 1234];
        yield 'integer from 5.0' => ['5.0', ['integer'], 5];
        yield 'decimal dot' => ['1,234.50', ['decimal'], '1234.50'];
        yield 'decimal comma' => ['1.234,50', ['decimal:,'], '1234.50'];
        yield 'decimal nbsp' => ["1\u{A0}234,5", [['decimal', ',']], '1234.5'];
        yield 'boolean so' => ['Так', ['boolean'], true];
        yield 'boolean no' => ['ні', ['boolean'], false];
        yield 'boolean en' => ['YES', ['boolean'], true];
        yield 'boolean custom words' => ['ja', [['type' => 'boolean', 'arg' => 'ja', 'arg2' => 'nee']], true];
        yield 'date format' => ['31.01.2026', [['type' => 'date', 'arg' => 'd.m.Y']], '2026-01-31'];
        yield 'datetime format' => ['31.01.2026 09:30', [['type' => 'datetime', 'arg' => 'd.m.Y H:i']], '2026-01-31 09:30:00'];
        yield 'date auto' => ['2026-01-31', ['date'], '2026-01-31'];
        yield 'excel serial date' => ['46053', ['date'], '2026-01-31'];
        yield 'enum map' => ['Активний', [['type' => 'enum', 'arg' => ProductStatus::class, 'map' => ['Активний' => 'active']]], 'active'];
        yield 'enum by label' => ['archived', [['type' => 'enum', 'arg' => ProductStatus::class]], 'archived'];
        yield 'enum by case name' => ['Draft', [['type' => 'enum', 'arg' => ProductStatus::class]], 'draft'];
        yield 'json' => ['{"a":1}', ['json'], ['a' => 1]];
        yield 'split' => ['a; b ;;c', ['split:;'], ['a', 'b', 'c']];
        yield 'split default comma' => ['a,b', ['split'], ['a', 'b']];
        yield 'default when blank' => ['  ', [['type' => 'default', 'arg' => '0']], '0'];
        yield 'default keeps value' => ['5', [['type' => 'default', 'arg' => '0']], '5'];
        yield 'regex' => ['+38 (067) 123-45-67', [['type' => 'regex', 'arg' => '[^\d+]', 'arg2' => '']], '+380671234567'];
        yield 'blank number is null' => ['', ['integer'], null];
        yield 'null if empty' => ['', ['null_if_empty'], null];
    }

    /**
     * @param list<mixed> $steps
     */
    #[DataProvider('casts')]
    public function test_cast(mixed $in, array $steps, mixed $expected): void
    {
        $this->assertSame($expected, CastPipeline::apply($in, $steps));
    }

    /**
     * @return iterable<string, array{mixed, list<mixed>}>
     */
    public static function bad(): iterable
    {
        yield 'integer' => ['abc', ['integer']];
        yield 'decimal' => ['1x', ['decimal']];
        yield 'boolean' => ['maybe', ['boolean']];
        yield 'date' => ['not a date', ['date']];
        yield 'date wrong format' => ['31/01/2026', [['type' => 'date', 'arg' => 'd.m.Y']]];
        yield 'json' => ['{oops', ['json']];
        yield 'enum' => ['zzz', [['type' => 'enum', 'arg' => ProductStatus::class]]];
    }

    /**
     * @param list<mixed> $steps
     */
    #[DataProvider('bad')]
    public function test_a_value_that_does_not_fit_fails_with_a_message(mixed $in, array $steps): void
    {
        $this->expectException(CastException::class);

        CastPipeline::apply($in, $steps);
    }

    public function test_unknown_steps_are_dropped(): void
    {
        $this->assertSame([], CastPipeline::steps(['bogus', ['type' => 'nope']]));
    }

    public function test_model_casts_preselect_a_sensible_pipeline(): void
    {
        $types = fn (?string $cast, ?string $column): array => array_column(CastPipeline::suggest($cast, $column), 'type');

        $this->assertSame(['trim', 'decimal'], $types('decimal:2', null));
        $this->assertSame(['trim', 'integer'], $types(null, 'integer'));
        $this->assertSame(['trim', 'boolean'], $types('boolean', null));
        $this->assertSame(['trim', 'date'], $types('date', null));
        $this->assertSame(['trim', 'datetime'], $types('datetime', null));
        $this->assertSame(['json'], $types('array', null));
        $this->assertSame(['enum'], $types(ProductStatus::class, null));
        $this->assertSame(ProductStatus::class, CastPipeline::suggest(ProductStatus::class, null)[0]['arg']);
    }
}
