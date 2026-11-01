<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Asignua\FilamentXlsxImport\Jobs\ImportXlsxJob;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Dispatching a queued import and the per-user cap on unfinished ones.
 */
final class QueuedImports
{
    /**
     * @param array<string, mixed> $spec
     */
    public static function dispatch(array $spec, ?string $connection = null, ?string $queue = null): void
    {
        $connection ??= self::string(config('filament-xlsx-import.queue.connection'));
        $queue ??= self::string(config('filament-xlsx-import.queue.queue'));
        $spec['root'] = request()->root();

        $job = new ImportXlsxJob($spec);
        $job->timeout = self::timeoutFor($connection);

        if ($connection !== null) {
            $job->onConnection($connection);
        }

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        RunStore::put((string) $spec['run_id'], ['status' => 'queued']);
        self::withSlots($spec, function (array $slots) use ($spec): array {
            $slots[(string) $spec['run_id']] = time() + self::timeoutFor(null) + 60;

            return $slots;
        });
        dispatch($job);
    }

    /**
     * Kept under the connection's `retry_after`: with `tries = 1` a job that outlives it is picked up
     * by a second worker while the first one is still writing.
     */
    public static function timeoutFor(?string $connection): int
    {
        $timeout = max(30, (int) config('filament-xlsx-import.queue.timeout', 3600));
        $retryAfter = config('queue.connections.'.($connection ?? config('queue.default')).'.retry_after');

        return is_numeric($retryAfter) ? max(10, min($timeout, (int) $retryAfter - 10)) : $timeout;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function hasFreeSlot(array $spec): bool
    {
        $max = (int) config('filament-xlsx-import.queue.max_per_user', 2);

        return $max <= 0 || count(self::active($spec)) < $max;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function release(array $spec, string $id): void
    {
        self::withSlots($spec, static function (array $slots) use ($id): array {
            unset($slots[$id]);

            return $slots;
        });
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, int>
     */
    private static function active(array $spec): array
    {
        $slots = Cache::get(self::key($spec), []);

        return array_filter(is_array($slots) ? $slots : [], static fn (mixed $until): bool => is_int($until) && $until > time());
    }

    /**
     * @param array<string, mixed>                            $spec
     * @param Closure(array<string, int>): array<string, int> $change
     */
    private static function withSlots(array $spec, Closure $change): void
    {
        $key = self::key($spec);
        $apply = static fn () => Cache::put($key, $change(self::active($spec)), 86400);

        try {
            Cache::lock($key.':lock', 5)->block(3, $apply);
        } catch (Throwable) {
            $apply();
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function key(array $spec): string
    {
        return 'filament-xlsx-import:slots:'.($spec['guard'] ?? '').':'.($spec['user'] ?? '');
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
