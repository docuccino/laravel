<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\IgnoredAuthorizeAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\IgnoredHtmlAction;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\IgnoredResponsesController;

/**
 * `#[IgnoreResponse]` against every producer that writes a response. A response carries a BODY, and a
 * body hoists into `components.schemas`; nothing prunes that bucket by reachability, so removing the
 * response after the fact would trade a visible defect for an invisible one. Each producer therefore
 * consults the attribute BEFORE it converts anything, and every row here holds both halves: the status
 * is absent, and the bucket is left with nothing unreachable.
 *
 * These routes are registered ad-hoc so no committed golden churns.
 */
const IGNORE_RESPONSE_ARTICLE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource';

const IGNORE_RESPONSE_COLLECTION = 'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection';

/**
 * The stub engine for the ignore routes: an ArticleResource wherever a hoisting body is wanted, a
 * paginator/query trace wherever the producer's finding comes from the call rather than the type.
 */
function ignoreResponseEngine(): TypeEngine
{
    $location = new SourceLocation('');
    $controller = IgnoredResponsesController::class.'::';
    $resource = new ClassT(IGNORE_RESPONSE_ARTICLE);
    $collection = new ClassT(IGNORE_RESPONSE_COLLECTION, [$resource]);
    $inline = new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]);

    return WorkbenchEngine::make(
        analysisOverrides: [
            $controller.'inferred' => new ActionAnalysis(returns: [new ReturnSite($resource, $location)]),
            $controller.'shared' => new ActionAnalysis(returns: [new ReturnSite($resource, $location)]),
            $controller.'companion' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), $location)],
            ),
            $controller.'signalled' => new ActionAnalysis(
                returns: [new ReturnSite($resource, $location)],
                throws: [new ThrownException(
                    'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
                    404,
                    [],
                    ThrowConfidence::Certain,
                    ThrowDisposition::Signal,
                )],
            ),
            $controller.'throttled' => new ActionAnalysis(returns: [new ReturnSite($inline, $location)]),
            $controller.'created' => new ActionAnalysis(returns: [new ReturnSite($resource, $location)]),
            $controller.'paginated' => new ActionAnalysis(returns: [new ReturnSite($collection, $location)]),
            $controller.'jsonPaginated' => new ActionAnalysis(returns: [new ReturnSite($collection, $location)]),
            $controller.'queried' => new ActionAnalysis(returns: [new ReturnSite($collection, $location)]),
            $controller.'implicit' => new ActionAnalysis(returns: [new ReturnSite($inline, $location)]),
            $controller.'contradicted' => new ActionAnalysis(returns: [new ReturnSite($inline, $location)]),
            $controller.'declaredError' => new ActionAnalysis(returns: [new ReturnSite($inline, $location)]),
            $controller.'redirect' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\RedirectResponse'), $location)],
            ),
            IgnoredHtmlAction::class.'::handle' => new ActionAnalysis(returns: [new ReturnSite($inline, $location)]),
            IgnoredAuthorizeAction::class.'::handle' => new ActionAnalysis(returns: [new ReturnSite($inline, $location)]),
            IgnoredAuthorizeAction::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('title', new LiteralT('required|string|max:100')),
            ]), $location)]),
        ],
        traceOverrides: [
            $controller.'created' => TraceScript::forChain(
                'new \\'.IGNORE_RESPONSE_ARTICLE.'(\\Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Widget::create([]))',
                'Illuminate\\Database\\Eloquent\\Builder',
            ),
            $controller.'paginated' => TraceScript::forChain('$q->paginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
            $controller.'jsonPaginated' => TraceScript::forChain('$q->jsonPaginate()', 'Illuminate\\Database\\Eloquent\\Builder'),
            $controller.'queried' => TraceScript::forChain(
                "QueryBuilder::for(\\Workbench\\App\\Models\\Form::class)->allowedFilters(['name'])->paginate(20)",
            ),
        ],
    );
}

/**
 * Builds a document over EXACTLY the ignore routes a row names (the workbench's own route set is reset
 * away), so `components.schemas` holds only what these routes hoisted and an orphan has nowhere to hide.
 */
