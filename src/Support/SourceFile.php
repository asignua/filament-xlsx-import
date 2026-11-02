<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use DateTimeInterface;
use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * One uploaded workbook (.xlsx / .ods) or an already normalised UTF-8 .csv, read as a stream.
 * Cells come out as strings: dates as `Y-m-d[ H:i:s]`, booleans as 1/0, numbers without an
 * exponent — the same shape a CSV row has, which is what Filament importers expect.
 */
final class SourceFile
{
    public const array EXTENSIONS = ['xlsx', 'ods', 'csv', 'txt', 'tsv'];

    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly string $delimiter = ',',
    ) {}

    public static function extensionOf(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, ['txt', 'tsv'], true) ? 'csv' : $extension;
    }

    public function isCsv(): bool
    {
        return $this->extension === 'csv';
    }

    /**
     * @return list<string>
     */
    public function sheets(): array
    {
        if ($this->isCsv()) {
            return [basename($this->path)];
        }

        $reader = $this->reader();
        $names = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }
        } finally {
            $reader->close();
        }

        return $names;
    }

    /**
     * Every row of a sheet as [source row number => cells], empty rows left out.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(int $sheetIndex = 0): Generator
    {
        $reader = $this->reader();

        try {
            $index = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                if ($index++ !== $sheetIndex) {
                    continue;
                }

                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    // A hostile or broken sheet can be thousands of columns wide: read one more than the limit, enough to notice.
                    $cells = array_map(self::stringify(...), array_slice($row->toArray(), 0, self::maxColumns() + 1));

                    if (array_filter($cells, static fn (string $cell): bool => trim($cell) !== '') === []) {
                        continue;
                    }

                    yield $number => array_values($cells); // @phpstan-ignore arrayValues.list
                }

                return;
            }

            throw new RuntimeException("The workbook has no sheet #{$sheetIndex}.");
        } finally {
            $reader->close();
        }
    }

    /**
     * The header row, made unique ("Name", "Name (2)") with blanks named by their column letter, then the data rows keyed by it.
     *
     * @return Generator<int, array<string, string>> row number => [header => value]
     */
    public function table(int $sheetIndex = 0): Generator
    {
        $headers = null;

        foreach ($this->rows($sheetIndex) as $number => $cells) {
            if ($headers === null) {
                $headers = self::uniqueHeaders($cells);

                continue;
            }

            $row = [];

            foreach ($headers as $position => $header) {
                $row[$header] = $cells[$position] ?? '';
            }

            yield $number => $row;
        }
    }

    /**
     * @param list<string> $cells
     *
     * @return list<string>
     */
    public static function uniqueHeaders(array $cells): array
    {
        $seen = [];
        $headers = [];

        foreach ($cells as $position => $cell) {
            $header = trim($cell) !== '' ? trim($cell) : __('filament-xlsx-import::filament-xlsx-import.column_letter', ['letter' => self::letter($position)]);
            $base = $header;

            for ($n = 2; isset($seen[mb_strtolower($header)]); $n++) {
                $header = "{$base} ({$n})";
            }

            $seen[mb_strtolower($header)] = true;
            $headers[] = $header;
        }

        return $headers;
    }

    public static function letter(int $position): string
    {
        $letter = '';

        for ($n = $position + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }

    /**
     * One pass over the sheet: headers, row count, a few sample values and the distinct values
     * (for the enum/boolean value tables) of every column.
     *
     * @return array{headers: list<string>, rows: int, samples: array<string, list<string>>, distinct: array<string, list<string>>}
     */
    public function scan(int $sheetIndex = 0, int $sampleCount = 3, int $distinctLimit = 40, int $distinctRows = 5_000, ?int $maxRows = null): array
    {
        $headers = [];
        $samples = [];
        $distinct = [];
        $overflow = [];
        $count = 0;

        foreach ($this->rows($sheetIndex) as $cells) {
            if ($headers === []) {
                $headers = self::uniqueHeaders($cells);
                $samples = array_fill_keys($headers, []);
                $distinct = array_fill_keys($headers, []);

                continue;
            }

            $count++;

            // Stop reading as soon as the file is known to be too long: it is refused anyway.
            if ($maxRows !== null && $count > $maxRows) {
                break;
            }

            foreach ($headers as $position => $header) {
                $value = trim($cells[$position] ?? '');

                if ($value === '') {
                    continue;
                }

                if (count($samples[$header]) < $sampleCount && !in_array($value, $samples[$header], true)) {
                    $samples[$header][] = mb_strimwidth($value, 0, 60, '…');
                }

                if ($count <= $distinctRows && !isset($overflow[$header]) && !in_array($value, $distinct[$header], true)) {
                    $distinct[$header][] = $value;

                    if (count($distinct[$header]) > $distinctLimit) {
                        $overflow[$header] = true;
                        $distinct[$header] = [];
                    }
                }
            }
        }

        foreach ($overflow as $header => $true) {
            $distinct[$header] = [];
        }

        return ['headers' => $headers, 'rows' => $count, 'samples' => $samples, 'distinct' => $distinct];
    }

    /**
     * @phpstan-ignore missingType.generics
     */
    private function reader(): ReaderInterface
    {
        $reader = match ($this->extension) {
            'xlsx' => new XlsxReader(self::xlsxOptions()),
            'ods' => new OdsReader(self::odsOptions()),
            'csv' => new CsvReader(self::csvOptions($this->delimiter)),
            default => throw new RuntimeException("Unsupported file type [{$this->extension}]."),
        };

        $reader->open($this->path);

        return $reader;
    }

    private static function xlsxOptions(): XlsxOptions
    {
        $options = new XlsxOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_FORMAT_DATES = false;

        return $options;
    }

    private static function odsOptions(): OdsOptions
    {
        $options = new OdsOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        return $options;
    }

    private static function csvOptions(string $delimiter): CsvOptions
    {
        $options = new CsvOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->FIELD_DELIMITER = $delimiter;

        return $options;
    }

    public static function maxColumns(): int
    {
        return max(1, (int) config('filament-xlsx-import.max_columns', 200));
    }

    public static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            return (string) (floor($value) === $value && abs($value) < 1e15 ? (int) $value : (float) sprintf('%.15g', $value));
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
