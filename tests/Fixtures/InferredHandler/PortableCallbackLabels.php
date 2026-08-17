<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InferredHandler;

use Closure;
use Docuccino\Core\Inference\CallableRef;
use ReflectionFunction;
use RuntimeException;

/**
 * The two render callbacks whose LABEL a diagnostic has to publish, declared in a file of their own so
 * the file and line each label carries are stable bytes: a golden pins them, and a line that moved
 * because an unrelated test above it was edited would read as a changed contract.
 */
final class PortableCallbackLabels
{
    /** Skipped by the reflector: a builtin-typed first parameter is no exception the tier can bind. */
    public static function unanalysable(): Closure
    {
        return static fn (string $whoops) => response()->json([], 400);
    }

    /**
     * The label the tier keys a deferral by for an anonymous callback — minted by the product, off a real
     * closure, so it carries whatever `ReflectionFunction` says about this machine.
     */
    public static function deferralLabel(): string
    {
        $anonymous = static fn (RuntimeException $e) => response()->json([], 500);
        $function = new ReflectionFunction($anonymous);

        return (new CallableRef((string) $function->getFileName(), null, null, (int) $function->getStartLine()))->target();
    }
}
