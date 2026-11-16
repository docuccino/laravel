<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Config\BuildConfig;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\BindingController;
use Workbench\App\Http\Controllers\DescribedBindingController;
use Workbench\App\Models\Ledger;

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
        $router->model('explicit', Ledger::class);
        // …and with a fallback the framework calls when the key finds nothing, which decides in a closure.
        $router->model('fallback', Ledger::class, static fn (string $value): Ledger => new Ledger);

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
        // Bound on a date column of a model that picked its own wire format, so the parameter and the
        // response body both publish a date the document cannot state a `format` for.
        $router->get('api/binding-journals/{journal:filed_on}', [BindingController::class, 'showJournal']);
        // The same binding on a model that did NOT pick one, so both publish the format the framework
        // writes — the pair is what keeps the weakening a consequence of the override alone.
        $router->get('api/binding-almanacs/{almanac:recorded_on}', [BindingController::class, 'showAlmanac']);
        // The same model bound on a `date`-cast column instead: the segment carries the date the column
        // is stored as, while the body carries the date-time the framework writes for it.
        $router->get('api/binding-observations/{almanac:observed_on}', [BindingController::class, 'showAlmanac']);
        $router->get('api/binding-articles/{article}', [BindingController::class, 'showArticle']);
        $router->get('api/binding-seasons/{season}', [BindingController::class, 'showSeason']);
        $router->get('api/binding-custom/{custom}', [BindingController::class, 'showBound']);
        $router->get('api/binding-dossiers/{dossier}', [BindingController::class, 'showDossier']);
        // Bound with `Route::model()`, by key and with a column the framework ignores for it.
        $router->get('api/binding-explicit/{explicit}', [BindingController::class, 'showExplicit']);
        $router->get('api/binding-explicit-refs/{explicit:reference}', [BindingController::class, 'showExplicit']);
        $router->get('api/binding-fallback/{fallback}', [BindingController::class, 'showFallback']);
        // Models that resolve a binding in code of their own: a hashid trait, by key and by a named column,
        // which leaves the scoped children it finds the framework's way alone; and a narrowed binding
        // query on a model that also finds its scoped children itself.
        $router->get('api/binding-vouchers/{voucher}', [BindingController::class, 'showVoucher']);
        $router->get('api/binding-voucher-codes/{voucher:code}', [BindingController::class, 'showVoucher']);
        $router->get('api/binding-vouchers/{voucher}/entries/{entry}', [BindingController::class, 'showVoucherEntry'])
            ->scopeBindings();
        $router->get('api/binding-coupons/{coupon}', [BindingController::class, 'showCoupon']);
        $router->get('api/binding-coupons/{coupon}/entries/{entry}', [BindingController::class, 'showCouponEntry'])
            ->scopeBindings();
        // A framework trait that validates the value before the default lookup, which keeps the column.
        $router->get('api/binding-beacons/{beacon}', [BindingController::class, 'showBeacon']);
        // Every note a binding writes, on segments their author described as well.
        $router->get('api/binding-described/{ledger:reference}/entries/{entry}', [DescribedBindingController::class, 'showEntry'])
            ->scopeBindings()
            ->withTrashed();
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
    // `getRouteKeyName()` is one `return 'slug';` — a declaration in all but name, and the column the
    // framework queries.
    'a model that names its route key as a literal' => ['/api/binding-articles/{article}', 'article', 'slug'],
    // The framework's own `Route::model()` binder resolves through the model's route key.
    'a Route::model() binding' => ['/api/binding-explicit/{explicit}', 'explicit', 'id'],
    // …and never sees the route's `:reference`, which only implicit binding reads. Publishing it would
    // name a column the server does not look the value up by.
    'a Route::model() binding the route names a column for' => ['/api/binding-explicit-refs/{explicit}', 'explicit', 'id'],
    // `HasUuids` checks the value is a uuid, then runs the framework's own lookup on the key.
    'a model whose framework trait validates the value first' => ['/api/binding-beacons/{beacon}', 'beacon', 'id'],
    // The parent decodes its own segment but finds its children the framework's way, on their key.
    'a child of a model that resolves only its own binding' => ['/api/binding-vouchers/{voucher}/entries/{entry}', 'entry', 'id'],
]);

/**
 * A segment whose column is known is typed from that column — the key's shape for a model routing on its
 * key, the named column's for one routing on another — because a client sending the wrong shape is
 * refused before the lookup is ever made.
 */
it('types a segment from the column it is matched on', function (string $path, string $name, string $type): void {
    $result = localityBuild(bindingFactRoutes());
    /** @var array{schema: array<string, mixed>} $parameter */
    $parameter = pathParameter(emittedArray($result)['paths'][$path]['get'], $name);
    $untyped = array_filter(
        [...diagnosticsCoded($result->diagnostics, 'route-binding.untyped'), ...diagnosticsCoded($result->diagnostics, 'route-binding.column-untyped'), ...diagnosticsCoded($result->diagnostics, 'route-binding.custom-binder')],
        static fn ($diagnostic): bool => str_contains($diagnostic->routeSignature ?? '', $path),
    );

    // Typed, not fallen back to the string an unread segment gets: nothing says it went untyped.
    expect($parameter['schema']['type'])->toBe($type)
        ->and($untyped)->toBe([]);
})->with([
    'the slug a model names as its route key' => ['/api/binding-articles/{article}', 'article', 'string'],
    'the key a Route::model() binding resolves on' => ['/api/binding-explicit/{explicit}', 'explicit', 'integer'],
]);

