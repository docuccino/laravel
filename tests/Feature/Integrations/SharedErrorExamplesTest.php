<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\CredentialsRejectedException;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ExportController;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ExportFailure;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ExportProblem;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ExportProblemRenderer;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ExportRefusedException;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\GuardedController;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\GuardProblem;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\GuardProblemRenderer;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\PortalController;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\PortalProblem;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\PortalProblemRenderer;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\RegionBlockedException;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\RoleMissingException;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\SessionExpiredException;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\TokenExpiredException;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/**
 * The shared-error hoist's example collapse, proven through the whole adapter rather than over a
 * hand-built document.
 *
 * The shape is the one nearly every application has: ONE renderer, one problem document, several reasons
 * for it. Three guards protect two endpoints each, three arms answer 403 with the same carrier and fill in
 * different words. That is one contract illustrated three ways — and each arm repeating puts all three
 * over the sharing threshold, so before the collapse they were three response components claiming one
 * name, all three pushed onto a hash suffix: a generated client offered three structurally identical
 * types for one concept, none of them named after anything.
 *
 * The export family at the end asks the other half of the same question: not which illustration survives,
 * but whether the one that does is a valid instance of the schema it sits beside, where that schema states
 * a value domain of its own.
 */

/** The three guards, as exception type → the words that guard's arm fills in. */
const GUARD_ARMS = [
    'token' => [TokenExpiredException::class, 'Token expired', 'Refresh the token and retry.', ['profile', 'settings']],
    'role' => [RoleMissingException::class, 'Role missing', 'Ask an administrator for access.', ['audit', 'billing']],
    'region' => [RegionBlockedException::class, 'Region blocked', 'This endpoint is not served in your region.', ['catalog', 'pricing']],
];

/** The six guarded endpoints. */
function guardedRoutes(Router $router): void
{
    foreach (GUARD_ARMS as [, , , $actions]) {
        foreach ($actions as $action) {
            $router->get('api/guarded-'.$action, [GuardedController::class, $action]);
        }
    }
}

/**
 * The engine as the six routes and the three render arms script it. The scripted return type is the one
 * the real engine recovers from {@see GuardProblemRenderer}: the constructed `GuardProblem`, the folded
 * 403 and media type, and one supplied member per constructor argument that arm wrote — every one of the
 * four, since every arm passes all four and each folds. The arms differ only in two of those words.
 *
 * The renderer registers ONCE per application, however many builds a test runs: the handler outlives a
 * build, and re-registering would change what the next build's descriptor cache is keyed on.
 */
function guardEngine(): TypeEngine
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $renderer = new GuardProblemRenderer;

    if (! app()->bound('tests.guard-renderer')) {
        app()->instance('tests.guard-renderer', true);
        $handler->renderable($renderer);
    }

    $function = new ReflectionFunction(Closure::fromCallable($renderer));
    $location = new SourceLocation('');

    $callables = [];
    $analyses = [];
    foreach (GUARD_ARMS as [$exception, $title, $detail, $actions]) {
        $symbol = (new CallableRef(
            (string) $function->getFileName(),
            $renderer::class,
            $function->getName(),
            0,
            $function->getParameters()[0]->getName(),
            $exception,
        ))->symbol();

        $callables[$symbol] = new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ClassT(GuardProblem::class),
                new LiteralT(403),
                new LiteralT('application/problem+json'),
                new ArrayShapeT([
                    new ArrayShapeField('type', new LiteralT('about:blank')),
                    new ArrayShapeField('title', new LiteralT($title)),
                    new ArrayShapeField('status', new LiteralT(403)),
                    new ArrayShapeField('detail', new LiteralT($detail)),
                ]),
            ]),
            $location,
        )]);

        foreach ($actions as $action) {
            $analyses[GuardedController::class.'::'.$action] = new ActionAnalysis(
                returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('ok', ScalarT::bool())]), $location)],
                throws: [new ThrownException($exception, 403, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
            );
        }
    }

    return WorkbenchEngine::make($callables, [
        GuardProblem::class => new ClassMetadata(GuardProblem::class, [
            new PropertyMetadata('type', ScalarT::string()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::int()),
            new PropertyMetadata('detail', ScalarT::string()),
        ]),
    ], $analyses);
}

