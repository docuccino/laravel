<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Docuccino\Laravel\Integrations\Eloquent\DateColumnSchema;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Dial;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gnomon;
use Workbench\App\Enums\WidgetStatus;

/**
 * Every entry of the `$casts` → JSON Schema table in BOTH directions, plus the unknown-entry
 * degradation (docs/testing.md). A cast base maps to a fixed fragment; the date casts the
 * `serializeDate()` hook governs have no response answer to give here and defer to the date policy;
 * a caster the table doesn't know returns null in both directions so the column keeps its inferred
 * type; enum casts route away through `isEnum()`.
 */

/** Column → [cast, whether the bytes prove `serializeDate()` governs it]. */
$dialCasts = [
    'a date cast' => ['dated', 'date', true],
    'an immutable date cast' => ['dated_immutable', 'immutable_date', true],
    'a datetime cast' => ['stamped', 'datetime', true],
    'an immutable datetime cast' => ['stamped_immutable', 'immutable_datetime', true],
    // The framework hands the hook the whole cast VALUE, so a name it never spells is not on the list.
    'the internal cast-type name' => ['internal_named', 'custom_datetime', false],
    'a parameterised datetime cast' => ['patterned', 'datetime:d/m/Y', false],
    'a parameterised date cast' => ['patterned_date', 'date:Y-m-d', false],
    'a bespoke-pattern date cast' => ['patterned_date_bespoke', 'date:d/m/Y', false],
    'a parameterised immutable cast' => ['patterned_immutable', 'immutable_datetime:d/m/Y', false],
    'the unix timestamp cast' => ['unixed', 'timestamp', false],
];

/**
 * The columns {@see Dial} declares a cast for. `getCasts()` adds the primary key's own cast, which is
 * no date form and no part of what this file reads.
 *
 * @return list<string>
 */
function dialColumns(): array
{
    $dial = new Dial;

    return array_values(array_diff(array_keys($dial->getCasts()), [$dial->getKeyName()]));
}

it('maps every known cast base in both directions', function (string $cast, ?array $written, ?array $accepted): void {
    expect(CastSchema::written($cast))->toBe($written)
        ->and(CastSchema::accepted($cast))->toBe($accepted);
})->with([
    // The hook's four: what the RESPONSE carries is the date policy's to say, so there is no answer
    // here; what a REQUEST may put in is the domain the cast names.
    'datetime' => ['datetime', null, ['type' => 'string', 'format' => 'date-time']],
    'immutable_datetime' => ['immutable_datetime', null, ['type' => 'string', 'format' => 'date-time']],
    'date' => ['date', null, ['type' => 'string', 'format' => 'date']],
    'immutable_date' => ['immutable_date', null, ['type' => 'string', 'format' => 'date']],
    // Everything else answers both directions alike.
    'custom_datetime' => ['custom_datetime', ['type' => 'string', 'format' => 'date-time'], ['type' => 'string', 'format' => 'date-time']],
    'timestamp' => ['timestamp', ['type' => 'integer'], ['type' => 'integer']],
    'boolean' => ['boolean', ['type' => 'boolean'], ['type' => 'boolean']],
    'bool' => ['bool', ['type' => 'boolean'], ['type' => 'boolean']],
    'integer' => ['integer', ['type' => 'integer'], ['type' => 'integer']],
    'int' => ['int', ['type' => 'integer'], ['type' => 'integer']],
    'real' => ['real', ['type' => 'number'], ['type' => 'number']],
    'float' => ['float', ['type' => 'number'], ['type' => 'number']],
    'double' => ['double', ['type' => 'number'], ['type' => 'number']],
    'decimal' => ['decimal', ['type' => 'string'], ['type' => 'string']],
    'string' => ['string', ['type' => 'string'], ['type' => 'string']],
    'encrypted' => ['encrypted', ['type' => 'string'], ['type' => 'string']],
    'hashed' => ['hashed', ['type' => 'string'], ['type' => 'string']],
    'array' => ['array', ['type' => ['array', 'object']], ['type' => ['array', 'object']]],
    'collection' => ['collection', ['type' => ['array', 'object']], ['type' => ['array', 'object']]],
    'json' => ['json', ['type' => ['array', 'object']], ['type' => ['array', 'object']]],
    'object' => ['object', ['type' => 'object'], ['type' => 'object']],
]);

