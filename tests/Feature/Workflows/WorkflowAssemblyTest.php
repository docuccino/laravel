<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\WorkflowController;

/**
 * `#[WorkflowStep]` assembled into the document's `x-docuccino.workflows`, against real builds.
 *
 * The attribute is the whole authoring surface, so nearly all of this is about what the build can say
 * about a declaration that is wrong — a workflow assembled from declarations spread across controllers
 * has no one file to read, and the diagnostics are what stand in for reading it.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->post('api/flows/reserve', [WorkflowController::class, 'reserve']);
    $router->post('api/flows/pay', [WorkflowController::class, 'pay']);
    $router->get('api/flows/mistyped', [WorkflowController::class, 'mistyped']);
    $router->get('api/flows/unshaped', [WorkflowController::class, 'unshaped']);
    $router->get('api/flows/strays', [WorkflowController::class, 'strays']);
    $router->get('api/flows/reads', [WorkflowController::class, 'reads']);
    $router->get('api/flows/spaced', [WorkflowController::class, 'spaced']);
    $router->get('api/flows/twin-one', [WorkflowController::class, 'twinOne']);
    $router->get('api/flows/twin-two', [WorkflowController::class, 'twinTwo']);
    $router->get('api/flows/sends', [WorkflowController::class, 'sends']);
    $router->post('api/dup/one', [WorkflowController::class, 'reserve']);
    $router->post('api/dup/two', [WorkflowController::class, 'reserve']);
    $router->get('api/flows/first', [WorkflowController::class, 'first']);
    $router->get('api/flows/second', [WorkflowController::class, 'second']);
});

/**
 * The workflows the document publishes, keyed by id.
 *
 * @param  list<string>  $routes
 * @return array<string, array<string, mixed>>
 */
function assembledWorkflows(array $routes = ['api/flows/reserve', 'api/flows/pay']): array
{
    setDocuments(['default' => [
        'info' => ['title' => 'Flows API', 'version' => '1.0.0'],
        'routes' => ['include' => $routes],
        'error_responses' => 'none',
    ]]);

    /** @var list<array<string, mixed>> $workflows */
    $workflows = generateDocument()->document->toArray()['x-docuccino']['workflows'] ?? [];

    return array_column($workflows, null, 'id');
}

/**
 * The workflow diagnostics one build raises, by code.
 *
 * @param  list<string>  $routes
 * @return list<string>
 */
function workflowCodes(array $routes): array
{
    setDocuments(['default' => [
        'info' => ['title' => 'Flows API', 'version' => '1.0.0'],
        'routes' => ['include' => $routes],
        'error_responses' => 'none',
    ]]);

    return array_values(array_map(
        static fn (Diagnostic $d): string => $d->code,
        array_filter(
            generateDocument()->diagnostics,
            static fn (Diagnostic $d): bool => str_starts_with($d->code, 'workflow.'),
        ),
    ));
}

it('assembles one workflow from declarations on separate operations', function (): void {
    $checkout = assembledWorkflows()['checkout'];

    expect(array_column($checkout['steps'], 'id'))->toBe(['reserve', 'pay']);
});

it('reads where a parameter travels off the operation that declares it', function (): void {
    // The author names the parameter; the operation says it is a query parameter, and repeating that in
    // the declaration would be a second answer to a question the document has already settled.
    $steps = assembledWorkflows()['checkout']['steps'];

    expect($steps[1]['parameters'])->toBe([[
        'name' => 'hold',
        'in' => 'query',
        'value' => '$steps.reserve.outputs.holdId',
    ]]);
});

it('carries the outputs a later step reads', function (): void {
    expect(assembledWorkflows()['checkout']['steps'][0]['outputs'])->toBe(['holdId' => '$response.body#/id']);
});

/*
 * The check the whole distributed-authoring argument turns on. A workflow output is a promise to a
 * consumer, and a pointer at a member the response schema does not describe is a promise the document
 * cannot keep — nothing else in the build notices it, and an Arazzo runner meets it as a null.
 */
it('reports an output read from a member the response does not document', function (): void {
    expect(workflowCodes(['api/flows/mistyped']))->toBe(['workflow.output-undocumented']);
});

it('says nothing about a response that describes no shape to contradict', function (): void {
    // The degradation that keeps the report honest: an unconstrained response says nothing either way,
    // and a warning there would fire where nothing can be done.
    expect(workflowCodes(['api/flows/unshaped']))->toBe([]);
});

it('says nothing about the output it can resolve', function (): void {
    // The control the two rows above need: the same check, over a pointer the response does document.
    expect(workflowCodes(['api/flows/reserve']))->toBe([]);
});

it('reports a parameter the operation does not declare', function (): void {
    expect(workflowCodes(['api/flows/strays']))->toBe(['workflow.parameter-undeclared']);
});

it('reports an output the step it names does not produce', function (): void {
    expect(workflowCodes(['api/flows/reserve', 'api/flows/pay', 'api/flows/reads']))->toBe(['workflow.output-unresolved']);
});

it('reports two steps claiming one position', function (): void {
    expect(workflowCodes(['api/flows/first', 'api/flows/second']))->toContain('workflow.order-contested');
});

it('reports a configured workflow no operation declares', function (): void {
    setDocuments(['default' => [
        'info' => ['title' => 'Flows API', 'version' => '1.0.0'],
        'routes' => ['include' => ['api/flows/reserve']],
        'error_responses' => 'none',
        'workflows' => ['nobody-declares-this' => ['summary' => 'A workflow that is not there.']],
    ]]);

    $codes = array_map(static fn (Diagnostic $d): string => $d->code, generateDocument()->diagnostics);

    expect($codes)->toContain('workflow.describes-nothing');
});