/**
 * The document the six guarded routes produce, alone — the workbench's own routes would only add noise.
 *
 * @return array<string, mixed>
 */
function guardDocument(): array
{
    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    guardedRoutes($router);

    app()->instance(TypeEngine::class, guardEngine());

    return generateDocument()->document->toArray();
}

/**
 * The 403 media type one route documents, read through whatever component it ended up in.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function guardMediaAt(array $document, string $action): array
{
    $response = $document['paths']['/api/guarded-'.$action]['get']['responses']['403'] ?? [];

    $ref = is_array($response) ? ($response['$ref'] ?? null) : null;
    if (is_string($ref)) {
        $response = $document['components']['responses'][substr($ref, strlen('#/components/responses/'))] ?? [];
    }

    $media = is_array($response) ? ($response['content']['application/problem+json'] ?? []) : [];

    return is_array($media) ? $media : [];
}

afterEach(function (): void {
    removeFragmentCacheDirs('warm');
    removeFragmentCacheDirs('cold');
});

it('gives three renderer arms of one status a single, plainly named response component', function (): void {
    // The measurable win. Three arms each stated twice used to publish three components — one per arm,
    // each shoved onto `Error403_<hash>` because all three asked for `Error403` and none could keep it.
    $document = guardDocument();

    $refs = array_map(
        static fn (string $action): mixed => $document['paths']['/api/guarded-'.$action]['get']['responses']['403']['$ref'] ?? null,
        ['profile', 'settings', 'audit', 'billing', 'catalog', 'pricing'],
    );

    expect(array_keys($document['components']['responses']))->toBe(['Error403'])
        ->and(array_unique($refs))->toBe(['#/components/responses/Error403']);
});

it('offers every arm\'s illustration on the one response they share', function (): void {
    $document = guardDocument();
    $media = guardMediaAt($document, 'profile');

    $titles = array_map(static fn (array $example): mixed => $example['value']['title'], $media['examples']);
    sort($titles);

    expect($media)->not->toHaveKey('example')
        ->and($media['examples'])->toHaveCount(3)
        ->and($titles)->toBe(['Region blocked', 'Role missing', 'Token expired'])
        // Each key is a function of its own body, so no arm's key mentions any other arm.
        ->and(array_keys($media['examples']))->each->toMatch('/^example_[a-z2-7]{8}$/');
});

it('leaves every illustration sitting beside the schema it was written against', function (): void {
    // The honesty invariant. The arms merged on a key that keeps every media type and every `schema` in
    // it, so the merge widened nothing — each example still stands beside the one schema it always did,
    // and the shared component states that schema by reference rather than by a copy of its own.
    $document = guardDocument();
    $media = guardMediaAt($document, 'audit');

    $schema = $media['schema'];
    unset($schema['x-docuccino']);

    expect($schema)->toBe(['$ref' => '#/components/schemas/GuardProblem'])
        ->and(array_keys($document['components']['schemas']['GuardProblem']['properties']))
        ->toBe(['type', 'title', 'status', 'detail']);

    // Every arm passed all four constructor arguments, so every illustration carries all four members —
    // membership comes from what that arm supplied, not from what the shared schema calls required.
    foreach ($media['examples'] as $example) {
        expect(array_keys($example['value']))->toBe(['type', 'title', 'status', 'detail']);
    }
});

it('publishes the same bytes and the same diagnostics on a warm fragment-cache build', function (): void {
    // The hoist runs over the finished document, so the examples it merges have to reach it on a warm hit
    // too — an illustration lost to the cache would be a component that quietly says less than it did.
    $warm = assertWarmEqualsCold(guardedRoutes(...), guardedRoutes(...), guardEngine(...));

    expect(guardMediaAt($warm->document->toArray(), 'pricing')['examples'])->toHaveCount(3);
});

/**
 * The 401 arms {@see PortalProblemRenderer} answers, as endpoint → [exception, the words that arm writes
 * out]. A null word is one the arm asks the exception for, which folds to nothing and leaves the schema
 * to answer — the whole reason an error example carries a member the build never read.
 */
