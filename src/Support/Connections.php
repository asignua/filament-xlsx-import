<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Data\Definition;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The database connections a run writes to: the model's own (not the default one — a model on
 * `$connection = 'reporting'` must be rehearsed and committed there) and those of the related models. A dry run
 * rolls all of them back; a row is a transaction on all of them.
 */
final class Connections
{
    /**
     * @return list<string>
     */
    public static function names(Definition $definition): array
    {
        $names = [self::of($definition->model)];

        foreach ($definition->targets as $target) {
            if ($target->relatedModel !== '') {
                $names[] = self::of($target->relatedModel);
            }
        }

        return array_values(array_unique($names));
    }

    public static function of(string $class): string
    {
        $model = class_exists($class) ? new $class : null;

        return ($model instanceof Model ? $model->getConnectionName() : null) ?? (string) config('database.default');
    }

    /**
     * @param list<string> $names
     */
    public static function begin(array $names): void
    {
        foreach ($names as $name) {
            DB::connection($name)->beginTransaction();
        }
    }

    /**
     * @param list<string> $names
     */
    public static function commit(array $names): void
    {
        foreach (array_reverse($names) as $name) {
            DB::connection($name)->commit();
        }
    }

    /**
     * @param list<string> $names
     */
    public static function rollBack(array $names): void
    {
        foreach (array_reverse($names) as $name) {
            $connection = DB::connection($name);

            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Rolls every connection back to the level it had before the run — never below it, so an enclosing transaction
     * (a test, a caller's) is not touched even when the run's own was already committed by an implicit commit.
     *
     * @param array<string, int> $levels
     */
    public static function rollBackTo(array $levels): void
    {
        foreach (array_reverse($levels, true) as $name => $level) {
            $connection = DB::connection($name);

            while ($connection->transactionLevel() > $level) {
                $connection->rollBack();
            }
        }
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, int>
     */
    public static function levels(array $names): array
    {
        $levels = [];

        foreach ($names as $name) {
            $levels[$name] = DB::connection($name)->transactionLevel();
        }

        return $levels;
    }

    /**
     * A savepoint per row on every connection involved.
     *
     * @param list<string>     $names
     * @param Closure(): mixed $callback
     */
    public static function transaction(array $names, Closure $callback): mixed
    {
        $run = $callback;

        foreach (array_reverse($names) as $name) {
            $inner = $run;
            $run = static fn () => DB::connection($name)->transaction($inner);
        }

        return $run();
    }
}
