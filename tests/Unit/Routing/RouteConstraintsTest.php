<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\RouteConstraints;
use Illuminate\Routing\Route;
use Symfony\Component\Routing\Route as CompiledRoute;

/**
 * What the router requires of each segment, read as its compiler reads it. The compiler is Symfony's,
 * so it is the oracle here: an expression handed to it and read back is exactly what the path regex
 * embeds, and a reader stripping anchors any other way would publish a pattern the router never applies.
 */
it('strips anchors exactly as the route compiler does', function (string $expression): void {
    $route = (new Route(['GET'], 'items/{item}', static fn (): null => null))->where('item', $expression);

    expect(RouteConstraints::of($route))->toBe(['item' => (new CompiledRoute('/'))->setRequirement('item', $expression)->getRequirement('item')]);
})->with([
    'unanchored' => ['[a-z]+'],
    'both anchors' => ['^[a-z]+$'],
    'string anchors' => ['\A[a-z]+\z'],
    'a start anchor only' => ['^[a-z]+'],
    'an end anchor only' => ['[a-z]+$'],
    'an inner \z kept' => ['a\zb\z'],
    'an escaped dollar, stripped as the compiler strips it' => ['a\$'],
]);

it('reads only the segments the path template uses, sorted by name', function (): void {
    $route = (new Route(['GET'], 'items/{item}/{kind?}', static fn (): null => null))
        ->where(['kind' => '[a-z]+', 'item' => '[0-9]+', 'unused' => '[0-9]+']);

    // A global pattern sits in every route's constraints; one for a name this path does not use keys
    // nothing here, or registering it would retire every fragment in the application.
    expect(RouteConstraints::of($route))->toBe(['item' => '[0-9]+', 'kind' => '[a-z]+'])
        ->and(RouteConstraints::cacheInputs($route))->toBe(['where:item=[0-9]+', 'where:kind=[a-z]+']);
});

it('keys nothing for a route with no constraint', function (): void {
    expect(RouteConstraints::cacheInputs(new Route(['GET'], 'items/{item}', static fn (): null => null)))->toBe([]);
});

it('publishes no pattern for a constraint the router embeds differently or cannot compile', function (string $expression): void {
    // The router embeds the expression in the whole path's regex, so an inner anchor anchors the PATH and
    // the segment can never match as a pattern would say; a count PCRE refuses is no route at all.
    expect(RouteConstraints::pattern($expression))->toBeNull();
})->with([
    'an inner start anchor' => ['a|^b'],
    'an inner end anchor' => ['a$|b'],
    'a string anchor' => ['a\Ab'],
    'a count PCRE refuses' => ['a{70000}'],
]);

it('reads a constraint under the router\'s own modifiers', function (): void {
    // `u` is on in the router, so `\d` is every script's digit, and the pattern is widened to say so.
    expect(RouteConstraints::pattern('\d+'))->toBe('^(?:[0-9]|[^\x00-\x7F])+$')
        ->and(RouteConstraints::pattern('[a-z]{2}|latest'))->toBe('^(?:[a-z]{2}|latest)$');
});
