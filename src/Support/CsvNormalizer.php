<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use RuntimeException;

/**
 * Turns whatever CSV a user exports (UTF-8 with or without BOM, UTF-16/32, Windows-1251/1252) into
 * plain UTF-8 and sniffs the delimiter, so the reader downstream only ever sees one shape.
 */
final class CsvNormalizer
{
    /**
     * @return array{encoding: string, delimiter: string}
     */
    public static function normalize(string $from, string $to, ?string $encoding = null, ?string $delimiter = null): array
    {
        $head = (string) file_get_contents($from, false, null, 0, 65_536);
        [$detected, $skip] = self::detect($head);
        $encoding = $encoding !== null && $encoding !== '' ? $encoding : $detected;

        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot read or write the CSV file.');
        }

        try {
            if (strcasecmp($encoding, 'UTF-8') === 0) {
                $skip === 3 && fread($in, 3);
                stream_copy_to_stream($in, $out);
            } else {
                // The "UTF-16"/"UTF-32" converters read their own BOM; the BOM-less variants must not skip bytes.
                stream_filter_append($in, 'convert.iconv.'.$encoding.'/UTF-8//IGNORE', STREAM_FILTER_READ);
                stream_copy_to_stream($in, $out);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        $sample = (string) file_get_contents($to, false, null, 0, 8_192);

        return ['encoding' => strtoupper($encoding), 'delimiter' => $delimiter !== null && $delimiter !== '' ? $delimiter : self::sniff($sample)];
    }

    /**
     * @return array{0: string, 1: int} encoding and the length of the BOM to skip
     */
    public static function detect(string $head): array
    {
        return match (true) {
            str_starts_with($head, "\xEF\xBB\xBF") => ['UTF-8', 3],
            str_starts_with($head, "\xFF\xFE\x00\x00"), str_starts_with($head, "\x00\x00\xFE\xFF") => ['UTF-32', 0],
            str_starts_with($head, "\xFF\xFE"), str_starts_with($head, "\xFE\xFF") => ['UTF-16', 0],
            default => [self::detectWithoutBom($head), 0],
        };
    }

    private static function detectWithoutBom(string $head): string
    {
        // A sample cut in the middle of a character is still valid UTF-8 up to its last bytes.
        $probe = rtrim($head, "\x80..\xBF");
        $probe = preg_replace('/[\xC0-\xFF]$/', '', $probe) ?? $probe;

        if (mb_check_encoding($probe, 'UTF-8')) {
            return 'UTF-8';
        }

        $candidates = (array) config('filament-xlsx-import.csv.fallback_encodings', ['Windows-1251', 'Windows-1252']);
        $best = (string) ($candidates[0] ?? 'Windows-1252');
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            $decoded = @iconv((string) $candidate, 'UTF-8//IGNORE', $head);

            if ($decoded === false) {
                continue;
            }

            // Right guess = letters of ONE script, and Latin text that is mostly ASCII. A wrong guess turns
            // Cyrillic bytes into a wall of accented Latin («Àðòèêóë») or Latin text into Cyrillic noise.
            $cyrillic = (int) preg_match_all('/\p{Cyrillic}/u', $decoded);
            $ascii = (int) preg_match_all('/[A-Za-z]/', $decoded);
            $accented = (int) preg_match_all('/[^\x00-\x7F]/u', (string) preg_replace('/[^\p{Latin}]/u', '', $decoded));
            $symbols = (int) preg_match_all('/[^\p{L}\p{N}\p{P}\p{Z}\p{C}]/u', $decoded);
            $score = ($ascii + $accented >= $cyrillic ? $ascii - 2 * $accented - 3 * $cyrillic : $cyrillic - 3 * ($ascii + $accented)) - 3 * $symbols;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $candidate;
            }
        }

        return $best;
    }

    public static function sniff(string $sample): string
    {
        $lines = array_slice(preg_split('/\R/', $sample) ?: [], 0, 6);
        $best = ',';
        $bestScore = 0;

        foreach ((array) config('filament-xlsx-import.csv.delimiters', [',', ';', "\t", '|']) as $delimiter) {
            $delimiter = (string) $delimiter;
            $counts = [];

            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }

                $counts[] = count(str_getcsv($line, $delimiter, '"', '')) - 1;
            }

            $first = $counts[0] ?? 0;

            if ($first < 1) {
                continue;
            }

            $consistent = count(array_filter($counts, static fn (int $c): bool => $c === $first));
            $score = $first * $consistent;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }
}
