<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Rules;

/** Counts the times anything calls it: application code a build must never run. */
final class CallRecorder
{
    public static int $calls = 0;

    public static function record(mixed ...$arguments): mixed
    {
        self::$calls++;

        return $arguments[0] ?? null;
    }
}
