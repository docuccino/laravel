<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Workbench\App\Enums\WidgetStatus;

/**
 * Every entry of the `$casts` → JSON Schema table, plus the unknown-entry degradation (docs/testing.md).
 * Each cast base maps to a fixed schema fragment; a caster the table doesn't know returns null so the
 * column keeps its inferred type; enum casts route away through `isEnum()`.
 */
it('maps every known cast base to its schema fragment', function (string $cast, array $expected): void {
    expect(CastSchema::forCast($cast))->toBe($expected);
})->with([
    'datetime' => ['datetime', ['type' => 'string', 'format' => 'date-time']],
    'immutable_datetime' => ['immutable_datetime', ['type' => 'string', 'format' => 'date-time']],
    'custom_datetime' => ['custom_datetime', ['type' => 'string', 'format' => 'date-time']],
    'date' => ['date', ['type' => 'string', 'format' => 'date']],
    'immutable_date' => ['immutable_date', ['type' => 'string', 'format' => 'date']],
    'timestamp' => ['timestamp', ['type' => 'integer']],
    'boolean' => ['boolean', ['type' => 'boolean']],
    'bool' => ['bool', ['type' => 'boolean']],
    'integer' => ['integer', ['type' => 'integer']],
    'int' => ['int', ['type' => 'integer']],
    'real' => ['real', ['type' => 'number']],
    'float' => ['float', ['type' => 'number']],
    'double' => ['double', ['type' => 'number']],
    'decimal' => ['decimal', ['type' => 'string']],
    'string' => ['string', ['type' => 'string']],
    'encrypted' => ['encrypted', ['type' => 'string']],
    'hashed' => ['hashed', ['type' => 'string']],
    'array' => ['array', ['type' => ['array', 'object']]],
    'collection' => ['collection', ['type' => ['array', 'object']]],
    'json' => ['json', ['type' => ['array', 'object']]],
    'object' => ['object', ['type' => 'object']],
]);

it('strips decimal/plain parameters and is case-insensitive on the base', function (): void {
    // A bare parameter after the colon (`decimal:2`) is ignored, and the base is case-insensitive.
    expect(CastSchema::forCast('decimal:2'))->toBe(['type' => 'string'])
        ->and(CastSchema::forCast('DateTime'))->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and(CastSchema::forCast('BOOLEAN'))->toBe(['type' => 'boolean'])
        ->and(CastSchema::forCast('json:unicode'))->toBe(['type' => ['array', 'object']]);
});

it('maps a datetime:FORMAT to an honest format claim', function (string $cast, array $expected): void {
    expect(CastSchema::forCast($cast))->toBe($expected);
})->with([
    // Default and ISO date-time forms.
    'default datetime' => ['datetime', ['type' => 'string', 'format' => 'date-time']],
    'ISO atom' => ['datetime:Y-m-d\\TH:i:sP', ['type' => 'string', 'format' => 'date-time']],
    'space-separated ISO' => ['datetime:Y-m-d H:i:s', ['type' => 'string', 'format' => 'date-time']],
    'date-only' => ['datetime:Y-m-d', ['type' => 'string', 'format' => 'date']],
    // A bespoke non-ISO format is neither date nor date-time: a plain string with the format noted.
    'custom format' => ['datetime:d/m/Y', ['type' => 'string', 'description' => 'Serialized using the date format "d/m/Y".']],
]);