const PORTAL_ARMS = [
    'dashboard' => [SessionExpiredException::class, 'https://example.com/problems/session-expired', 'Sign in again to continue.'],
    'exports' => [CredentialsRejectedException::class, null, null],
    'reports' => [CredentialsRejectedException::class, null, null],
];

/**
 * The authenticated endpoints, `$only` naming which of them the router gets.
 *
 * @param  list<string>  $only
 */
function portalRoutes(Router $router, array $only): void
{
    foreach ($only as $action) {
        $router->get('api/portal-'.$action, [PortalController::class, $action]);
    }
}

/**
 * The engine as {@see PortalProblemRenderer} scripts it: one constructed `PortalProblem` per arm, at 401
 * under `application/problem+json`, carrying one supplied constructor argument per member — folded where
 * the arm wrote the word out, unfolded where it asked the exception.
 *
 * The renderer registers ONCE per application, however many builds a test runs: the handler outlives a
 * build, and re-registering would change what the next build's descriptor cache is keyed on.
 */
function portalEngine(): TypeEngine
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $renderer = new PortalProblemRenderer;

    if (! app()->bound('tests.portal-renderer')) {
        app()->instance('tests.portal-renderer', true);
        $handler->renderable($renderer);
    }

    $function = new ReflectionFunction(Closure::fromCallable($renderer));
    $location = new SourceLocation('');
    $unread = static fn (?string $word): DType => $word === null
        ? new UnknownT('constructor argument not folded')
        : new LiteralT($word);

    $callables = [];
    $analyses = [];
    foreach (PORTAL_ARMS as $action => [$exception, $type, $detail]) {
        $symbol = (new CallableRef(
            (string) $function->getFileName(),
            $renderer::class,
            $function->getName(),
            0,
            $function->getParameters()[0]->getName(),
            $exception,
        ))->symbol();

        $callables[$symbol] = new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ClassT(PortalProblem::class),
                new LiteralT(401),
                new LiteralT('application/problem+json'),
                new ArrayShapeT([
                    new ArrayShapeField('type', $unread($type)),
                    new ArrayShapeField('title', new LiteralT('Unauthenticated')),
                    new ArrayShapeField('status', new LiteralT(401)),
                    new ArrayShapeField('detail', $unread($detail)),
                ]),
            ]),
            $location,
        )]);

        $analyses[PortalController::class.'::'.$action] = new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('ok', ScalarT::bool())]), $location)],
            throws: [new ThrownException($exception, 401, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
        );
    }

    return WorkbenchEngine::make($callables, [
        PortalProblem::class => new ClassMetadata(PortalProblem::class, [
            new PropertyMetadata('type', ScalarT::string()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::int()),
            new PropertyMetadata('detail', ScalarT::string()),
        ]),
    ], $analyses);
}

/**
 * The document a given set of portal routes produces, alone.
 *
 * @param  list<string>  $only
 * @return array<string, mixed>
 */
function portalDocument(array $only): array
{
    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    portalRoutes($router, $only);

    app()->instance(TypeEngine::class, portalEngine());

    return generateDocument()->document->toArray();
}

/**
 * The 401 media type one portal route documents, read through whatever component it ended up in.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function portalMediaAt(array $document, string $action): array
{
    $response = $document['paths']['/api/portal-'.$action]['get']['responses']['401'] ?? [];

    $ref = is_array($response) ? ($response['$ref'] ?? null) : null;
    if (is_string($ref)) {
        $response = $document['components']['responses'][substr($ref, strlen('#/components/responses/'))] ?? [];
    }

    $media = is_array($response) ? ($response['content']['application/problem+json'] ?? []) : [];

    return is_array($media) ? $media : [];
}

/** The `x-docuccino.facts` an operation's own 401 node carries beside its `$ref`. */
function portalFactsAt(array $document, string $action): array
{
    $facts = $document['paths']['/api/portal-'.$action]['get']['responses']['401']['x-docuccino']['facts'] ?? [];

    return is_array($facts) ? $facts : [];
}

