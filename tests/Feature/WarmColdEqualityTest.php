<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\ClaimController;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\SsoController;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\BindingController;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ErrorsController;
use Docuccino\Laravel\Tests\Support\LocalityEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\IntegrationsController;

/**
 * A fragment cache is only sound if a warm build is indistinguishable from a cold one — bytes AND
 * diagnostics. The existing cache suite holds the route set constant between warming and building,
 * which is the degenerate case; the defects live where the route set CHANGES between the two, because
 * that is when a fragment outlives the document it was cached for and nothing invalidates it.
 *
 * Each row warms on one route set and documents another. See {@see assertWarmEqualsCold()}.
 */

// A shared error body across two operations, and two same-short-name classes contesting one component
// name — the two things a warm build reassembles rather than recomputes.
$base = static function (Router $r): void {
    $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
    $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
    $r->post('api/zz-sso-a', [SsoController::class, 'store']);
    $r->get('api/zz-sso-b', [SsoController::class, 'show']);
};

// The same set with one of the two contestants gone — the app that deleted an endpoint.
$survivor = static function (Router $r): void {
    $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
    $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
    $r->post('api/zz-sso-a', [SsoController::class, 'store']);
};

// The base set plus a Sanctum-protected route: one `components.securitySchemes` entry an operation
// names by key, not by `$ref`.
$secured = static function (Router $r) use ($base): void {
    $base($r);
    $r->get('api/zz-secured', [ClaimController::class, 'show'])->middleware('auth:sanctum');
};

// The same, in both modes, so the stateful scheme publishes the app's session cookie name — and both
// schemes publish something the build environment chose.
$environmentDerived = static function (Router $r) use ($base): void {
    $base($r);
    $r->get('api/zz-stateful', [ClaimController::class, 'show'])
        ->middleware(['auth:sanctum', 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful']);
    $r->get('api/zz-scoped', [ClaimController::class, 'show'])->middleware('scope:read');
};

it('serves a warm build exactly what a cold one would', function (callable $before, callable $after): void {
    assertWarmEqualsCold($before, $after, LocalityEngine::factory());
})->with([

    // The shape the existing cache suite already covers, kept as the row it is: nothing changed, so
    // every fragment hits and nothing has to be noticed.
    'the same route set twice' => [$base, $base],

    // The added route contests a component name with one already cached, so the warm build has to
    // rename a fragment it did not rebuild.
    'a route added' => [$survivor, $base],

    // The one nothing invalidates: no fragment belongs to a route that is gone, so the survivor's
    // cached `$ref` — settled while it was contested — has to come back to the plain name by itself.
    'a route removed' => [$base, $survivor],

    // The route name is the operationId, so a fragment keyed without it answers under the wrong name.
    'a route renamed' => [
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-named', [ClaimController::class, 'show'])->name('portal.show');
        },
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-named', [ClaimController::class, 'show'])->name('portal.read');
        },
    ],

    // Laravel parses `:slug` out of `uri()`, so these two are the same signature down to the byte while
    // typing their parameter off different columns. A key that leaves the column out serves the first
    // one's integer for the second's slug.
    'a route that named a binding column' => [
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-bound/{blank}', [BindingController::class, 'blank']);
        },
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-bound/{blank:slug}', [BindingController::class, 'blank']);
        },
    ],

    // The diagnostic half. An untypable binding column is reported by the route's own fragment, so a
    // warm hit — which reassembles rather than rebuilds — has to replay it or the warm build is quietly
    // more confident than the cold one.
    'a binding column nothing types, twice' => [
        $untypedColumn = static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-bound/{blank:slug}', [BindingController::class, 'blank']);
        },
        $untypedColumn,
    ],

    // A catch-all reports and emits nothing, and its report is a document-level one — so it must survive
    // a build where every fragment beside it came back warm.
    'a fallback route added' => [
        $base,
        static function (Router $r) use ($base): void {
            $base($r);
            $r->prefix('api')->group(static function (Router $g): void {
                $g->fallback([ClaimController::class, 'show']);
            });
        },
    ],

    // Security lives in `components.securitySchemes`, which the operation names as a KEY rather than
    // reaching through a `$ref` — so a fragment carrying only its `$ref` closure comes back warm holding
    // a `security` requirement for a scheme no longer in the document. Both rows below are fully warm on
    // the secured route, which is precisely when nothing re-registers it.
    'a secured route, twice' => [$secured, $secured],

    // …and the secured fragment coming back warm into a build that is NOT fully warm, since an added
    // route re-registers everything around it and could hide the loss.
    'a secured route kept while another is added' => [
        $secured,
        static function (Router $r) use ($secured): void {
            $secured($r);
            $r->get('api/zz-extra', [ClaimController::class, 'show']);
        },
    ],

    // The diagnostic half of the same thing. Both schemes publish a value taken from the environment
    // (`app.url`, `session.cookie`), so both report one — and a warm build reporting fewer of those is
    // the silent degradation that makes `--fail-on=warning` useless.
    'a route whose scheme is environment-derived, twice' => [$environmentDerived, $environmentDerived],

    // A scheme name is a slot too. Two routes referencing scopes the other doesn't build two DIFFERENT
    // `passport` definitions, and the second takes `passport_2` — so a fragment cached while its route
    // held the plain name has to come back as `passport_2` when a route that sorts before it takes it,
    // requirement and all. Warming on the later route alone is what puts it in that position.
    'a security scheme another route takes the name of' => [
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-scope-b', [ClaimController::class, 'show'])->middleware('scope:beta');
        },
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-scope-a', [ClaimController::class, 'show'])->middleware('scope:alpha');
            $r->get('api/zz-scope-b', [ClaimController::class, 'show'])->middleware('scope:beta');
        },
    ],

    // Method, URI, name, middleware and action all agree — the host is the only thing between the two
    // routes, so a key that leaves it out serves the first host's fragment for the second.
    'a route rehosted' => [
        static function (Router $r) use ($base): void {
            $base($r);
            $r->domain('a.example.com')->get('api/zz-hosted', [ClaimController::class, 'show']);
        },
        static function (Router $r) use ($base): void {
            $base($r);
            $r->domain('b.example.com')->get('api/zz-hosted', [ClaimController::class, 'show']);
        },
    ],
]);

