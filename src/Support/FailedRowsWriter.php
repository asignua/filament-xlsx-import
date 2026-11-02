<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Streams the rows that failed into `report.xlsx`: the source row number, the original columns and
 * the reason. Every cell is a string cell (never a formula), so an `=HYPERLINK(…)` from the upload is
 * only text when the report is opened.
 */
final class FailedRowsWriter
{
    private ?Writer $writer = null;

    public int $count = 0;

    /**
     * @param list<string> $headers
     */
    public function __construct(private readonly string $path, private readonly array $headers) {}

    /**
     * @param array<string, mixed> $row
     */
    public function add(int $number, array $row, string $message): void
    {
        if ($this->writer === null) {
            $this->writer = new Writer;
            $this->writer->openToFile($this->path);
            $this->writer->addRow(new Row(
                array_map(static fn (string $h): StringCell => new StringCell($h, null), [
                    __('filament-xlsx-import::filament-xlsx-import.report.row'),
                    ...$this->headers,
                    __('filament-xlsx-import::filament-xlsx-import.report.error'),
                ]),
                (new Style)->setFontBold(),
            ));
        }

        $cells = [(string) $number];

        foreach ($this->headers as $header) {
            $cells[] = SourceFile::stringify($row[$header] ?? '');
        }

        $cells[] = $message;

        $this->writer->addRow(new Row(array_map(static fn (string $v): StringCell => new StringCell($v, null), $cells)));
        $this->count++;
    }

    public function close(): void
    {
        $this->writer?->close();
        $this->writer = null;
    }
}
