<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Docuccino\Laravel\Integrations\Eloquent\DateColumnSchema;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Astrolabe;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Chronicle;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Coupon;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Hourglass;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Invoice;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Ledger;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Metronome;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waybill;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\Ticket;

/**
 * `{post:slug}` accepts a slug, so the parameter has to be typed from THAT column — the model's route
 * key would say `integer`, and a confident wrong type is worse than no type at all. This is the whole
 * column-typing table, plus every way it declines: an unknown column, a shape no URL segment can carry,
 * a class that is not a model, an engine that recovered nothing. A refusal is not a gap here, it is the
 * answer that keeps the caller's plain-string fallback honest.
 */
it('types a bound column, or refuses to', function (string $fqcn, string $column, array $properties, ?array $expected): void {
    $metadata = new ClassMetadata($fqcn, array_map(
        static fn (array $property): PropertyMetadata => new PropertyMetadata($property[0], $property[1]),
        $properties,
    ));

    expect((new EloquentModelReflector)->columnSchemaFor($fqcn, $column, $metadata)[0])->toBe($expected);
})->with([

    // Every scalar the engine can recover for a column, i.e. every entry of the shared scalar table.
    'a string @property' => [Merchant::class, 'name', [['name', ScalarT::string()]], ['type' => 'string']],
    'an int @property' => [Merchant::class, 'code', [['code', ScalarT::int()]], ['type' => 'integer']],
    'a float @property' => [Merchant::class, 'rating', [['rating', ScalarT::float()]], ['type' => 'number']],
    'a bool @property' => [Merchant::class, 'listed', [['listed', ScalarT::bool()]], ['type' => 'boolean']],

    // A bound segment always carries a value, so the null branch of a nullable column is not part of
    // what the client sends.
    'a nullable scalar @property' => [
        Merchant::class,
        'name',
        [['name', UnionT::of([ScalarT::string(), new NullT])]],
        ['type' => 'string'],
    ],

    // Refusals: nothing here has a single-scalar form a URL segment could carry.
    'a class-typed @property' => [Merchant::class, 'joined', [['joined', new ClassT('DateTimeImmutable')]], null],
    'an enum @property' => [Merchant::class, 'tier', [['tier', new EnumT('Tier', ['Gold'])]], null],
    'an unrecovered @property' => [Merchant::class, 'name', [['name', new UnknownT('nope')]], null],
    'a union of two scalars' => [
        Merchant::class,
        'name',
        [['name', UnionT::of([ScalarT::string(), ScalarT::int()])]],
        null,
    ],

    // A `$casts` entry pins the column, beating whatever the docblock claimed — ModelSchema's order.
    'a boolean cast over a wrong @property' => [
        Widget::class,
        'is_active',
        [['is_active', ScalarT::string()]],
        ['type' => 'boolean'],
    ],
    'a datetime cast' => [Widget::class, 'created_at', [], ['type' => 'string', 'format' => 'date-time']],
    // The segment carries the value a client types, which for a date-cast column is the date the
    // column is stored as — not the date-time its response body carries.
    'a date cast' => [Astrolabe::class, 'sighted_on', [], ['type' => 'string', 'format' => 'date']],
    // A cast naming its own format is written with it and never reaches the hook, so the override
    // changes nothing here. What a SEGMENT carries is still the stored column, which is why a bespoke
    // pattern — the one the body describes in prose — leaves the parameter on `format: date`.
    'a cast naming its own format, under the override' => [Metronome::class, 'beat_on', [], ['type' => 'string', 'format' => 'date']],
    'a cast naming a bespoke format, under the override' => [Metronome::class, 'chimed_on', [], ['type' => 'string', 'format' => 'date']],
    // Read off the Laravel 11+ `casts()` METHOD, which reflection of default properties cannot see.
    'a cast declared by the casts() method' => [Invoice::class, 'issued_at', [], ['type' => 'string', 'format' => 'date-time']],
    // A serializeDate() override makes the wire format unknowable, so the format claim is dropped.
    'a datetime cast under a serializeDate override' => [Chronicle::class, 'published_at', [], ['type' => 'string']],
    // These serialise as containers in a body and as nothing at all in a path segment.
    'an array cast' => [Widget::class, 'meta', [], null],
    'an enum cast' => [Widget::class, 'status', [], null],

    // A `$dates` entry is a date-time column with no cast to say so, and it outranks whatever the engine
    // recovered — the same order a response body reads it in. A tag naming a different type is not a
    // second opinion about the wire: the column is a date attribute, and a date attribute is whatever
    // `serializeDate()` writes.
    'a $dates column' => [Ledger::class, 'posted_at', [], ['type' => 'string', 'format' => 'date-time']],
    'a $dates column the engine typed otherwise' => [
        Ledger::class,
        'posted_at',
        [['posted_at', ScalarT::int()]],
        ['type' => 'string', 'format' => 'date-time'],
    ],
    // Under the override the value is a bespoke string whatever the docblock claimed, so the override
    // is read before the engine's type rather than after it.
    'a $dates column the engine typed, under a serializeDate override' => [
        Hourglass::class,
        'posted_at',
        [['posted_at', ScalarT::int()]],
        ['type' => 'string'],
    ],
    // A framework timestamp is a date attribute no `$casts`/`$dates` entry names, so nothing typed it
    // before; under the override it is still a string the client sends.
    'a framework timestamp under a serializeDate override' => [Hourglass::class, 'created_at', [], ['type' => 'string']],
    // A `$fillable`-only name types the column as "anything", which is no answer for a path segment.
    'a $fillable-only column' => [Ledger::class, 'reference', [], null],

    // The key column still answers with the key's schema, which is where the formats live.
    'the key column of a HasUuids model, over a weaker @property' => [
        Vault::class,
        'id',
        [['id', ScalarT::string()]],
        ['type' => 'string', 'format' => 'uuid'],
    ],
    'the key column of a HasUlids model' => [Waybill::class, 'id', [], ['type' => 'string', 'format' => 'ulid']],
    'the key column of a string-keyed model' => [Coupon::class, 'id', [], ['type' => 'string']],
    'the key column with nothing else to go on' => [Blank::class, 'id', [], ['type' => 'integer']],

    // The degradations that matter most: nothing recovered, so nothing is claimed.
    'a column no source mentions' => [Blank::class, 'slug', [], null],
    'a column on a model the engine reported no properties for' => [Merchant::class, 'name', [], null],
    'a binding on a class that is not a model' => [Ticket::class, 'reference', [['reference', ScalarT::string()]], null],
    'a binding on a class that does not exist' => ['App\\Models\\Nope', 'slug', [], null],
]);

