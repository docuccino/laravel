<?php

declare(strict_types=1);

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Extensions\IgnoredParametersExtension;
use Docuccino\Laravel\Support\ParameterLocations;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\IgnoredMembersController;
use Workbench\App\Http\Controllers\IgnoredParamsController;
use Workbench\App\Http\Requests\FilterWindowRequest;

/**
 * `#[IgnoreParam]` is subtractive, so what it has to survive is every producer that could write the
 * parameter back: a FormRequest recovered a phase later, the paginator key the last extension in the
 * parameter phase writes, and a parameter attribute at its own layer. These routes are registered
 * ad-hoc so no committed golden churns.
 */
function ignoreParamDocument(): GenerationResult
{
    $location = new SourceLocation('');
    $controller = IgnoredParamsController::class.'::';
    $collection = new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [
        new ClassT('Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource'),
    ]);

    // The FormRequest's rules() as the engine recovers it — a constant array shape, never executed.
    $rules = new ArrayShapeT([
        new ArrayShapeField('search', new LiteralT('nullable|string|max:100')),
        new ArrayShapeField('trace_id', new LiteralT('nullable|string')),
    ]);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [
            $controller.'paged' => new ActionAnalysis(returns: [new ReturnSite($collection, $location)]),
            'Workbench\\App\\Http\\Requests\\SearchFormsRequest::rules' => new ActionAnalysis(
                returns: [new ReturnSite($rules, $location)],
            ),
        ],
        traceOverrides: [
            $controller.'paged' => TraceScript::forChain('$q->paginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
        ],
    ));

    /** @var Router $router */
    $router = app('router');
    $router->get('api/ignored/search', [IgnoredParamsController::class, 'search']);
    $router->get('api/ignored/paged', [IgnoredParamsController::class, 'paged']);
    $router->get('api/ignored/forms/{form}', [IgnoredParamsController::class, 'show']);
    $router->get('api/ignored/bare', [IgnoredParamsController::class, 'bare']);
    $router->get('api/ignored/miscased', [IgnoredParamsController::class, 'miscased']);
    $router->get('api/ignored/nowhere', [IgnoredParamsController::class, 'nowhere']);
    $router->get('api/ignored/contradicted', [IgnoredParamsController::class, 'contradicted']);
    $router->get('api/ignored/renamed', [IgnoredParamsController::class, 'renamed']);
    $router->get('api/ignored/repeated', [IgnoredParamsController::class, 'repeated']);
    $router->get('api/ignored/nameless', [IgnoredParamsController::class, 'nameless']);

    return generateDocument();
}

/**
 * `[in, name]` for every parameter one of the ad-hoc operations documents.
 *
 * @return list<array{0: string, 1: string}>
 */
function ignoreParamParameters(GenerationResult $result, string $path): array
{
    $document = $result->document->toArray();
    /** @var list<array<string, mixed>> $parameters */
    $parameters = $document['paths'][$path]['get']['parameters'] ?? [];

    return array_map(
        static fn (array $parameter): array => [(string) $parameter['in'], (string) $parameter['name']],
        $parameters,
    );
}

it('keeps an ignored parameter out of the document, whichever producer writes it', function (string $path, array $gone, array $kept): void {
    $parameters = ignoreParamParameters(ignoreParamDocument(), $path);

    // Anti-vacuity: the row names every parameter the operation is left with, so an operation that
    // documented nothing at all — a producer that stopped running — fails rather than passes.
    expect($parameters)->toBe($kept);

    foreach ($gone as $absent) {
        expect($parameters)->not->toContain($absent);
    }
})->with([
    // The FormRequest lands `search` and `trace_id` as query parameters in the phase AFTER the one the
    // ignore is read in.
    'query, recovered from a FormRequest' => [
        '/api/ignored/search',
        [['query', 'trace_id']],
        [['header', 'X-Trace'], ['cookie', 'flavour'], ['query', 'search']],
    ],
    // The paginator key is written by the last extension in the parameter phase.
    'query, written by the last parameter producer' => [
        '/api/ignored/paged',
        [['query', 'page']],
        [['header', 'X-Trace'], ['cookie', 'flavour']],
    ],
    // The route's own path segment.
    'path, from the route pattern' => [
        '/api/ignored/forms/{form}',
        [['path', 'form']],
        [['header', 'X-Trace'], ['cookie', 'flavour']],
    ],
    // Class-level parameter attributes an action opts out of.
    'header and cookie, from class-level attributes' => [
        '/api/ignored/bare',
        [['header', 'X-Trace'], ['cookie', 'flavour']],
        [],
    ],
    // Same action, same layer: the ignore is the retraction, so it wins over the declaration.
    'query, contradicted by a same-action attribute' => [
        '/api/ignored/contradicted',
        [['query', 'draft']],
        [['header', 'X-Trace'], ['cookie', 'flavour']],
    ],
]);

