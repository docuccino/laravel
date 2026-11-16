<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use DateTimeImmutable;
use DateTimeZone;
use Docuccino\Core\Draft\SchemaNotes;

/**
 * A PHP `date()` format string → what a value written with it publishes. One home for the policy, because
 * three readers ask the same question: a Data class's response properties read the format their transformer
 * writes, its request properties the one their cast reads, and an Eloquent `datetime:FORMAT` cast its own
 * parameter.
 *
 * The `format` claim is an ALLOW-LIST rather than a heuristic: `date`/`date-time` name RFC 3339 shapes and
 * nothing else, so a pattern that writes something else publishes a string naming the pattern instead of a
 * keyword its own values fail. The bytes are the one thing the schema cannot carry, so an example is
 * rendered WITH the pattern rather than derived from the keyword.
 */
final class DateWireFormat
{
    /** Spatie's own `data.date_format` default (`DATE_ATOM`), so an unconfigured app documents what it sends. */
    public const DEFAULT_FORMAT = 'Y-m-d\TH:i:sP';

    /** The one PHP format whose value is an integer rather than a formatted string. */
    public const UNIX = 'U';

    /** What an integer epoch is, in the one sentence every reader says it with. */
    public const TIMESTAMP_NOTE = 'Unix timestamp (seconds).';

    /** The instant every {@see example()} is of: a constant, so no clock or locale reaches the document. */
    private const SAMPLE_INSTANT = '2024-01-01 00:00:00';

    /**
     * The formats whose rendered value really is the OAS `format` it would claim — RFC 3339 throughout:
     * `DATE_ATOM` and its microsecond form (spatie's default), Carbon's JSON form, PHP's `c`, and the ISO
     * date. A pattern absent here may still write a date (`d/m/Y`, `Y-m-d H:i:s`, `Y-m-d\T`); what it does
     * not write is a value the keyword's own validator accepts.
     *
     * @var array<string, string>
     */
    private const ISO = [
        'Y-m-d\TH:i:sP' => 'date-time',
        'Y-m-d\TH:i:s.uP' => 'date-time',
        'Y-m-d\TH:i:s.u\Z' => 'date-time',
        'c' => 'date-time',
        'Y-m-d' => 'date',
    ];

    /** The OAS `format` a value written this way satisfies, or null where no keyword names its shape. */
    public static function oas(string $phpFormat): ?string
    {
        return self::ISO[$phpFormat] ?? null;
    }

    /**
     * The schema a value SERIALIZED with this format publishes: the `format` keyword where one is honest,
     * else a string with the pattern named in a note — a vague true shape beats a precise false one. A
     * note ({@see SchemaNotes}) rather than the description, so prose the property's author writes leads
     * it instead of replacing it.
     *
     * @return array<string, mixed>
     */
    public static function serializedSchema(string $phpFormat): array
    {
        $oas = self::oas($phpFormat);

        return $oas !== null
            ? ['type' => 'string', 'format' => $oas]
            : SchemaNotes::onto(['type' => 'string'], [sprintf('Serialized using the date format "%s".', $phpFormat)]);
    }

    /**
     * What a value serialized as an integer Unix timestamp publishes, its meaning as a note for the reason
     * {@see serializedSchema()} gives.
     *
     * @return array<string, mixed>
     */
    public static function timestampSchema(): array
    {
        return SchemaNotes::onto(['type' => 'integer'], [self::TIMESTAMP_NOTE]);
    }

    /**
     * The OAS `format` every value written with any of these patterns satisfies, or null where they do not
     * all share one: a request accepting `d/m/Y` or `Y-m-d` takes a value no single keyword names.
     *
     * @param  non-empty-list<string>  $phpFormats
     */
    public static function oasOfAll(array $phpFormats): ?string
    {
        $named = array_unique(array_map(self::oas(...), $phpFormats));

        return count($named) === 1 ? reset($named) : null;
    }

    /**
     * The note naming the pattern a request ACCEPTS, or the patterns where it accepts any of several, for a
     * reader whose `format` keyword cannot say it. Several are quoted, as a pattern may hold a comma.
     */
    public static function expected(string $phpFormat, string ...$more): string
    {
        if ($more === []) {
            return sprintf('Expected format: %s', $phpFormat);
        }

        return sprintf('Expected format: one of "%s"', implode('", "', [$phpFormat, ...$more]));
    }

    /**
     * The pattern the values `createFromFormat()` READS with `$phpFormat` are written in: the format less its
     * unescaped `!` and `|`, which reset fields rather than match a byte, and which `format()` would write
     * literally. Only for a reader that parses with no round trip — Laravel's `date_format` rule formats the
     * parsed value back and compares, so its `!` is a byte no value matches.
     */
    public static function readPattern(string $phpFormat): string
    {
        return preg_replace('/\\\\.(*SKIP)(*FAIL)|[!|]/s', '', $phpFormat) ?? $phpFormat;
    }

    /** The fixed instant rendered with the pattern — the bytes the wire really carries. */
    public static function example(string $phpFormat): string
    {
        return (new DateTimeImmutable(self::SAMPLE_INSTANT, new DateTimeZone('UTC')))->format($phpFormat);
    }

    /**
     * Every pattern {@see oas()} answers for — the source of truth a catalogue test reads.
     *
     * @return list<string>
     */
    public static function isoFormats(): array
    {
        return array_keys(self::ISO);
    }
}