it('publishes one illustration where two arms of an error differ only where one of them filled in', function (): void {
    // Both arms answer 401 with one carrier, one status, one media type and one title; one writes its
    // problem type and detail out and the other asks the exception, so the build reads them on one arm
    // and fills them from the declared type on the other. A filled member is not a value the server
    // sends, so the filled body illustrates nothing the read one does not — and publishing both told a
    // consumer this contract has two shapes, under two keys neither of which names anything.
    $document = portalDocument(['dashboard', 'exports']);
    $media = portalMediaAt($document, 'dashboard');

    expect($media)->not->toHaveKey('examples')
        ->and($media['example'])->toBe([
            'type' => 'https://example.com/problems/session-expired',
            'title' => 'Unauthenticated',
            'status' => 401,
            'detail' => 'Sign in again to continue.',
        ])
        ->and(array_keys($document['components']['responses']))->toBe(['Error401'])
        // The one channel that can carry the answer: the arm that filled says so on its own node, which
        // is what survives a warm fragment-cache hit.
        ->and(portalFactsAt($document, 'exports'))
        ->toBe(['examplePlaceholders' => ['application/problem+json' => ['detail', 'type']]])
        ->and(portalFactsAt($document, 'dashboard'))->toBe([]);
});

it('keeps an example the application wrote beside the one the arms collapsed to', function (): void {
    // An author's example is evidence of a body somebody saw, so it arrives with nothing recorded as
    // filled and is therefore one the collapse can never drop. What collapses is only what the build
    // filled in for itself.
    $document = portalDocument(['dashboard', 'exports', 'reports']);
    $values = array_map(
        static fn (array $example): mixed => $example['value'],
        portalMediaAt($document, 'dashboard')['examples'],
    );

    // …and the author's example carries no fill record of its own, however much its arm filled in for the
    // example it displaced: the record belongs to the body that PUBLISHES, or an author's own words would
    // be droppable on the strength of a body nobody published.
    expect(portalFactsAt($document, 'reports'))->toBe([])
        ->and($values)->toHaveCount(2)
        ->and($values)->toContain([
            'type' => 'https://example.com/problems/session-expired',
            'title' => 'Unauthenticated',
            'status' => 401,
            'detail' => 'Sign in again to continue.',
        ])
        ->and($values)->toContain([
            'type' => 'https://example.com/problems/portal-token-revoked',
            'title' => 'Unauthenticated',
            'status' => 401,
            'detail' => 'The access token for this portal was revoked.',
        ]);
});

it('collapses to the same example, and the same bytes, on a warm fragment-cache build', function (): void {
    // The sharp risk. The arms reach the hoist as cached fragments, so the choice has to be a function of
    // the accumulated set at the end — first- or last-writer-wins would publish one arm's body cold and
    // the other's warm, which is a changed contract nobody asked for.
    $before = static fn (Router $router) => portalRoutes($router, ['dashboard']);
    $after = static fn (Router $router) => portalRoutes($router, ['dashboard', 'exports', 'reports']);

    $warm = assertWarmEqualsCold($before, $after, portalEngine(...));
    $document = $warm->document->toArray();

    expect(portalMediaAt($document, 'exports')['examples'])->toHaveCount(2)
        ->and(portalFactsAt($document, 'exports'))
        ->toBe(['examplePlaceholders' => ['application/problem+json' => ['detail', 'type']]]);

    // Byte-locked, because this population had no golden: every laravel golden's error examples folded
    // in full, so nothing in the corpus stood where an example has to be filled at all.
    assertGolden('workbench-shared-error-example.uir.json', (new UirEmitter)->emit($warm->document));
});

/** The two endpoints one export failure answers for. */
function exportRoutes(Router $router): void
{
    foreach (['archive', 'ledger'] as $action) {
        $router->get('api/export-'.$action, [ExportController::class, $action]);
    }
}

/**
 * The engine as {@see ExportProblemRenderer} scripts it: the constructed `ExportProblem` at 409 under
 * `application/problem+json`, with the two words the arm writes out folded and the two members it asks the
 * failure for left unread — which is what a `reason` and a `failedAt` look like to a build.
 *
 * The renderer registers ONCE per application, however many builds a test runs: the handler outlives a
 * build, and re-registering would change what the next build's descriptor cache is keyed on.
 */
