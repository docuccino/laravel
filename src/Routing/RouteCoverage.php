<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Support\PortablePattern;
use Illuminate\Routing\Route;

/**
 * Whether every request one form of a route matches is matched by another, decided off what the router
 * compiles — the path's tokens and each segment's requirement, the host regex, the scheme. It answers
 * yes only where that is provable: an undecided pair is "no", so a caller never drops a route another
 * does not in fact serve.
 *
 * @phpstan-import-type Token from RouteTemplate
 *
 * @phpstan-type Alphabet array{negated: bool, chars: string, wide: string, empty: bool}
 *
 * @internal
 */
final class RouteCoverage
{
    /**
     * A key two forms share whenever one could cover the other: their literals and separators in order.
     *
     * @param  list<Token>  $tokens
     */
    public static function skeleton(array $tokens): string
    {
        $key = '';
        foreach ($tokens as $token) {
            $key .= $token['name'] === null ? 'T'.strlen($token['text']).':'.$token['text'] : 'V'.strlen($token['separator']).':'.$token['separator'];
        }

        return $key;
    }

    /**
     * Whether the router answers every request the covered form matches with the covering one, were the
     * covering one tried first.
     *
     * @param  list<Token>  $covering
     * @param  list<Token>  $covered
     */
    public static function covers(Route $coveringRoute, array $covering, Route $coveredRoute, array $covered): bool
    {
        if (! self::coversScheme($coveringRoute, $coveredRoute) || ! self::coversHost($coveringRoute, $coveredRoute)) {
            return false;
        }

        if (count($covering) !== count($covered)) {
            return false;
        }

        foreach ($covering as $i => $token) {
            $other = $covered[$i];
            if ($token['name'] === null || $other['name'] === null) {
                if ($token['name'] !== $other['name'] || $token['text'] !== $other['text']) {
                    return false;
                }

                continue;
            }

            $requirement = (string) $token['requirement'];
            if ($token['separator'] !== $other['separator'] || ! self::coversRequirement($requirement, (string) $other['requirement'])) {
                return false;
            }

            // A possessive segment takes all it can and gives none back, so where it is not the covered
            // one's own it covers only if it stops where what follows it starts.
            if ($requirement !== $other['requirement'] && preg_match('/[*+]\+\z/', $requirement) === 1 && ! self::stopsBefore($requirement, $covering[$i + 1] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the router answers the one path a form without segments matches (`/users/me`) with the
     * covering route, were that route tried first: its compiled regex is the router's own test.
     *
     * @param  list<Token>  $covered  literal tokens only
     */
    public static function coversPath(Route $coveringRoute, string $regex, Route $coveredRoute, array $covered): bool
    {
        if (! self::coversScheme($coveringRoute, $coveredRoute) || ! self::coversHost($coveringRoute, $coveredRoute)) {
            return false;
        }

        return preg_match($regex, self::literalPath($covered)) === 1;
    }

    /**
     * The path a request for a form without segments carries, as the router reads it: `/` for the root,
     * and no trailing slash otherwise.
     *
     * @param  list<Token>  $tokens
     */
    public static function literalPath(array $tokens): string
    {
        $path = rtrim(implode('', array_column($tokens, 'text')), '/');

        return $path === '' ? '/' : $path;
    }

    /** Whether every segment value the second requirement accepts, the first accepts too. */
    public static function coversRequirement(string $covering, string $covered): bool
    {
        if ($covering === $covered) {
            return true;
        }

        $outer = self::exact($covering);
        $inner = self::bound($covered);
        if ($outer === null || $inner === null || ($inner['empty'] && ! $outer['empty'])) {
            return false;
        }

        // A negated alphabet is every character but the ones it names, so it covers another where what it
        // excludes the other excludes too, or where the other names nothing it excludes.
        if ($outer['negated']) {
            return $inner['negated']
                ? self::subset($outer['chars'], $inner['chars']) && self::wideWithin($outer['wide'], $inner['wide'])
                : $outer['chars'] === '' || strpbrk($inner['chars'], $outer['chars']) === false;
        }

        return ! $inner['negated'] && self::subset($inner['chars'], $outer['chars']) && self::wideWithin($inner['wide'], $outer['wide']);
    }

    /**
     * Whether a possessive requirement ends a segment exactly where the next token begins: the form's
     * last token, or one whose first character the requirement cannot take.
     *
     * @param  Token|null  $next
     */
    private static function stopsBefore(string $requirement, ?array $next): bool
    {
        if ($next === null) {
            return true;
        }

        $char = ($next['name'] === null ? $next['text'] : $next['separator'])[0] ?? '';
        $alphabet = self::exact($requirement);
        if ($alphabet === null || $char === '' || ord($char) > 0x7E) {
            return false;
        }

        return $alphabet['negated'] === str_contains($alphabet['chars'], $char);
    }

    /** Whether the classes beyond ASCII one alphabet names (`\d`, `\w`) are within another's; `\w` holds `\d`. */
    private static function wideWithin(string $wide, string $of): bool
    {
        return $wide === '' || str_contains($of, 'w') || ($wide === 'd' && $of === 'd');
    }

    private static function coversScheme(Route $covering, Route $covered): bool
    {
        $scheme = static fn (Route $route): string => $route->httpOnly() ? 'http' : ($route->secure() ? 'https' : '');

        return $scheme($covering) === '' || $scheme($covering) === $scheme($covered);
    }

    /** A route bound to no host answers on every host; otherwise only the same compiled host, names aside. */
    private static function coversHost(Route $covering, Route $covered): bool
    {
        $outer = RouteTemplate::hostRegex($covering);
        if ($outer === null) {
            return true;
        }

        $inner = RouteTemplate::hostRegex($covered);
        $unnamed = static fn (string $regex): string => (string) preg_replace('/\(\?P<\w+>/', '(', $regex);

        return $inner !== null && $unnamed($outer) === $unnamed($inner);
    }

    /**
     * The language of a requirement that is exactly "every string over an alphabet": a character class,
     * `\d` or `\w` under `+`, `*` or their possessive forms, or `.` under them (the router compiles with
     * `s`). Anything else is not read, and so is never said to cover or be covered.
     *
     * @return Alphabet|null
     */
    private static function exact(string $requirement): ?array
    {
        if (preg_match('/\A\.([*+])\+?\z/', $requirement, $dot) === 1) {
            return ['negated' => true, 'chars' => '', 'wide' => '', 'empty' => $dot[1] === '*'];
        }

        // A bare `\d` or `\w` is the class holding it alone.
        $requirement = (string) preg_replace('/\A(\\\\[dw])(?=[*+]\+?\z)/', '[$1]', $requirement);

        if (preg_match('/\A\[(\^?)((?:\\\\.|[^\\\\\]])+)\]([*+])\+?\z/', $requirement, $class) !== 1) {
            return null;
        }

        $set = self::members($class[2]);

        return $set === null ? null : ['negated' => $class[1] === '^', 'chars' => $set['chars'], 'wide' => $set['wide'], 'empty' => $class[3] === '*'];
    }

    /**
     * An alphabet every value of the requirement is spelled in — the exact one where there is one,
     * else the characters of a literal alternation or of the framework's UUID and ULID shorthands.
     *
     * @return Alphabet|null
     */
    private static function bound(string $requirement): ?array
    {
        $exact = self::exact($requirement);
        if ($exact !== null) {
            return $exact;
        }

        $literals = PortablePattern::literals($requirement);
        if ($literals !== null) {
            return ['negated' => false, 'chars' => count_chars(implode('', $literals), 3), 'wide' => '', 'empty' => false];
        }

        return match (RouteConstraints::format($requirement)) {
            // Its `\d` is every script's digit under the router's `u`.
            'uuid' => ['negated' => false, 'chars' => '-0123456789ABCDEFabcdef', 'wide' => 'd', 'empty' => false],
            'ulid' => ['negated' => false, 'chars' => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', 'wide' => '', 'empty' => false],
            default => null,
        };
    }

    /**
     * The ASCII characters a class body names, and the classes it names beyond ASCII (`\d` and `\w`
     * under `u`); null for anything else a class can hold — a range with an escape at either end among
     * them, which is read no further than that.
     *
     * @return array{chars: string, wide: string}|null
     */
    private static function members(string $body): ?array
    {
        $chars = '';
        $wide = '';
        $length = strlen($body);
        // A `-` after an atom and before another character makes the two a range.
        $ranges = static fn (int $at): bool => ($body[$at + 1] ?? '') === '-' && isset($body[$at + 2]);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '\\') {
                $next = $body[++$i] ?? '';
                if ($ranges($i)) {
                    return null;
                }

                if ($next === 'd' || $next === 'w') {
                    $chars .= $next === 'd' ? '0123456789' : '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';
                    $wide .= $next;
                } elseif (preg_match('/\A[[:punct:]]\z/', $next) === 1) {
                    $chars .= $next;
                } else {
                    return null;
                }

                continue;
            }

            // A POSIX class or a nested set is past what this reads.
            if ($char === '[' || ord($char) > 0x7E || ord($char) < 0x20) {
                return null;
            }

            if ($ranges($i)) {
                $to = $body[$i + 2];
                if ($to === '\\' || $to === '[' || ord($to) < ord($char) || ord($to) > 0x7E) {
                    return null;
                }
                $chars .= implode('', array_map('chr', range(ord($char), ord($to))));
                $i += 2;

                continue;
            }

            $chars .= $char;
        }

        return ['chars' => count_chars($chars, 3), 'wide' => str_contains($wide, 'w') ? 'w' : ($wide === '' ? '' : 'd')];
    }

    private static function subset(string $chars, string $of): bool
    {
        return $chars === '' || strspn($chars, $of) === strlen($chars);
    }
}
