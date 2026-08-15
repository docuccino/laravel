<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ArchiveArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ExplicitMethodAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\HtmlResponseAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\JsonResponseAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\PublishArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\SimpleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\WithAttributesAction;
use Illuminate\Routing\Router;

/**
 * The laravel-actions integration end-to-end. The route reflector's method remap runs against real
 * reflection of real action fixtures (an invokable action → its handle()/asController()); the resolved
 * method's body analysis is scripted by the stub engine, since this integration only redirects *which*
 * method gets analysed — reflection work, not engine work, and the engine's method analysis is already
 * proven by the fixture group. Request body from rules() and the 403 from authorize() run against the
 * real container-registered extension set.
 */
function actionEngine(): StubTypeEngine
{
    $loc = new SourceLocation('');
    $literal = static fn (array $fields): ActionAnalysis => new ActionAnalysis(
        returns: [new ReturnSite(new ArrayShapeT($fields), $loc)],
    );

    return new StubTypeEngine(analyses: [
        PublishArticleAction::class.'::rules' => $literal([
            new ArrayShapeField('title', new LiteralT('required|string|max:100')),
            new ArrayShapeField('body', new LiteralT('required|string')),
        ]),
        PublishArticleAction::class.'::handle' => new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]), $loc)],
        ),
        // asController() carries a different return shape from handle(), so a 200 body of `archived` shows
        // the analysis was redirected there, not just the docblock summary.
        ArchiveArticleAction::class.'::asController' => new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('archived', ScalarT::bool())]), $loc)],
        ),
        // handle() and jsonResponse() carry different shapes, so a 200 body of `{data, meta}` shows the
        // success analysis went to jsonResponse (the decorator's real JSON wire shape) rather than
        // handle()'s bare `{id}`.
        JsonResponseAction::class.'::handle' => new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]), $loc)],
        ),
        JsonResponseAction::class.'::jsonResponse' => new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())])),
                new ArrayShapeField('meta', new ArrayShapeT([new ArrayShapeField('published', ScalarT::bool())])),
            ]), $loc)],
        ),
        // htmlResponse action: handle() drives the JSON body (no jsonResponse redirect); the text/html
        // note is additive.
        HtmlResponseAction::class.'::handle' => new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]), $loc)],
        ),
    ]);
}

/**
 * @param  string|array{0: class-string, 1: string}  $action
 * @return array<string, mixed>
 */
function actionOperation(string $verb, string $path, string|array $action): array
{
    /** @var Router $router */
    $router = app('router');
    $router->{$verb}($path, $action);

    app()->instance(TypeEngine::class, actionEngine());

    return generateDocument()->document->toArray()['paths']['/'.$path][$verb] ?? [];
}

it('resolves an invokable action to handle(), documenting its summary, rules() body and authorize() 403', function (): void {
    $operation = actionOperation('post', 'api/publish', PublishArticleAction::class);

    // Summary comes from the resolved handle() docblock (not the trait's __invoke forwarder).
    expect($operation['summary'])->toBe('Publish an article.');

    // rules() became the JSON request body, hoisted to a component named after the action class
    // (single source class) and marked as the shape a client SENDS, so the action's own shape can
    // never come to mean it; the full document carries the component the operation $refs.
    expect($operation['requestBody']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/PublishArticleActionRequest']);
    $document = generateDocument()->document->toArray();
    expect($document['components']['schemas'])->toHaveKey('PublishArticleActionRequest');
    $properties = $document['components']['schemas']['PublishArticleActionRequest']['properties'] ?? [];
    expect($properties)->toHaveKeys(['title', 'body']);

    // authorize() became a 403.
    expect($operation['responses'])->toHaveKey('403');

    // The resolved handle()'s return analysis composed into a 200 body — the analysis was redirected,
    // not just the docblock summary.
    $ok = $operation['responses']['200']['content']['application/json']['schema']['properties'] ?? [];
    expect($ok)->toHaveKey('id');
});

it('resolves an action defining asController() to that method over handle()', function (): void {
    $operation = actionOperation('put', 'api/archive', ArchiveArticleAction::class);

    // The asController() docblock summary shows it won precedence over handle()…
    expect($operation['summary'])->toBe('Archive an article.');

    // …and its own return shape composed into the 200 body, so the analysis followed it too.
    $ok = $operation['responses']['200']['content']['application/json']['schema']['properties'] ?? [];
    expect($ok)->toHaveKey('archived');
});

it('adds no request body or 403 for a minimal action with neither rules() nor authorize()', function (): void {
    $operation = actionOperation('get', 'api/simple', SimpleAction::class);

    expect($operation)->not->toHaveKey('requestBody')
        ->and($operation['responses'] ?? [])->not->toHaveKey('403');
});

it('documents no rules() body or authorize() 403 for an explicitly-registered method', function (): void {
    // Registered as [ExplicitMethodAction::class, 'store']: the package never validates an explicit
    // method, so despite the action defining rules() + authorize(), neither is documented.
    $operation = actionOperation('post', 'api/explicit', [ExplicitMethodAction::class, 'store']);

    expect($operation['summary'])->toBe('Store an article.')
        ->and($operation)->not->toHaveKey('requestBody')
        ->and($operation['responses'] ?? [])->not->toHaveKey('403');
});

it('documents no rules() body or authorize() 403 for a WithAttributes action', function (): void {
    // The WithAttributes trait opts out of automatic request validation, so rules()/authorize() never
    // run even though the invokable route dispatches through handle().
    $operation = actionOperation('post', 'api/with-attributes', WithAttributesAction::class);

    expect($operation)->not->toHaveKey('requestBody')
        ->and($operation['responses'] ?? [])->not->toHaveKey('403');
});

it('builds the 200 body from jsonResponse() when the action defines it, not from handle()', function (): void {
    // The package's controller decorator returns jsonResponse($result) for JSON clients, so its
    // `{data, meta}` return — not handle()'s bare `{id}` — is the real wire shape.
    $operation = actionOperation('post', 'api/publish-json', JsonResponseAction::class);

    $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? [];
    expect($schema['properties'] ?? [])->toHaveKeys(['data', 'meta'])
        ->and($schema['properties'] ?? [])->not->toHaveKey('id');
});

it('builds the 200 body from the resolved method when the action defines no jsonResponse() (unchanged)', function (): void {
    // With no jsonResponse()/htmlResponse() the success body stays exactly the dispatched handle()'s
    // analysis.
    $operation = actionOperation('post', 'api/publish', PublishArticleAction::class);

    $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? [];
    expect($schema['properties'] ?? [])->toHaveKey('id')
        ->and($operation['responses']['200']['content'] ?? [])->not->toHaveKey('text/html');
});

it('records a text/html success representation when the action defines htmlResponse()', function (): void {
    // htmlResponse() serves non-JSON clients, so the endpoint also returns text/html — noted as a string
    // content type, not a JSON-typed body. The JSON body still comes from handle().
    $operation = actionOperation('get', 'api/show-html', HtmlResponseAction::class);

    $content = $operation['responses']['200']['content'] ?? [];
    expect($content)->toHaveKeys(['application/json', 'text/html'])
        ->and($content['text/html']['schema']['type'] ?? null)->toBe('string')
        ->and($content['application/json']['schema']['properties'] ?? [])->toHaveKey('id');
});
