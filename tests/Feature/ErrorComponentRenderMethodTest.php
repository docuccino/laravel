<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ComponentDeclaration;
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
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\DeclaredErrorsController;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\OverridingApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\ThingMissingException;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * `#[ErrorComponent]` on a RENDER METHOD, through the whole adapter.
 *
 * The class anchor says one thing per exception class, which is not enough when one exception family is
 * rendered into several different bodies — a base every API error extends would put one name over all of
 * them and the contest would retire it. The method anchor is the other half: the engine reads it off the
 * render path and hands it over on the return site, and the tier that built the body from that return
 * site claims it as its own name. So the ladder needs no new rung — `DeclaredErrorComponent::mayReplace()`
 * already says a class declaration replaces the status DEFAULT and nothing a producer named itself.
 *
 * The engine is stubbed here, as everywhere in the workbench: what the analyser really recovers off the
 * render path is proved against real code in inference-phpstan's `--group=fixture` InferredHandlerTest.
 */

/** The action symbols these rows script, one per route registered below. */
const RENDER_METHOD_ACTIONS = [
    'first' => DeclaredErrorsController::class.'::first',
    'second' => DeclaredErrorsController::class.'::second',
    'third' => DeclaredErrorsController::class.'::third',
    'fourth' => DeclaredErrorsController::class.'::fourth',
];

/** A declaration as the engine reports one, on the render method that answered. */
function renderMethodDeclaration(string $name, string $method = 'renderRejection'): ComponentDeclaration
{
    return new ComponentDeclaration(
        $name,
        'App\\Exceptions\\PortalRenderer::'.$method,
        new SourceLocation('/app/Exceptions/PortalRenderer.php', 12),
    );
}

/**
 * One scripted handler analysis: the `JsonResponse<payload, status>` the render path recovered, with the
 * declaration (or none) the render method carried.
 *
 * @param  list<string>  $bodyKeys
 * @param  list<string>  $dependencyFiles
 */
function renderedResponse(int $status, array $bodyKeys, ?ComponentDeclaration $component, array $dependencyFiles = []): ActionAnalysis
{
    return new ActionAnalysis(
        returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT(array_map(
                    static fn (string $key): ArrayShapeField => new ArrayShapeField($key, ScalarT::string()),
                    $bodyKeys,
                )),
                new LiteralT($status),
            ]),
            new SourceLocation(''),
            $component,
        )],
        dependencyFiles: $dependencyFiles,
    );
}

/**
 * Register `$byAction`'s routes, a render callback per scripted exception, and an engine that scripts both
 * the throws and what analysing each callback recovers. Route URIs sort after everything the workbench
 * states, so nothing here can perturb it.
 *
 * @param  array<string, array{class-string, int}>  $byAction  action name → `[FQCN, status]`
 * @param  array<class-string, ActionAnalysis>  $rendered  exception FQCN → what its render callback recovers
 */
function renderMethodBuild(array $byAction, array $rendered, ?TypeEngine $engine = null): GenerationResult
{
    /** @var Router $router */
    $router = app('router');
    foreach (array_keys($byAction) as $action) {
        $router->get('api/zz-rendered-'.$action, [DeclaredErrorsController::class, $action]);
    }

    $analyses = [];
    foreach ($byAction as $action => $exception) {
        $analyses[RENDER_METHOD_ACTIONS[$action]] = new ActionAnalysis(throws: [new ThrownException(
            $exception[0],
            $exception[1],
            [],
            ThrowConfidence::Certain,
            ThrowDisposition::Signal,
        )]);
    }

    $callables = [];
    foreach ($rendered as $fqcn => $analysis) {
        // The closure's declared parameter type is what the reflector matches the thrown class against.
        $callables[registerRenderCallback(static fn (Throwable $e) => response()->json([], 409), $fqcn)] = $analysis;
    }

    app()->instance(TypeEngine::class, $engine ?? WorkbenchEngine::make($callables, analysisOverrides: $analyses));

    return generateDocument();
}

