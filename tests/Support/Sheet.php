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
     * @param list<list<mixed>>         $rows
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
