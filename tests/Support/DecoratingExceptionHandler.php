<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * A stand-in for a handler DECORATOR — the shape Collision installs in console, holding the real
 * Foundation handler in a property and exposing no `renderCallbacks` of its own. The reflector must
 * walk through it to the wrapped handler. Every contract method delegates to the inner handler.
 */
final class DecoratingExceptionHandler implements ExceptionHandler
{
    public function __construct(private readonly ExceptionHandler $inner) {}

    public function report(Throwable $e): void
    {
        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
