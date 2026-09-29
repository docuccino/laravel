<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\OptionalSegments;
use Illuminate\Routing\Route;

/**
 * The value leaving an optional segment off sends, read as the dispatcher fills it.
 */
it('keys the route\'s own defaults for its optional segments and nothing else', function (): void {
    $route = (new Route(['GET'], 'posts/{post}/{page?}/{size?}/{sort?}', static fn (): null => null))
        ->defaults('page', 'latest')
        ->defaults('size', 10)
        ->defaults('sort', ['a'])
        ->defaults('post', 'ignored');

    // A default on a required segment never reaches the action, and a null one is the same as none.
    expect(OptionalSegments::cacheInputs($route))->toBe(['default:page=latest', 'default:size=10', 'default:sort=array'])
        ->and(OptionalSegments::cacheInputs((new Route(['GET'], 'posts/{page?}', static fn (): null => null))->defaults('page', null)))->toBe([]);
});

it('reads a default only off a parameter the segment fills', function (): void {
    $route = new Route(['GET'], 'posts/{page?}', static fn (): null => null);

    expect(OptionalSegments::defaultOf($route, new ReflectionFunction(static fn (string $other = 'x', string $page = 'first'): null => null), 'page'))->toBe('first')
        // A class is resolved by the container, never filled from the segment.
        ->and(OptionalSegments::defaultOf($route, new ReflectionFunction(static fn (?ArrayObject $page = null): null => null), 'page'))->toBeNull()
        ->and(OptionalSegments::defaultOf($route, new ReflectionFunction(static fn (string $page): null => null), 'page'))->toBeNull()
        ->and(OptionalSegments::defaultOf($route, null, 'page'))->toBeNull()
        // A segment the router requires is never left off, so nothing is sent in its place.
        ->and(OptionalSegments::defaultOf(new Route(['GET'], 'posts/{page?}/items', static fn (): null => null), new ReflectionFunction(static fn (string $page = 'first'): null => null), 'page'))->toBeNull();
});

it('publishes a default only where the requirement the router compiled accepts it', function (string $uri, string $value, ?string $expected): void {
    // No constraint here: the requirement is the default the compiler derives from the separators, so a
    // segment before a `.` cannot carry one.
    $route = (new Route(['GET'], $uri, static fn (): null => null))->defaults('name', $value);

    expect(OptionalSegments::defaultOf($route, null, 'name'))->toBe($expected);
})->with([
    'a plain segment' => ['files/{name?}', 'a.b', 'a.b'],
    'a segment a separator follows' => ['files/{name?}.{format?}', 'a.b', null],
    'a slash no segment carries' => ['files/{name?}', 'a/b', null],
]);
