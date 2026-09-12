<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\DateColumnSchema;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Astrolabe;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Chronicle;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Daybook;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Dial;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Hourglass;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Ledger;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Metronome;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waterclock;
use Illuminate\Database\Eloquent\Model;

/**
 * The date policy both the response body and the bound path segment read, so a column cannot be dated
 * one way in one and another way in the other.
 */
it('claims date-time only for the bytes the framework really writes', function (): void {
    // Stated from the framework, not from the constant: Laravel serialises a date by handing it to
    // `Model::serializeDate()`, so the one pattern this may claim `date-time` for is whatever that
    // method renders. A Laravel release that changed its mind fails here rather than in a document.
    $model = new class extends Model {};
    $written = (new ReflectionMethod($model, 'serializeDate'))
        ->invoke($model, new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC')));

    expect($written)->toBe(DateWireFormat::example(DateColumnSchema::DEFAULT_FORMAT))
        ->and(DateWireFormat::oas(DateColumnSchema::DEFAULT_FORMAT))->toBe('date-time');
});

it('publishes a date attribute with its format, or gives the format up and says so', function (string $fqcn, array $expected, bool $givenUp): void {
    $facts = (new EloquentModelReflector)->facts($fqcn);

    expect(DateColumnSchema::schema($facts))->toBe($expected)
        ->and(DateColumnSchema::formatGivenUp($facts))->toBe($givenUp);
})->with([
    'a model that serialises dates the framework way' => [Ledger::class, ['type' => 'string', 'format' => 'date-time'], false],
    'a model that overrides serializeDate()' => [Chronicle::class, ['type' => 'string'], true],
    'a model that inherits the override' => [Daybook::class, ['type' => 'string'], true],
]);

it('recognises every source that makes a column a date attribute', function (string $fqcn, string $column, bool $expected): void {
    expect(DateColumnSchema::isAttribute($column, (new EloquentModelReflector)->facts($fqcn)))->toBe($expected);
})->with([
    'a datetime cast' => [Chronicle::class, 'published_at', true],
    'a date cast' => [Astrolabe::class, 'sighted_on', true],
    'a $dates entry' => [Ledger::class, 'posted_at', true],
    'a framework timestamp' => [Hourglass::class, 'created_at', true],
    'the other framework timestamp' => [Hourglass::class, 'updated_at', true],
    'a soft-delete column' => [Vault::class, 'deleted_at', true],

    // The negatives, one per source: the name only counts where the model really has that source.
    'a timestamp name on a model with timestamps off' => [Waterclock::class, 'created_at', false],
    'a soft-delete name on a model without the trait' => [Ledger::class, 'deleted_at', false],
    'a cast that is not a date cast' => [Ledger::class, 'amount', false],
    // A cast naming its own format is written with that format and never reaches the hook, so this
    // policy is not the one that decides it — the cast table answers both directions for it.
    'a cast that names its own format' => [Metronome::class, 'beat_on', false],
    'a cast that names a bespoke format' => [Metronome::class, 'chimed_on', false],
    // The internal cast-type name reaches no branch of the framework's own serialisation, so an
    // override cannot touch it either.
    'the internal cast-type name' => [Dial::class, 'internal_named', false],
    'a $fillable-only column' => [Ledger::class, 'reference', false],
    'a column no source mentions' => [Merchant::class, 'name', false],
]);
