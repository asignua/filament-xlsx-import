<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use DateInterval;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
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
     * Every row of a sheet as [source row number => cells], empty rows left out. Cells are text.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(int $sheetIndex = 0): Generator
    {
        foreach ($this->typedRows($sheetIndex) as $number => $cells) {
            yield $number => array_map(self::stringify(...), $cells);
        }
    }

    /**
     * The same rows with the cells' own types: numbers stay int/float, dates stay DateTimeInterface, booleans bool — so a cast
     * can tell the number 12.5 from the text «12,5». CSV cells are always text.
     *
     * @return Generator<int, list<bool|DateTimeInterface|float|int|string>>
     */
    public function typedRows(int $sheetIndex = 0): Generator
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
                    $cells = array_map(self::native(...), self::values($row));

                    if (array_filter($cells, static fn (string|int|float|bool|DateTimeInterface $cell): bool => trim(self::stringify($cell)) !== '') === []) {
                        continue;
                    }

                    yield $number => $cells;
                }

                return;
            }

            throw new RuntimeException("The workbook has no sheet #{$sheetIndex}.");
        } finally {
            $reader->close();
        }
    }

    /**
     * A row's cell values, capped at one more than the column limit. A formula cell yields the result the spreadsheet app cached
     * next to it (`getValue()` would return the formula text, e.g. `=+380501112233`); a null result means TEXT that merely starts with `=` (OpenSpout
     * wraps every such string — CSV field, ODS or inline string — in a FormulaCell) or a formula that evaluated to an error: its own text is kept.
     *
     * @return list<mixed>
     */
    private static function values(Row $row): array
    {
        return array_values(array_map(
            static fn (Cell $cell): mixed => $cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue(),
            array_slice($row->getCells(), 0, self::maxColumns() + 1),
        ));
    }

    public static function native(mixed $value): string|int|float|bool|DateTimeInterface
    {
        return match (true) {
            $value instanceof DateTimeInterface, is_int($value), is_float($value), is_bool($value), is_string($value) => $value,
            default => self::stringify($value),
        };
    }

    /**
     * The header row, made unique ("Name", "Name (2)") with blanks named by their column letter, then the data rows keyed by it.
     *
     * @return Generator<int, array<string, bool|DateTimeInterface|float|int|string>> row number => [header => value]
     */
    public function table(int $sheetIndex = 0): Generator
    {
        $headers = null;

        foreach ($this->typedRows($sheetIndex) as $number => $cells) {
            if ($headers === null) {
                $headers = self::uniqueHeaders(array_map(self::stringify(...), $cells));

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
     * @return array{headers: list<string>, rows: int, samples: array<string, list<bool|float|int|string>>, distinct: array<string, list<string>>}
     */
    public function scan(int $sheetIndex = 0, int $sampleCount = 3, int $distinctLimit = 40, int $distinctRows = 5_000, ?int $maxRows = null): array
    {
        $headers = [];
        $samples = [];
        $distinct = [];
        $overflow = [];
        $count = 0;

        foreach ($this->typedRows($sheetIndex) as $cells) {
            if ($headers === []) {
                $headers = self::uniqueHeaders(array_map(self::stringify(...), $cells));
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
                $native = $cells[$position] ?? '';
                $value = trim(self::stringify($native));

                if ($value === '') {
                    continue;
                }

                // Samples keep the cell's type (a number stays a number) so the live «sample → result» line casts like the import does.
                $sample = is_string($native) || $native instanceof DateTimeInterface ? mb_strimwidth($value, 0, 60, '…') : $native;

                if (count($samples[$header]) < $sampleCount && !in_array($sample, $samples[$header], true)) {
                    $samples[$header][] = $sample;
                }

                // Case-insensitive: «Активний» and «активний» are one value of the value table (the cast looks values up that way).
                if ($count <= $distinctRows && !isset($overflow[$header]) && !in_array(mb_strtolower($value), array_map('mb_strtolower', $distinct[$header]), true)) {
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

        if ($value instanceof DateInterval) {
            // A duration cell ([h]:mm:ss): total hours, so 36:30:00 stays 36:30:00.
            return sprintf('%s%d:%02d:%02d', $value->invert ? '-' : '', $value->d * 24 + $value->h, $value->i, $value->s);
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