/** A mapper that must beat the inferred-handler tier, which is the only way to beat it: order ahead of it. */
function aheadOfHandlerMapper(string $fqcn, string $status, string $name): ExceptionToResponse
{
    return new #[ExtensionOrder(priority: Priorities::FIRST + 1)] class($fqcn, $status, $name) implements ExceptionToResponse
    {
        public function __construct(
            private readonly string $fqcn,
            private readonly string $status,
            private readonly string $name,
        ) {}

        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return is_a($exception->exceptionFqcn, $this->fqcn, true);
        }

        public function producer(): string
        {
            return 'integration:acme';
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ?ResponseDraft
        {
            $by = Contribution::integration('acme');

            $draft = new ResponseDraft($this->status);
            $draft->claimComponentName($this->name, $by);
            $draft->setDescription('Conflict', $by);
            $draft->content('application/json')->set('type', 'object', $by);
            $draft->content('application/json')->set('properties', ['detail' => ['type' => 'string']], $by);

            return $draft;
        }
    };
}

/** The route closure the locality and warm/cold harnesses replay. */
function renderedRoutes(string ...$actions): callable
{
    return static function (Router $router) use ($actions): void {
        $router->get('api/forms/{form}', [FormController::class, 'show']);
        foreach ($actions as $action) {
            $router->get('api/zz-rendered-'.$action, [DeclaredErrorsController::class, $action]);
        }
    };
}

afterEach(function (): void {
    removeFragmentCacheDirs('warm');
    removeFragmentCacheDirs('cold');
    removeFragmentCacheDirs('rendered');
});

it('publishes an error under the name the render method that built it declares', function (): void {
    $rendered = ['ThingMissingException' => renderedResponse(409, ['detail'], renderMethodDeclaration('RenderedRejection'))];

    $document = renderMethodBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ], [ThingMissingException::class => $rendered['ThingMissingException']])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('RenderedRejection')
        // Neither the name the exception CLASS declares nor the status default is published beside it.
        ->and($document['components']['schemas'])->not->toHaveKey('ResourceMissing')
        ->and($document['components']['schemas'])->not->toHaveKey('Conflict')
        ->and($document['paths']['/api/zz-rendered-first']['get']['responses']['409']['$ref'])
        ->toBe('#/components/responses/RenderedRejection');
});

it('leaves the exception class to name a body the render method did not', function (): void {
    // The control for the row above: same tier, same body, no declaration on the method that built it. The
    // class anchor is then the most specific thing said about the response, exactly as before this existed.
    $document = renderMethodBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ], [ThingMissingException::class => renderedResponse(409, ['detail'], null)])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and($document['components']['schemas'])->not->toHaveKey('Conflict');
});

it('lets a mapper ordered ahead of the tier name the body instead', function (): void {
    // A registered mapper still wins, and the way it wins is unchanged: the chain resolves to the first
    // mapper that answers, so one that must beat ground truth orders itself ahead of it.
    Docuccino::extend(aheadOfHandlerMapper(ThingMissingException::class, '409', 'FromMapper'));

    $document = renderMethodBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ], [ThingMissingException::class => renderedResponse(409, ['detail'], renderMethodDeclaration('RenderedRejection'))])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('FromMapper')
        ->and($document['components']['schemas'])->not->toHaveKey('RenderedRejection')
        ->and($document['components']['schemas'])->not->toHaveKey('ResourceMissing');
});

it('refuses a declared name no component key could carry and tells the method that declared it', function (): void {
    // The same refusal the class anchor gets, from the tier that read this one: `claimComponentName()`
    // drops the name at the write and says nothing, so the author of the attribute would be left with a
    // line of code that does nothing. The response keeps the name it would have had — here the one the
    // exception class declares, since a refused name is no declaration and nothing else claimed it.
    $result = renderMethodBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ], [ThingMissingException::class => renderedResponse(409, ['detail'], renderMethodDeclaration('Not Found!'))]);

    $document = $result->document->toArray();
    $rejected = diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid');

    expect($document['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and(json_encode($document))->not->toContain('Not Found!')
        ->and($rejected)->toHaveCount(2)
        ->and($rejected[0]->message)->toContain('App\\Exceptions\\PortalRenderer::renderRejection')
        ->and($rejected[0]->message)->toContain('Not Found!')
        ->and($rejected[0]->severity)->toBe(Severity::Warning)
        ->and($rejected[0]->source?->file)->toContain('PortalRenderer.php');
});

it('keeps a declared name that happens to spell the status default', function (): void {
    // The render method named this body `Conflict`, which is also what the 409 default is called — the
    // same shape as `#[ErrorComponent("NotFound")]` on a 404. Asking the VALUE whether anything had named
    // the response reads a deliberate name as the absence of one and hands the body to the exception class
    // instead, so the fact travels from the write (`DeclaredErrorComponentTest` pins the rule itself).
    $document = renderMethodBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ], [ThingMissingException::class => renderedResponse(409, ['detail'], renderMethodDeclaration('Conflict'))])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('Conflict')
        ->and($document['components']['schemas'])->not->toHaveKey('ResourceMissing')
        ->and($document['paths']['/api/zz-rendered-first']['get']['responses']['409']['$ref'])
        ->toBe('#/components/responses/Conflict');
});

