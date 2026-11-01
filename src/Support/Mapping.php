<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Data\Definition;
use Asignua\FilamentXlsxImport\Data\TargetField;

/**
 * The mapping of a file to a Definition: one entry per file column.
 *
 * Entry: `header`, `target` (key or null = skip), `casts` (CastPipeline steps), `mode` (lookup|create),
 * `many` (add|replace), `samples`, and — what makes the model's own rules visible in the UI —
 * `rule`: the target/casts/mode/many the model rules would have chosen, or null.
 */
final class Mapping
{
    public static function normalize(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
    }

    /**
     * @param list<string>                    $headers
     * @param array<string, list<string>>     $samples
     * @param array<string, list<string>>     $distinct
     * @param list<array<string, mixed>>|null $preset   saved entries, matched by header
     *
     * @return list<array<string, mixed>>
     */
    public static function initial(Definition $definition, array $headers, array $samples = [], array $distinct = [], ?array $preset = null): array
    {
        $auto = self::autoMatch($definition, $headers);
        $byHeader = [];

        foreach ($preset ?? [] as $entry) {
            if (is_string($entry['header'] ?? null)) {
                $byHeader[self::normalize($entry['header'])] = $entry;
            }
        }

        $used = [];
        $entries = [];

        foreach ($headers as $index => $header) {
            $saved = $byHeader[self::normalize($header)] ?? null;
            $ruleTarget = $auto[$index] ?? null;
            $rule = $ruleTarget !== null && $definition->target($ruleTarget)?->fromRules
                ? self::defaults($definition->targets[$ruleTarget], $distinct[$header] ?? [])
                : null;

            if ($saved !== null && (($saved['target'] ?? null) === null || $definition->target((string) $saved['target']) !== null)) {
                $target = $saved['target'] ?? null;
                $entry = [
                    'target' => $target,
                    'casts' => self::forForm(self::fillEnumTable(CastPipeline::steps((array) ($saved['casts'] ?? [])), $distinct[$header] ?? [])),
                    'mode' => in_array($saved['mode'] ?? null, ['lookup', 'create'], true) ? $saved['mode'] : 'lookup',
                    'many' => in_array($saved['many'] ?? null, ['add', 'replace'], true) ? $saved['many'] : 'add',
                ];
                $origin = 'preset';
            } elseif ($ruleTarget !== null) {
                $entry = self::defaults($definition->targets[$ruleTarget], $distinct[$header] ?? []);
                $origin = $rule !== null ? 'rules' : 'auto';
            } else {
                $entry = ['target' => null, 'casts' => [], 'mode' => 'lookup', 'many' => 'add'];
                $origin = 'none';
            }

            if (is_string($entry['target'])) {
                if (isset($used[$entry['target']])) {
                    $entry = ['target' => null, 'casts' => [], 'mode' => 'lookup', 'many' => 'add'];
                    $origin = 'none';
                } else {
                    $used[$entry['target']] = true;
                }
            }

            $entries[] = $entry + [
                'header' => $header,
                'samples' => $samples[$header] ?? [],
                'distinct' => $distinct[$header] ?? [],
                'rule' => $rule,
                'origin' => $origin,
            ];
        }

        return $entries;
    }

    /**
     * The target's default pipeline, with the enum value table filled from what the file contains.
     *
     * @param list<string> $distinct
     *
     * @return array{target: string, casts: list<array<string, mixed>>, mode: string, many: string}
     */
    public static function defaults(TargetField $target, array $distinct = []): array
    {
        return [
            'target' => $target->key,
            'casts' => self::forForm(self::fillEnumTable($target->casts, $distinct)),
            'mode' => $target->mode,
            'many' => $target->many,
        ];
    }

    /**
     * The value table as a list of `{from, to}` rows, the shape the form edits.
     *
     * @param list<array{type: string, arg: string, arg2: string, map: array<string, string>}> $casts
     *
     * @return list<array<string, mixed>>
     */
    public static function forForm(array $casts): array
    {
        return array_map(static function (array $cast): array {
            $rows = [];

            foreach ($cast['map'] as $from => $to) {
                $rows[] = ['from' => (string) $from, 'to' => $to];
            }

            return ['map' => $rows] + $cast;
        }, $casts);
    }

    /**
     * @param list<array{type: string, arg: string, arg2: string, map: array<string, string>}> $casts
     * @param list<string>                                                                     $distinct
     *
     * @return list<array{type: string, arg: string, arg2: string, map: array<string, string>}>
     */
    public static function fillEnumTable(array $casts, array $distinct): array
    {
        foreach ($casts as $i => $step) {
            if ($step['type'] !== 'enum') {
                continue;
            }

            foreach ($distinct as $value) {
                if (!array_key_exists($value, $step['map'])) {
                    $step['map'][$value] = (string) (CastPipeline::enumValue($step['arg'], $value) ?? '');
                }
            }

            $casts[$i] = $step;
        }

        return $casts;
    }

