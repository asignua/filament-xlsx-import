<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use BackedEnum;
use Carbon\Carbon;
use DateTimeInterface;
use Throwable;
use UnitEnum;

/**
 * The transforms a user can chain on a mapped column. A step is `['type' => ..., 'arg' => ..., 'arg2' => ..., 'map' => [...]]`
 * (`arg` is the format / separator / pattern / default, `arg2` the second operand, `map` a value table);
 * in code a step may also be written `'decimal:,'` or `['decimal', ',']`.
 */
final class CastPipeline
{
    public const array TYPES = ['trim', 'string', 'upper', 'lower', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'enum', 'json', 'split', 'default', 'regex', 'null_if_empty'];

    public const array DATE_FORMATS = ['d.m.Y', 'm/d/Y', 'Y-m-d', 'd/m/Y', 'd-m-Y'];

    public const array DATETIME_FORMATS = ['d.m.Y H:i', 'm/d/Y H:i', 'Y-m-d H:i:s', 'd/m/Y H:i', 'd.m.Y H:i:s'];

    public const string TRUTHY = '1,true,yes,y,on,так,т,+,да,ja,tak,oui,si,sí';

    public const string FALSY = '0,false,no,n,off,ні,н,-,нет,nein,nie,non';

    /**
     * @return array{type: string, arg: string, arg2: string, map: array<string, string>}
     */
    public static function step(mixed $step): array
    {
        if (is_string($step)) {
            [$type, $arg] = array_pad(explode(':', $step, 2), 2, '');
            $step = ['type' => $type, 'arg' => $arg];
        } elseif (is_array($step) && array_is_list($step)) {
            $step = ['type' => $step[0] ?? '', 'arg' => $step[1] ?? '', 'arg2' => $step[2] ?? ''];
        }

        $step = (array) $step;
        $map = is_array($step['map'] ?? null) ? $step['map'] : [];

        return [
            'type' => is_string($step['type'] ?? null) ? $step['type'] : '',
            'arg' => is_scalar($step['arg'] ?? null) ? (string) $step['arg'] : '',
            'arg2' => is_scalar($step['arg2'] ?? null) ? (string) $step['arg2'] : '',
            'map' => array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $map),
        ];
    }

    /**
     * @param iterable<mixed> $steps
     *
     * @return list<array{type: string, arg: string, arg2: string, map: array<string, string>}>
     */
    public static function steps(iterable $steps): array
    {
        $out = [];

        foreach ($steps as $step) {
            $normalised = self::step($step);

            if (in_array($normalised['type'], self::TYPES, true)) {
                $out[] = $normalised;
            }
        }

        return $out;
    }

    /**
     * @param iterable<mixed> $steps
     *
     * @throws CastException
     */
    public static function apply(mixed $value, iterable $steps): mixed
    {
        foreach (self::steps($steps) as $step) {
            $value = self::run($value, $step);
        }

        return $value;
    }

    /**
     * @param array{type: string, arg: string, arg2: string, map: array<string, string>} $step
     */
    private static function run(mixed $value, array $step): mixed
    {
        $blank = $value === null || $value === '' || (is_string($value) && trim($value) === '');

        return match ($step['type']) {
            'trim' => is_string($value) ? trim(str_replace("\u{A0}", ' ', $value)) : $value,
            'string' => self::text($value),
            'upper' => is_string($value) ? mb_strtoupper($value) : $value,
            'lower' => is_string($value) ? mb_strtolower($value) : $value,
            'null_if_empty' => $blank ? null : $value,
            'default' => $blank ? $step['arg'] : $value,
            'integer' => $blank ? null : self::integer($value),
            'decimal' => $blank ? null : self::decimal($value, $step['arg'], $step['arg2']),
            'boolean' => $blank ? null : self::boolean($value, $step),
            'date' => $blank ? null : self::date($value, $step['arg'], $step['arg2'], false),
            'datetime' => $blank ? null : self::date($value, $step['arg'], $step['arg2'], true),
            'enum' => $blank ? null : self::enum($value, $step),
            'json' => $blank ? null : self::json($value),
            'split' => $blank ? [] : self::split($value, $step['arg']),
            'regex' => self::regex($value, $step['arg'], $step['arg2']),
            default => $value,
        };
    }

    private static function clean(mixed $value): string
    {
        return trim(str_replace(["\u{A0}", "\u{202F}", ' '], '', self::text($value)));
    }

    /**
     * A cell as text: numbers without an exponent, dates as `Y-m-d[ H:i:s]`, booleans as 1/0.
     */
    private static function text(mixed $value): string
    {
        return is_array($value) ? implode(',', array_map(self::text(...), $value)) : SourceFile::stringify($value);
    }

    private static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        $clean = self::clean($value);

        if (!preg_match('/^[+-]?\d+([.,]0+)?$/', $clean)) {
            throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.not_integer', ['value' => self::text($value)]));
        }

        return (int) preg_replace('/[.,]0+$/', '', $clean);
    }

    /**
     * A real number cell (int/float) is a number: no separator is parsed out of it. Text is parsed by what the rule declares:
     * `arg` the decimal separator (`.`, `,`, empty = auto), `arg2` the thousands separator (`.`, `,`, `space`, `none`, empty = not declared).
     * A text where the separator could be either (`1,234`, `12.5` under a comma rule) is refused instead of guessed.
     */
    private static function decimal(mixed $value, string $decimal, string $thousands = ''): string
    {
        if (is_int($value) || is_float($value)) {
            return SourceFile::stringify($value);
        }

        $clean = self::clean($value);
        $decimal = in_array($decimal, ['.', ','], true) ? $decimal : '';
        $thousands = in_array($thousands, ['.', ',', 'space', 'none'], true) ? $thousands : '';
        $wrongSeparator = static fn (string $declared): CastException => new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.wrong_decimal_separator', ['value' => self::text($value), 'separator' => $declared]));
        $ambiguous = new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.ambiguous_decimal', ['value' => self::text($value)]));

        if ($thousands !== '') {
            $group = match ($thousands) {
                'none', 'space' => '',
                default => $thousands,
            };
            $clean = $group !== '' ? str_replace($group, '', $clean) : $clean;
            $clean = str_replace($decimal === ',' ? ',' : '.', '.', $clean);
        } else {
            $dots = substr_count($clean, '.');
            $commas = substr_count($clean, ',');

            if ($dots > 0 && $commas > 0) {
                $last = strrpos($clean, '.') > strrpos($clean, ',') ? '.' : ',';
                $other = $last === '.' ? ',' : '.';

                if ($decimal !== '' && $decimal !== $last) {
                    throw $wrongSeparator($decimal);
                }

                if (substr_count($clean, $last) !== 1) {
                    throw $ambiguous;
                }

                $clean = str_replace($last, '.', str_replace($other, '', $clean));
            } elseif ($dots + $commas > 0) {
                $char = $dots > 0 ? '.' : ',';
                $count = max($dots, $commas);

                if ($count > 1) {
                    // 1.234.567 — a grouping; the same char twice can never be a decimal point.
                    if ($decimal === $char) {
                        throw $ambiguous;
                    }

                    $clean = str_replace($char, '', $clean);
                } elseif ($decimal !== '') {
                    if ($char !== $decimal) {
                        throw $wrongSeparator($decimal);
                    }

                    $clean = str_replace($char, '.', $clean);
                } else {
                    // Auto: 12,5 and 12.50 are decimals; exactly three digits after a lone separator (1,234) could be a thousands mark.
                    if (strlen(substr($clean, (int) strrpos($clean, $char) + 1)) === 3) {
                        throw $ambiguous;
                    }

                    $clean = str_replace($char, '.', $clean);
                }
            }
        }

        if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $clean)) {
            throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.not_decimal', ['value' => self::text($value)]));
        }

        return $clean;
    }

    /**
     * @param array{arg: string, arg2: string} $step
     */
    private static function boolean(mixed $value, array $step): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $word = mb_strtolower(trim(self::text($value)));
        $truthy = array_map('trim', explode(',', mb_strtolower($step['arg'] !== '' ? $step['arg'] : self::TRUTHY)));
        $falsy = array_map('trim', explode(',', mb_strtolower($step['arg2'] !== '' ? $step['arg2'] : self::FALSY)));

        return match (true) {
            in_array($word, $truthy, true) => true,
            in_array($word, $falsy, true) => false,
            default => throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.not_boolean', ['value' => self::text($value)])),
        };
    }

    /**
     * A real date cell, an ISO date or an Excel serial is taken as it is — whatever format the rule declares for TEXT dates.
     */
    private static function date(mixed $value, string $format, string $custom, bool $time): string
    {
        $format = $format === 'custom' ? $custom : $format;

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format($time ? 'Y-m-d H:i:s' : 'Y-m-d');
        }

        $text = trim(self::text($value));

        try {
            if (is_numeric($text) && (float) $text > 20_000 && (float) $text < 80_000) {
                $carbon = Carbon::parse('1899-12-30')->addSeconds((int) round(((float) $text) * 86_400));
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $text) === 1) {
                $carbon = Carbon::parse($text);
            } elseif ($format !== '') {
                $carbon = Carbon::createFromFormat($format, $text);
                $errors = Carbon::getLastErrors();

                if (!$carbon instanceof Carbon || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                    throw new CastException('format');
                }
            } else {
                $carbon = Carbon::parse($text);
            }
        } catch (Throwable) {
            throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.not_date', ['value' => $text, 'format' => $format !== '' ? $format : 'auto']));
        }

        return $carbon->format($time ? 'Y-m-d H:i:s' : 'Y-m-d');
    }

    /**
     * @param array{arg: string, map: array<string, string>} $step
     */
    private static function enum(mixed $value, array $step): string|int
    {
        $text = trim(self::text($value));
        $table = [];

        foreach ($step['map'] as $from => $to) {
            $key = mb_strtolower(trim((string) $from));

            // An empty «imported as» never cancels a filled one that looks the same once the case is ignored.
            if ($to !== '' || !array_key_exists($key, $table)) {
                $table[$key] = $to;
            }
        }

        $key = mb_strtolower($text);

        if (array_key_exists($key, $table) && $table[$key] !== '') {
            $resolved = self::enumValue($step['arg'], $table[$key]);

            return $resolved ?? $table[$key];
        }

        $resolved = self::enumValue($step['arg'], $text);

        if ($resolved !== null) {
            return $resolved;
        }

        if ($step['arg'] === '' && $step['map'] === []) {
            return $text;
        }

        throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.unknown_value', ['value' => $text]));
    }

    /**
     * The cases of an enum as value => label (`HasLabel`/`getLabel()` when it has one, else the case name).
     *
     * @return array<string, string>
     */
    public static function enumChoices(string $class): array
    {
        if ($class === '' || !enum_exists($class)) {
            return [];
        }

        $choices = [];

        foreach ($class::cases() as $case) {
            $label = method_exists($case, 'getLabel') ? $case->getLabel() : null;
            $choices[(string) ($case instanceof BackedEnum ? $case->value : $case->name)] = is_string($label) ? $label : (is_object($label) && method_exists($label, '__toString') ? (string) $label : $case->name);
        }

        return $choices;
    }

    public static function enumValue(string $class, string $text): string|int|null
    {
        if ($class === '' || !enum_exists($class)) {
            return null;
        }

        $needle = mb_strtolower(trim($text));

        /** @var list<UnitEnum> $cases */
        $cases = $class::cases();

        foreach ($cases as $case) {
            $labels = [mb_strtolower($case->name)];

            if ($case instanceof BackedEnum) {
                $labels[] = mb_strtolower((string) $case->value);
            }

            if (method_exists($case, 'getLabel')) {
                $label = $case->getLabel();
                $labels[] = mb_strtolower(is_string($label) ? $label : (string) ($label?->__toString() ?? ''));
            }

            if (in_array($needle, $labels, true)) {
                return $case instanceof BackedEnum ? $case->value : $case->name;
            }
        }

        return null;
    }

    private static function json(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.not_json', ['value' => mb_strimwidth((string) $value, 0, 40, '…')]));
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $value, string $separator): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $separator = $separator === '' ? ',' : $separator;

        return array_values(array_filter(array_map('trim', explode($separator, (string) $value)), static fn (string $part): bool => $part !== ''));
    }

    private static function regex(mixed $value, string $pattern, string $replacement): mixed
    {
        if (!is_string($value) || $pattern === '') {
            return $value;
        }

        $delimited = '~'.str_replace('~', '\~', $pattern).'~u';
        $result = @preg_replace($delimited, $replacement, $value);

        if ($result === null) {
            throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.bad_pattern'));
        }

        return $result;
    }

    /**
     * A cast pipeline that suits a model cast / column type, to pre-select in the UI.
     *
     * @return list<array{type: string, arg: string, arg2: string, map: array<string, string>}>
     */
    public static function suggest(?string $modelCast, ?string $columnType): array
    {
        $cast = $modelCast !== null ? strtolower($modelCast) : null;

        if ($modelCast !== null && enum_exists($modelCast)) {
            return self::steps([['type' => 'enum', 'arg' => $modelCast]]);
        }

        $kind = match (true) {
            $cast !== null && in_array($cast, ['int', 'integer'], true) => 'integer',
            $cast !== null && (in_array($cast, ['float', 'double', 'real'], true) || str_starts_with($cast, 'decimal')) => 'decimal',
            $cast !== null && in_array($cast, ['bool', 'boolean'], true) => 'boolean',
            $cast !== null && in_array($cast, ['date', 'immutable_date'], true) => 'date',
            $cast !== null && (str_contains($cast, 'datetime') || $cast === 'timestamp') => 'datetime',
            $cast !== null && preg_match('/array|json|object|collection/', $cast) === 1 => 'json',
            default => $columnType,
        };

        return match ($kind) {
            'integer' => self::steps(['trim', 'integer']),
            'decimal' => self::steps(['trim', 'decimal']),
            'boolean' => self::steps(['trim', 'boolean']),
            'date' => self::steps(['trim', 'date']),
            'datetime' => self::steps(['trim', 'datetime']),
            'json' => self::steps(['json']),
            'string' => self::steps(['trim']),
            default => [],
        };
    }
}
