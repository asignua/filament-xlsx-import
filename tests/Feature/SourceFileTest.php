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

    private function formulaWorkbook(): SourceFile
    {
        $text = static fn (string $ref, string $value): string => '<c r="'.$ref.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
        $rows = '<row r="1">'.$text('A1', 'Name').'<c r="B1" t="str"><f>COUNTIF(A:A,"x")</f><v>Phone</v></c>'.$text('C1', 'Note').$text('D1', 'Day').$text('E1', 'Hours').'</row>'
            .'<row r="2">'.$text('A2', 'Ada').'<c r="B2"><f>+380501112233</f><v>380501112233</v></c><c r="C2" t="str"><f>IFERROR(1,"hi")</f><v>hello</v></c>'
            .'<c r="D2" s="1"><f>DATE(2026,1,31)</f><v>46053</v></c><c r="E2" s="2"><f>1+0.5</f><v>1.5</v></c></row>'
            .'<row r="3">'.$text('A3', 'Bob').'<c r="B3"><f>A1+1</f></c><c r="C3" t="str"><f>"x"</f></c>'
            .'<c r="D3" t="b"><f>TRUE()</f><v>1</v></c><c r="E3" s="2"><v>1.5208333333333</v></c></row>';
        $path = $this->tmp('xlsx');
        file_put_contents($path, Sheet::raw($rows)->getContent());

        return new SourceFile($path, 'xlsx');
    }

    public function test_formula_cells_read_their_cached_result_not_the_formula_text(): void
    {
        $rows = iterator_to_array($this->formulaWorkbook()->typedRows(), false);

        $this->assertSame('Phone', $rows[0][1]);
        $this->assertSame(380501112233, $rows[1][1]);
        $this->assertSame('hello', $rows[1][2]);
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][3]);
        $this->assertSame('2026-01-31', $rows[1][3]->format('Y-m-d'));
        $this->assertSame('36:00:00', SourceFile::stringify($rows[1][4]));
        // OpenSpout reads a numeric formula with no cached <v> as 0 (it cannot be told from a cached 0); a text one as empty.
        $this->assertSame(0, $rows[2][1]);
        $this->assertSame('', $rows[2][2]);
        $this->assertTrue($rows[2][3]);
        $this->assertSame('36:30:00', SourceFile::stringify($rows[2][4]));
    }

    public function test_formula_results_flow_through_rows_table_and_scan(): void
    {
        $file = $this->formulaWorkbook();

        $this->assertSame(['Name', 'Phone', 'Note', 'Day', 'Hours'], iterator_to_array($file->rows(), false)[0]);
        $this->assertSame(['Ada', '380501112233', 'hello', '2026-01-31', '36:00:00'], iterator_to_array($file->rows(), false)[1]);

        $table = iterator_to_array($file->table(), false);
        $this->assertSame(380501112233, $table[0]['Phone']);
        $this->assertSame(0, $table[1]['Phone']);

        $scan = $file->scan();
        $this->assertSame(['Name', 'Phone', 'Note', 'Day', 'Hours'], $scan['headers']);
        $this->assertSame([380501112233, 0], $scan['samples']['Phone']);
        $this->assertSame(['380501112233', '0'], $scan['distinct']['Phone']);
    }

    public function test_text_that_merely_starts_with_an_equals_sign_stays_text(): void
    {
        $text = static fn (string $ref, string $value): string => '<c r="'.$ref.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
        $path = $this->tmp('xlsx');
        file_put_contents($path, Sheet::raw('<row r="1">'.$text('A1', 'Note').'</row><row r="2">'.$text('A2', '=not a formula').'</row>')->getContent());

        $this->assertSame([1 => ['Note'], 2 => ['=not a formula']], iterator_to_array((new SourceFile($path, 'xlsx'))->rows()));
        $this->assertSame('=1+1', iterator_to_array($this->csvFile("A\n=1+1\n")->rows())[2][0]);
    }

    public function test_plain_duration_cells_become_hours_text_not_blank(): void
    {
        $this->assertSame('1:05:09', SourceFile::stringify(new \DateInterval('P0DT1H5M9S')));
        $this->assertSame('-0:30:00', SourceFile::stringify((function (): \DateInterval {
            $i = new \DateInterval('P0DT0H30M0S');
            $i->invert = 1;

            return $i;
        })()));
        $this->assertSame('1:05:09', SourceFile::native(new \DateInterval('P0DT1H5M9S')));
    }

    public function test_xlsx_cells_keep_their_types_and_rows_reads_them_as_text(): void
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

        $file = new SourceFile($path, 'xlsx');
        $typed = iterator_to_array($file->table(), false)[0];

        $this->assertSame('Ada', $typed['Name']);
        $this->assertSame(3, $typed['Qty']);
        $this->assertSame(12.5, $typed['Price']);
        $this->assertTrue($typed['Active']);
        $this->assertInstanceOf(DateTimeImmutable::class, $typed['Day']);
        $this->assertSame('2026-01-31', $typed['Day']->format('Y-m-d'));

        // rows() is the text view of the same cells
        $this->assertSame(['Ada', '3', '12.5', '1', '2026-01-31', '2026-01-31 09:30:00'], iterator_to_array($file->rows(), false)[1]);
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
