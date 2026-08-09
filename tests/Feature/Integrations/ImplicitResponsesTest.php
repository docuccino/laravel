<?php

declare(strict_types=1);

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\ImplicitResponsesExtension;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\GateController;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\GateRequest;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\PlainRequest;

/**
 * The implicit-response matrix (design §Errors): 401/422/404/403 synthesized from statically-visible
 * middleware / binding / request signals, flowed through the resolved exception→response chain. Each
 * signal has positive rows and the negative rows the brief mandates (#[Unauthenticated] suppresses 401;
 * no bindings → no 404; authorize() returning literal true → no 403; #[IgnoreResponse] opt-out;
 * error_responses => 'none' emits nothing).
 */
function implicitResponseMappers(string $errorResponses): array
{
    $document = new DocumentConfig('default', [], errorResponses: $errorResponses);

    return app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all($document), [])->exceptionToResponse;
}

/**
 * @param  list<object>  $attributes
 * @param  array<string, string>  $routeBindings
 */
function implicitContext(
    RouteDescriptor $route,
    string $errorResponses = 'default',
    array $attributes = [],
    array $routeBindings = [],
    ?TypeEngine $engine = null,
    ?ActionRef $actionRef = null,
    ?string $formRequestClass = null,
): RouteContext {
    return new RouteContext(
        route: $route,
        actionRef: $actionRef ?? new ActionRef('', null, 'index'),
        attributes: new AttributeSet($attributes),
        engine: $engine ?? new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: 'auth*', errorResponses: $errorResponses),
        exceptionMappers: implicitResponseMappers($errorResponses),
        routeBindings: $routeBindings,
        formRequestClass: $formRequestClass,
    );
}

function runImplicit(RouteContext $context, ?OperationDraft $operation = null): OperationDraft
{
    $operation ??= new OperationDraft;
    (new ImplicitResponsesExtension)->handle($operation, $context);

    return $operation;
}

/** @return list<string> */
function implicitStatuses(OperationDraft $operation): array
{
    // PHP coerces numeric-string array keys ('401') to int, so restore the string the draft API uses.
    return array_map(strval(...), array_keys($operation->freeze()->responses));
}

it('synthesizes a 401 for an auth-middleware route', function (): void {
    $context = implicitContext(new RouteDescriptor(['GET'], 'api/me', middleware: ['auth:sanctum']));

    expect(implicitStatuses(runImplicit($context)))->toContain('401');
});

it('suppresses the 401 on an #[Unauthenticated] route', function (): void {
    $context = implicitContext(
        new RouteDescriptor(['GET'], 'api/me', middleware: ['auth:sanctum']),
        attributes: [new Unauthenticated],
    );

    expect(implicitStatuses(runImplicit($context)))->not->toContain('401');
});

it('adds no 401 to a route without auth middleware', function (): void {
    $context = implicitContext(new RouteDescriptor(['GET'], 'api/public', middleware: ['throttle:60,1']));

    expect(implicitStatuses(runImplicit($context)))->toBe([]);
});

it('synthesizes a 422 when a validated request body was recovered at the integration layer', function (string $producer): void {
    $context = implicitContext(new RouteDescriptor(['POST'], 'api/things'));
    $operation = new OperationDraft;
    // Stand in for a request-recovery integration having recovered a body (its integration producer
    // owns it). Layer-based, not a closed producer list: form-request/spatie-data/laravel-actions all
    // qualify, and so does a third-party recoverer writing at the integration layer.
    $operation->set('requestBody', ['content' => ['application/json' => ['schema' => ['type' => 'object']]]], Contribution::integration($producer));

    expect(implicitStatuses(runImplicit($context, $operation)))->toContain('422');
})->with([
    'form-request' => ['form-request'],
    'spatie-data' => ['spatie-data'],
    'laravel-actions' => ['laravel-actions'],
    'third-party recoverer' => ['acme-request-recovery'],
]);

it('adds no 422 when the request body came from an attribute, not validation recovery', function (): void {
    $context = implicitContext(new RouteDescriptor(['POST'], 'api/things'));
    $operation = new OperationDraft;
    $operation->set('requestBody', ['content' => ['application/json' => ['schema' => ['type' => 'object']]]], Contribution::attribute());

    expect(implicitStatuses(runImplicit($context, $operation)))->not->toContain('422');
});

it('adds no 422 when no request body was recovered', function (): void {
    $context = implicitContext(new RouteDescriptor(['POST'], 'api/things'));

    expect(implicitStatuses(runImplicit($context)))->not->toContain('422');
});

it('synthesizes exactly one 404 for a route with model-bound path parameters', function (): void {
    $context = implicitContext(
        new RouteDescriptor(['GET'], 'api/posts/{post}/comments/{comment}'),
        routeBindings: ['post' => 'App\\Models\\Post', 'comment' => 'App\\Models\\Comment'],
    );

    $statuses = implicitStatuses(runImplicit($context));
    expect($statuses)->toContain('404')
        // One 404 per operation regardless of how many params are bound.
        ->and(array_count_values($statuses)['404'] ?? 0)->toBe(1);
});