it('reads the same column the same way in a path as in a body', function (): void {
    // The two answers are allowed to differ in shape (a body may carry `null`), never in kind. Pinning
    // one column both ways is what stops the path table drifting away from ModelSchema's.
    $metadata = new ClassMetadata(Merchant::class, [new PropertyMetadata('name', ScalarT::string())]);

    expect((new EloquentModelReflector)->columnSchemaFor(Merchant::class, 'name', $metadata)[0])
        ->toBe(schemaConverter()->convert(ScalarT::string()));
});

it('leaves a nullable column non-null only in the path', function (DType $type): void {
    // The body keeps the null branch; the path drops it. Both are true of the same column.
    $metadata = new ClassMetadata(Merchant::class, [new PropertyMetadata('name', $type)]);

    expect((new EloquentModelReflector)->columnSchemaFor(Merchant::class, 'name', $metadata)[0])
        ->toBe(['type' => 'string']);
})->with([
    // The null-last spelling is the table's own `a nullable scalar @property` row above; this states the
    // other branch order, which no row does.
    'null first' => [UnionT::of([new NullT, ScalarT::string()])],
]);

/**
 * The answer beside the schema: a bound date column loses its `format` to a `serializeDate()` override,
 * and the caller only knows because the same call said so ({@see DateColumnSchema}). Both answers are
 * here, plus the columns that give nothing up, so a row that stopped reporting it fails rather than
 * quietly publishing a weakened parameter nobody is told about.
 */
it('says whether a bound column gave its date format up', function (string $fqcn, string $column, array $properties, bool $expected): void {
    $metadata = new ClassMetadata($fqcn, array_map(
        static fn (array $property): PropertyMetadata => new PropertyMetadata($property[0], $property[1]),
        $properties,
    ));

    expect((new EloquentModelReflector)->columnSchemaFor($fqcn, $column, $metadata)[1])->toBe($expected);
})->with([
    'a datetime cast under the override' => [Chronicle::class, 'published_at', [], true],
    'a $dates column under the override' => [Hourglass::class, 'posted_at', [], true],
    'a $dates column the docblock typed, under the override' => [
        Hourglass::class,
        'posted_at',
        [['posted_at', ScalarT::int()]],
        true,
    ],
    'a framework timestamp under the override' => [Hourglass::class, 'created_at', [], true],

    // The same columns without an override keep their `format`, so there is nothing to report.
    'a datetime cast with no override' => [Widget::class, 'created_at', [], false],
    'a $dates column with no override' => [Ledger::class, 'posted_at', [], false],
    // Not a date attribute at all, on a model that does override.
    'a plain column on an overriding model' => [Chronicle::class, 'title', [['title', ScalarT::string()]], false],
    // The override cannot reach a cast written with its own parameter, so there is no loss to report —
    // a notice here would name a format the model never gave up.
    'a cast naming its own format, under the override' => [Metronome::class, 'beat_on', [], false],
    'a cast naming a bespoke format, under the override' => [Metronome::class, 'chimed_on', [], false],
]);

it('accepts a date-cast segment as the date it is stored as, though the body sends a date-time', function (): void {
    // The one column whose two directions differ, both halves pinned together so neither can be
    // changed alone. A segment is matched against the stored column and a body carries what
    // `serializeDate()` wrote; publishing the body's date-time here would refuse every value the route
    // actually resolves, and publishing the segment's date there would describe a response the server
    // never sends.
    $metadata = new ClassMetadata(Astrolabe::class, [new PropertyMetadata('sighted_on', new ClassT('Illuminate\\Support\\Carbon'))]);

    $facts = (new EloquentModelReflector)->facts(Astrolabe::class);

    expect((new EloquentModelReflector)->columnSchemaFor(Astrolabe::class, 'sighted_on', $metadata))
        ->toBe([['type' => 'string', 'format' => 'date'], false])
        ->and(CastSchema::written('date'))->toBeNull()
        ->and(DateColumnSchema::schema($facts))->toBe(['type' => 'string', 'format' => 'date-time']);
});