function ignoreResponseDocument(array $only = []): GenerationResult
{
    $controller = IgnoredResponsesController::class;

    $routes = [
        'inferred' => ['get', 'api/ignored-responses/inferred', [$controller, 'inferred']],
        'shared' => ['get', 'api/ignored-responses/shared', [$controller, 'shared']],
        'companion' => ['get', 'api/ignored-responses/companion', [$controller, 'companion']],
        'signalled' => ['get', 'api/ignored-responses/signalled', [$controller, 'signalled']],
        'throttled' => ['get', 'api/ignored-responses/throttled', [$controller, 'throttled']],
        'created' => ['post', 'api/ignored-responses/created', [$controller, 'created']],
        'paginated' => ['get', 'api/ignored-responses/paginated', [$controller, 'paginated']],
        'jsonPaginated' => ['get', 'api/ignored-responses/json-paginated', [$controller, 'jsonPaginated']],
        'queried' => ['get', 'api/ignored-responses/queried', [$controller, 'queried']],
        'implicit' => ['get', 'api/ignored-responses/implicit/{form}', [$controller, 'implicit']],
        'contradicted' => ['get', 'api/ignored-responses/contradicted', [$controller, 'contradicted']],
        'declaredError' => ['get', 'api/ignored-responses/declared-error', [$controller, 'declaredError']],
        'redirect' => ['get', 'api/ignored-responses/redirect', [$controller, 'redirect']],
        'html' => ['get', 'api/ignored-responses/html', IgnoredHtmlAction::class],
        'authorize' => ['post', 'api/ignored-responses/authorize', IgnoredAuthorizeAction::class],
    ];

    $wanted = $only === [] ? array_keys($routes) : $only;

    return localityBuild(
        static function (Router $router) use ($routes, $wanted): void {
            foreach ($wanted as $key) {
                [$verb, $uri, $action] = $routes[$key];
                $registered = $router->{$verb}($uri, $action);
                if ($key === 'throttled') {
                    $registered->middleware('throttle:60,1');
                }
            }
        },
        ignoreResponseEngine(...),
    );
}

/**
 * The response statuses one operation documents, byte-sorted.
 *
 * @return list<string>
 */
function ignoreResponseStatuses(array $document, string $verb, string $path): array
{
    /** @var array<string, mixed> $responses */
    $responses = $document['paths'][$path][$verb]['responses'] ?? [];
    $statuses = array_map(strval(...), array_keys($responses));
    sort($statuses, SORT_STRING);

    return $statuses;
}

/**
 * The component entries nothing outside their own bucket reaches, transitively, as `bucket/name` — the
 * residue a producer leaves when it converts a body and then loses the response that would have
 * referenced it. Responses as well as schemas: a repeated error body hoists into `components.responses`,
 * and nothing prunes either bucket by reachability.
 *
 * @return list<string>
 */
function ignoreResponseOrphans(array $document): array
{
    $orphans = [];

    foreach (['responses', 'schemas'] as $bucket) {
        /** @var array<string, mixed> $entries */
        $entries = $document['components'][$bucket] ?? [];

        $outside = $document;
        unset($outside['components'][$bucket]);

        $reached = [];
        $frontier = componentRefsIn($outside, $bucket);

        while ($frontier !== []) {
            $name = array_pop($frontier);
            if (isset($reached[$name]) || ! array_key_exists($name, $entries)) {
                continue;
            }
            $reached[$name] = true;
            $frontier = [...$frontier, ...componentRefsIn($entries[$name], $bucket)];
        }

        foreach (array_keys($entries) as $name) {
            if (! isset($reached[(string) $name])) {
                $orphans[] = $bucket.'/'.$name;
            }
        }
    }

    sort($orphans, SORT_STRING);

    return $orphans;
}

