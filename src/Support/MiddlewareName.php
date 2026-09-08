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
 * A leading `\` is not part of a class name: `Foo::class` never renders one, a hand-written string
 * often carries one, and PHP resolves both to the same class — so it is trimmed off both sides before
 * comparing. Case is NOT folded, though PHP resolves a class name case-insensitively: every spelling
 * the framework's own constructors render is canonical-case, and the alias half is looked up
 * case-sensitively by the framework's own resolver, so folding would answer for a string the framework
 * itself refuses to resolve.
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
     * The entry as every reader should see it, with the leading `\` a hand-written class string may
     * carry taken off. Stated here so a reader matching a class PREFIX — which most of them do, being
     * vendor tables of their own class names — does not have to know the rule.
     */
    public static function normalize(string $middleware): string
    {
        return ltrim($middleware, '\\');
    }

    /**
     * Everything after the name, unsplit — the empty string for a middleware written bare, and null
     * where `$middleware` is none of `$names`.
     *
     * The empty string therefore means "bare OR an empty argument list": `auth` and `auth:` both
     * answer `''`, and they are different middleware strings to the framework. {@see bare()} is what
     * tells them apart, and a caller that reconstructs a name has to ask.
     */
    public static function arguments(string $middleware, string ...$names): ?string
    {
        $subject = self::normalize($middleware);

        foreach ($names as $name) {
            $name = self::normalize($name);

            if ($subject === $name) {
                return '';
            }

            if (str_starts_with($subject, $name.':')) {
                return substr($subject, strlen($name) + 1);
            }
        }

        return null;
    }

    /**
     * The name half of an entry, normalised and with any arguments taken off — what a reader asks
     * `class_exists()` about, since the grammar is `name[:args]` and the arguments are never part of
     * the class.
     */
    public static function name(string $middleware): string
    {
        return self::normalize(explode(':', $middleware, 2)[0]);
    }

    /**
     * A group member's name the way `MiddlewareNameResolver::parseMiddlewareGroup()` reattaches its
     * parameters: on truthiness, where the top-level `resolve()` uses `! is_null()`. The two falsy
     * parameter strings are therefore dropped INSIDE a group and kept on a route, so a member written
     * `auth:` or `auth:0` reaches the pipeline as a bare `auth` — a different middleware from the one
     * the same string names on the route itself.
     */
    public static function asGroupMember(string $member): string
    {
        [$name, $parameters] = array_pad(explode(':', $member, 2), 2, null);

        return $name.($parameters ? ':'.$parameters : '');
    }

    /**
     * Whether a middleware string carries no argument separator at all. The grammar is `name[:args]`,
     * so this is the one fact that separates a bare name from a name with an empty argument list —
     * which the framework treats as two different strings.
     */
    public static function bare(string $middleware): bool
    {
        return ! str_contains($middleware, ':');
    }
}