it('strips decimal/plain parameters and is case-insensitive on the base', function (): void {
    // A bare parameter after the colon (`decimal:2`) is ignored, and the base is case-insensitive.
    expect(CastSchema::accepted('decimal:2'))->toBe(['type' => 'string'])
        ->and(CastSchema::accepted('DateTime'))->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and(CastSchema::accepted('BOOLEAN'))->toBe(['type' => 'boolean'])
        ->and(CastSchema::accepted('json:unicode'))->toBe(['type' => ['array', 'object']])
        // Case-insensitive in the guard too, or an application spelling a cast `DATE` would have its
        // response direction answered by a table that has no answer for it.
        ->and(CastSchema::written('DATE'))->toBeNull()
        ->and(CastSchema::serializesThroughDateHook('DATE'))->toBeTrue();
});

it('publishes the format a parameterised date cast writes, and accepts the domain it stores', function (string $cast, array $written, array $accepted): void {
    // A cast naming its own format is WRITTEN with it, whichever of the five carries it; the hook is
    // never reached. A request puts in the cast's domain either way — the value is matched against the
    // stored column, which the parameter does not touch.
    expect(CastSchema::written($cast))->toBe($written)
        ->and(CastSchema::accepted($cast))->toBe($accepted);
})->with([
    // ISO date-time forms.
    'ISO atom' => ['datetime:Y-m-d\\TH:i:sP', ['type' => 'string', 'format' => 'date-time'], ['type' => 'string', 'format' => 'date-time']],
    'Carbon JSON form' => ['datetime:Y-m-d\\TH:i:s.u\\Z', ['type' => 'string', 'format' => 'date-time'], ['type' => 'string', 'format' => 'date-time']],
    'date-only' => ['datetime:Y-m-d', ['type' => 'string', 'format' => 'date'], ['type' => 'string', 'format' => 'date-time']],
    // `date-time` is RFC 3339, which wants the `T` and an offset — a space-separated value has neither, so
    // it is a described string like any other format no keyword names.
    'space-separated' => ['datetime:Y-m-d H:i:s', ['type' => 'string', 'description' => 'Serialized using the date format "Y-m-d H:i:s".'], ['type' => 'string', 'format' => 'date-time']],
    'custom format' => ['datetime:d/m/Y', ['type' => 'string', 'description' => 'Serialized using the date format "d/m/Y".'], ['type' => 'string', 'format' => 'date-time']],
    // The `date` half, which read its parameter in neither direction: a bespoke pattern published
    // `format: date` over bytes no full-date validator accepts, and `date:c` a full-date keyword over a
    // value carrying a time.
    'a date cast with a bespoke pattern' => ['date:d/m/Y', ['type' => 'string', 'description' => 'Serialized using the date format "d/m/Y".'], ['type' => 'string', 'format' => 'date']],
    'a date cast with an ISO date pattern' => ['date:Y-m-d', ['type' => 'string', 'format' => 'date'], ['type' => 'string', 'format' => 'date']],
    'a date cast with a full ISO pattern' => ['date:c', ['type' => 'string', 'format' => 'date-time'], ['type' => 'string', 'format' => 'date']],
    'an immutable date cast with a bespoke pattern' => ['immutable_date:d/m/Y', ['type' => 'string', 'description' => 'Serialized using the date format "d/m/Y".'], ['type' => 'string', 'format' => 'date']],
    // The internal name takes its parameter the same way, and it is the only form that MUST have one.
    'the internal cast-type name' => ['custom_datetime:d/m/Y', ['type' => 'string', 'description' => 'Serialized using the date format "d/m/Y".'], ['type' => 'string', 'format' => 'date-time']],
]);

