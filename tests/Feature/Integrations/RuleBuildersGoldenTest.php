<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\RuleBuilders\OrderController;
use Docuccino\Laravel\Tests\Fixtures\RuleBuilders\StoreOrderRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Rules built with the framework's fluent string, date, number and list builders — `Rule::string()->min(2)` and
 * its siblings, and the objects written with `new` — locked in emitted bytes. No golden held one.
 */
/** An order {@see StoreOrderRequest} takes, with every field its builders bound. */
const BUILT_ORDER = ['name' => 'Ada', 'code' => 'ABC-1', 'delivery' => '2999-01-01', 'quantity' => 3, 'tags' => ['featured']];

function ruleBuildersBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-orders', [OrderController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [OrderController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreOrderRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreOrderRequest::class))->getFileName(),
            StoreOrderRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits rules built with the framework builders byte-identical to their committed golden', function (): void {
    assertGolden('rule-builders.uir.json', (new UirEmitter)->emit(ruleBuildersBuild()->document));
});

/*
 * A built rule is the rule string it casts to, so the document owes what the string publishes: it never
 * refuses a body the server takes, and refuses what a bound on the value alone refuses. A row marked inexact
 * is refused on a fact no schema keyword states — a date relative to today, a member a list must hold — or
 * on a bound that comes from the request, which the document leaves out.
 */
it('takes every order the server takes, and refuses what a bound on the value refuses', function (array $fields, bool $accepted, bool $exact): void {
    $body = [...BUILT_ORDER, ...$fields];

    // The server's answer, by Laravel itself.
    expect(Validator::make($body, (new StoreOrderRequest)->rules())->passes())->toBe($accepted);

    $document = json_decode((new UirEmitter)->emit(ruleBuildersBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StoreOrderRequest';
    $published = (new SchemaValidator)->validate(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    expect($published)->toBe($accepted || ! $exact);
})->with([
    'every field as listed' => [[], true, true],
    'a one-letter name' => [['name' => 'A'], false, true],
    'a name past its maximum' => [['name' => str_repeat('a', 41)], false, true],
    'a name that is a number' => [['name' => 5], false, true],
    'a code with a space' => [['code' => 'AB 1'], false, true],
    'a delivery in another format' => [['delivery' => '01/02/2999'], false, true],
    'a delivery in the past' => [['delivery' => '2000-01-01'], false, false],
    'a quantity of nought' => [['quantity' => 0], false, true],
    'a quantity past its maximum' => [['quantity' => 100], false, true],
    'a fractional quantity' => [['quantity' => 1.5], false, true],
    'tags without the one they must hold' => [['tags' => ['sale']], false, false],
    'labels holding one they must not' => [['labels' => ['spam']], false, false],
    'labels without it' => [['labels' => ['new']], true, true],
    'a note past a bound the request sets' => [['note' => str_repeat('a', 201)], false, false],
]);
