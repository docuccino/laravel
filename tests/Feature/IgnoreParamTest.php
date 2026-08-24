<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\IgnoredParamsController;

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

it('says nothing about an ignore whose name matches no parameter', function (): void {
    // The class-level `#[IgnoreParam(name: 'per_page')]` matches nothing on any of these actions, which
    // is how a class-level ignore is ordinarily written — one key some of a controller's actions page by.
    // A finding here would fire on every action that was never wrong, so there is none.
    $result = ignoreParamDocument();

    $mentions = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => str_contains($diagnostic->message, 'per_page'),
    ));

    // Anti-vacuity: the build did raise diagnostics, so an empty haystack is not what proves this.
    expect($result->diagnostics)->not->toBeEmpty()
        ->and($mentions)->toBe([]);
});

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
        // The legal set, so the reader never has to look it up.
        ->and($reports[0]->help)->toContain('cookie, header, path or query')
        // Nothing was dropped, which is what the report says.
        ->and(ignoreParamParameters($result, '/api/ignored/nowhere'))->toContain(['header', 'X-Trace']);
});
