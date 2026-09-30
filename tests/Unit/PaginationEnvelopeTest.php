<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Support\PageLinks;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Integrations\Support\SpatieDataEnvelope;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\LinkedTimacdonaldCollection;

/**
 * The paginator envelope builders, one case per mode. Laravel's envelope
 * (resources + jsonPaginate) and spatie/laravel-data's envelope diverge deliberately, so both are
 * pinned here so a change to either is a conscious edit.
 */
$item = ['$ref' => '#/components/schemas/Article'];

it('builds the Laravel paginator envelope per mode', function (string $mode, array $linkKeys, array $metaKeys, array $metaAbsent) use ($item): void {
    $schema = PaginationEnvelope::of($mode, $item, PageLinks::Laravel);

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['data', 'links', 'meta'])
        ->and($schema['properties']['data'])->toBe(['type' => 'array', 'items' => $item])
        ->and(array_keys($schema['properties']['links']['properties']))->toBe($linkKeys)
        ->and($schema['properties']['meta']['properties'])->toHaveKeys($metaKeys);

    foreach ($metaAbsent as $absent) {
        expect($schema['properties']['meta']['properties'])->not->toHaveKey($absent);
    }
})->with([
    // length knows the total → last link + last_page/total counters.
    'length' => ['length', ['first', 'last', 'prev', 'next'], ['current_page', 'last_page', 'total'], []],
    // simple does not count the set → no last link, no last_page/total.
    'simple' => ['simple', ['first', 'prev', 'next'], ['current_page', 'from', 'per_page'], ['last_page', 'total']],
    // cursor → opaque tokens, no page counters.
    'cursor' => ['cursor', ['first', 'last', 'prev', 'next'], ['next_cursor', 'prev_cursor'], ['total', 'last_page']],
]);

it('builds spatie\'s own envelope per mode (links array, *_page_url meta)', function (string $mode, array $metaKeys, array $metaAbsent) use ($item): void {
    $schema = SpatieDataEnvelope::of($mode, $item);

    expect($schema['required'])->toBe(['data', 'links', 'meta'])
        // links is an ARRAY of {url,label,active}, not the Laravel {first,last,prev,next} object.
        ->and($schema['properties']['links']['type'])->toBe('array')
        ->and(array_keys($schema['properties']['links']['items']['properties']))->toBe(['url', 'label', 'active'])
        ->and($schema['properties']['meta']['properties'])->toHaveKeys($metaKeys);

    foreach ($metaAbsent as $absent) {
        expect($schema['properties']['meta']['properties'])->not->toHaveKey($absent);
    }
})->with([
    'length' => ['length', ['total', 'first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url'], []],
    'cursor' => ['cursor', ['next_cursor', 'prev_cursor', 'next_page_url', 'prev_page_url'], ['total', 'last_page']],
]);

it('reads which page links a collection sends from the paginationInformation() it inherits', function (string $collection, PageLinks $links): void {
    expect(PageLinks::of(new ClassT($collection)))->toBe($links);
})->with([
    'Laravel\'s anonymous collection' => [ResourceReflector::ANONYMOUS_COLLECTION, PageLinks::Laravel],
    'Laravel\'s JSON:API collection' => [ResourceReflector::JSON_API_COLLECTION, PageLinks::Laravel],
    'timacdonald\'s collection' => [TimacdonaldResourceReflector::JSON_API_COLLECTION, PageLinks::Available],
    'a subclass inheriting timacdonald\'s' => [LinkedTimacdonaldCollection::class, PageLinks::Available],
    'a class that is not there' => ['App\\Http\\Resources\\MissingCollection', PageLinks::Laravel],
]);

it('refuses to build a page without being told which links it sends', function (Closure $build): void {
    // Leaving it out would publish Laravel's links for a collection that drops the null ones.
    expect($build)->toThrow(ArgumentCountError::class);
})->with([
    'the envelope' => [static fn () => PaginationEnvelope::of('length', [])],
    'its parts' => [static fn () => PaginationEnvelope::parts('length')],
    'what it sends' => [static fn () => PaginationEnvelope::sent('length')],
    'what it always sends' => [static fn () => PaginationEnvelope::alwaysSent('length')],
]);

it('publishes a cursor page that drops its null links as possibly none at all, and every other page as an object', function (string $kind, PageLinks $links, bool $mayBeEmpty): void {
    $part = PaginationEnvelope::parts($kind, $links)['links']['schema'];
    $empty = static fn (array $schema): bool => in_array(['type' => 'array', 'maxItems' => 0], $schema['anyOf'] ?? [], true);

    // The keys sent on every page are what decides it: a part with none may be the empty array `[]`.
    expect($empty($part))->toBe($mayBeEmpty)
        ->and(PaginationEnvelope::alwaysSent($kind, $links)['links'] === [])->toBe($mayBeEmpty);
})->with([
    'length, Laravel' => ['length', PageLinks::Laravel, false],
    'simple, Laravel' => ['simple', PageLinks::Laravel, false],
    'cursor, Laravel' => ['cursor', PageLinks::Laravel, false],
    'length, available' => ['length', PageLinks::Available, false],
    'simple, available' => ['simple', PageLinks::Available, false],
    'cursor, available' => ['cursor', PageLinks::Available, true],
]);