it('reports one refused name per mistake however many returns carry it', function (): void {
    // A renderer with three `return`s under one bad attribute is one typo. The engine stamps the analysed
    // method's declaration onto every site it harvested, so the tier keys what it reports by the mistake
    // the way the class anchor does — three byte-identical warnings say nothing the first did not.
    $analysis = new ActionAnalysis(returns: array_map(
        static fn (int $status): ReturnSite => new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([new ArrayShapeField('detail', ScalarT::string())]),
                new LiteralT($status),
            ]),
            new SourceLocation(''),
            renderMethodDeclaration('Not Found!'),
        ),
        [409, 409, 409],
    ));

    $result = renderMethodBuild([
        'first' => [ThingMissingException::class, 409],
    ], [ThingMissingException::class => $analysis]);

    expect(diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid'))->toHaveCount(1);
});

it('retires a name two render methods claim over two different bodies, and warns', function (): void {
    // Two arms naming one component for bodies that differ is a genuine contest, and it is the contest
    // core already settles: both climb `ComponentNames`' ladder onto names derived from their own content.
    // Nothing about the method anchor needs a second mechanism for it.
    $result = renderMethodBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
        'third' => [OverridingApiException::class, 410],
        'fourth' => [OverridingApiException::class, 410],
    ], [
        ThingMissingException::class => renderedResponse(409, ['detail'], renderMethodDeclaration('PortalProblem')),
        OverridingApiException::class => renderedResponse(410, ['reason', 'retryAfter'], renderMethodDeclaration('PortalProblem', 'renderGone')),
    ]);

    $document = $result->document->toArray();
    $names = array_values(array_filter(
        array_keys($document['components']['schemas']),
        static fn (string $name): bool => str_starts_with($name, 'PortalProblem'),
    ));

    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('PortalProblem')
        ->and($names[0])->toMatch('/^PortalProblem_[a-z2-7]{8}$/')
        ->and($names[1])->toMatch('/^PortalProblem_[a-z2-7]{8}$/')
        ->and(diagnosticsCoded($result->diagnostics, 'components.name-collision'))->not->toBeEmpty();
});

