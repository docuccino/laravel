<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\DeepObjectRequiredController;
use Workbench\App\Http\Requests\FilterNestedRequiredRequest;
use Workbench\App\Http\Requests\FilterRequiredMemberRequest;

/**
 * What a deepObject container publishes about requiredness, through a whole build. A filter has no
 * parameter of its own under this representation, so "the server demands this" lives on the container's
 * `required` list and on the container's own flag — both answered by more than one producer, at more
 * than one layer, a phase apart. Get either wrong and a consumer is told a request the server refuses
 * is valid, or that one it accepts is not. These routes are registered ad-hoc so no committed golden
 * churns.
 */
function deepObjectRequiredDocument(): GenerationResult
{
    $location = new SourceLocation('');
    $controller = DeepObjectRequiredController::class.'::';

    $chain = <<<'PHP'
        QueryBuilder::for(\Workbench\App\Models\Gadget::class)->allowedFilters([
            AllowedFilter::exact('status'),
            AllowedFilter::exact('min_days'),
            AllowedFilter::callback('window', static function (Builder $query, mixed $value): void {
                $query->whereBetween('starts_at', (array) $value);
            }),
        ])->paginate(20)
        PHP;

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [
            // rules() as the engine recovers it — the constant arrays the classes really return.
            FilterRequiredMemberRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('filter.min_days', new LiteralT('required|integer')),
                new ArrayShapeField('filter.status', new LiteralT('string')),
            ]), $location)]),
            FilterNestedRequiredRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('filter.window.from', new LiteralT('required|date')),
                new ArrayShapeField('filter.window.to', new LiteralT('date')),
            ]), $location)]),
        ],
        traceOverrides: [
            $controller.'contested' => TraceScript::forChain($chain),
            $controller.'nested' => TraceScript::forChain($chain),
            $controller.'optional' => TraceScript::forChain($chain),
        ],
    ));

    /** @var Router $router */
    $router = app('router');
    $router->get('api/deep-required/contested', [DeepObjectRequiredController::class, 'contested']);
    $router->get('api/deep-required/nested', [DeepObjectRequiredController::class, 'nested']);
    $router->get('api/deep-required/optional', [DeepObjectRequiredController::class, 'optional']);

    setBuild('documents.default.representation.filters', 'deepObject');

    return generateDocument(static function (array $raw): array {
        $raw['info'] = ['title' => 'Deep required API', 'version' => '1.0.0'];
        $raw['routes'] = ['include' => ['api/deep-required/*']];

        return $raw;
    });
}

/**
 * One route's `filter` container, as the document publishes it.
 *
 * @return array<string, mixed>
 */
function deepObjectRequiredContainer(GenerationResult $result, string $path): array
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

it('lists the members every producer requires, not only the last layer to answer', function (): void {
    $container = deepObjectRequiredContainer(deepObjectRequiredDocument(), '/api/deep-required/contested');

    // The declaration requires `status` in the parameter phase at the attribute layer; the rules
    // require `min_days` a phase later at the integration layer. A list written whole by whichever
    // producer answered last loses the other one, and the document then marks a request omitting a
    // value the server demands as valid.
    expect($container['schema']['required'])->toBe(['status', 'min_days'])
        ->and(array_keys($container['schema']['properties']))->toContain('status', 'min_days')
        ->and($container['required'])->toBeTrue();
});

it('requires the container for a member required below its own members', function (): void {
    $container = deepObjectRequiredContainer(deepObjectRequiredDocument(), '/api/deep-required/nested');

    // Nothing at the top of the container is required, so the requirement is reachable only by
    // descending — and an optional container would say a request carrying no `filter` is valid while
    // the server refuses it for the window it never received.
    expect($container['schema'])->not->toHaveKey('required')
        ->and($container['schema']['properties']['window']['required'])->toBe(['from'])
        ->and($container['required'])->toBeTrue();
});

it('leaves the container optional where the author says so, and keeps the member required', function (): void {
    $container = deepObjectRequiredContainer(deepObjectRequiredDocument(), '/api/deep-required/optional');

    // The declaration outranks the rules, and the two claims are not in conflict: send no filter and
    // the server is content, send one and it must carry `min_days`. Deriving the container's own
    // requiredness over the top of the declaration would take away the override the diagnostics point
    // authors at, and publish it with no record of who decided.
    expect($container['required'])->toBeFalse()
        ->and($container['schema']['required'])->toBe(['min_days']);
});

it('names the producer behind a container the document requires', function (): void {
    $container = deepObjectRequiredContainer(deepObjectRequiredDocument(), '/api/deep-required/nested');

    /** @var list<array<string, mixed>> $records */
    $records = $container['x-docuccino']['provenance'];
    $fields = [];
    foreach ($records as $record) {
        foreach ((array) ($record['fields'] ?? []) as $field) {
            $fields[(string) $field] = (string) $record['producer'];
        }
    }

    // Provenance is compiled, not read: a record naming the producer whose `required: false` this
    // replaced points a reader at a file that says the opposite of the document.
    expect($fields['required'] ?? null)->toBe('integration:form-request');
});

/**
 * The artifact: the document a consumer receives for a contested filter surface, in bytes. A member
 * dropped from the list, a container gone optional, or a requirement that stopped reaching the
 * container each move this file.
 */
it('emits the contested deepObject container byte-identically', function (): void {
    assertGolden('workbench-deep-required.uir.json', (new UirEmitter)->emit(deepObjectRequiredDocument()->document));
});
