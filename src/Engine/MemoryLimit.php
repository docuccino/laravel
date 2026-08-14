<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

/**
 * The php.ini memory ceiling in-process inference runs under. In-process PHPStan analyses inside the
 * calling process, so the ceiling is whatever that process started with — and exhausting it is an
 * uncatchable fatal, the one failure mode the engine's "degrade rather than fail" promise cannot cover.
 *
 * Raise-only, deliberately: the knob exists to stop an OOM, so it never lowers a ceiling, never caps a
 * process that is already unlimited, and never removes a ceiling either. Pure, so every shorthand and
 * comparison is dataset-testable.
 */
final class MemoryLimit
{
    /** php.ini shorthand suffixes, in bytes. */
    private const UNITS = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];

    /** The `memory_limit` value meaning "no ceiling". */
    private const UNLIMITED = '-1';

    /** Digits that always fit a 64-bit int, so the scaling below can be bounds-checked rather than overflow. */
    private const MAX_DIGITS = 18;

    /**
     * Bytes for a php.ini memory value: a plain byte count, or one carrying a `K`/`M`/`G` suffix. An
     * unlimited `-1` reads as `PHP_INT_MAX`, so it compares as the largest ceiling there is. Null when the
     * value isn't a shorthand PHP would accept — a figure too large to be a byte count included, since a
     * saturating cast and an overflowing multiplication are worse answers than "unreadable".
     */
    public static function bytes(string $value): ?int
    {
        $value = strtolower(trim($value));

        if ($value === self::UNLIMITED) {
            return PHP_INT_MAX;
        }

        $unit = substr($value, -1);
        $scale = self::UNITS[$unit] ?? 1;
        $number = isset(self::UNITS[$unit]) ? substr($value, 0, -1) : $value;

        if (! ctype_digit($number) || strlen($number) > self::MAX_DIGITS || (int) $number > intdiv(PHP_INT_MAX, $scale)) {
            return null;
        }

        return (int) $number * $scale;
    }

    /**
     * The limit worth setting, or null to leave the process alone — nothing configured, a value PHP
     * wouldn't accept, or a ceiling no higher than the one already in force.
     *
     * `-1` is readable but not askable: this knob exists to stop an OOM, and removing the ceiling
     * altogether is the opposite of that. A process that already runs unlimited is still left alone.
     */
    public static function target(?string $configured, string $current): ?string
    {
        if ($configured === null || trim($configured) === '' || trim($configured) === self::UNLIMITED) {
            return null;
        }

        $wanted = self::bytes($configured);
        if ($wanted === null) {
            return null;
        }

        $inForce = self::bytes($current);

        return $inForce !== null && $inForce >= $wanted ? null : trim($configured);
    }
}
