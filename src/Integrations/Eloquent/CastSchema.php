<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

/**
 * Maps an Eloquent `$casts` entry to the JSON Schema fragment it serialises to — datetime casts pick
 * up a `date-time`/`date` format (honouring a `datetime:FORMAT` parameter), native casts fix a type,
 * decimal/hashed stay strings, `array`/`collection`/`json` admit either a JSON object or array, and an
 * `encrypted:<inner>` compound decrypts-then-casts to the inner type.
 *
 * The built-in `Illuminate\Database\Eloquent\Casts\As*` class casts are covered by a small class →
 * fragment table (their FQCN is the `$casts` value): `AsStringable`/`AsUri`/`AsHtmlString` serialise
 * to a string; `AsFluent`/`AsArrayObject` to a JSON object; `AsCollection` to a JSON array; and —
 * decrypt-THEN-cast, so NOT an opaque string — `AsEncryptedArrayObject` to an object,
 * `AsEncryptedCollection` to an array. The two enum-valued class casts (`AsEnumCollection:Enum`,
 * `AsEnumArrayObject:Enum`) serialise to an array of the parameterised enum's values; they are routed
 * through the Enum integration by {@see ModelSchema} (which owns the enum machinery), so this table
 * exposes only their enum-FQCN parameter via {@see enumCollectionEnum()} and returns null for them here.
 *
 * A backed-enum cast (a backed-enum class-string) is likewise routed through the Enum integration and
 * returns null, as does any unrecognised custom caster class, so the column falls back to its inferred
 * type.
 */
final class CastSchema
{
    private const AS_NAMESPACE = 'Illuminate\\Database\\Eloquent\\Casts\\';

    /**
     * The built-in `As*` class casts whose serialised shape is fixed (no enum parameter): the cast's
     * `$casts` value is one of these FQCNs (optionally with a trailing `:arg` this table ignores).
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
        // Decrypt-then-cast: the ciphertext is decrypted and decoded, so these serialise as the decoded
        // JSON value (object/array), never as the opaque encrypted string.
        self::AS_NAMESPACE.'AsEncryptedArrayObject' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsEncryptedCollection' => ['type' => 'array'],
    ];

    /** The two enum-valued `As*` class casts, whose enum parameter {@see ModelSchema} routes. */
    private const AS_ENUM_COLLECTION = [
        self::AS_NAMESPACE.'AsEnumCollection',
        self::AS_NAMESPACE.'AsEnumArrayObject',
    ];

    private const DATE_CASTS = [
        'datetime',
        'immutable_datetime',
        'custom_datetime',
        'date',
        'immutable_date',
    ];

    /**
     * The schema fragment for a cast, or null to fall back to the inferred column type.
     *
     * @return array<string, mixed>|null
     */
    public static function forCast(string $cast): ?array
    {
        $parts = explode(':', $cast, 2);
        $base = $parts[0];
        $parameter = $parts[1] ?? null;

        // Built-in As* class casts are matched on the FQCN (case-sensitive), before the native-cast
        // table lowercases the base. The enum-valued ones fall through to null (routed by ModelSchema).
        if (isset(self::CLASS_CASTS[$base])) {
            return self::CLASS_CASTS[$base];
        }

        // `encrypted:<inner>` decrypts then casts to the inner type — it serialises as that inner type
        // (array/object/string), NOT as an opaque string.
        if (strtolower($base) === 'encrypted' && $parameter !== null && $parameter !== '') {
            return self::forCast($parameter);
        }

        return match (strtolower($base)) {
            'datetime', 'immutable_datetime', 'custom_datetime' => self::datetime($parameter),
            'date', 'immutable_date' => ['type' => 'string', 'format' => 'date'],
            'timestamp' => ['type' => 'integer'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'integer', 'int' => ['type' => 'integer'],
            'real', 'float', 'double' => ['type' => 'number'],
            'decimal' => ['type' => 'string'],
            'string', 'encrypted', 'hashed' => ['type' => 'string'],
            // A JSON/array/collection column decodes to whatever it stored — an assoc array is a JSON
            // object, a list is a JSON array — so it admits both. `json:unicode` is the same shape.
            'array', 'collection', 'json' => ['type' => ['array', 'object']],
            'object' => ['type' => 'object'],
            default => null,
        };
    }

    /**
     * The schema for a `datetime` cast, honouring a `datetime:FORMAT` parameter: the default (ISO-8601)
     * and other time-bearing ISO forms are `date-time`; the ISO date-only form is `date`; any other
     * custom format serialises to a bespoke string that is neither, so it is a plain string with the
     * format noted in the description rather than a wrong `format` claim.
     *
     * @return array<string, mixed>
     */
    private static function datetime(?string $format): array
    {
        if ($format === null || $format === '') {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (in_array($format, ['Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s.uP', 'Y-m-d H:i:s', 'c'], true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if ($format === 'Y-m-d') {
            return ['type' => 'string', 'format' => 'date'];
        }

        return ['type' => 'string', 'description' => sprintf('Serialized using the date format "%s".', $format)];
    }

    /** Whether a cast value names a backed enum (routed through the Enum integration path). */
    public static function isEnum(string $cast): bool
    {
        $base = explode(':', $cast, 2)[0];

        return enum_exists($base);
    }

    /**
     * The enum FQCN of an `AsEnumCollection:Enum` / `AsEnumArrayObject:Enum` cast (serialised as an
     * array of that enum's values), or null when the cast is not one of those class casts. The enum
     * itself is routed through the Enum integration by {@see ModelSchema}, which assembles the array.
     */
    public static function enumCollectionEnum(string $cast): ?string
    {
        $parts = explode(':', $cast, 2);
        $enum = $parts[1] ?? null;

        return in_array($parts[0], self::AS_ENUM_COLLECTION, true) && $enum !== null && $enum !== ''
            ? $enum
            : null;
    }

    /**
     * Whether a cast serialises a date/datetime via `serializeDate()` (so a model overriding that
     * method makes its wire format unknowable — {@see ModelSchema} weakens these to a plain string).
     * The `timestamp` cast is excluded: it serialises as a unix integer, not through `serializeDate()`.
     */
    public static function isDateCast(string $cast): bool
    {
        return in_array(strtolower(explode(':', $cast, 2)[0]), self::DATE_CASTS, true);
    }
}