it('does not ask an author to reconcile class declarations a render method already outranked', function (): void {
    // Two classes naming one status differently is a contest only where one of them could have won. Here
    // the body was named by the method that built it, so neither was ever in the running and there is
    // nothing to reconcile — reporting one would send the reader to edit two attributes to no effect.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-rendered-first', [DeclaredErrorsController::class, 'first']);
    $router->get('api/zz-rendered-second', [DeclaredErrorsController::class, 'second']);

    $both = new ActionAnalysis(throws: [
        new ThrownException(ThingMissingException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        new ThrownException(OverridingApiException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
    ]);

    $callables = [
        registerRenderCallback(static fn (ThingMissingException $e) => response()->json([], 409), ThingMissingException::class) => renderedResponse(409, ['detail'], renderMethodDeclaration('RenderedRejection')),
    ];

    app()->instance(TypeEngine::class, WorkbenchEngine::make($callables, analysisOverrides: [
        RENDER_METHOD_ACTIONS['first'] => $both,
        RENDER_METHOD_ACTIONS['second'] => $both,
    ]));

    $result = generateDocument();
    $document = $result->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('RenderedRejection')
        ->and($document['components']['schemas'])->not->toHaveKey('Conflict')
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.error-component-contested'))->toBeEmpty();
});

it('publishes the same bytes and the same diagnostics on a warm fragment-cache build', function (): void {
    // The declaration is read while a route is built, so it travels on the operation fragment or not at
    // all — a warm hit that lost it would republish under the exception class's name instead.
    $symbol = registerRenderCallback(
        static fn (ThingMissingException $e) => response()->json([], 409),
        ThingMissingException::class,
    );
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        [$symbol => renderedResponse(409, ['detail'], renderMethodDeclaration('RenderedRejection'))],
        analysisOverrides: [
            RENDER_METHOD_ACTIONS['first'] => new ActionAnalysis(throws: [
                new ThrownException(ThingMissingException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            ]),
            RENDER_METHOD_ACTIONS['second'] => new ActionAnalysis(throws: [
                new ThrownException(ThingMissingException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            ]),
        ],
    );

    $warm = assertWarmEqualsCold(renderedRoutes('first'), renderedRoutes('first', 'second'), $engine);

    expect($warm->document->toArray()['components']['schemas'])->toHaveKey('RenderedRejection');
});

it('replays a refused name\'s warning on a warm fragment-cache build', function (): void {
    // The refusal is decided while a route is built, so the warning travels on that route's fragment or
    // not at all. Reporting less warm than cold would leave the author fixing the typo they were told
    // about and never hearing about the one they were not.
    $symbol = registerRenderCallback(
        static fn (ThingMissingException $e) => response()->json([], 409),
        ThingMissingException::class,
    );
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        [$symbol => renderedResponse(409, ['detail'], renderMethodDeclaration('Not Found!'))],
        analysisOverrides: [
            RENDER_METHOD_ACTIONS['first'] => new ActionAnalysis(throws: [
                new ThrownException(ThingMissingException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            ]),
            RENDER_METHOD_ACTIONS['second'] => new ActionAnalysis(throws: [
                new ThrownException(ThingMissingException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            ]),
        ],
    );

    $warm = assertWarmEqualsCold(renderedRoutes('first'), renderedRoutes('first', 'second'), $engine);

    expect(diagnosticsCoded($warm->diagnostics, 'attribute.error-component-invalid'))->toHaveCount(2);
});

it('does not move an operation a render method it never reaches learns to name', function (): void {
    // Locality. The workbench form route's own 404 must be byte-identical before and after two unrelated
    // routes start publishing a 409 the render path named.
    $symbol = registerRenderCallback(
        static fn (ThingMissingException $e) => response()->json([], 409),
        ThingMissingException::class,
    );
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        [$symbol => renderedResponse(409, ['detail'], renderMethodDeclaration('RenderedRejection'))],
        analysisOverrides: [
            RENDER_METHOD_ACTIONS['first'] => new ActionAnalysis(throws: [
                new ThrownException(ThingMissingException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            ]),
            RENDER_METHOD_ACTIONS['second'] => new ActionAnalysis(throws: [
                new ThrownException(ThingMissingException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            ]),
        ],
    );

    assertUnaffectedByUnrelatedRoute(
        renderedRoutes(),
        static function (Router $router): void {
            $router->get('api/zz-rendered-first', [DeclaredErrorsController::class, 'first']);
            $router->get('api/zz-rendered-second', [DeclaredErrorsController::class, 'second']);
        },
        'GET /api/forms/{form}',
        $engine,
    );
});

it('invalidates a fragment when the file the render method declared its name in is edited', function (): void {
    // The declaring method is usually not in the file the render path names — an inherited house helper is
    // the ordinary case — so the engine reports that file on the analysis and the tier records it as a
    // dependency. A warm build that missed it would publish yesterday's name.
    $declaring = sys_get_temp_dir().'/docuccino-render-decl-'.uniqid('', true).'.php';
    file_put_contents($declaring, "<?php\n\n// the file the name was written in\n");

    try {
        fragmentCacheDir('rendered');

        /** @var Router $router */
        $router = app('router');
        $router->get('api/zz-rendered-first', [DeclaredErrorsController::class, 'first']);

        $symbol = registerRenderCallback(
            static fn (ThingMissingException $e) => response()->json([], 409),
            ThingMissingException::class,
        );

        $engine = new CountingTypeEngine(WorkbenchEngine::make(
            [$symbol => renderedResponse(409, ['detail'], renderMethodDeclaration('RenderedRejection'), [$declaring])],
            analysisOverrides: [RENDER_METHOD_ACTIONS['first'] => new ActionAnalysis(throws: [
                new ThrownException(ThingMissingException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            ])],
        ));
        app()->instance(TypeEngine::class, $engine);

        $document = generateDocument()->document->toArray();
        $engine->analyzeCount = 0;

        // The declaration really is what named this response, so the file edited below is really the one
        // the answer came from.
        expect($document['paths']['/api/zz-rendered-first']['get']['responses']['409']['x-docuccino']['facts']['component'])
            ->toBe('RenderedRejection');

        generateDocument();
        expect($engine->analyzeCount)->toBe(0);

        file_put_contents($declaring, "<?php\n\n// edited\n");
        generateDocument();

        expect($engine->analyzeCount)->toBeGreaterThan(0);
    } finally {
        @unlink($declaring);
    }
});
