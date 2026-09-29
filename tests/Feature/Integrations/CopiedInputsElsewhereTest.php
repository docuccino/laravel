<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Support\LaravelActionHooks;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\InlineCopyController;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\HookReadsAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\RecordNoteAction;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * A key merged from a header before validation validates the header, wherever the merge is written: in a
 * laravel-actions action's `prepareForValidation()`, or in a controller action's body ahead of an inline
 * `validate()`. The stub engine scripts each trace over the real bodies, and the routes are registered ad-hoc
 * so no committed golden churns.
 *
 * @return array<string, mixed>
 */
function copiedElsewhereDocument(): array
{
    $action = (string) (new ReflectionClass(RecordNoteAction::class))->getFileName();
    $hooks = (string) (new ReflectionClass(HookReadsAction::class))->getFileName();
    $controller = (string) (new ReflectionClass(InlineCopyController::class))->getFileName();
    $actionRequest = ['request' => new ClassT(LaravelActionHooks::ACTION_REQUEST)];

    $traces = [
        RecordNoteAction::class.'::asController' => TraceScript::forMethod($action, RecordNoteAction::class, 'asController', $actionRequest),
        RecordNoteAction::class.'::prepareForValidation' => TraceScript::forMethod($action, RecordNoteAction::class, 'prepareForValidation', $actionRequest),
        InlineCopyController::class.'::store' => TraceScript::forMethod($controller, InlineCopyController::class, 'store', ['request' => new ClassT('Illuminate\\Http\\Request')]),
    ];
    foreach ([...LaravelActionHooks::HOOKS, 'unhooked'] as $hook) {
        $traces[HookReadsAction::class.'::'.$hook] = TraceScript::forMethod($hooks, HookReadsAction::class, $hook, $actionRequest);
    }

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [
            RecordNoteAction::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('key', new LiteralT('required|uuid')),
                new ArrayShapeField('title', new LiteralT('required|string')),
            ]), new SourceLocation(''))]),
        ],
        traceOverrides: $traces,
    ));

    /** @var Router $router */
    $router = app('router');
    $router->post('api/action-notes', RecordNoteAction::class);
    $router->post('api/inline-notes', [InlineCopyController::class, 'store']);
    $router->post('api/hooked', HookReadsAction::class);
    $router->post('api/hooked/explicit', [HookReadsAction::class, 'store']);

    return json_decode(json_encode(generateDocument()->document->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * One operation's parameters by location and name, and its request body schema, without provenance.
 *
 * @param  array<string, mixed>  $document
 * @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>}
 */
function copiedElsewhereOperation(array $document, string $path): array
{
    $strip = static function (mixed $value) use (&$strip): mixed {
        if (! is_array($value)) {
            return $value;
        }
        unset($value['x-docuccino']);

        return array_map($strip, $value);
    };

    $operation = $strip($document['paths'][$path]['post']);
    $parameters = [];
    foreach ($operation['parameters'] ?? [] as $parameter) {
        $parameters[$parameter['in'].':'.$parameter['name']] = $parameter;
    }

    $body = $operation['requestBody']['content']['application/json']['schema'] ?? [];
    $ref = $body['$ref'] ?? null;
    if (is_string($ref)) {
        $body = $strip($document['components']['schemas'][substr($ref, strlen('#/components/schemas/'))]);
    }

    return [$parameters, $body];
}

it("publishes the rules for a header an action's hook copies on the header, and drops the body field", function (): void {
    [$parameters, $body] = copiedElsewhereOperation(copiedElsewhereDocument(), '/api/action-notes');

    // The package validates the request it handed the hook, so `merge()` wrote the header over whatever the
    // body sent, and an absent header is a present null that `uuid` fails: required, and a UUID.
    expect($parameters)->toBe([
        'header:Idempotency-Key' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid', 'example' => '3fa85f64-5717-4562-b3fc-2c963f66afa6']],
    ])->and(array_keys($body['properties']))->toBe(['title'])
        ->and($body['required'])->toBe(['title']);
});

it('publishes the rules for a header merged before an inline validate() on the header, and drops the body field', function (): void {
    [$parameters, $body] = copiedElsewhereOperation(copiedElsewhereDocument(), '/api/inline-notes');

    // `validate()` validates the input as it stands at the call, which the merge above it already wrote.
    expect($parameters['header:Idempotency-Key'])->toBe(['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid', 'example' => '3fa85f64-5717-4562-b3fc-2c963f66afa6']])
        ->and($parameters['query:page']['required'])->toBeFalse()
        ->and($parameters['query:page']['schema']['type'])->toBe('integer')
        ->and(array_keys($body['properties']))->toBe(['title']);
});

it('publishes a header read in every method laravel-actions calls on the action while it validates, bar the gate', function (): void {
    [$parameters] = copiedElsewhereOperation(copiedElsewhereDocument(), '/api/hooked');

    // The gate's header decides a 403, as a FormRequest's `authorize()` does, and a method the package never
    // calls is not read on any request.
    $expected = [];
    foreach (array_diff(LaravelActionHooks::HOOKS, ['authorize']) as $hook) {
        $expected[] = 'header:X-Hook-'.$hook;
    }
    sort($expected, SORT_STRING);
    $published = array_keys($parameters);
    sort($published, SORT_STRING);

    expect(count($expected))->toBeGreaterThan(10)
        ->and($published)->toBe($expected);
});

it('publishes no header a hook reads where the package does not validate for the dispatch', function (): void {
    // An explicitly-registered method never validates, so no hook runs for it.
    [$parameters] = copiedElsewhereOperation(copiedElsewhereDocument(), '/api/hooked/explicit');

    expect($parameters)->toBe([]);
});
