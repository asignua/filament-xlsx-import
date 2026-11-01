<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

final class RowOutcome
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    /**
     * @param array<string, array{0: string|null, 1: string|null}> $changes field => [old, new]
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $changes = [],
        public readonly string $label = '',
        public readonly bool $duplicate = false,
    ) {}

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }
}