/**
 * The one value a cached fragment could not carry. A fragment is JSON on disk, and it was read back
 * with an associative decode, where `{}` and `[]` are the same PHP array — so a `{}` example went in
 * and a `[]` came out, and all four halves of the rule broke at once: the warm build published
 * `"example": []` beside `type: object`, its `contentHash` differed from the cold one's so a diff
 * against a committed artifact read the component as removed and re-added, the example lint reported a
 * mismatch nothing cold ever saw, and the component registry — which dedupes by content — counted the
 * warm `[]` and the cold `{}` as two bodies contesting one name.
 *
 * Invisible until now because the cache suite's own rows carry no `{}`, and the free-form-map row pins
 * the cold path only. `storeArticle` documents the Data class that carries `@example {}`.
 */
it('serves a warm build the empty-object example a cold one publishes', function (): void {
    $routes = static function (Router $router): void {
        $router->post('api/zz-articles', [IntegrationsController::class, 'storeArticle']);
    };

    // Bytes, document array and diagnostics, both directions — see assertWarmEqualsCold().
    $warm = assertWarmEqualsCold($routes, $routes);

    // …and equal-to-cold proves nothing unless the shared value is the right one, so pin what the warm
    // build actually published: an object in the draft, `{}` in the bytes, and nothing said about it.
    /** @var array<string, mixed> $overrides */
    $overrides = $warm->document->toArray()['components']['schemas']['Article']['properties']['overrides'];

    expect($overrides['example'])->toBeInstanceOf(stdClass::class)
        ->and((array) $overrides['example'])->toBe([])
        ->and((new UirEmitter)->emit($warm->document))->toContain('"example": {}')
        ->and((new UirEmitter)->emit($warm->document))->not->toContain('"example": []')
        ->and(diagnosticsCoded($warm->diagnostics, 'lint.example-mismatch'))->toBe([]);
});
