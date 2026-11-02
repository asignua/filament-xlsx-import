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

    /**
     * A workbook with a hand-written sheet: OpenSpout's writer saves formulas WITHOUT the cached `<v>` result, which is what every
     * spreadsheet app stores. Style ids: 1 = date `yyyy-mm-dd`, 2 = duration `[h]:mm:ss`.
     *
     * @param string $rowsXml the `<row>` elements of the sheet
     */
    public static function raw(string $rowsXml, string $name = 'raw.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xi-').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="[h]:mm:ss"/></numFmts><fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="165" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rowsXml.'</sheetData></worksheet>');
        $zip->close();

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