it('keeps an ignored response out of the document, whichever producer writes it', function (string $verb, string $path, string $gone, array $kept): void {
    $document = ignoreResponseDocument()->document->toArray();
    $statuses = ignoreResponseStatuses($document, $verb, $path);

    // Anti-vacuity: the row names every status the operation is left with, so an operation that
    // documented nothing at all — a producer that stopped running — fails rather than passes.
    expect($statuses)->toBe($kept)
        ->and($statuses)->not->toContain($gone);
})->with([
    // Inference's own success response, over a body that hoists.
    'inference' => ['get', '/api/ignored-responses/inferred', '200', []],
    // A throw the engine saw, through the exception→response chain.
    'a signalled exception' => ['get', '/api/ignored-responses/signalled', '404', ['200']],
    // The rate-limit integration, synthesized from `throttle` middleware.
    'throttle middleware' => ['get', '/api/ignored-responses/throttled', '429', ['200']],
    // The api-resources re-home of a freshly-created resource, 200 → 201. Dropping the status the
    // re-home would have published declines the whole re-home, so inference's own 200 stands: an ignore
    // takes away the status it names and never a second one the author did not.
    'a created resource' => ['post', '/api/ignored-responses/created', '201', ['200']],
    // The LATE resource-collection rewraps, which hoist a paginated envelope of their own.
    'a paginated collection' => ['get', '/api/ignored-responses/paginated', '200', []],
    'a json-api-paginated collection' => ['get', '/api/ignored-responses/json-paginated', '200', []],
    // Query Builder's strict-mode 400.
    'a strict-mode query builder' => ['get', '/api/ignored-responses/queried', '400', ['200']],
    // The implicit 404 a model-bound path segment synthesizes — the one producer that already consulted.
    'a route-model binding' => ['get', '/api/ignored-responses/implicit/{form}', '404', ['200']],
    // Same action, same layer: the ignore is the retraction, so it wins over the declaration.
    'a #[Response] the same action contradicts' => ['get', '/api/ignored-responses/contradicted', '202', ['200']],
    // The same at an error status, where the declaration also names a component. Nothing CONVERTS here —
    // the declaring producer declined at its own phase — so the orphan half of this pair cannot see it:
    // what publishes the status is the Finalize pass that asks whether the name was read, over a draft
    // whose response accessor is get-or-create.
    'a #[Response] naming an error component' => ['get', '/api/ignored-responses/declared-error', '404', ['200']],
    // laravel-actions: the html representation is additive to the 200, and the 403 comes from authorize().
    'a laravel-actions html representation' => ['get', '/api/ignored-responses/html', '200', []],
    'a laravel-actions authorize() gate' => ['post', '/api/ignored-responses/authorize', '403', ['200', '422']],
]);

it('leaves nothing an ignored response would have hoisted behind as an orphan component', function (string $route, array $schemas): void {
    // Each row is ONE ignoring route plus the companion, which ignores nothing and hoists a component of
    // its own — so the buckets are never empty and an answer of "nothing was hoisted at all" cannot pass.
    $document = ignoreResponseDocument([$route, 'companion'])->document->toArray();

    expect($document['components']['schemas'] ?? [])->toHaveKey('FormData')
        ->and(ignoreResponseOrphans($document))->toBe([])
        ->and(array_keys($document['components']['schemas']))->toEqualCanonicalizing(['FormData', ...$schemas]);
})->with([
    // The resource body inference would have hoisted for the dropped 200.
    'inference' => ['inferred', []],
    // The 200 survives here, so its body legitimately hoists; the dropped 404's body must not.
    'a signalled exception' => ['signalled', ['ArticleResource', 'AuthorResource']],
    // The rate-limit 429 asks the error chain for a body, which registers a shared response of its own.
    'throttle middleware' => ['throttled', []],
    // The 200 the declined re-home leaves standing hoists the resource; the 201's second conversion of
    // it must not add anything the 201 was the only reference for.
    'a created resource' => ['created', ['ArticleResource', 'AuthorResource']],
    // The paginated rewraps hoist an envelope, its links/meta parts and a page component besides.
    'a paginated collection' => ['paginated', []],
    'a json-api-paginated collection' => ['jsonPaginated', []],
    // The 200 survives and really is paginated, so the envelope's components belong to it; the dropped
    // 400's body must not.
    'a strict-mode query builder' => ['queried', ['ArticleResource', 'ArticleResourcePage', 'AuthorResource', 'PaginationLinks', 'PaginationMeta']],
    // The implicit 404 — the one producer that already consulted the attribute.
    'a route-model binding' => ['implicit', []],
    // A declared body under a status the same action drops.
    'a #[Response] the same action contradicts' => ['contradicted', []],
    'a #[Response] naming an error component' => ['declaredError', []],
    // laravel-actions.
    'a laravel-actions html representation' => ['html', []],
    // The action's rules() still hoist a request body; only the dropped 403 must leave nothing.
    'a laravel-actions authorize() gate' => ['authorize', ['IgnoredAuthorizeActionRequest']],
]);