it('says nothing about a column it cannot read', function (string $path, string $name): void {
    [$facts, $description] = bindingFactsOf($path, $name);

    expect($facts)->not->toHaveKey('key')
        ->and(str_contains($description ?? '', 'Matched on'))->toBeFalse();
})->with([
    // `getRouteKeyName()` reads configuration when the request runs. Publishing `id` here would name
    // the one column the server does NOT look this up by.
    'a model that decides its route key at run time' => ['/api/binding-dossiers/{dossier}', 'dossier'],
    // The binder decides in a closure, and it runs before implicit binding either way.
    'a segment the application binds itself' => ['/api/binding-custom/{custom}', 'custom'],
    // An enum segment is matched against the enum's own values, which the schema already publishes as
    // an `enum` — there is no column and nothing prose could add.
    'an enum binding' => ['/api/binding-seasons/{season}', 'season'],
    // The model resolves the binding itself — a hashid trait decoding the segment — so the key it would
    // name by default, and a column the route names, are both columns the server may never query.
    // `Route::model()` with a fallback: whatever the key misses, the application's closure answers —
    // values the key's type refuses, and a default that never 404s.
    'a Route::model() binding with a fallback' => ['/api/binding-fallback/{fallback}', 'fallback'],
    'a model that resolves its bindings itself' => ['/api/binding-vouchers/{voucher}', 'voucher'],
    'a model that resolves its bindings itself, under a column the route names' => ['/api/binding-voucher-codes/{voucher}', 'voucher'],
    'a model that narrows its binding query' => ['/api/binding-coupons/{coupon}', 'coupon'],
    // The parent finds its scoped children in code of its own, so the child's key decides nothing.
    'a child its parent finds its own way' => ['/api/binding-coupons/{coupon}/entries/{entry}', 'entry'],
]);

/**
 * A segment whose matching column the build cannot read cannot be TYPED off the model's key either.
 * Publishing `integer` for a slug-keyed model, or for a binder that looks a record up by name, is a
 * precise wrong answer: it refuses every value the route accepts, and a generated client gets a
 * parameter it cannot pass. The vague true shape is a string, and a notice names what to pin.
 *
 * A `Route::model()` binder is the framework's own closure around the model it was handed, so it is read
 * as that model's binding; a `Route::bind()` closure is the application's, and so is a model's own
 * `resolveRouteBinding()`, and those are widened.
 */
it('widens the schema of a binding whose column it cannot read, and reports it', function (string $path, string $name, string $code, bool $resolvedInCode): void {
    $result = localityBuild(bindingFactRoutes());
    $document = emittedArray($result);

    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = $document['paths'];
    /** @var array{schema: array<string, mixed>} $parameter */
    $parameter = pathParameter($paths[$path]['get'], $name);
    $reported = array_values(array_filter(
        diagnosticsCoded($result->diagnostics, $code),
        static fn ($diagnostic): bool => $diagnostic->routeSignature === 'GET '.$path && str_contains($diagnostic->message, '{'.$name.'}'),
    ));

    expect($parameter['schema']['type'])->toBe('string')
        ->and($parameter['schema'])->not->toHaveKey('format')
        ->and($reported)->toHaveCount(1)
        // A model that finds the record itself is one the integration could have typed: the notice says
        // why it did not, rather than that nothing could.
        ->and(str_contains($reported[0]->message, 'in code of its own'))->toBe($resolvedInCode);
})->with([
    'a model that decides its route key at run time' => ['/api/binding-dossiers/{dossier}', 'dossier', 'route-binding.untyped', false],
    'a segment the application binds itself' => ['/api/binding-custom/{custom}', 'custom', 'route-binding.custom-binder', false],
    // A hashid segment holds a code, not the integer key: `integer` would refuse every value it takes.
    'a model that resolves its bindings itself' => ['/api/binding-vouchers/{voucher}', 'voucher', 'route-binding.untyped', true],
    'a model that narrows its binding query' => ['/api/binding-coupons/{coupon}', 'coupon', 'route-binding.untyped', true],
    'a child its parent finds its own way' => ['/api/binding-coupons/{coupon}/entries/{entry}', 'entry', 'route-binding.untyped', true],
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

/**
 * How a segment resolves is decided by the route before the action runs, so it is as true under the
 * author's description as without one: a consumer told what the ledger IS still needs to know it is
 * looked up by its reference, that an entry from another ledger 404s, and that a deleted one resolves.
 * The author's prose leads, because it is theirs, and the notes follow beneath it.
 */
it('keeps every note a binding writes beneath the description its author wrote', function (string $name, string $authored, string $notes, array $facts): void {
    [$published, $description] = bindingFactsOf('/api/binding-described/{ledger}/entries/{entry}', $name);

    expect($description)->toBe($authored."\n\n".$notes)
        ->and($published)->toBe($facts);
})->with([
    'a segment matched on the column its route names' => [
        'ledger',
        'The ledger the entry was filed against.',
        'Matched on the resource\'s `reference`. Resolves soft-deleted (trashed) records as well as active ones.',
        ['key' => 'reference', 'withTrashed' => true],
    ],
    'a segment scoped to its parent' => [
        'entry',
        'The entry to show.',
        'Matched on the resource\'s `id`. Scoped to `{ledger}`: only values belonging to it match. Resolves soft-deleted (trashed) records as well as active ones.',
        ['key' => 'id', 'scopedTo' => 'ledger', 'withTrashed' => true],
    ],
]);

it('serves a warm build the same bytes and diagnostics as a cold one', function (): void {
    $warm = assertWarmEqualsCold(bindingFactRoutes(), bindingFactRoutes());

    assertGolden('workbench-route-bindings.uir.json', (new UirEmitter)->emit($warm->document));
});
