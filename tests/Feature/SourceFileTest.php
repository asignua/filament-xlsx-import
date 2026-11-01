<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Support\CsvNormalizer;
use Asignua\FilamentXlsxImport\Support\SourceFile;
use Asignua\FilamentXlsxImport\Tests\Support\Sheet;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\ODS\Writer as OdsWriter;

class SourceFileTest extends TestCase
{
    private function tmp(string $ext): string
    {
        return tempnam(sys_get_temp_dir(), 'xi-').'.'.$ext;
    }

    private function csvFile(string $bytes, ?string $encoding = null, ?string $delimiter = null): SourceFile
    {
        $raw = $this->tmp('raw');
        $out = $this->tmp('csv');
        file_put_contents($raw, $bytes);
        $info = CsvNormalizer::normalize($raw, $out, $encoding, $delimiter);

        return new SourceFile($out, 'csv', $info['delimiter']);
    }

    public function test_xlsx_cells_become_strings_like_a_csv_row(): void
    {
        $path = $this->tmp('xlsx');
        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Name', 'Qty', 'Price', 'Active', 'Day', 'At']));
        $day = (new Style)->setFormat('yyyy-mm-dd');
        $at = (new Style)->setFormat('yyyy-mm-dd hh:mm:ss');
        $writer->addRow(new Row([
            Cell::fromValue('Ada'), Cell::fromValue(3), Cell::fromValue(12.5), Cell::fromValue(true),
            new DateTimeCell(new DateTimeImmutable('2026-01-31'), $day), new DateTimeCell(new DateTimeImmutable('2026-01-31 09:30:00'), $at),
        ]));
        $writer->close();

        $rows = iterator_to_array((new SourceFile($path, 'xlsx'))->table(), false);

        $this->assertSame(['Name' => 'Ada', 'Qty' => '3', 'Price' => '12.5', 'Active' => '1', 'Day' => '2026-01-31', 'At' => '2026-01-31 09:30:00'], $rows[0]);
    }

    public function test_empty_rows_are_skipped_but_row_numbers_stay_the_sheets_own(): void
    {
        $file = Sheet::xlsx([['a', 'b'], ['1', '2'], [null, null], ['3', '4']]);
        $path = $this->tmp('xlsx');
        file_put_contents($path, $file->getContent());

        $numbers = array_keys(iterator_to_array((new SourceFile($path, 'xlsx'))->table()));

        $this->assertSame([2, 4], $numbers);
    }

    public function test_ods_is_read(): void
    {
        $path = $this->tmp('ods');
        $writer = new OdsWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Name', 'City']));
        $writer->addRow(Row::fromValues(['Ada', 'Lviv']));
        $writer->close();

        $this->assertSame([['Name' => 'Ada', 'City' => 'Lviv']], array_values(iterator_to_array((new SourceFile($path, 'ods'))->table(), false)));
    }

    public function test_sheets_are_listed_and_a_chosen_one_is_read(): void
    {
        $file = Sheet::xlsx([['a'], ['first']], more: ['Second' => [['a'], ['second']]]);
        $path = $this->tmp('xlsx');
        file_put_contents($path, $file->getContent());
        $source = new SourceFile($path, 'xlsx');

        $this->assertSame(['Sheet1', 'Second'], $source->sheets());
        $this->assertSame([['a' => 'second']], array_values(iterator_to_array($source->table(1), false)));
    }

    public function test_headers_are_made_unique_and_blanks_named_by_letter(): void
    {
        $this->assertSame(['Name', 'name (2)', 'Column C'], SourceFile::uniqueHeaders(['Name', 'name', '']));
        $this->assertSame('AA', SourceFile::letter(26));
    }

    public function test_scan_collects_rows_samples_and_distinct_values(): void
    {
        $file = Sheet::xlsx([['Status', 'Note'], ['on', 'a'], ['off', 'b'], ['on', 'c'], ['on', 'd']]);
        $path = $this->tmp('xlsx');
        file_put_contents($path, $file->getContent());

        $scan = (new SourceFile($path, 'xlsx'))->scan();

        $this->assertSame(4, $scan['rows']);
        $this->assertSame(['on', 'off'], $scan['samples']['Status']);
        $this->assertSame(['on', 'off'], $scan['distinct']['Status']);
    }

    public function test_csv_delimiter_is_sniffed(): void
    {
        $this->assertSame(';', $this->csvFile("a;b;c\n1;2;3\n")->delimiter);
        $this->assertSame("\t", $this->csvFile("a\tb\n1\t2\n")->delimiter);
        $this->assertSame(',', $this->csvFile("a,b\n1,2\n")->delimiter);
        $this->assertSame('|', $this->csvFile("a|b\n1|2\n")->delimiter);
    }

    public function test_csv_with_a_utf8_bom_loses_the_bom(): void
    {
        $rows = iterator_to_array($this->csvFile("\xEF\xBB\xBFname,city\nАда,Львів\n")->table(), false);

        $this->assertSame([['name' => 'Ада', 'city' => 'Львів']], $rows);
    }

    public function test_csv_in_utf16_is_converted(): void
    {
        $bytes = "\xFF\xFE".mb_convert_encoding("name;city\nАда;Львів\n", 'UTF-16LE', 'UTF-8');

        $this->assertSame([['name' => 'Ада', 'city' => 'Львів']], iterator_to_array($this->csvFile($bytes)->table(), false));
    }

    public function test_csv_in_windows_1251_is_detected(): void
    {
        $bytes = mb_convert_encoding("назва;місто\nАндрій;Київ\nОльга;Львів\n", 'Windows-1251', 'UTF-8');

        $this->assertSame([['назва' => 'Андрій', 'місто' => 'Київ'], ['назва' => 'Ольга', 'місто' => 'Львів']], iterator_to_array($this->csvFile($bytes)->table(), false));
    }

    public function test_csv_in_windows_1252_is_detected(): void
    {
        $bytes = mb_convert_encoding("nom;ville\nRené;Besançon\nZoë;Zürich\n", 'Windows-1252', 'UTF-8');

        $this->assertSame([['nom' => 'René', 'ville' => 'Besançon'], ['nom' => 'Zoë', 'ville' => 'Zürich']], iterator_to_array($this->csvFile($bytes)->table(), false));
    }

    public function test_the_encoding_can_be_forced(): void
    {
        $bytes = mb_convert_encoding("a;b\nÆble;ø\n", 'ISO-8859-1', 'UTF-8');

        $this->assertSame([['a' => 'Æble', 'b' => 'ø']], iterator_to_array($this->csvFile($bytes, 'ISO-8859-1')->table(), false));
    }

    public function test_quoted_delimiters_do_not_split_a_cell(): void
    {
        $rows = iterator_to_array($this->csvFile("name,note\n\"Smith, John\",\"say \"\"hi\"\"\"\n")->table(), false);

        $this->assertSame([['name' => 'Smith, John', 'note' => 'say "hi"']], $rows);
    }
}
