<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

interface RowImporter
{
    /**
     * @param array<string, string> $row header => cell
     */
    public function process(array $row, int $number): RowOutcome;

    /**
     * Called once when a run ends, to undo per-run state.
     */
    public function finish(): void;
}
