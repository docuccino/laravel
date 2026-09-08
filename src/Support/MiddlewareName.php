<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * Whether a route's middleware string names a given middleware, in either spelling the framework
 * writes it: the registered alias, or the middleware's own class name — each of them bare, or followed
 * by `:` and the arguments.
 *
 * The class-name spelling is not exotic. Every static constructor the framework ships for a middleware
 * that takes arguments renders `static::class.':'.$arguments` — `Authorize::using()`,
 * `ValidateSignature::relative()`, `EnsureEmailIsVerified::redirectTo()` — so a reader that knows only
 * the alias sees no middleware at all on a route written that way. One list per middleware, read here,
 * because the alias and the class name are two spellings of one thing and a reader that knows one of
 * them is a hole.
 *
 * Pure, so the grammar is dataset-testable.
 */
final class MiddlewareName
{
    /** Whether `$middleware` is any of `$names`, whatever arguments it carries. */
    public static function matches(string $middleware, string ...$names): bool
    {
        return self::arguments($middleware, ...$names) !== null;
    }

    /**
     * Everything after the name, unsplit — the empty string for a middleware written bare, and null
     * where `$middleware` is none of `$names`.
     */
    public static function arguments(string $middleware, string ...$names): ?string
    {
        foreach ($names as $name) {
            if ($middleware === $name) {
                return '';
            }

            if (str_starts_with($middleware, $name.':')) {
                return substr($middleware, strlen($name) + 1);
            }
        }

        return null;
    }
}
