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
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Chronicle;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Coupon;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Invoice;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Ledger;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
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

    expect((new EloquentModelReflector)->columnSchemaFor($fqcn, $column, $metadata))->toBe($expected);
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
    // Read off the Laravel 11+ `casts()` METHOD, which reflection of default properties cannot see.
    'a cast declared by the casts() method' => [Invoice::class, 'issued_at', [], ['type' => 'string', 'format' => 'date-time']],
    // A serializeDate() override makes the wire format unknowable, so the format claim is dropped.
    'a datetime cast under a serializeDate override' => [Chronicle::class, 'published_at', [], ['type' => 'string']],
    // These serialise as containers in a body and as nothing at all in a path segment.
    'an array cast' => [Widget::class, 'meta', [], null],
    'an enum cast' => [Widget::class, 'status', [], null],

    // A `$dates` entry is a date-time column with no cast to say so, and it ranks below whatever the
    // engine recovered — the same order a response body reads it in.
    'a $dates column' => [Ledger::class, 'posted_at', [], ['type' => 'string', 'format' => 'date-time']],
    'a $dates column the engine typed' => [Ledger::class, 'posted_at', [['posted_at', ScalarT::int()]], ['type' => 'integer']],
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

    expect((new EloquentModelReflector)->columnSchemaFor(Merchant::class, 'name', $metadata))
        ->toBe(schemaConverter()->convert(ScalarT::string()));
});

it('leaves a nullable column non-null only in the path', function (DType $type): void {
    // The body keeps the null branch; the path drops it. Both are true of the same column.
    $metadata = new ClassMetadata(Merchant::class, [new PropertyMetadata('name', $type)]);

    expect((new EloquentModelReflector)->columnSchemaFor(Merchant::class, 'name', $metadata))
        ->toBe(['type' => 'string']);
})->with([
    'null first' => [UnionT::of([new NullT, ScalarT::string()])],
    'null last' => [UnionT::of([ScalarT::string(), new NullT])],
]);
