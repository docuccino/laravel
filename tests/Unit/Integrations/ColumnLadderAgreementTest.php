<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumnResolver;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Astrolabe;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Chronicle;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Hourglass;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Ledger;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waterclock;

/**
 * `filter[x]` and a bound `{model:x}` segment ask the same question of the same column — what may a
 * client put in — down two separate ladders: {@see FilterColumnResolver} and
 * {@see EloquentModelReflector::columnSchemaFor()}. Two ladders over one domain owe a guard that they
 * agree, and it has to state each side's answer LITERALLY: a guard that asked one ladder to confirm the
 * other would agree with whatever the code did.
 *
 * Written out, so the rows that DIFFER are the point. Both differences are the date policy, which is a
 * branch of the segment ladder that the filter ladder does not have:
 *
 * - a date column nothing CASTS — a `$dates` entry, a framework timestamp — is a plain string to the
 *   filter and the date policy's shape to the segment. Vaguer and true, not wrong; the cost is a
 *   generated client that cannot tell a caller what `filter[created_at]` takes.
 * - a date CAST on a model overriding `serializeDate()` reverses it: the filter publishes the domain the
 *   cast names, and the segment gives the format up. Here the FILTER is the confident one, because the
 *   override changes what the server writes and not what it will match — so the segment is reading a
 *   write-direction answer for a read-direction question.
 *
 * Neither is this test's to settle, and both are pinned rather than asserted away: a row that starts
 * agreeing is as much a change as one that stops, and either way somebody chose it.
 */
it('answers a column the same way for a filter as for a bound path segment, or differs on the date policy alone', function (string $model, string $column, ?array $filter, ?array $segment): void {
    $resolved = (new FilterColumnResolver)->resolve($model, $column);

    expect($resolved->isEnum())->toBeFalse('no row here is enum-valued')
        ->and($resolved->scalarSchema)->toBe($filter, 'filter['.$column.']')
        ->and((new EloquentModelReflector)->columnSchemaFor($model, $column, new ClassMetadata($model, []))[0])
        ->toBe($segment, '{model:'.$column.'}');
})->with([
    // Where the two ladders share a rung they answer alike: no source, the key, and a cast.
    'a column no source types' => [Merchant::class, 'name', null, null],
    'the primary key' => [Merchant::class, 'id', ['type' => 'integer'], ['type' => 'integer']],
    'a native cast' => [Ledger::class, 'amount', ['type' => 'integer'], ['type' => 'integer']],
    'a date cast, no override' => [
        Astrolabe::class, 'sighted_on',
        ['type' => 'string', 'format' => 'date'], ['type' => 'string', 'format' => 'date'],
    ],
    'an immutable date cast, no override' => [
        Astrolabe::class, 'filed_on',
        ['type' => 'string', 'format' => 'date'], ['type' => 'string', 'format' => 'date'],
    ],

    // The filter ladder has no date branch, so an uncast date column is a plain string to it.
    'a $dates entry, no override' => [Ledger::class, 'posted_at', null, ['type' => 'string', 'format' => 'date-time']],
    'a $dates entry a docblock tagged, no override' => [
        Waterclock::class, 'posted_at', null, ['type' => 'string', 'format' => 'date-time'],
    ],
    'a framework timestamp under an override' => [Chronicle::class, 'created_at', null, ['type' => 'string']],
    'an inherited override, framework timestamp' => [Hourglass::class, 'created_at', null, ['type' => 'string']],
    'an inherited override, $dates entry' => [Hourglass::class, 'posted_at', null, ['type' => 'string']],

    // And the one row the other way about: the filter keeps the domain the cast names, the segment
    // gives the format up to the override.
    'a date cast under an override' => [
        Chronicle::class, 'published_at',
        ['type' => 'string', 'format' => 'date-time'], ['type' => 'string'],
    ],
]);
