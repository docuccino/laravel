<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Diagnostics\AcceptedCodes;

/**
 * `docuccino.diagnostics.accept` as the core value object.
 *
 * One reader, because the severity gate and the console both ask the same question and must never
 * answer it differently: a diagnostic printed as accepted is exactly one `--fail-on` stopped
 * counting.
 *
 * @internal
 */
final class AcceptedDiagnostics
{
    public static function read(): AcceptedCodes
    {
        /** @var array<array-key, mixed> $accept */
        $accept = (array) config('docuccino.diagnostics.accept', []);

        return AcceptedCodes::of($accept);
    }
}