    /**
     * @param list<string> $headers
     *
     * @return array<int, string> header index => target key
     */
    private static function autoMatch(Definition $definition, array $headers): array
    {
        $builtin = (array) (require __DIR__.'/../../resources/aliases.php');
        $builtin = array_replace($builtin, (array) config('filament-xlsx-import.aliases', []));

        $tiers = [[], [], [], []];

        foreach ($definition->targets as $key => $target) {
            $tiers[0][$key] = [self::normalize($key), self::normalize($target->label)];
            $tiers[1][$key] = array_map(self::normalize(...), $target->aliases);

            if (!$target->isRelation()) {
                $tiers[2][$key] = array_map(self::normalize(...), array_map('strval', (array) ($builtin[$key] ?? [])));
            }
        }

        $result = [];
        $taken = [];

        foreach ([0, 1, 2] as $tier) {
            foreach ($headers as $index => $header) {
                if (isset($result[$index])) {
                    continue;
                }

                $needle = self::normalize($header);

                if ($needle === '') {
                    continue;
                }

                foreach ($tiers[$tier] as $key => $names) {
                    if (!isset($taken[$key]) && in_array($needle, $names, true)) {
                        $result[$index] = $key;
                        $taken[$key] = true;

                        break;
                    }
                }
            }
        }

        // «Назва товару» for an alias «назва»: a header that starts with a known alias or name (4+ letters).
        foreach ($headers as $index => $header) {
            $needle = self::normalize($header);

            if (isset($result[$index]) || $needle === '') {
                continue;
            }

            foreach ([0, 1, 2] as $tier) {
                foreach ($tiers[$tier] as $key => $names) {
                    foreach ($names as $name) {
                        if (!isset($taken[$key]) && mb_strlen($name) >= 4 && str_starts_with($needle, $name)) {
                            $result[$index] = $key;
                            $taken[$key] = true;

                            continue 3;
                        }
                    }
                }
            }
        }

        // Last resort: a near miss («Emial», «Custmer name»).
        foreach ($headers as $index => $header) {
            $needle = self::normalize($header);

            if (isset($result[$index]) || mb_strlen($needle) < 5) {
                continue;
            }

            $best = null;
            $bestPercent = 0.0;

            foreach ($definition->targets as $key => $target) {
                if (isset($taken[$key])) {
                    continue;
                }

                foreach ([self::normalize($key), self::normalize($target->label)] as $name) {
                    similar_text($needle, $name, $percent);

                    if ($percent >= 80 && $percent > $bestPercent) {
                        $best = $key;
                        $bestPercent = $percent;
                    }
                }
            }

            if ($best !== null) {
                $result[$index] = $best;
                $taken[$best] = true;
            }
        }

        return $result;
    }

    /**
     * What the wizard form holds → clean entries (the form carries view-only keys and string maps).
     *
     * @param array<mixed> $state
     *
     * @return list<array{header: string, target: string|null, casts: list<array{type: string, arg: string, arg2: string, map: array<string, string>}>, mode: string, many: string}>
     */
    public static function fromState(array $state): array
    {
        $entries = [];

        foreach ($state as $item) {
            if (!is_array($item) || !is_string($item['header'] ?? null)) {
                continue;
            }

            $casts = [];

            foreach ((array) ($item['casts'] ?? []) as $cast) {
                $cast = (array) $cast;
                $cast['map'] = self::mapFromState($cast['map'] ?? []);
                $casts[] = $cast;
            }

            $target = $item['target'] ?? null;

            $entries[] = [
                'header' => $item['header'],
                'target' => is_string($target) && $target !== '' ? $target : null,
                'casts' => CastPipeline::steps($casts),
                'mode' => ($item['mode'] ?? null) === 'create' ? 'create' : 'lookup',
                'many' => ($item['many'] ?? null) === 'replace' ? 'replace' : 'add',
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private static function mapFromState(mixed $map): array
    {
        $out = [];

        foreach ((array) $map as $from => $to) {
            if (is_array($to) && isset($to['from'])) {
                $out[(string) $to['from']] = (string) ($to['to'] ?? '');
            } elseif (is_scalar($to) || $to === null) {
                $out[(string) $from] = (string) ($to ?? '');
            }
        }

        return $out;
    }
}