function exportEngine(): TypeEngine
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $renderer = new ExportProblemRenderer;

    if (! app()->bound('tests.export-renderer')) {
        app()->instance('tests.export-renderer', true);
        $handler->renderable($renderer);
    }

    $function = new ReflectionFunction(Closure::fromCallable($renderer));
    $location = new SourceLocation('');

    $symbol = (new CallableRef(
        (string) $function->getFileName(),
        $renderer::class,
        $function->getName(),
        0,
        $function->getParameters()[0]->getName(),
        ExportRefusedException::class,
    ))->symbol();

    $callables = [$symbol => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [
            new ClassT(ExportProblem::class),
            new LiteralT(409),
            new LiteralT('application/problem+json'),
            new ArrayShapeT([
                new ArrayShapeField('type', new LiteralT('https://example.com/problems/export-refused')),
                new ArrayShapeField('title', new LiteralT('Conflict')),
                new ArrayShapeField('status', new LiteralT(409)),
                new ArrayShapeField('reason', new UnknownT('constructor argument not folded')),
                new ArrayShapeField('failedAt', new UnknownT('constructor argument not folded')),
                new ArrayShapeField('retryable', new UnknownT('constructor argument not folded')),
                new ArrayShapeField('context', new UnknownT('constructor argument not folded')),
            ]),
        ]),
        $location,
    )])];

    $analyses = [];
    foreach (['archive', 'ledger'] as $action) {
        $analyses[ExportController::class.'::'.$action] = new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('ok', ScalarT::bool())]), $location)],
            throws: [new ThrownException(ExportRefusedException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
        );
    }

    // The declared types, as an engine reports them. What each member PUBLISHES is the spatie
    // integration's reading of them — the enum's cases and the app's date wire format — which is the half
    // that has to reach the example.
    return WorkbenchEngine::make($callables, [
        ExportProblem::class => new ClassMetadata(ExportProblem::class, [
            new PropertyMetadata('type', ScalarT::string()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::int()),
            new PropertyMetadata('reason', new EnumT(ExportFailure::class, ['QuotaExceeded', 'SourceUnavailable'])),
            new PropertyMetadata('failedAt', new ClassT(CarbonImmutable::class)),
            new PropertyMetadata('retryable', ScalarT::bool()),
            new PropertyMetadata('context', new MapT(ScalarT::string(), new UnknownT('mixed'))),
        ]),
    ], $analyses);
}

/**
 * The build the two export routes produce, alone — diagnostics included, since the example lint's
 * silence is half of what this family proves.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutateConfig
 */
function exportResult(?callable $mutateConfig = null): GenerationResult
{
    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    exportRoutes($router);

    app()->instance(TypeEngine::class, exportEngine());

    return generateDocument($mutateConfig);
}

/**
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutateConfig
 * @return array<string, mixed>
 */
function exportDocument(?callable $mutateConfig = null): array
{
    return exportResult($mutateConfig)->document->toArray();
}

