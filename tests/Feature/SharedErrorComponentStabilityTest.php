<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ErrorsController;
use Illuminate\Routing\Router;

/**
 * The shared error shape, proven through the whole adapter rather than over a hand-built document.
 *
 * These pin what `ComponentNameStabilityTest` pins for class-identified schemas, one bucket over:
 * `Error403` must not go to whichever shape the document walk meets first, which would silently repoint
 * every operation returning the other one; and a difference in how two operations DESCRIBE one body must
 * not split it into two components at all.
 */

/**
 * The two-403-shapes document, plus any extra routes named by action. Routes process in sorted
 * `METHOD uri` order, so `GET api/aaa-*` beats everything below and decides who is met first.
 *
 * @param  list<array{string, string}>  $extra  [uri, action]
 */
function sharedErrorDocument(array $extra = []): GenerationResult
{
    $router = app('router');
    $router->get('api/zz-denied', [ErrorsController::class, 'denied']);
    $router->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
    $router->get('api/zz-blocked', [ErrorsController::class, 'blocked']);
    $router->get('api/zz-blocked-again', [ErrorsController::class, 'blockedAgain']);

    foreach ($extra as [$uri, $action]) {
        $router->get($uri, [ErrorsController::class, $action]);
    }

    bindStubEngine();

    return generateDocument();
}

/**
 * The `$ref` one route's 403 SHAPE points at, read through the response component when the whole
 * response was hoisted too.
 *
 * @param  array<string, mixed>  $document
 */
function errorRefAt(array $document, string $path): ?string
{
    $response = $document['paths'][$path]['get']['responses']['403'] ?? [];

    $ref = is_array($response) ? ($response['$ref'] ?? null) : null;
    if (is_string($ref)) {
        $response = $document['components']['responses'][substr($ref, strlen('#/components/responses/'))] ?? [];
    }

    return $response['content']['application/json']['schema']['$ref'] ?? null;
}

/**
 * Every shared error shape the document published, by name.
 *
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function errorSchemaNames(array $document): array
{
    return errorComponentNames($document, 'schemas');
}

/**
 * Every shared 403 component of one bucket, by name — the published set a `$ref` has to name a member
 * of, which is what makes an assertion about one of them exact rather than merely not-the-other.
 *
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function errorComponentNames(array $document, string $bucket): array
{
    $names = array_keys(array_filter(
        $document['components'][$bucket] ?? [],
        static fn (string $name): bool => str_starts_with($name, 'Error403'),
        ARRAY_FILTER_USE_KEY,
    ));
    sort($names);

    return array_map(strval(...), $names);
}

it('shares one shape between two operations that describe it differently', function (): void {
    $document = sharedErrorDocument()->document->toArray();

    $denied = $document['paths']['/api/zz-denied']['get']['responses']['403'];
    $again = $document['paths']['/api/zz-denied-again']['get']['responses']['403'];

    expect(errorRefAt($document, '/api/zz-denied'))->not->toBeNull()
        ->and(errorRefAt($document, '/api/zz-denied-again'))->toBe(errorRefAt($document, '/api/zz-denied'))
        // One response as well as one shape — prose does not change what a response is — and each arm
        // keeps the words it was given: the shared component states the ones it published, and the arm
        // that says something else states its own beside the `$ref` that overrides them.
        ->and($denied['$ref'] ?? null)->toBe($again['$ref'] ?? null)
        ->and(resolveResponse($document, $denied)['description'])->toBe('Forbidden')
        ->and(resolveResponse($document, $again)['description'])->toBe('You may not do that');
});

it('shares the whole response between two operations that state it identically', function (): void {
    // The second pass, through the adapter. `blocked` and `blockedAgain` state the same 403 in the same
    // words, so they share a response component — which in turn points at the shared shape.
    $document = sharedErrorDocument()->document->toArray();

    $ref = $document['paths']['/api/zz-blocked']['get']['responses']['403']['$ref'] ?? null;
    $denied = $document['paths']['/api/zz-denied']['get']['responses']['403']['$ref'] ?? null;

    // Two bodies, two response components — and the two the pairs point at are exactly the two the
    // document published, which says which name each pair got rather than only that they differ.
    $published = array_map(
        static fn (string $name): string => '#/components/responses/'.$name,
        errorComponentNames($document, 'responses'),
    );
    $pointed = [$ref, $denied];
    sort($pointed);

    expect($ref)->not->toBeNull()
        ->and($document['paths']['/api/zz-blocked-again']['get']['responses']['403']['$ref'] ?? null)->toBe($ref)
        ->and($document['components']['responses'][substr((string) $ref, strlen('#/components/responses/'))]['content']['application/json']['schema'] ?? [])
        ->toHaveKey('$ref')
        ->and($pointed)->toBe($published);
});

it('retires the plain name when two shapes contest a status', function (): void {
    $document = sharedErrorDocument()->document->toArray();

    expect(errorSchemaNames($document))->toHaveCount(2)
        ->and(errorSchemaNames($document))->not->toContain('Error403')
        ->and(errorSchemaNames($document))->each->toMatch('/^Error403_[a-z2-7]{8}$/')
        ->and(errorRefAt($document, '/api/zz-denied'))->not->toBe(errorRefAt($document, '/api/zz-blocked'));
});

it('does not move an existing component when an unrelated route is added', function (): void {
    // `GET api/aaa-unrelated` sorts before every route above and returns one of the two shapes, so it
    // flips which one the document walk meets first. Under a positional suffix that alone would swap
    // the two published names.
    $before = sharedErrorDocument()->document->toArray();
    $after = sharedErrorDocument([['api/aaa-unrelated', 'unrelated']])->document->toArray();

    expect(errorSchemaNames($after))->toBe(errorSchemaNames($before))
        ->and(errorRefAt($after, '/api/zz-denied'))->toBe(errorRefAt($before, '/api/zz-denied'))
        ->and(errorRefAt($after, '/api/zz-blocked'))->toBe(errorRefAt($before, '/api/zz-blocked'))
        ->and($after['components']['schemas'][errorSchemaNames($after)[0]])
        ->toBe($before['components']['schemas'][errorSchemaNames($before)[0]]);
});

it('publishes the same bytes and the same diagnostics on a warm fragment-cache build', function (): void {
    // The hoist runs over the finished document, so a warm hit — where no route re-runs — has to land on
    // the same names from the same shapes. Fewer diagnostics on a warm build would be a silent loss too.
    //
    // Through `assertWarmEqualsCold()` rather than by building twice and comparing: two builds against a
    // cache nobody proved was written or hit are two COLD builds, and they agree whether the cache works
    // or is inert.
    $routes = static function (Router $router): void {
        $router->get('api/zz-denied', [ErrorsController::class, 'denied']);
        $router->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
        $router->get('api/zz-blocked', [ErrorsController::class, 'blocked']);
        $router->get('api/zz-blocked-again', [ErrorsController::class, 'blockedAgain']);
    };

    $warm = assertWarmEqualsCold($routes, $routes);

    expect(errorSchemaNames($warm->document->toArray()))->toHaveCount(2);
});