it('keeps a component an operation that did NOT ignore its response still reaches', function (): void {
    // Declining to hoist is a decision about ONE response. A component another operation references must
    // survive it, or dropping a response on one action would break a `$ref` on another.
    $document = ignoreResponseDocument(['inferred', 'shared'])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('ArticleResource')
        ->and(ignoreResponseOrphans($document))->toBe([])
        ->and(ignoreResponseStatuses($document, 'get', '/api/ignored-responses/inferred'))->toBe([])
        ->and(componentRefsIn($document['paths']['/api/ignored-responses/shared']['get'], 'schemas'))
        ->toContain('ArticleResource');
});

it('leaves the redirect range standing when a member of it is ignored', function (): void {
    // `3XX` stands in for ONE status nobody named, and a DECLARATION at 302 retires it because it makes
    // the unknown known ({@see OperationDraft::supersedeStatusRange()}). An ignore establishes nothing:
    // it names a status this operation never documented, so it drops nothing and retires nothing.
    $document = ignoreResponseDocument(['redirect'])->document->toArray();

    expect(ignoreResponseStatuses($document, 'get', '/api/ignored-responses/redirect'))->toBe(['3XX']);
});

it('reads the attribute the same on a warm build as on a cold one', function (): void {
    // Every producer now reads `#[IgnoreResponse]` where only two did. It is read off the route's
    // attribute set, whose whole controller hierarchy already keys the fragment — so this pins that no
    // NEW input crept in unrecorded: bytes and diagnostics both, warm against cold.
    $controller = IgnoredResponsesController::class;

    $warm = assertWarmEqualsCold(
        static function (Router $router) use ($controller): void {
            $router->get('api/ignored-responses/companion', [$controller, 'companion']);
        },
        static function (Router $router) use ($controller): void {
            $router->get('api/ignored-responses/companion', [$controller, 'companion']);
            $router->get('api/ignored-responses/inferred', [$controller, 'inferred']);
            $router->get('api/ignored-responses/signalled', [$controller, 'signalled']);
            $router->get('api/ignored-responses/throttled', [$controller, 'throttled'])->middleware('throttle:60,1');
        },
        ignoreResponseEngine(...),
    );

    // Equal-and-both-wrong is equality that proves nothing: the warm build really did drop the statuses.
    $document = $warm->document->toArray();

    expect(ignoreResponseStatuses($document, 'get', '/api/ignored-responses/inferred'))->toBe([])
        ->and(ignoreResponseStatuses($document, 'get', '/api/ignored-responses/signalled'))->toBe(['200'])
        ->and(ignoreResponseStatuses($document, 'get', '/api/ignored-responses/throttled'))->toBe(['200'])
        ->and(ignoreResponseOrphans($document))->toBe([]);
});

it('says nothing about an ignore whose status matches nothing', function (): void {
    // The class-level `#[IgnoreResponse(status: 418)]` matches nothing on any of these actions, which is
    // how a class-level ignore is ordinarily written — one status some of a controller's actions answer
    // with. A finding here would fire on every action that was never wrong, so there is none.
    $result = ignoreResponseDocument();

    $mentions = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => str_contains($diagnostic->message, '418'),
    ));

    // Anti-vacuity: the build did raise diagnostics, so an empty haystack is not what proves this.
    expect($result->diagnostics)->not->toBeEmpty()
        ->and($mentions)->toBe([]);
});