/**
 * The 409 media type an export route documents, read through whatever component it ended up in.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function exportMediaAt(array $document, string $action): array
{
    $response = $document['paths']['/api/export-'.$action]['get']['responses']['409'] ?? [];

    $ref = is_array($response) ? ($response['$ref'] ?? null) : null;
    if (is_string($ref)) {
        $response = $document['components']['responses'][substr($ref, strlen('#/components/responses/'))] ?? [];
    }

    $media = is_array($response) ? ($response['content']['application/problem+json'] ?? []) : [];

    return is_array($media) ? $media : [];
}

it('illustrates a filled member with a value the schema beside it accepts', function (): void {
    // The contract, and it is the schema's rather than this tier's: an example is an INSTANCE of the schema
    // it sits beside, so a member the document declares as one of two codes cannot be illustrated by
    // `"string"` — the schema rejects it, and a consumer who copies the example sends a body the server
    // refuses. Where the schema names the value domain (`enum`) or the value's shape (`format`) it has
    // already answered the question this fill is asking, and the fill takes that answer.
    $document = exportDocument();
    $media = exportMediaAt($document, 'archive');

    // The extension bag is lifted out first, because `[]` and `{}` are ONE PHP value and an identity
    // comparison cannot tell them apart — which is the defect at that member, so it is asserted below by
    // the only comparison that can see it. Its presence is asserted here, so lifting it hides nothing.
    $example = $media['example'];
    expect($example)->toHaveKey('context');
    unset($example['context']);

    expect($example)->toBe([
        'type' => 'https://example.com/problems/export-refused',
        'title' => 'Conflict',
        'status' => 409,
        // The first entry, because a list's order is authored and every other reader of the document shows
        // that branch — never `"string"`, which is not one of the two values this member may hold.
        'reason' => 'quota-exceeded',
        // The one sample the whole build illustrates a `date-time` with. A constant: nothing here may move
        // with the clock or the machine.
        'failedAt' => '2024-01-01T00:00:00Z',
        'retryable' => true,
    ]);

    // A bag the schema calls an OBJECT, illustrated by a PHP array, publishes as the JSON list `[]` — a
    // value that same schema rejects, and one the build's own example lint then reports against an example
    // its reader never wrote (design §1, the empty-object invariant). `stdClass` is the spelling that
    // survives to the bytes, so it is what the fill has to produce.
    expect($media['example']['context'])->toEqual(new stdClass);
});

it('still records a schema-derived fill as a member nothing read', function (): void {
    // The half a better fill could quietly lose. `quota-exceeded` reads like a value the server sends, and
    // it is not one this build proved: the record is the only thing that can tell those apart downstream,
    // and a collapse consulting it would otherwise treat this arm as having READ the member and drop a
    // rival illustration that had actually proved it.
    $document = exportDocument();

    $facts = $document['paths']['/api/export-archive']['get']['responses']['409']['x-docuccino']['facts'] ?? [];

    expect($facts)->toBe(['examplePlaceholders' => ['application/problem+json' => ['context', 'failedAt', 'reason', 'retryable']]]);
});

it('publishes an error example no build-time lint can fault', function (): void {
    // The contract this fill answers to belongs to the SCHEMA, not to this tier: an example is an instance
    // of the schema beside it, and the build holds every published example to exactly that. A `"string"`
    // where the document says one of two codes is not a vaguer illustration — it is a body the server
    // refuses, and the resulting warning names an example its reader never wrote and cannot correct.
    $result = exportResult();
    $codes = array_map(static fn (Diagnostic $d): string => $d->code, $result->diagnostics);
    $document = $result->document->toArray();

    expect($codes)->not->toContain('lint.example-mismatch')
        ->and($codes)->not->toContain('lint.example-uncheckable')
        // Anti-vacuity: there really is a filled example here, sitting under a schema that constrains both
        // of the members it filled — so the silence above is a clean audit and not an empty one.
        ->and($document['components']['schemas']['ExportFailure']['enum'])
        ->toBe(['quota-exceeded', 'source-unavailable'])
        ->and($document['components']['schemas']['ExportProblem']['properties']['failedAt']['format'])
        ->toBe('date-time')
        ->and(exportMediaAt($document, 'archive')['example'])
        ->toHaveKeys(['reason', 'failedAt']);
});

it('illustrates a format with the sample the document configured for it', function (): void {
    // The one lookup carries the document's own `representation.examples.formats`, so an application that
    // states what a date-time looks like to ITS consumers says it once and every producer obeys — the
    // validation side, the collection export and this fill alike. A second table here is how one of the
    // three starts publishing a value the other two do not.
    $document = exportDocument(static function (array $raw): array {
        $raw['representation']['examples']['formats'] = ['date-time' => '2019-06-30T12:00:00Z'];

        return $raw;
    });

    expect(exportMediaAt($document, 'archive')['example']['failedAt'])->toBe('2019-06-30T12:00:00Z');
});

it('publishes the same bytes on a warm fragment-cache build', function (): void {
    $warm = assertWarmEqualsCold(exportRoutes(...), exportRoutes(...), exportEngine(...));

    // Byte-locked, because no golden in the corpus could reach this: every filled member in every one of
    // them is a bare `type: string` or `type: integer`, so none stands where the schema states a value
    // domain the fill has to honour.
    assertGolden('workbench-schema-stated-fill.uir.json', (new UirEmitter)->emit($warm->document));
});
