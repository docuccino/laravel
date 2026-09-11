<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Config\BuildConfig;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\BindingController;

/**
 * What a route binding lets a path parameter tell a consumer, and — the harder half — what it does
 * not.
 *
 * A consumer cannot see the application, so the only question the parameter answers for them is which
 * value to put in the segment. The schema says what SHAPE it is; the binding says which attribute of
 * the resource the server looks it up by, whether it is resolved inside its parent, and whether a
 * deleted record still resolves. None of that is derivable from the path, and all of it is decided
 * before the request reaches the action.
 *
 * The rule for each row is the same one: publish what the route or a declaration settles, and say
 * nothing where the answer moved into a method body. A parameter with a short description costs a
 * reader a lookup; one naming the wrong column sends every client to fetch by the wrong attribute.
 */
function bindingFactRoutes(): callable
{
    return static function (Router $router): void {
        // The application's own binder, registered the way a service provider registers one. It runs
        // BEFORE implicit binding and ignores both the route's column and the model's route key.
        $router->bind('custom', static fn (string $value): ?string => $value);

        $router->get('api/binding-ledgers/{ledger}', [BindingController::class, 'showByReference']);
        $router->get('api/binding-refs/{ledger:reference}', [BindingController::class, 'showByReference']);
        // Scoped by the route asking for it…
        $router->get('api/binding-ledgers/{ledger}/entries/{entry}', [BindingController::class, 'showEntry'])
            ->scopeBindings();
        // …and scoped because the child names its own column, which is the framework's other trigger.
        $router->get('api/binding-ledgers/{ledger}/titled/{entry:title}', [BindingController::class, 'showEntryByTitle']);
        // …and the same route with the framework's switch thrown: nested, keyed, and NOT scoped, so
        // any id of that column resolves whichever ledger the path names.
        $router->get('api/binding-ledgers/{ledger}/loose/{entry:title}', [BindingController::class, 'showEntryByTitle'])
            ->withoutScopedBindings();
        $router->get('api/binding-articles/{article}', [BindingController::class, 'showArticle']);
        $router->get('api/binding-seasons/{season}', [BindingController::class, 'showSeason']);
        $router->get('api/binding-custom/{custom}', [BindingController::class, 'showBound']);
    };
}

/**
 * The `routeBinding` facts and the description of one path parameter.
 *
 * @return array{0: array<string, mixed>, 1: ?string}
 */
function bindingFactsOf(string $path, string $name): array
{
    $document = emittedArray(localityBuild(bindingFactRoutes()));

    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = $document['paths'];
    /** @var array<string, mixed>|null $parameter */
    $parameter = pathParameter($paths[$path]['get'], $name);

    expect($parameter)->not->toBeNull();

    /** @var array{facts?: array{routeBinding?: array<string, mixed>}} $extension */
    $extension = $parameter['x-docuccino'] ?? [];
    $description = $parameter['description'] ?? null;

    return [$extension['facts']['routeBinding'] ?? [], is_string($description) ? $description : null];
}

afterEach(function (): void {
    $path = app(BuildConfig::class)->raw('cache.path');
    if (is_string($path)) {
        removeFragmentCacheDir($path);
    }
});

it('names the column a binding is matched on', function (string $path, string $name, string $key): void {
    [$facts, $description] = bindingFactsOf($path, $name);

    expect($facts['key'] ?? null)->toBe($key)
        // Twice over: a client generator reads the fact, a person reads the sentence, and a fact
        // stated only in prose is one no generator can act on.
        ->and($description)->toContain(sprintf('`%s`', $key));
})->with([
    // Nothing has moved the decision, so it is the declared primary key.
    'an implicit binding' => ['/api/binding-ledgers/{ledger}', 'ledger', 'id'],
    // The route names the column outright, and nothing needs reflecting to know it.
    'a route that names its column' => ['/api/binding-refs/{ledger}', 'ledger', 'reference'],
]);