it('reads an `in:` in whatever case it was written', function (): void {
    $parameters = ignoreParamParameters(ignoreParamDocument(), '/api/ignored/miscased');

    expect($parameters)->toBe([['cookie', 'flavour']]);
});

it('reports an `in:` that names no parameter location, and drops nothing for it', function (): void {
    $result = ignoreParamDocument();

    $reports = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'attribute.ignore-param-location',
    ));

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->severity)->toBe(Severity::Warning)
        // The value the author wrote, verbatim, and the name it was written beside.
        ->and($reports[0]->message)->toContain('"body"')
        ->and($reports[0]->message)->toContain('X-Trace')
        // The legal set, so the reader never has to look it up — written out, and then held to the set
        // the product declares, because a list spelled into prose is silent the day it goes short.
        ->and($reports[0]->help)->toContain('cookie, header, path or query')
        ->and(array_filter(
            ParameterLocations::all(),
            static fn (string $location): bool => ! str_contains($reports[0]->help ?? '', $location),
        ))->toBe([])
        // Nothing was dropped, which is what the report says.
        ->and(ignoreParamParameters($result, '/api/ignored/nowhere'))->toContain(['header', 'X-Trace']);
});

/*
 * A subtraction leaves no evidence: a parameter that was never documented and one the ignore removed
 * produce the same operation, so a name that matched nothing looks exactly like a name that worked and
 * the author goes on believing the field is hidden. Every row below is one shape of "matched nothing",
 * against the one shape that matched.
 */

/**
 * The `attribute.ignore-param-unmatched` reports one route raised, in the order they were raised.
 *
 * @return list<Diagnostic>
 */
function ignoreParamUnmatched(GenerationResult $result, string $path): array
{
    return array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'attribute.ignore-param-unmatched'
            && $diagnostic->routeSignature !== null
            && str_contains($diagnostic->routeSignature, ltrim($path, '/')),
    ));
}

it('reports an ignore whose name matches no parameter, and names what the operation does document', function (string $path, int $reports, array $quotes): void {
    $result = ignoreParamDocument();
    $found = ignoreParamUnmatched($result, $path);

    expect($found)->toHaveCount($reports);

    foreach ($found as $diagnostic) {
        expect($diagnostic->severity)->toBe(Severity::Warning);
    }

    foreach ($quotes as $quote) {
        expect($found[0]->message)->toContain($quote);
    }
})->with([
    // The remedy: the name the author wrote, beside the name the document publishes. `trace` was
    // `trace_id` before the rename, and the message puts the two a character apart.
    'a name renamed out from under it' => ['/api/ignored/renamed', 1, ['"trace"', 'query:trace_id', 'query:search']],
    // One mistake written twice is one mistake. A second report would send the reader looking for a
    // second declaration.
    'the same declaration twice' => ['/api/ignored/repeated', 1, ['"gone"']],
    // A name with nothing in it matches nothing in any of the four locations.
    'an empty name' => ['/api/ignored/nameless', 1, ['#[IgnoreParam(name: "")]']],
    // The `in:` is the mistake and it has already been reported as that; saying the name matched
    // nothing too would ask the author to fix the half that was fine.
    'an `in:` that names no location' => ['/api/ignored/nowhere', 0, []],
    // Every row of the dataset above this one: a name that matched is silent.
    'a name that matched' => ['/api/ignored/search', 0, []],
    'a path segment that matched' => ['/api/ignored/forms/{form}', 0, []],
    // Two declarations naming ONE parameter, in two spellings that do not dedupe. Both matched, because
    // both are judged against what stood before either removed anything.
    'the same parameter named twice, two ways' => ['/api/ignored/paged', 0, []],
    // A class-level declaration the action opts into: it matched here, so it is silent here.
    'a class-level parameter an action drops' => ['/api/ignored/bare', 0, []],
]);

it('says nothing about a class-level ignore an action never documented the name for', function (): void {
    // The class-level `#[IgnoreParam(name: 'per_page')]` matches nothing on ANY of these actions, and
    // that is how a class-level ignore is ordinarily written — one key some of a controller's actions
    // page by. Reporting it per route would fire on every action that was never wrong, so only a
    // declaration written on the action itself is reported.
    $result = ignoreParamDocument();

    $mentions = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => str_contains($diagnostic->message, 'per_page'),
    ));

    // Anti-vacuity: the build did raise this code elsewhere, so an empty haystack is not what proves it.
    expect(ignoreParamUnmatched($result, '/api/ignored/renamed'))->not->toBeEmpty()
        ->and($mentions)->toBe([]);
});

