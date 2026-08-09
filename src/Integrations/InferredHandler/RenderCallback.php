<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * A render callback discovered on the booted exception handler (`$exceptions->render(…)`): the source
 * location the engine analyses plus its first parameter — the name to narrow and the exception type it
 * handles (`Throwable`/`Exception` = a catch-all).
 *
 * Laravel stores EVERY render callable as a Closure (`Handler::renderable()` wraps a non-Closure via
 * `Closure::fromCallable()`), so an invokable renderer (`->render(new ProblemRenderer)`), an
 * `[$obj, 'method']` pair, or a first-class callable all arrive as method-backed closures. Those carry
 * their declaring {@see $class} + {@see $method} so the engine analyses the real method body; a genuine
 * anonymous closure leaves both null and is analysed by {@see $file}+{@see $line}.
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

    /** A method-backed callback (invokable object, `[$obj, 'method']`, first-class callable) vs an anonymous closure. */
    public function isMethod(): bool
    {
        return $this->method !== null;
    }
}
