<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\ComponentNames\ClaimController;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\SsoController;
use Docuccino\Laravel\Tests\Fixtures\Pagination\PagesController;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\BindingController;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ErrorsController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RouteStatusController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Admin\ReportController as AdminReportController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController as ApiReportController;
use Docuccino\Laravel\Tests\Support\ConditionalStatusEngine;
use Docuccino\Laravel\Tests\Support\LocalityEngine;
use Docuccino\Laravel\Tests\Support\PaginationEngine;
use Illuminate\Routing\Router;

/**
 * One row per shape that mints a name or shares structure ACROSS routes, each held to byte-identical
 * output for a subject operation the extra route has nothing to do with — its own node and every
 * component it transitively `$ref`s. The locality rule itself, and why a projection is the closure
 * rather than the node: {@see assertUnaffectedByUnrelatedRoute()}.
 */
it('does not move a route it did not touch', function (callable $baseline, callable $extra, string $subject, ?callable $engine): void {
    assertUnaffectedByUnrelatedRoute($baseline, $extra, $subject, $engine);
})->with([

    // One class, two shapes. On a slot-based name the request side lands on `Portal`/`Portal_2` by
    // route order, so documenting the read endpoint renames the write endpoint's body.
    'the same class hoisted as a request and as a response' => [
        static fn (Router $r) => $r->post('api/zz-portal', [ClaimController::class, 'store']),
        static fn (Router $r) => $r->get('api/zz-portal', [ClaimController::class, 'show']),
        'POST /api/zz-portal',
        LocalityEngine::factory(),
    ],

    // `GET api/aaa-unrelated` sorts before both SSO routes and reaches the INPUT shape, flipping which
    // of the two same-short-name classes registers first.
    'two same-short-name classes in different namespaces' => [
        static function (Router $r): void {
            $r->post('api/zz-sso-a', [SsoController::class, 'store']);
            $r->get('api/zz-sso-b', [SsoController::class, 'show']);
        },
        static fn (Router $r) => $r->get('api/aaa-unrelated', [SsoController::class, 'unrelated']),
        'GET /api/zz-sso-b',
        LocalityEngine::factory(),
    ],

    // Two classes of one short name whose shapes COINCIDE. Structural dedupe on bytes alone collapses
    // them into one component and drops the newcomer's identity, leaving the surviving `x-docuccino.id`
    // to whichever route ran first, under a `$ref` name that never moved to say so.
    'two same-short-name classes whose bodies are byte-equal' => [
        static function (Router $r): void {
            $r->get('api/zz-receipt-billing', [ClaimController::class, 'billingReceipt']);
            $r->get('api/zz-receipt-support', [ClaimController::class, 'supportReceipt']);
        },
        static fn (Router $r) => $r->get('api/aaa-receipt', [ClaimController::class, 'supportReceipt']),
        'GET /api/zz-receipt-billing',
        LocalityEngine::factory(),
    ],

    // Both pins carry no namespace, so there is nothing to walk and both fall to the hash rung — which
    // has to be derived from the pin, not from the order the routes arrived in.
    'a #[SchemaId]-pinned pair whose pins carry no namespace' => [
        static function (Router $r): void {
            $r->get('api/zz-user-api', [ClaimController::class, 'apiUser']);
            $r->get('api/zz-user-admin', [ClaimController::class, 'adminUser']);
        },
        static fn (Router $r) => $r->get('api/aaa-user', [ClaimController::class, 'apiUser']),
        'GET /api/zz-user-api',
        LocalityEngine::factory(),
    ],

    // The unexpandable class reserved `Gizmo` and published nothing, pushing the working one onto
    // `Gizmo_2` — renamed by a route that contributed nothing, with no collision to warn about.
    'a class the analyser cannot expand, beside one it can' => [
        static fn (Router $r) => $r->get('api/zz-gizmo-working', [ClaimController::class, 'workingGizmo']),
        static fn (Router $r) => $r->get('api/zz-gizmo-broken', [ClaimController::class, 'brokenGizmo']),
        'GET /api/zz-gizmo-working',
        LocalityEngine::factory(),
    ],

    // A status already carrying two shapes gains a third. Every published name is a hash of its own
    // body by then, so the arrival must be additive — a positional suffix would renumber the others.
    'a further distinct 4xx body on a status that already has two' => [
        static function (Router $r): void {
            $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
            $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
            $r->get('api/zz-blocked', [ErrorsController::class, 'blocked']);
            $r->get('api/zz-blocked-again', [ErrorsController::class, 'blockedAgain']);
        },
        static function (Router $r): void {
            $r->get('api/aaa-refused', [ErrorsController::class, 'refused']);
            $r->get('api/aaa-refused-again', [ErrorsController::class, 'refusedAgain']);
        },
        'GET /api/zz-denied',
        null,
    ],

    // The threshold itself: a body stated once stays inline, and a second occurrence lifts it into
    // `components`. That hoist is the third shape's business and may not reach the first two.
    'a repeated error body crossing the shared-response threshold' => [
        static function (Router $r): void {
            $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
            $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
            $r->get('api/zz-blocked', [ErrorsController::class, 'blocked']);
            $r->get('api/zz-blocked-again', [ErrorsController::class, 'blockedAgain']);
            $r->get('api/aaa-refused', [ErrorsController::class, 'refused']);
        },
        static fn (Router $r) => $r->get('api/aaa-refused-again', [ErrorsController::class, 'refusedAgain']),
        'GET /api/zz-denied',
        null,
    ],

    // Two item types paginated the same way. A page named for its position — `Page`, then `Page_2` —
    // is deterministic per build and still hands the earlier-sorting route the plain name, so adding a
    // list endpoint elsewhere in the application renames this one's page type.
    'a second item type paginated beside the first' => [
        static fn (Router $r) => $r->get('api/zz-page-articles', [PagesController::class, 'articles']),
        static fn (Router $r) => $r->get('api/aaa-page-authors', [PagesController::class, 'authors']),
        'GET /api/zz-page-articles',
        PaginationEngine::factory(),
    ],

    // The same item type paginated a second way. Both pages are facets of one identity, so the kinds
    // must separate them without either being the one that had to move. The cursor page also SHARES the
    // subject's `links` component, so the projection covers a hoisted envelope member arriving twice.
    'the same item type paginated by cursor beside by page' => [
        static fn (Router $r) => $r->get('api/zz-page-articles', [PagesController::class, 'articles']),
        static fn (Router $r) => $r->get('api/aaa-page-cursor', [PagesController::class, 'cursorArticles']),
        'GET /api/zz-page-articles',
        PaginationEngine::factory(),
    ],

    // A page whose envelope members are shapes the document had not seen: a simple page shares neither
    // `links` nor `meta` with a length-aware one. Those components are named for their shapes, so two
    // arriving beside the subject's may not renumber the ones it points at.
    'a paginator whose envelope members are new shapes' => [
        static fn (Router $r) => $r->get('api/zz-page-articles', [PagesController::class, 'articles']),
        static fn (Router $r) => $r->get('api/aaa-page-simple', [PagesController::class, 'simpleArticles']),
        'GET /api/zz-page-articles',
        PaginationEngine::factory(),
    ],

    // A catch-all contributes a diagnostic and no operation. Nothing it reports may reach the routes it
    // shares a document with — least of all the paths, which it would otherwise claim all of.
    'a fallback route arriving beside an ordinary one' => [
        static fn (Router $r) => $r->get('api/zz-catch', [ApiReportController::class, 'index']),
        static fn (Router $r) => $r->prefix('api')->group(static function (Router $g): void {
            $g->fallback([ApiReportController::class, 'index']);
        }),
        'GET /api/zz-catch',
        null,
    ],

    // `{blank:slug}` types its parameter off a column and reports the one it cannot type. Both are the
    // arriving route's business: the sibling bound the ordinary way keeps its route-key integer. The
    // baseline is two bound routes so the implicit 404 they share is already hoisted — otherwise the
    // row would be re-proving the shared-error threshold above rather than the binding column.
    'a route naming a binding column beside routes that do not' => [
        static function (Router $r): void {
            $r->get('api/zz-bound/{blank}', [BindingController::class, 'blank']);
            $r->get('api/zz-bound-again/{blank}', [BindingController::class, 'blank']);
        },
        static fn (Router $r) => $r->get('api/zz-bound-column/{blank:slug}', [BindingController::class, 'blank']),
        'GET /api/zz-bound/{blank}',
        null,
    ],

    // Two hosts on one URI are two operations and OpenAPI has room for one, so the sibling is reported
    // rather than emitted. The host-less route keeps the URI, and keeps the identity it was emitted
    // under before the sibling existed.
    'a host-bound sibling arriving beside a host-less route' => [
        static fn (Router $r) => $r->get('api/zz-hosts', [ApiReportController::class, 'index']),
        static fn (Router $r) => $r->domain('admin.example.com')->get('api/zz-hosts', [AdminReportController::class, 'index']),
        'GET /api/zz-hosts',
        null,
    ],

    // An app that never called `Passport::tokensCan()` builds a different `passport` scheme per scope
    // set, and a `security` requirement names its scheme by KEY — so a first-come name renumbered every
    // operation below the route that arrived. The baseline already contests the name, so the third
    // scope set has to be additive.
    'a third Passport scope set on a scheme name two already contest' => [
        static function (Router $r): void {
            $r->get('api/zz-scope-read', [ApiReportController::class, 'index'])->middleware('scope:read');
            $r->get('api/zz-scope-write', [ApiReportController::class, 'index'])->middleware('scope:write');
        },
        static fn (Router $r) => $r->get('api/aaa-scope-admin', [ApiReportController::class, 'index'])->middleware('scope:admin'),
        'GET /api/zz-scope-read',
        null,
    ],
    // A route-name status fold reads THIS route's name and nothing else. The arriving route's name
    // matches the pattern the read route's Data class tests, so a fold memoised per Data class — rather
    // than answered per route — would hand the read endpoint the create endpoint's 201.
    'a route whose name matches the pattern a shared Data class tests' => [
        static fn (Router $r) => $r->get('api/zz-cond-read', [RouteStatusController::class, 'show'])->name('cond.show'),
        static fn (Router $r) => $r->post('api/aaa-cond-create', [RouteStatusController::class, 'store'])->name('cond.things.store'),
        'GET /api/zz-cond-read',
        ConditionalStatusEngine::factory(),
    ],
]);

