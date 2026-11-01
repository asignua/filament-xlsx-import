<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

final class DenyCreatePolicy
{
    public function create(object $user): bool
    {
        return false;
    }

    public function update(object $user): bool
    {
        return true;
    }
}
