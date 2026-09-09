<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use RuntimeException;
use Throwable;

/**
 * A configured route filter that could not be applied. Thrown where the filter is resolved, which
 * every command and the viewer reach through DocumentBuilder, so nothing is built, written or served
 * until the config is fixed.
 *
 * It carries its own {@see Diagnostic} because the export command validates config before it builds
 * and reports this in the same channel as every other config error rather than as a stack trace. The
 * message is the diagnostic's, so both readings say the same sentence.
 *
 * @internal
 */
final class UnusableRouteFilterException extends RuntimeException
{
    public function __construct(public readonly Diagnostic $diagnostic, ?Throwable $previous = null)
    {
        parent::__construct($diagnostic->message, 0, $previous);
    }
}
