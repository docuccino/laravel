<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * What makes a string a request header's name, and when two names are the same header — as the framework's
 * header bag answers it. Its readers sit on both sides of the Extensions/Integrations line (the request-header
 * reads publish a header, the FormRequest's copied inputs find the one they published), and two readings of
 * "the same header" are how a copy stops finding its read.
 */
final class HeaderNames
{
    /** RFC 9110 `token`: a name outside it is no header a client could send. */
    private const TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    public static function isToken(string $name): bool
    {
        return preg_match(self::TOKEN, $name) === 1;
    }

    /** The key the framework's header bag looks a name up by: lowercased, with `_` read as `-`. */
    public static function lookupKey(string $name): string
    {
        return strtolower(strtr($name, '_', '-'));
    }
}
