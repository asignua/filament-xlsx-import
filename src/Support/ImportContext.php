<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Handed to `->saveUsing(fn (array $data, ?Model $existing, ImportContext $ctx) => …)`.
 */
final class ImportContext
{
    /**
     * @param array<string, mixed> $spec
     */
    public function __construct(
        public readonly ?Authenticatable $user,
        public readonly int $row,
        public readonly bool $dryRun,
        public readonly array $spec = [],
    ) {}
}