it('reports nothing where every declaration matched', function (): void {
    // The whole build's count, so a code that started firing on the working rows fails here rather than
    // passing every row's own assertion.
    $reports = array_values(array_filter(
        ignoreParamDocument()->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'attribute.ignore-param-unmatched',
    ));

    expect($reports)->toHaveCount(3);
});

it('escapes the two values it did not write into the location report', function (): void {
    // The name and the `in:` are the author's, but a diagnostic message is not only printed: it reaches
    // `x-docuccino.diagnostics` in the emitted document, where a `jq -r` re-arms an escape sequence that
    // survived as bytes. The sibling report next door already puts both through `PlainText`.
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/forms'),
        actionRef: new ActionRef('', 'App\\C', 'index'),
        attributes: new AttributeSet([new IgnoreParam(name: "X-Trace\x1b[31m", in: "bo\x07dy")]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
    );

    (new IgnoredParametersExtension)->handle(new OperationDraft, $context);

    $reports = array_values(array_filter(
        $context->components->diagnostics(),
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'attribute.ignore-param-location',
    ));

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->message)->not->toContain("\x1b")
        ->and($reports[0]->message)->not->toContain("\x07")
        ->and($reports[0]->message)->toContain('\x1B')
        ->and($reports[0]->message)->toContain('\x07');
});

/*
 * The representation that publishes a filter surface as ONE object parameter. A bracketed
 * `#[IgnoreParam]` names a MEMBER of that object there, not a parameter of its own, so a pass that only
 * removed parameters dropped nothing and published the field the author marked as not-for-publication —
 * a subtraction leaving no evidence, in the one place the author cannot see it happen.
 */

/**
 * The two deepObject routes as the whole pipeline builds them: a Query Builder list whose filters are
 * the container's members, with the application's own rules requiring one member and nesting two below
 * another. Registered ad-hoc, so no committed golden churns.
 */
function ignoredMembersDocument(): GenerationResult
{
    // One build for the whole family. The result is a value, and every caller only reads it, so the
    // routes and the engine instance are installed once rather than once per assertion.
    static $memo = null;
    if ($memo instanceof GenerationResult) {
        return $memo;
    }

    $location = new SourceLocation('');
    $controller = IgnoredMembersController::class.'::';

    // rules() as the engine recovers it — the constant array the class really returns.
    $rules = new ArrayShapeT([
        new ArrayShapeField('filter.opaque', new LiteralT('required|string|max:40')),
        new ArrayShapeField('filter.window.from', new LiteralT('date')),
        new ArrayShapeField('filter.window.to', new LiteralT('date')),
    ]);

    // Read out of the controller's real file, not copied: a chain written twice goes stale silently.
    // forMethod does not descend into a callee, so the shared `gadgets()` helper is the target.
    $chain = TraceScript::forMethod(
        (string) (new ReflectionClass(IgnoredMembersController::class))->getFileName(),
        IgnoredMembersController::class,
        'gadgets',
        receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [
            FilterWindowRequest::class.'::rules' => new ActionAnalysis(
                returns: [new ReturnSite($rules, $location)],
            ),
        ],
        traceOverrides: [
            $controller.'members' => $chain,
            $controller.'typo' => $chain,
            $controller.'repeated' => $chain,
        ],
    ));

    /** @var Router $router */
    $router = app('router');
    $router->get('api/ignored-members/list', [IgnoredMembersController::class, 'members']);
    $router->get('api/ignored-members/typo', [IgnoredMembersController::class, 'typo']);
    $router->get('api/ignored-members/repeated', [IgnoredMembersController::class, 'repeated']);

    setBuild('documents.default.representation.filters', 'deepObject');

    return $memo = generateDocument(static function (array $raw): array {
        $raw['info'] = ['title' => 'Ignored members API', 'version' => '1.0.0'];
        $raw['routes'] = ['include' => ['api/ignored-members/*']];

        return $raw;
    });
}

/**
 * One deepObject route's `filter` container, as the document publishes it.
 *
 * @return array<string, mixed>
 */
function ignoredMembersContainer(GenerationResult $result, string $path): array
{
    $document = $result->document->toArray();
    /** @var list<array<string, mixed>> $parameters */
    $parameters = $document['paths'][$path]['get']['parameters'] ?? [];

    foreach ($parameters as $parameter) {
        if (($parameter['name'] ?? null) === 'filter') {
            return $parameter;
        }
    }

    return [];
}