it('enriches a declared workflow from the configuration', function (): void {
    setDocuments(['default' => [
        'info' => ['title' => 'Flows API', 'version' => '1.0.0'],
        'routes' => ['include' => ['api/flows/reserve', 'api/flows/pay']],
        'error_responses' => 'none',
        'workflows' => ['checkout' => [
            'summary' => 'Take payment for a basket',
            'inputs' => ['type' => 'object', 'properties' => ['basketId' => ['type' => 'string']]],
        ]],
    ]]);

    /** @var list<array<string, mixed>> $workflows */
    $workflows = generateDocument()->document->toArray()['x-docuccino']['workflows'];

    expect($workflows[0]['summary'])->toBe('Take payment for a basket')
        ->and($workflows[0]['inputs'])->toBe(['type' => 'object', 'properties' => ['basketId' => ['type' => 'string']]]);
});

it('says nothing about the configuration a workflow author writes', function (): void {
    // A whole build, because this is the population no golden can stand in: the committed goldens
    // carry no diagnostics at all, so the channel this moves is invisible to every one of them.
    //
    // The ID is the application's — `checkout` is only what the shipped file shows one looking like —
    // and `inputs` is a JSON Schema, so its keywords are the spec's vocabulary. Both used to be
    // reported as naming no setting Docuccino reads while the build read them and published them,
    // which is the report contradicting the document beside it.
    setDocuments(['default' => [
        'info' => ['title' => 'Flows API', 'version' => '1.0.0'],
        'routes' => ['include' => ['api/flows/reserve', 'api/flows/pay']],
        'error_responses' => 'none',
        'workflows' => [
            'checkout' => [
                'summary' => 'Take payment for a basket',
                'inputs' => [
                    'type' => 'object',
                    'properties' => ['basketId' => ['type' => 'string']],
                    'required' => ['basketId'],
                ],
            ],
            'placeOrder' => ['description' => 'An ID no operation declares is its own report.'],
        ],
    ]]);

    // Through DocumentBuilder rather than generateDocument(): the configuration report is a pass of
    // the BUILD, so a document generated straight from a resolved config carries none of it and an
    // assertion made there would be silent whatever the lists said.
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());
    $codes = array_map(static fn (Diagnostic $d): string => $d->code, $result->diagnostics);

    /** @var list<array<string, mixed>> $workflows */
    $workflows = $result->document->toArray()['x-docuccino']['workflows'];

    expect($codes)->not->toContain('config.unknown-setting')
        ->and($codes)->not->toContain('config.value-type')
        // The other half of why that report was false: the settings under the ID really are read.
        ->and($workflows[0]['summary'])->toBe('Take payment for a basket')
        ->and($workflows[0]['inputs']['required'])->toBe(['basketId'])
        // …and the unclaimed ID keeps the report that IS about it, so the silence above is this
        // report standing down on the KEY rather than on the mistake.
        ->and($codes)->toContain('workflow.describes-nothing');
});

it('needs no configuration to publish a workflow', function (): void {
    // The rule the whole shape follows: a correct document with no configuration is the product, and
    // config only ever enriches what the attributes already declared.
    expect(assembledWorkflows()['checkout'])->not->toHaveKey('summary');
});

it('reports a workflow whose name Arazzo cannot carry', function (): void {
    expect(workflowCodes(['api/flows/spaced']))->toBe(['workflow.name-unusable'])
        ->and(assembledWorkflows(['api/flows/spaced']))->toBe([]);
});

it('reports two steps of one workflow sharing a step id', function (): void {
    // Two steps calling one operation mint the same id by default, which is when this happens for real.
    expect(workflowCodes(['api/flows/twin-one', 'api/flows/twin-two']))->toBe(['workflow.step-id-repeated']);
});

/*
 * One declaration reached by two routes is ONE declaration. Before this, an action bound twice produced
 * "two steps are both called reserve" and "2 steps both declare order 1" from a single attribute —
 * reports whose remedy is to edit a second declaration that does not exist. The repo's own golden had
 * absorbed exactly that.
 */
it('collapses a declaration several routes reached into one step', function (): void {
    $checkout = assembledWorkflows(['api/dup/*'])['checkout'];

    expect(array_column($checkout['steps'], 'id'))->toBe(['reserve'])
        ->and(workflowCodes(['api/dup/*']))->toBe([]);
});

it('still reports two different declarations that collide', function (): void {
    // The control the row above needs: collapsing identical declarations must not quiet a real mistake.
    expect(workflowCodes(['api/flows/twin-one', 'api/flows/twin-two']))->toBe(['workflow.step-id-repeated']);
});

/*
 * A workflow whose steps live in documents that do not overlap. `pay` reads an output of `reserve`, and
 * a document that publishes only `pay` cannot see it — which is the normal multi-document case, not a
 * mistake, and a report there fires on every build of every application that splits its routes.
 */
it('says nothing about a reference to a step this document does not publish', function (): void {
    expect(workflowCodes(['api/flows/pay']))->toBe([]);
});

it('still reports a reference to a step it does publish', function (): void {
    // The control: the same shape, in a document that HAS the step being read, with the output name
    // misspelled. Without it, the silence above is indistinguishable from deleting the check.
    expect(workflowCodes(['api/flows/reserve', 'api/flows/pay', 'api/flows/reads']))->toBe(['workflow.output-unresolved']);
});

/*
 * A declaration JSON cannot carry. The step is left out — there is nothing to publish — but a workflow
 * that quietly published a shorter sequence would be telling a consumer that these calls get them
 * there while omitting one, so the build says which declaration it could not read.
 */
it('reports a step whose declaration could not be carried', function (): void {
    expect(workflowCodes(['api/flows/sends']))->toBe(['workflow.step-unreadable'])
        ->and(assembledWorkflows(['api/flows/sends']))->toBe([]);
});