it('decrypts-then-casts an encrypted:<inner> compound to the inner shape', function (): void {
    // encrypted:array/collection/json serialise as the decoded JSON value, not a string;
    // encrypted:object as an object; bare encrypted stays a string.
    expect(CastSchema::written('encrypted:array'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::written('encrypted:collection'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::written('encrypted:json'))->toBe(['type' => ['array', 'object']])
        ->and(CastSchema::written('encrypted:object'))->toBe(['type' => 'object'])
        ->and(CastSchema::written('encrypted'))->toBe(['type' => 'string']);
});

it('maps every built-in As* class cast to its serialised shape', function (string $cast, array $expected): void {
    // The `As*` class casts serialise to a fixed shape read from the class FQCN in `$casts`.
    // AsEncrypted* decrypts then casts, so they're the decoded object/array, never the opaque string.
    expect(CastSchema::written($cast))->toBe($expected)
        ->and(CastSchema::accepted($cast))->toBe($expected);
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
    // The two enum-valued As* casts serialise to an array of the parameterised enum's values, but the
    // table answers null: ModelSchema assembles the array + enum routing through the Enum integration,
    // so only the enum FQCN is exposed here.
    expect(CastSchema::written($cast))->toBeNull()
        ->and(CastSchema::accepted($cast))->toBeNull()
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

it('returns null in both directions for a cast the table does not know', function (string $cast): void {
    // The column then keeps its inferred type rather than being described by a guess.
    expect(CastSchema::written($cast))->toBeNull()
        ->and(CastSchema::accepted($cast))->toBeNull();
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

it('governs by the hook exactly the casts whose bytes the hook decides', function (string $column, string $cast, bool $hookGoverned): void {
    // Stated from Laravel and not from the list: a cast belongs to the hook when, and only when,
    // replacing `serializeDate()` changes the BYTES the column serialises to. Two fixtures differing by
    // that method alone settle every row, so a release that moves one fails here rather than in a
    // document — and a response direction is given up only where a real loss is possible. Encoded
    // rather than compared in PHP: a cast can leave a Carbon in the array, and two equal instants are
    // two objects.
    $raw = array_fill_keys(dialColumns(), Dial::RAW);
    $plain = (new Dial)->setRawAttributes($raw, true)->toArray();
    $overridden = (new Gnomon)->setRawAttributes($raw, true)->toArray();

    expect(json_encode($plain[$column]) !== json_encode($overridden[$column]))->toBe($hookGoverned)
        ->and(CastSchema::serializesThroughDateHook($cast))->toBe($hookGoverned)
        ->and(CastSchema::written($cast) === null)->toBe($hookGoverned);
})->with($dialCasts);

it('reads every cast form the bytes fixture carries', function () use ($dialCasts): void {
    // The row set above is hand-written, so the fixture's own casts are the source of truth: adding a
    // date-cast form to it without a row here fails rather than going quietly unread.
    expect(array_map(static fn (array $row): string => $row[0], array_values($dialCasts)))
        ->toBe(dialColumns());
});

it('gives a date cast its response direction up because the bytes carry a time', function (): void {
    // The defect this table had, read off the wire: a `date` cast rounds to start-of-day and is then
    // serialised through the hook like any other date, so the value a response carries is a full
    // date-time and `format: date` is a claim its own bytes fail.
    $raw = array_fill_keys(dialColumns(), Dial::RAW);
    $written = (new Dial)->setRawAttributes($raw, true)->toArray()['dated'];

    expect($written)->toBe(DateWireFormat::example(DateColumnSchema::DEFAULT_FORMAT))
        ->and(DateWireFormat::oas(DateColumnSchema::DEFAULT_FORMAT))->toBe('date-time')
        // What an RFC 3339 full-date validator would have had to accept, and does not.
        ->and(preg_match('/^\d{4}-\d{2}-\d{2}$/', $written))->toBe(0)
        // So the response direction defers to the policy that reads those bytes, while a request may
        // still put in the date the column is stored as.
        ->and(CastSchema::written('date'))->toBeNull()
        ->and(CastSchema::accepted('date'))->toBe(['type' => 'string', 'format' => 'date']);
});

/**
 * Whether a value really satisfies the `format` a fragment claims — stated from RFC 3339, not from the
 * pattern the value was written with, so it cannot agree with the table by construction. A fragment
 * claiming no format has nothing to violate, so its `type` answers.
 *
 * @param  array<string, mixed>  $fragment
 */
function dateFragmentHoldsFor(array $fragment, mixed $value): bool
{
    return match ($fragment['format'] ?? null) {
        'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
        'date-time' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1,
        null => match ($fragment['type'] ?? null) {
            'string' => is_string($value),
            'integer' => is_int($value),
            default => false,
        },
        default => false,
    };
}

it('claims for every date cast only what the column really sends', function (string $column, string $cast, bool $hookGoverned): void {
    // The whole ladder against the wire, read off the ENCODED document because a cast can leave a
    // Carbon in `toArray()` and what a client validates is the JSON. The two producers divide the
    // ladder, so their UNION is asserted: a cast the hook governs owes nothing here and the date policy
    // answers, which on a model with no override is the framework's own form.
    $raw = array_fill_keys(dialColumns(), Dial::RAW);
    $sent = json_decode((string) json_encode((new Dial)->setRawAttributes($raw, true)->toArray()), true);

    $published = CastSchema::written($cast) ?? DateWireFormat::serializedSchema(DateColumnSchema::DEFAULT_FORMAT);

    expect(dateFragmentHoldsFor($published, $sent[$column]))
        ->toBeTrue($cast.' publishes '.json_encode($published).' for the bytes '.json_encode($sent[$column]));
})->with($dialCasts);
