<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * What Laravel's default middleware does to a blank request string: `TrimStrings` clears it with
 * `Str::trim()` and `ConvertEmptyStringsToNull` turns the empty result into null before any rule runs, so
 * a nullable field no refusing rule holds to account takes it as the null. The one place that says which
 * strings, at which keys, and which rules refuse.
 */
final class BlankString
{
    /**
     * `Str::trim()`'s whitespace and invisible characters from Laravel 12 on, a superset of `trim()`'s —
     * one code point each, so it reads as written under ECMA-262's `u` flag and PHP's alike.
     */
    public const string PATTERN = '^[\x00\t-\r '
        ."\u{85}\u{A0}\u{AD}\u{34F}\u{61C}\u{115F}\u{1160}\u{1680}\u{17B4}\u{17B5}\u{180E}\u{2000}"
        ."\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}\u{200B}"
        ."\u{200C}\u{200D}\u{200E}\u{200F}\u{2028}\u{2029}\u{202F}\u{205F}\u{2060}\u{2061}\u{2062}"
        ."\u{2063}\u{2064}\u{2065}\u{206A}\u{206B}\u{206C}\u{206D}\u{206E}\u{206F}\u{2800}\u{3000}"
        ."\u{3164}\u{FEFF}\u{FFA0}\u{1D159}\u{1D173}\u{1D174}\u{1D175}\u{1D176}\u{1D177}\u{1D178}"
        ."\u{1D179}\u{1D17A}\u{E0020}"
        .']*$';

    /** The empty string alone: all that reaches a key `TrimStrings` leaves untrimmed as null. */
    public const string EMPTY = '^$';

    /**
     * The implicit rules that fail the null a blank becomes whatever else the request holds. Laravel's
     * other implicit rules pass it, or refuse it only while another field says so.
     *
     * @var list<string>
     */
    public const array REFUSED_BY = ['accepted', 'declined', 'filled', 'missing', 'required'];

    /** @var list<string>|null */
    private static ?array $untrimmed = null;

    /** Whether `$rule` refuses a blank on its own. */
    public static function refusedBy(string $rule): bool
    {
        return in_array($rule, self::REFUSED_BY, true);
    }

    /**
     * The strings read as null at a field path: every blank, or only the empty string at a key the
     * framework's own `TrimStrings` leaves untrimmed (`password` and its kin). A list an application
     * configures itself is not read, so its keys are treated as trimmed.
     */
    public static function at(string $path): string
    {
        $untrimmed = self::untrimmed();

        return $untrimmed !== [] && Str::is($untrimmed, $path) ? self::EMPTY : self::PATTERN;
    }

    /**
     * The keys the installed `TrimStrings` skips by default.
     *
     * @return list<string>
     */
    public static function untrimmed(): array
    {
        if (self::$untrimmed !== null) {
            return self::$untrimmed;
        }

        $except = property_exists(TrimStrings::class, 'except') ? (new ReflectionProperty(TrimStrings::class, 'except'))->getDefaultValue() : [];

        return self::$untrimmed = is_array($except) ? array_values(array_filter($except, is_string(...))) : [];
    }
}
