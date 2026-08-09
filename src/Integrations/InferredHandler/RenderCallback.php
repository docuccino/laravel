<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * A render callback found on the booted exception handler: the source location the engine analyses, plus its
 * first parameter — the name to narrow and the exception type it handles (`Throwable`/`Exception` being a
 * catch-all).
 *
 * Laravel stores every render callable as a Closure, so an invokable renderer, an `[$obj, 'method']` pair
 * and a first-class callable all arrive as method-backed closures. Those carry their declaring
 * {@see $class} + {@see $method} so the engine analyses the real method body; a genuine anonymous closure
 * leaves both null and is analysed by {@see $file}+{@see $line}.
 */
final readonly class RenderCallback
{
    public function __construct(
        public string $file,
        public int $line,
        public string $parameterName,
        public string $exceptionType,
        public ?string $class = null,
        public ?string $method = null,
    ) {}

    /** Method-backed, as opposed to a genuine anonymous closure. */
    public function isMethod(): bool
    {
        return $this->method !== null;
    }
}