it('counts an operation\'s named security schemes as part of its emitted representation', function (): void {
    // What every row above compares is the operation's node plus the components it reaches. A `security`
    // requirement names its scheme by KEY and not through a `$ref`, so a ref-only walk leaves the scheme
    // DEFINITION outside the projection altogether — and a first-come `components.securitySchemes` name
    // goes uncaught by every row above.
    $document = emittedArray(localityBuild(static function (Router $r): void {
        $r->get('api/zz-scoped', [ApiReportController::class, 'index'])->middleware('scope:read');
    }));

    $operation = $document['paths']['/api/zz-scoped']['get'];
    $scheme = (string) array_key_first($operation['security'][0]);
    $projection = referencedComponents($document, $operation);

    expect($projection)->toHaveKey('#/components/securitySchemes/'.$scheme)
        ->and($projection['#/components/securitySchemes/'.$scheme])
        ->toBe($document['components']['securitySchemes'][$scheme]);
});

/**
 * The ONE case where an unrelated route does move an existing operation, stated here rather than left
 * out: `Error404` belongs to a status while a single shape holds it, and a second shape retires it for
 * both. The rows above all start from an already-contested status, so they are shaped around the case
 * that passes — this one starts from a document that publishes the plain name and takes it away.
 *
 * The rename is accepted (always discriminating would name the common single-shape case
 * `Error404_a1b2c3d4` for everybody), so what has to hold is that it is ANNOUNCED. A silent rename is
 * the defect; a reported one is a decision the reader can act on.
 */
