<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use BackedEnum;
use Carbon\Carbon;
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
            'string' => is_array($value) ? implode(',', $value) : (string) ($value ?? ''),
            'upper' => is_string($value) ? mb_strtoupper($value) : $value,
            'lower' => is_string($value) ? mb_strtolower($value) : $value,
            'null_if_empty' => $blank ? null : $value,
            'default' => $blank ? $step['arg'] : $value,
            'integer' => $blank ? null : self::integer($value),
            'decimal' => $blank ? null : self::decimal($value, $step['arg']),
            'boolean' => $blank ? null : self::boolean($value, $step),
            'date' => $blank ? null : self::date($value, $step['arg'], false),
            'datetime' => $blank ? null : self::date($value, $step['arg'], true),
            'enum' => $blank ? null : self::enum($value, $step),
            'json' => $blank ? null : self::json($value),
            'split' => $blank ? [] : self::split($value, $step['arg']),
            'regex' => self::regex($value, $step['arg'], $step['arg2']),
            default => $value,
        };
    }

    private static function clean(mixed $value): string
    {
        return trim(str_replace(["\u{A0}", "\u{202F}", ' '], '', (string) $value));
    }

    private static function integer(mixed $value): int
    {
        $clean = self::clean($value);

        if (!preg_match('/^[+-]?\d+([.,]0+)?$/', $clean)) {
            throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.not_integer', ['value' => (string) $value]));
        }

        return (int) preg_replace('/[.,]0+$/', '', $clean);
    }

    private static function decimal(mixed $value, string $separator): string
    {
        $separator = $separator === ',' ? ',' : '.';
        $clean = self::clean($value);
        $thousands = $separator === ',' ? '.' : ',';
        $clean = str_replace($thousands, '', $clean);
        $clean = str_replace($separator, '.', $clean);

        if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $clean)) {
            throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.not_decimal', ['value' => (string) $value]));
        }

        return $clean;
    }

    /**
     * @param array{arg: string, arg2: string} $step
     */
    private static function boolean(mixed $value, array $step): bool
    {
        $word = mb_strtolower(trim((string) $value));
        $truthy = array_map('trim', explode(',', mb_strtolower($step['arg'] !== '' ? $step['arg'] : self::TRUTHY)));
        $falsy = array_map('trim', explode(',', mb_strtolower($step['arg2'] !== '' ? $step['arg2'] : self::FALSY)));

        return match (true) {
            in_array($word, $truthy, true) => true,
            in_array($word, $falsy, true) => false,
            default => throw new CastException(__('filament-xlsx-import::filament-xlsx-import.cast.not_boolean', ['value' => (string) $value])),
        };
    }

    private static function date(mixed $value, string $format, bool $time): string
    {
        $text = trim((string) $value);

        try {
            if ($format !== '') {
                $carbon = Carbon::createFromFormat($format, $text);
                $errors = Carbon::getLastErrors();

                if (!$carbon instanceof Carbon || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                    throw new CastException('format');
                }
            } else {
                $carbon = is_numeric($text) && (float) $text > 20_000 && (float) $text < 80_000
                    ? Carbon::parse('1899-12-30')->addDays((int) $text)
                    : Carbon::parse($text);
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
        $text = trim((string) $value);
        $table = [];

        foreach ($step['map'] as $from => $to) {
            $table[mb_strtolower(trim((string) $from))] = $to;
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
            $cast !== null && in_array($cast, ['array', 'json', 'object', 'collection'], true) => 'json',
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
