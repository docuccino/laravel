<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * Maps an Eloquent `$casts` entry to a JSON Schema fragment: native casts fix a type, decimal/hashed stay
 * strings, `array`/`collection`/`json` admit object OR array, `encrypted:<inner>` takes the inner type.
 *
 * Read in TWO directions, because a column's two appearances answer different questions:
 * {@see written()} is what a response body carries, {@see accepted()} what a request may put in. Every
 * row answers both alike except the date casts (docs/design/defect-classes.md §"One table answering both
 * directions of the wire").
 *
 * Anything enum-valued returns null both ways and is routed through the Enum integration by
 * {@see ModelSchema} — a backed-enum cast, `AsEnumCollection:Enum`, `AsEnumArrayObject:Enum`. An
 * unrecognised custom caster returns null too, leaving the column on its inferred type.
 */
final class CastSchema
{
    private const AS_NAMESPACE = 'Illuminate\\Database\\Eloquent\\Casts\\';

    /**
     * Built-in `As*` class casts with a fixed serialised shape. The `$casts` value is the FQCN, possibly
     * with a trailing `:arg` this table ignores.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CLASS_CASTS = [
        self::AS_NAMESPACE.'AsStringable' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsUri' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsHtmlString' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsFluent' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsArrayObject' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsCollection' => ['type' => 'array'],
        // Decrypt-THEN-cast: these serialise as the decoded JSON value, never the ciphertext string.
        self::AS_NAMESPACE.'AsEncryptedArrayObject' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsEncryptedCollection' => ['type' => 'array'],
    ];

    /** The enum-valued `As*` class casts — an array of the parameterised enum's values. */
    private const AS_ENUM_COLLECTION = [
        self::AS_NAMESPACE.'AsEnumCollection',
        self::AS_NAMESPACE.'AsEnumArrayObject',
    ];

    /**
     * The casts `HasAttributes::addCastAttributesToArray()` hands to `serializeDate()`, matched as it
     * matches them — the whole cast value, so a parameterised one is not among them. `custom_datetime` is
     * absent deliberately: it is the framework's INTERNAL name and reaches no branch of that method.
     */
    private const DATE_HOOK_CASTS = [
        'date',
        'datetime',
        'immutable_date',
        'immutable_datetime',
    ];

    /** Every cast whose `:FORMAT` parameter is a date pattern — the hook's four plus the internal name. */
    private const DATE_CASTS = [...self::DATE_HOOK_CASTS, 'custom_datetime'];

    /**
     * What a response body carries, or null where the fragment is not this table's to give — a
     * hook-governed cast is the date policy's ({@see serializesThroughDateHook()}), and an enum-valued or
     * unrecognised one is routed or falls back as the header says.
     *
     * @return array<string, mixed>|null
     */
    public static function written(string $cast): ?array
    {
        if (self::serializesThroughDateHook($cast)) {
            return null;
        }

        return self::ownDateFormat($cast) ?? self::accepted($cast);
    }

    /**
     * What a request may put in — a filter value, a scope argument, a bound path segment. This is the
     * table itself, which is why {@see written()} reads it for everything but the date casts.
     *
     * @return array<string, mixed>|null
     */
    public static function accepted(string $cast): ?array
    {
        $parts = explode(':', $cast, 2);
        $base = $parts[0];
        $parameter = $parts[1] ?? null;

        // Class casts match on the FQCN case-sensitively, before the native table lowercases the base.
        if (isset(self::CLASS_CASTS[$base])) {
            return self::CLASS_CASTS[$base];
        }

        // `encrypted:<inner>` serialises as the inner type, not an opaque string.
        if (strtolower($base) === 'encrypted' && $parameter !== null && $parameter !== '') {
            return self::accepted($parameter);
        }

        // The date rows are the REQUEST answer — the domain the column stores.
        return match (strtolower($base)) {
            'datetime', 'immutable_datetime', 'custom_datetime' => ['type' => 'string', 'format' => 'date-time'],
            'date', 'immutable_date' => ['type' => 'string', 'format' => 'date'],
            'timestamp' => ['type' => 'integer'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'integer', 'int' => ['type' => 'integer'],
            'real', 'float', 'double' => ['type' => 'number'],
            'decimal' => ['type' => 'string'],
            'string', 'encrypted', 'hashed' => ['type' => 'string'],
            // Decodes to whatever was stored: an assoc array is an object, a list is an array, so both.
            'array', 'collection', 'json' => ['type' => ['array', 'object']],
            'object' => ['type' => 'object'],
            default => null,
        };
    }

    /**
     * What a date cast's OWN `:FORMAT` writes, or null for one naming none — Eloquent formats such a
     * column with the parameter and never reaches `serializeDate()`. Through {@see DateWireFormat}, so an
     * ISO pattern claims a `format` and a bespoke one names the pattern in prose.
     *
     * @return array<string, mixed>|null
     */
    private static function ownDateFormat(string $cast): ?array
    {
        $parts = explode(':', $cast, 2);
        $parameter = $parts[1] ?? '';

        return $parameter !== '' && in_array(strtolower($parts[0]), self::DATE_CASTS, true)
            ? DateWireFormat::serializedSchema($parameter)
            : null;
    }

    /** Whether a cast value names an enum. */
    public static function isEnum(string $cast): bool
    {
        $base = explode(':', $cast, 2)[0];

        return enum_exists($base);
    }

    /** The enum FQCN of an `AsEnumCollection:Enum` / `AsEnumArrayObject:Enum` cast. */
    public static function enumCollectionEnum(string $cast): ?string
    {
        $parts = explode(':', $cast, 2);
        $enum = $parts[1] ?? null;

        return in_array($parts[0], self::AS_ENUM_COLLECTION, true) && $enum !== null && $enum !== ''
            ? $enum
            : null;
    }

    /**
     * Whether `Model::serializeDate()` serialises this cast's value — the whole of when a date column's
     * wire format stops being knowable ({@see DateColumnSchema}). `timestamp` is a unix integer and a
     * PARAMETERISED cast never reaches the hook; "parameterised" is read exactly as
     * {@see ownDateFormat()} reads it, so the guard cannot see fewer forms than the answer it decides.
     */
    public static function serializesThroughDateHook(string $cast): bool
    {
        $parts = explode(':', $cast, 2);

        return ($parts[1] ?? '') === ''
            && in_array(strtolower($parts[0]), self::DATE_HOOK_CASTS, true);
    }
}