it('says nothing about a column it cannot read', function (string $path, string $name): void {
    [$facts, $description] = bindingFactsOf($path, $name);

    expect($facts)->not->toHaveKey('key')
        ->and(str_contains($description ?? '', 'Matched on'))->toBeFalse();
})->with([
    // `getRouteKeyName()` is a method body. Publishing `id` here would name the one column the server
    // does NOT look this up by.
    'a model that overrides its route key' => ['/api/binding-articles/{article}', 'article'],
    // The binder decides in a closure, and it runs before implicit binding either way.
    'a segment the application binds itself' => ['/api/binding-custom/{custom}', 'custom'],
    // An enum segment is matched against the enum's own values, which the schema already publishes as
    // an `enum` — there is no column and nothing prose could add.
    'an enum binding' => ['/api/binding-seasons/{season}', 'season'],
]);

/**
 * A segment whose matching column the build cannot read cannot be TYPED off the model's key either.
 * Publishing `integer` for a slug-keyed model, or for a binder that looks a record up by name, is a
 * precise wrong answer: it refuses every value the route accepts, and a generated client gets a
 * parameter it cannot pass. The vague true shape is a string, and a notice names what to pin.
 *
 * The registry cannot tell a `Route::bind()` binder from a `Route::model()` one, whose key really is
 * the model's, so widening covers both. That is the trade the degradation rule settles: the second
 * loses some type safety, and not widening loses the first a working request.
 */
it('widens the schema of a binding whose column it cannot read, and reports it', function (string $path, string $name, string $code): void {
    $result = localityBuild(bindingFactRoutes());
    $document = emittedArray($result);

    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = $document['paths'];
    /** @var array{schema: array<string, mixed>} $parameter */
    $parameter = pathParameter($paths[$path]['get'], $name);

    expect($parameter['schema']['type'])->toBe('string')
        ->and($parameter['schema'])->not->toHaveKey('format')
        ->and(diagnosticsCoded($result->diagnostics, $code))->toHaveCount(1);
})->with([
    'a model whose route key is a method body' => ['/api/binding-articles/{article}', 'article', 'route-binding.untyped'],
    'a segment the application binds itself' => ['/api/binding-custom/{custom}', 'custom', 'route-binding.custom-binder'],
]);

/**
 * A child the framework resolves through its parent only matches records belonging to that parent, so
 * an id that is perfectly valid on its own still 404s. Nothing else in the operation states it: the
 * path shows the nesting, and nesting alone does not imply scoping.
 */
it('names the parent a scoped binding is resolved within', function (string $path, string $name): void {
    [$facts, $description] = bindingFactsOf($path, $name);

    expect($facts['scopedTo'] ?? null)->toBe('ledger')
        ->and($description)->toContain('Scoped to `{ledger}`');
})->with([
    'scoping the route asked for' => ['/api/binding-ledgers/{ledger}/entries/{entry}', 'entry'],
    'scoping implied by the child naming its column' => ['/api/binding-ledgers/{ledger}/titled/{entry}', 'entry'],
]);

/**
 * The parent of a scoped pair is not itself scoped, and a nested route the framework does NOT scope
 * says so by staying silent. Both are asserted here because the rule has two sides and a producer
 * that marked everything nested would pass the row above on its own.
 */
it('leaves a parameter the framework does not scope unmarked', function (string $path, string $name): void {
    [$facts] = bindingFactsOf($path, $name);

    expect($facts)->not->toHaveKey('scopedTo');
})->with([
    'the parent of a scoped child' => ['/api/binding-ledgers/{ledger}/entries/{entry}', 'ledger'],
    'a binding with no parent at all' => ['/api/binding-ledgers/{ledger}', 'ledger'],
    // The opt-out, on the one route shape that would otherwise scope itself. Marking this one would
    // tell every consumer their id has to belong to the parent when the server accepts it either way.
    'a child whose route prevented scoping' => ['/api/binding-ledgers/{ledger}/loose/{entry}', 'entry'],
]);

it('serves a warm build the same bytes and diagnostics as a cold one', function (): void {
    $warm = assertWarmEqualsCold(bindingFactRoutes(), bindingFactRoutes());

    assertGolden('workbench-route-bindings.uir.json', (new UirEmitter)->emit($warm->document));
});
