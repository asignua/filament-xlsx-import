<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Asignua\FilamentXlsxImport\Support\SourceFile;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

/**
 * Builds the uploads the tests feed to the import, and reads generated workbooks back.
 */
final class Sheet
{
    /**
     * @param list<list<mixed>>                $rows
     * @param array<string, list<list<mixed>>> $more extra sheets by name
     */
    public static function xlsx(array $rows, string $name = 'data.xlsx', array $more = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xi-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Sheet1');

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        foreach ($more as $sheetName => $sheetRows) {
            $writer->addNewSheetAndMakeItCurrent()->setName($sheetName);

            foreach ($sheetRows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * A workbook written with REAL typed cells, the way a spreadsheet app saves it: numbers are numeric cells, dates are date cells
     * with a date number format, text is text. (`xlsx()` writes numbers typed too but dates as bare serials.)
     *
     * @param list<list<mixed>> $rows
     */
    public static function typed(array $rows, string $format = 'xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xi-').'.'.$format;
        $writer = $format === 'ods' ? new \OpenSpout\Writer\ODS\Writer : new Writer;
        $writer->openToFile($path);
        $date = (new \OpenSpout\Common\Entity\Style\Style)->setFormat('dd.mm.yyyy');
        $dateTime = (new \OpenSpout\Common\Entity\Style\Style)->setFormat('dd.mm.yyyy hh:mm');

        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(static function (mixed $value) use ($date, $dateTime): \OpenSpout\Common\Entity\Cell {
                if ($value instanceof \DateTimeInterface) {
                    return new \OpenSpout\Common\Entity\Cell\DateTimeCell($value, $value->format('H:i:s') === '00:00:00' ? $date : $dateTime);
                }

                return \OpenSpout\Common\Entity\Cell::fromValue($value);
            }, $row)));
        }

        $writer->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent('typed.'.$format, $content);
    }

    public static function csv(string $content, string $name = 'data.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * Every row of the first sheet of a generated workbook, as text.
     *
     * @return list<list<string>>
     */
    public static function read(string $path, string $extension = 'xlsx'): array
    {
        return array_values(iterator_to_array((new SourceFile($path, $extension))->rows(), false));
    }

    public static function zipEntry(string $path, string $entry): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $content = (string) $zip->getFromName($entry);
        $zip->close();

        return $content;
    }
}
