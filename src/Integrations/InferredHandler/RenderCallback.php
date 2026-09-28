<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * A render callback found on the booted exception handler: where it is written ({@see LocatedCallable}), plus
 * its first parameter — the name to narrow and the exception type it handles (`Throwable`/`Exception` being a
 * catch-all).
 */
final readonly class RenderCallback
{
    public function __construct(
        public LocatedCallable $at,
        public string $parameterName,
        public string $exceptionType,
    ) {}
}
