<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\InferredHandler\LocatedCallable;
use Docuccino\Laravel\Integrations\InferredHandler\RespondCallback;
use Docuccino\Laravel\Integrations\InferredHandler\RespondConditions;
use Docuccino\Laravel\Integrations\Support\RoutePredicates;
use Illuminate\Routing\Route;
use Symfony\Component\Routing\RouteCompiler;

/**
 * {@see RoutePredicates::routeIs()} against the framework's own `Route::named()`, and the two readers that
 * settle a route-name branch — a `respond()` callback's, and a Data object's `calculateResponseStatus()` —
 * held to that one answer, so neither can settle a route the other settles differently.
 */
it('answers routeIs() for a route as the framework does, through every reader that settles it', function (?string $name, array $patterns): void {
    $route = new Route(['GET'], 'api/things', static fn () => null);
    if ($name !== null) {
        $route->name($name);
    }
    $runtime = $route->named(...$patterns);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/things', $name),
        actionRef: new ActionRef('app/Http/ThingController.php', 'App\\Http\\ThingController', 'show'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
    $respond = RespondConditions::reachable(
        [new CallCondition('request', 'routeIs', $patterns, true)],
        new RespondCallback(new LocatedCallable('bootstrap/app.php', 1), 'response', 'e', 'request'),
        $context,
        new ResponseDraft('404'),
    );

    expect(RoutePredicates::routeIs($patterns, $name))->toBe($runtime)
        ->and($respond)->toBe($runtime);
})->with([
    'an exact name' => ['things.store', ['things.store']],
    'a wildcard suffix' => ['things.store', ['things.*']],
    'a wildcard prefix' => ['api.things.store', ['*.store']],
    'a refusing pattern' => ['things.show', ['*.store']],
    'any of several' => ['things.show', ['*.store', '*.show']],
    'none of several' => ['things.index', ['*.store', '*.show']],
    'an unnamed route' => [null, ['*']],
]);

it('reads the separators an omitted optional parameter takes along as the route compiler does', function (): void {
    expect((new ReflectionClassConstant(RoutePredicates::class, 'SEPARATORS'))->getValue())
        ->toBe(RouteCompiler::SEPARATORS);
});
