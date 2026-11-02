<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

final class RecordingHandlers
{
    public static int $before = 0;

    public static int $after = 0;

    public static function reset(): void
    {
        self::$before = 0;
        self::$after = 0;
    }

    public static function before(object $user, ?object $panel): void
    {
        self::$before++;
    }

    public static function after(object $user, ?object $panel): void
    {
        self::$after++;
    }
}
