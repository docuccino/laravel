<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Integrations\Support\SpatieDataEnvelope;

/**
 * The paginator envelope builders, one case per mode. Laravel's envelope
 * (resources + jsonPaginate) and spatie/laravel-data's envelope diverge deliberately, so both are
 * pinned here so a change to either is a conscious edit.
 */
$item = ['$ref' => '#/components/schemas/Article'];

it('builds the Laravel paginator envelope per mode', function (string $mode, array $linkKeys, array $metaKeys, array $metaAbsent) use ($item): void {
    $schema = match ($mode) {
        'length' => PaginationEnvelope::length($item),
        'simple' => PaginationEnvelope::simple($item),
        'cursor' => PaginationEnvelope::cursor($item),
    };

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
    $schema = $mode === 'cursor' ? SpatieDataEnvelope::cursor($item) : SpatieDataEnvelope::length($item);

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