it('drops the member a bracketed ignore names, and nothing else about the container', function (): void {
    $container = ignoredMembersContainer(ignoredMembersDocument(), '/api/ignored-members/list');

    // Anti-vacuity: the container really is the one object parameter this representation publishes, and
    // the members the author kept are still in it — so this is the removal and not a producer that
    // stopped running.
    expect($container['style'])->toBe('deepObject')
        ->and(array_keys($container['schema']['properties']))->toBe(['status', 'window'])
        // `filter.opaque` is `required` in the application's rules, so the container required it and was
        // itself required because of it. Both statements were ABOUT the dropped member: a required list
        // naming a member nobody publishes tells a consumer their request must carry a value the document
        // does not describe, and a generated client then demands a field it cannot name.
        ->and($container['schema'])->not->toHaveKey('required')
        ->and($container['required'])->toBeFalse()
        // And the nested leaf: the sibling bound is untouched, so the removal is addressed at the leaf
        // and not at its parent.
        ->and(array_keys($container['schema']['properties']['window']['properties']))->toBe(['to'])
        ->and($container['schema']['properties']['window']['properties']['to']['format'])->toBe('date');
});

it('reports a bracketed name no member matches, and names the members beside the parameters', function (): void {
    $result = ignoredMembersDocument();
    $reports = ignoreParamUnmatched($result, '/api/ignored-members/typo');

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->severity)->toBe(Severity::Warning)
        ->and($reports[0]->message)->toContain('"filter[opaqu]"')
        // The remedy has to carry the member spelling. Answered with `query:filter` alone, the reader is
        // handed back the one address they already tried, a character away from the one that works —
        // and it is what the bracketed representation already answers, where each member IS a parameter.
        ->and($reports[0]->message)->toContain('query:filter[opaque]')
        ->and($reports[0]->message)->toContain('query:filter[window][from]');

    // Nothing was dropped, which is what the report says: the filter the author meant to hide is
    // published, and that is the whole reason this declaration is not left silent.
    expect(array_keys(ignoredMembersContainer($result, '/api/ignored-members/typo')['schema']['properties']))
        ->toBe(['status', 'opaque', 'window']);
});

it('says nothing where a bracketed ignore reached a member', function (string $path): void {
    $result = ignoredMembersDocument();

    // Anti-vacuity: the build did raise this code on the route next door, so an empty haystack is not
    // what proves it.
    expect(ignoreParamUnmatched($result, '/api/ignored-members/typo'))->not->toBeEmpty()
        ->and(ignoreParamUnmatched($result, $path))->toBe([]);
})->with([
    'a name that matched' => ['/api/ignored-members/list'],
    // Two declarations naming ONE member, in two spellings that do not dedupe. The second is judged
    // against what stood before the first removed anything, or it reports the member the first had just
    // taken away as one that was never published.
    'the same member named twice, two ways' => ['/api/ignored-members/repeated'],
]);

/**
 * The artifact: the document a consumer receives for a filter surface an author subtracted from, in
 * bytes. A member creeping back into the container, a `required` list naming one that is gone, or a
 * container that claims to be required because of one, each moves these files — so a change to what
 * this representation publishes has something to be false about. Restricted to the two routes, so no
 * committed golden churns.
 */
it('emits the subtracted deepObject container and its diagnostics byte-identically', function (): void {
    $result = ignoredMembersDocument();

    assertGolden('workbench-ignored-members.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden(
        'workbench-ignored-members.diagnostics.json',
        json_encode(diagnosticRecords($result->diagnostics), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
});

it('reports a bracketed member it could not reach, rather than reading the member list as a match', function (): void {
    // The wire spelling has no escape, so a member whose name holds a `]` closes its own group early
    // and no bracketed name means it. The member is LISTED for a reader — it is published — so judging
    // the declaration by string equality against that list answers "matched" for a removal that
    // reaches nothing, and a subtraction leaves the same document either way. Reachability is the
    // criterion because it is what the removal itself will use.
    $by = Contribution::integration('query-builder');
    $operation = new OperationDraft;
    $container = $operation->parameter('query', 'filter');
    $container->set('style', 'deepObject', $by);
    $container->schema()->set('type', 'object', $by);
    $container->schema()->property('a]b')->set('type', 'string', $by);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\C', 'index'),
        attributes: new AttributeSet([new IgnoreParam(name: 'filter[a]b]', in: 'query')]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
    );

    (new IgnoredParametersExtension)->handle($operation, $context);

    $reports = array_values(array_filter(
        $context->components->diagnostics(),
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'attribute.ignore-param-unmatched',
    ));

    expect($reports)->toHaveCount(1)
        ->and(array_keys((array) $operation->parameter('query', 'filter')->freeze()->toArray()['schema']['properties']))
        ->toBe(['a]b']);
});

it('runs where every deepObject container has already been written', function (): void {
    // A container is not a Parameters-phase fact: the rules recovery mints one in the Request phase,
    // so a pass asking which members exist before then is answered about a container that is not there
    // yet. Finalize is the only phase from which the answer is complete, and this is the pass whose
    // report and whose removal both depend on it.
    expect((new IgnoredParametersExtension)->phase())->toBe(OperationPhase::Finalize);
});
