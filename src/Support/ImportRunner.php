<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the uploaded file of a run row by row through the right engine (Importer class or plain
 * model) and tallies the outcome. A dry run does the real writes inside one transaction that is
 * rolled back, so uniqueness, observers and database constraints all speak — and a key repeated in the
 * file shows up as an update of the row before it, as it will in the real run.
 */
final class ImportRunner
{
    /**
     * @param array<string, mixed>                                                      $spec
     * @param Closure(array<string, mixed>, ?\Illuminate\Database\Eloquent\Model, ImportContext): mixed|null $saveUsing
     * @param Closure(int, int): void|null                                              $progress
     *
     * @return array<string, mixed>
     */
    public static function run(array $spec, bool $dry, ?Closure $saveUsing = null, ?Closure $progress = null): array
    {
        $id = (string) $spec['run_id'];
        $meta = RunStore::meta($id);
        $definition = DefinitionResolver::resolve(
            is_string($spec['model'] ?? null) ? $spec['model'] : null,
            is_string($spec['importer'] ?? null) ? $spec['importer'] : null,
            is_string($spec['definition'] ?? null) ? $spec['definition'] : null,
            (array) ($spec['overrides'] ?? []),
        );

        /** @var list<array{header: string, target: string|null, casts: list<array{type: string, arg: string, arg2: string, map: array<string, string>}>, mode: string, many: string}> $mapping */
        $mapping = $spec['mapping'];
        $user = Auth::user();
        $engine = $definition->importer !== null
            ? new ImporterRowImporter($definition->importer, $mapping, (array) ($spec['options'] ?? []), $spec, $user, (string) ($meta['file_name'] ?? 'import'))
            : new ModelRowImporter($definition, $mapping, $spec, $user, $dry, $saveUsing);

        $headers = array_values(array_map('strval', (array) ($meta['headers'] ?? [])));
        $report = RunStore::path($id, 'report.xlsx');
        @unlink($report);
        $failures = new FailedRowsWriter($report, $headers);

        $total = (int) ($meta['rows'] ?? 0);
        $limit = $dry ? max(0, (int) config('filament-xlsx-import.dry_run.max_rows', 5000)) : 0;
        $chunk = max(1, (int) config('filament-xlsx-import.chunk_size', 200));

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'processed' => 0, 'total' => $total, 'truncated' => false, 'duplicates' => 0,
            'samples' => ['created' => [], 'updated' => [], 'errors' => [], 'skipped' => []], 'has_report' => false];

        DB::beginTransaction();

        try {
            foreach (RunStore::source($id)->table((int) ($meta['sheet'] ?? 0)) as $number => $row) {
                if ($limit > 0 && $result['processed'] >= $limit) {
                    $result['truncated'] = true;

                    break;
                }

                $outcome = $engine->process($row, $number);
                $result['processed']++;
                $result[$outcome->status]++;
                $result['duplicates'] += $outcome->duplicate ? 1 : 0;
                self::sample($result, $outcome, $number);

                if ($outcome->status === RowOutcome::FAILED) {
                    $failures->add($number, $row, $outcome->message);
                }

                if (!$dry && $result['processed'] % $chunk === 0) {
                    DB::commit();
                    $progress?->__invoke($result['processed'], $total);
                    DB::beginTransaction();
                }
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            $failures->close();
            $engine->finish();
        }

        $result['has_report'] = $failures->count > 0;
        $progress?->__invoke($result['processed'], $total);

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function sample(array &$result, RowOutcome $outcome, int $number): void
    {
        $samples = &$result['samples'];

        match ($outcome->status) {
            RowOutcome::UPDATED => count($samples['updated']) < 10 && $samples['updated'][] = ['row' => $number, 'label' => $outcome->label, 'changes' => $outcome->changes],
            RowOutcome::CREATED => count($samples['created']) < 5 && $samples['created'][] = ['row' => $number, 'label' => $outcome->label, 'changes' => $outcome->changes],
            RowOutcome::FAILED => count($samples['errors']) < 20 && $samples['errors'][] = ['row' => $number, 'message' => $outcome->message],
            default => $samples['skipped'][$outcome->message] = ($samples['skipped'][$outcome->message] ?? 0) + 1,
        };
    }
}