it('decrypts-then-casts an encrypted:<inner> compound to the inner shape', function (): void {
    // encrypted:array/collection/json serialise as the decoded JSON value, not a string;
    // encrypted:object as an object; bare encrypted stays a string.
    expect(CastSchema::forCast('encrypted:array'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::forCast('encrypted:collection'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::forCast('encrypted:json'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::forCast('encrypted:object'))->toBe(['type' => 'object'])
        ->and(CastSchema::forCast('encrypted'))->toBe(['type' => 'string']);
});

it('maps every built-in As* class cast to its serialised shape', function (string $cast, array $expected): void {
    // The `As*` class casts serialise to a fixed shape read from the class FQCN in `$casts`.
    // AsEncrypted* decrypts then casts, so they're the decoded object/array, never the opaque string.
    expect(CastSchema::forCast($cast))->toBe($expected);
})->with([
    'AsStringable → string' => ['Illuminate\\Database\\Eloquent\\Casts\\AsStringable', ['type' => 'string']],
    'AsUri → string' => ['Illuminate\\Database\\Eloquent\\Casts\\AsUri', ['type' => 'string']],
    'AsHtmlString → string' => ['Illuminate\\Database\\Eloquent\\Casts\\AsHtmlString', ['type' => 'string']],
    'AsFluent → object' => ['Illuminate\\Database\\Eloquent\\Casts\\AsFluent', ['type' => 'object']],
    'AsArrayObject → object' => ['Illuminate\\Database\\Eloquent\\Casts\\AsArrayObject', ['type' => 'object']],
    'AsCollection → array' => ['Illuminate\\Database\\Eloquent\\Casts\\AsCollection', ['type' => 'array']],
    'AsEncryptedArrayObject → object' => ['Illuminate\\Database\\Eloquent\\Casts\\AsEncryptedArrayObject', ['type' => 'object']],
    'AsEncryptedCollection → array' => ['Illuminate\\Database\\Eloquent\\Casts\\AsEncryptedCollection', ['type' => 'array']],
]);

it('exposes the enum parameter of an AsEnumCollection / AsEnumArrayObject cast (routed by ModelSchema)', function (?string $enum, string $cast): void {
    // The two enum-valued As* casts serialise to an array of the parameterised enum's values, but forCast
    // returns null: ModelSchema assembles the array + enum routing through the Enum integration, so only
    // the enum FQCN is exposed here.
    expect(CastSchema::forCast($cast))->toBeNull()
        ->and(CastSchema::enumCollectionEnum($cast))->toBe($enum);
})->with([
    'AsEnumCollection:Enum' => [WidgetStatus::class, 'Illuminate\\Database\\Eloquent\\Casts\\AsEnumCollection:'.WidgetStatus::class],
    'AsEnumArrayObject:Enum' => [WidgetStatus::class, 'Illuminate\\Database\\Eloquent\\Casts\\AsEnumArrayObject:'.WidgetStatus::class],
]);

it('reports enumCollectionEnum null for a non-enum-collection cast', function (string $cast): void {
    expect(CastSchema::enumCollectionEnum($cast))->toBeNull();
})->with([
    'a bare enum-collection with no parameter' => ['Illuminate\\Database\\Eloquent\\Casts\\AsEnumCollection'],
    'a plain As* cast' => ['Illuminate\\Database\\Eloquent\\Casts\\AsCollection'],
    'a native cast' => ['datetime'],
    'an empty string' => [''],
]);

it('recognises the date casts serializeDate() governs (excluding the integer timestamp cast)', function (string $cast, bool $isDate): void {
    expect(CastSchema::isDateCast($cast))->toBe($isDate);
})->with([
    'datetime' => ['datetime', true],
    'datetime:FORMAT' => ['datetime:Y-m-d', true],
    'immutable_datetime' => ['immutable_datetime', true],
    'custom_datetime' => ['custom_datetime', true],
    'date' => ['date', true],
    'immutable_date' => ['immutable_date', true],
    'DATE (case-insensitive)' => ['DATE', true],
    'timestamp is a unix integer, not serializeDate' => ['timestamp', false],
    'boolean' => ['boolean', false],
    'a custom caster' => ['App\\Casts\\Money', false],
]);

it('returns null for a cast the table does not know so the column keeps its inferred type', function (string $cast): void {
    expect(CastSchema::forCast($cast))->toBeNull();
})->with([
    'a custom caster class' => ['App\\Casts\\Money'],
    'an unknown keyword' => ['nonsense'],
    'an empty string' => [''],
]);

it('recognises a backed-enum cast base, ignoring parameters and non-enums', function (): void {
    expect(CastSchema::isEnum(WidgetStatus::class))->toBeTrue()
        // The enum-cast class-string may carry a `:default` parameter Laravel strips likewise.
        ->and(CastSchema::isEnum(WidgetStatus::class.':draft'))->toBeTrue()
        ->and(CastSchema::isEnum('datetime'))->toBeFalse()
        ->and(CastSchema::isEnum('App\\Casts\\Money'))->toBeFalse();
});
