<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * A handler DECORATOR that also carries an UNINITIALIZED typed property, declared BEFORE the property
 * holding the real handler. Reading an uninitialized typed property by reflection throws, so a
 * per-walk try/catch would abort the whole unwrap and silently discover zero render callbacks; the
 * reflector's per-property guard must skip this property and keep walking to `$inner`.
 */
final class UninitializedPropertyExceptionHandler implements ExceptionHandler
{
    /** Never assigned — `ReflectionProperty::getValue()` on this throws. */
    private ExceptionHandler $unset;

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