it('retires a plain shared-error name when a second shape arrives, and says so', function (): void {
    $before = localityBuild(static function (Router $r): void {
        $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
        $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
    });

    $after = localityBuild(static function (Router $r): void {
        $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
        $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
        $r->get('api/aaa-blocked', [ErrorsController::class, 'blocked']);
        $r->get('api/aaa-blocked-again', [ErrorsController::class, 'blockedAgain']);
    });

    $names = static fn ($result): array => array_values(array_filter(
        array_map(strval(...), array_keys(emittedArray($result)['components']['schemas'] ?? [])),
        static fn (string $name): bool => str_starts_with($name, 'Error403'),
    ));

    $collisions = diagnosticsCoded($after->diagnostics, 'components.name-collision');

    expect($names($before))->toBe(['Error403'])
        // Both shapes moved off it; neither kept a name the other had asked for.
        ->and($names($after))->toHaveCount(2)
        ->and($names($after))->not->toContain('Error403')
        ->and($names($after))->each->toMatch('/^Error403_[a-z2-7]{8}$/')
        // …and the build said so, naming the retired name and both replacements.
        ->and($collisions)->not->toBeEmpty()
        ->and($collisions[0]->message)->toContain('"Error403"')
        ->and($collisions[0]->message)->toContain($names($after)[0])
        ->and($collisions[0]->message)->toContain($names($after)[1])
        // The document that still publishes the plain name is not warned about anything.
        ->and(diagnosticsCoded($before->diagnostics, 'components.name-collision'))->toBe([]);
});
