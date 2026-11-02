<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Data\Definition;
use Asignua\FilamentXlsxImport\Data\TargetField;
use Carbon\Carbon;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

/**
 * A blank workbook generated from an import definition: a header per column, an example row and — for
 * columns with a fixed set of values (enum casts, `in:` rules) — a real Excel dropdown that points at
 * a «Lists» sheet. OpenSpout has no data-validation API, so the `<dataValidations>` block is added to the sheet XML afterwards.
 */
final class TemplateBuilder
{
    public const int VALIDATED_ROWS = 1000;

    /**
     * @return list<TargetField>
     */
    public static function columns(Definition $definition, ?string $updateBy = null): array
    {
        $preferred = [];

        foreach ($definition->targets as $target) {
            if ($target->isRelation() && (!isset($preferred[$target->relation]) || in_array($target->field, ['name', 'title'], true))) {
                $preferred[$target->relation] = $target->key;
            }
        }

        $columns = [];

        foreach ($definition->targets as $target) {
            if ($target->isRelation()) {
                if ($preferred[$target->relation] !== $target->key && !$target->fromRules) {
                    continue;
                }
            } elseif ($definition->importer === null && $target->key === 'id' && $updateBy !== 'id') {
                continue;
            }

            $columns[] = $target;
        }

        return array_slice($columns, 0, 40);
    }

    public static function build(Definition $definition, string $path, ?string $updateBy = null, bool $example = true): void
    {
        $columns = self::columns($definition, $updateBy ?? $definition->updateBy);
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(__('filament-xlsx-import::filament-xlsx-import.template.sheet'));

        $header = (new Style)->setFontBold()->setBackgroundColor('E5E7EB');
        $writer->addRow(new Row(array_map(static fn (TargetField $t): StringCell => new StringCell($t->label, null), $columns), $header));

        if ($example) {
            $muted = (new Style)->setFontItalic()->setFontColor(Color::rgb(120, 120, 120));
            $writer->addRow(new Row(array_map(static fn (TargetField $t): StringCell => new StringCell(self::example($t), null), $columns), $muted));
        }

        $lists = [];

        foreach ($columns as $position => $target) {
            if ($target->options !== null && $target->options !== []) {
                $lists[$position] = $target->options;
            }
        }

        if ($lists !== []) {
            $writer->addNewSheetAndMakeItCurrent()->setName(__('filament-xlsx-import::filament-xlsx-import.template.lists'));
            $depth = max(array_map('count', $lists));
            $listHeader = [];

            foreach ($lists as $position => $values) {
                $listHeader[] = new StringCell($columns[$position]->label, null);
            }

            $writer->addRow(new Row($listHeader, $header));

            for ($i = 0; $i < $depth; $i++) {
                $writer->addRow(new Row(array_map(static fn (array $values): StringCell => new StringCell($values[$i] ?? '', null), array_values($lists))));
            }
        }

        $writer->close();

        if ($lists !== []) {
            self::addDropdowns($path, $lists);
        }
    }

    /**
     * An example that the column's own rule reads back: «12,50» for a decimal-comma rule, «31.01.2026» for `d.m.Y`.
     */
    private static function example(TargetField $t): string
    {
        if ($t->example !== '') {
            return $t->example;
        }

        if ($t->options !== null && $t->options !== []) {
            return (string) $t->options[0];
        }

        foreach ($t->casts as $cast) {
            $format = $cast['arg'] === 'custom' ? $cast['arg2'] : $cast['arg'];

            switch ($cast['type']) {
                case 'decimal':
                    return $cast['arg'] === ',' ? '12,50' : '12.50';
                case 'date':
                    return Carbon::parse('2026-01-31')->format($format !== '' ? $format : 'Y-m-d');
                case 'datetime':
                    return Carbon::parse('2026-01-31 09:30:00')->format($format !== '' ? $format : 'Y-m-d H:i:s');
                case 'boolean':
                    return trim(explode(',', $cast['arg'] !== '' ? $cast['arg'] : 'yes')[0]);
                case 'split':
                    return 'one'.($cast['arg'] !== '' ? $cast['arg'] : ',').'two';
            }
        }

        return match ($t->type) {
            'integer' => '1',
            'decimal' => '12.50',
            'boolean' => 'yes',
            'date' => '2026-01-31',
            'datetime' => '2026-01-31 09:30:00',
            default => __('filament-xlsx-import::filament-xlsx-import.template.example'),
        };
    }

    /**
     * @param array<int, list<string>> $lists column position => allowed values
     */
    private static function addDropdowns(string $path, array $lists): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot reopen the template to add dropdowns.');
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheet === false) {
            $zip->close();

            return;
        }

        $sheetName = str_replace("'", "''", (string) __('filament-xlsx-import::filament-xlsx-import.template.lists'));
        $rules = '';
        $n = 0;

        foreach ($lists as $position => $values) {
            $column = SourceFile::letter($position);
            $listColumn = SourceFile::letter($n);
            $last = count($values) + 1;
            $rules .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="'.$column.'2:'.$column.(self::VALIDATED_ROWS + 1).'">'
                .'<formula1>\''.htmlspecialchars($sheetName, ENT_XML1).'\'!$'.$listColumn.'$2:$'.$listColumn.'$'.$last.'</formula1></dataValidation>';
            $n++;
        }

        $block = '<dataValidations count="'.count($lists).'">'.$rules.'</dataValidations>';

        // Schema order: …sheetData, autoFilter, mergeCells, conditionalFormatting, dataValidations, hyperlinks, … pageMargins.
        $anchor = preg_match('/<(hyperlinks|printOptions|pageMargins|pageSetup|headerFooter|drawing|legacyDrawing|tableParts)\b/', $sheet, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : strrpos($sheet, '</worksheet>');

        if ($anchor === false) {
            $zip->close();

            return;
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', substr($sheet, 0, $anchor).$block.substr($sheet, $anchor));
        $zip->close();
    }
}