it('adds no 404 when the route has no model bindings', function (): void {
    $context = implicitContext(new RouteDescriptor(['GET'], 'api/posts/{post}'));

    expect(implicitStatuses(runImplicit($context)))->not->toContain('404');
});

it('synthesizes a 403 for authorization middleware', function (string $middleware): void {
    $context = implicitContext(new RouteDescriptor(['GET'], 'api/guarded', middleware: [$middleware]));

    expect(implicitStatuses(runImplicit($context)))->toContain('403');
})->with([
    'can' => ['can:update,post'],
    'signed' => ['signed'],
    'signed:relative' => ['signed:relative'],
    'verified' => ['verified'],
    'verified:route' => ['verified:route'],
]);

it('synthesizes a 403 for a FormRequest whose authorize() can deny', function (): void {
    $engine = new StubTypeEngine(analyses: [
        GateRequest::class.'::authorize' => new ActionAnalysis(returns: [new ReturnSite(ScalarT::bool(), new SourceLocation(''))]),
    ]);
    $context = implicitContext(
        new RouteDescriptor(['POST'], 'api/gated'),
        engine: $engine,
        actionRef: new ActionRef((string) (new ReflectionClass(GateController::class))->getFileName(), GateController::class, 'store'),
        formRequestClass: GateRequest::class,
    );

    expect(implicitStatuses(runImplicit($context)))->toContain('403');
});

it('adds no 403 for a FormRequest authorize() that returns literal true', function (): void {
    $engine = new StubTypeEngine(analyses: [
        GateRequest::class.'::authorize' => new ActionAnalysis(returns: [new ReturnSite(new LiteralT(true), new SourceLocation(''))]),
    ]);
    $context = implicitContext(
        new RouteDescriptor(['POST'], 'api/gated'),
        engine: $engine,
        actionRef: new ActionRef((string) (new ReflectionClass(GateController::class))->getFileName(), GateController::class, 'store'),
        formRequestClass: GateRequest::class,
    );

    expect(implicitStatuses(runImplicit($context)))->not->toContain('403');
});

it('adds no 403 when the route has no FormRequest and no authorization middleware', function (): void {
    $context = implicitContext(new RouteDescriptor(['POST'], 'api/open', middleware: ['throttle:60,1']));

    expect(implicitStatuses(runImplicit($context)))->not->toContain('403');
});

it('adds no 403 for a FormRequest that declares no authorize() gate of its own', function (): void {
    $context = implicitContext(
        new RouteDescriptor(['POST'], 'api/plain'),
        actionRef: new ActionRef((string) (new ReflectionClass(GateController::class))->getFileName(), GateController::class, 'store'),
        formRequestClass: PlainRequest::class,
    );

    expect(implicitStatuses(runImplicit($context)))->not->toContain('403');
});

it('honours #[IgnoreResponse] as an opt-out for a synthesized status', function (): void {
    $context = implicitContext(
        new RouteDescriptor(['GET'], 'api/me', middleware: ['auth:sanctum']),
        attributes: [new IgnoreResponse(401)],
    );

    expect(implicitStatuses(runImplicit($context)))->not->toContain('401');
});

it('emits nothing when error_responses is none', function (): void {
    $context = implicitContext(
        new RouteDescriptor(['POST'], 'api/things/{thing}', middleware: ['auth:sanctum', 'can:update']),
        errorResponses: 'none',
        routeBindings: ['thing' => 'App\\Models\\Thing'],
    );

    expect(implicitStatuses(runImplicit($context)))->toBe([]);
});

it('references the Problem Details component when the preset is active', function (): void {
    $context = implicitContext(
        new RouteDescriptor(['GET'], 'api/me', middleware: ['auth:sanctum']),
        errorResponses: 'problem-details',
    );

    $response = runImplicit($context)->freeze()->responses['401'];

    expect($response->ref)->toBe('#/components/responses/ProblemUnauthenticated');
});

it('does not double up a status the action already throws (chain merge)', function (): void {
    // An explicit framework-errors 422 is applied first; the implicit validated-request 422 then
    // targets the same status-keyed response and is shadowed — exactly one 422, framework shape kept.
    $context = implicitContext(new RouteDescriptor(['POST'], 'api/things'));
    $operation = new OperationDraft;
    $operation->set('requestBody', ['content' => ['application/json' => ['schema' => ['type' => 'object']]]], Contribution::integration('spatie-data'));
    // Pre-apply the explicit framework 422 body (message + errors map).
    $operation->response('422')->content('application/json')->set('type', 'object', Contribution::integration('framework-errors'));

    runImplicit($context, $operation);

    $statuses = implicitStatuses($operation);
    expect(array_count_values($statuses)['422'] ?? 0)->toBe(1);
});
