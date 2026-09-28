<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\PublishArticleAction;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\InheritedMergeRulesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MergedRulesController;
use Docuccino\Laravel\Tests\Support\RulesTraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ValidatedQueryController;
use Workbench\App\Http\Requests\ListWidgetsRequest;

/**
 * A read verb validates its query the way a write verb validates its body: the rules become query
 * parameters instead of a body, and a value they refuse is a 422 either way. So the error surface of a
 * validated read owes the 422 as surely as a write's does — and a parameter only DECLARED, which no
 * validator stands behind, owes none.
 */
function validatedQueryDocument(): GenerationResult
{
    return localityBuild(validatedQueryRoutes(), validatedQueryEngine());
}

/** @return callable(Router): void */
function validatedQueryRoutes(): callable
{
    return static function (Router $router): void {
        $router->get('api/validated-query/form-request', [ValidatedQueryController::class, 'formRequest']);
        $router->get('api/validated-query/inline', [ValidatedQueryController::class, 'inline']);
        $router->get('api/validated-query/validator', [ValidatedQueryController::class, 'validator']);
        $router->get('api/validated-query/declared', [ValidatedQueryController::class, 'declared']);
        // The other two recoverers, a Data object and an action's own rules(), on the same verb.
        $router->get('api/validated-query/data', [MergedRulesController::class, 'store']);
        $router->get('api/validated-query/action', PublishArticleAction::class);
        // The same FormRequest on a write verb: the 422 the read routes publish is this one.
        $router->post('api/validated-query/form-request', [ValidatedQueryController::class, 'formRequest']);
    };
}

/** @return callable(): TypeEngine */
function validatedQueryEngine(): callable
{
    $rule = <<<'PHP'
        ['sometimes', \Illuminate\Validation\Rule::enum(\Workbench\App\Enums\WidgetStatus::class)]
        PHP;
    $controller = ValidatedQueryController::class.'::';
    // Every action answers the same success body, so each operation has a response surface of its own
    // and the declared route's lack of a 422 is not the lack of any response at all.
    $success = new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]), new SourceLocation(''))]);
    $analyses = [];
    foreach (['formRequest', 'inline', 'validator', 'declared'] as $method) {
        $analyses[$controller.$method] = $success;
    }
    $analyses[PublishArticleAction::class.'::handle'] = $success;
    // An action's rules() as the engine recovers it — the constant array the class really returns.
    $analyses[PublishArticleAction::class.'::rules'] = new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
        new ArrayShapeField('title', new LiteralT('required|string|max:100')),
        new ArrayShapeField('body', new LiteralT('required|string')),
    ]), new SourceLocation(''))]);

    return static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [InheritedMergeRulesData::class => new ClassMetadata(InheritedMergeRulesData::class, [new PropertyMetadata('name', ScalarT::string())])],
        analysisOverrides: $analyses,
        traceOverrides: [
            ListWidgetsRequest::class.'::rules' => RulesTraceScript::forPhp("return ['status' => {$rule}];"),
            $controller.'inline' => RulesTraceScript::forPhp("\$request->validate(['status' => {$rule}]);"),
            $controller.'validator' => RulesTraceScript::forPhp("Validator::make(\$request->query(), ['status' => {$rule}])->validate();"),
        ],
    );
}

/**
 * One operation of the validated-query document.
 *
 * @return array<string, mixed>
 */
function validatedQueryOperation(GenerationResult $result, string $verb, string $action): array
{
    /** @var array<string, mixed> $operation */
    $operation = $result->document->toArray()['paths']['/api/validated-query/'.$action][$verb] ?? [];

    return $operation;
}

it('publishes a 422 on a read route whose query is validated, whichever way it is validated', function (string $action, string $parameter): void {
    $operation = validatedQueryOperation(validatedQueryDocument(), 'get', $action);

    // The parameter is the premise: the rules really did reach the query. Without it the 422 below
    // would be asserted of an operation that validates nothing the document shows.
    expect(collect($operation['parameters'] ?? [])->firstWhere('name', $parameter)['in'] ?? null)->toBe('query')
        ->and($operation['responses'] ?? [])->toHaveKey('422');
})->with([
    'a FormRequest' => ['form-request', 'status'],
    'an inline validate()' => ['inline', 'status'],
    'Validator::make()->validate()' => ['validator', 'status'],
    'a Data object' => ['data', 'name'],
    'an action rules()' => ['action', 'title'],
]);

it('publishes the enum the rule enforces beside the 422 an out-of-enum value earns', function (): void {
    $operation = validatedQueryOperation(validatedQueryDocument(), 'get', 'form-request');

    $status = collect($operation['parameters'] ?? [])->firstWhere('name', 'status');
    expect(json_encode($status['schema'] ?? null))->toContain('WidgetStatus')
        ->and($operation['responses'] ?? [])->toHaveKey('422');
});

it('publishes the same 422 a write route validating the same rules gets', function (): void {
    $result = validatedQueryDocument();

    $strip = static function (mixed $node) use (&$strip): mixed {
        if (! is_array($node)) {
            return $node;
        }
        unset($node['x-docuccino']);

        return array_map($strip, $node);
    };

    $read = validatedQueryOperation($result, 'get', 'form-request')['responses']['422'] ?? null;
    $write = validatedQueryOperation($result, 'post', 'form-request')['responses']['422'] ?? null;

    // Floor: the write route has its 422, so the equality below is not two absences agreeing.
    expect($write)->toBeArray()
        ->and($strip($read))->toBe($strip($write));
});

it('publishes no 422 for a query parameter that is only declared', function (): void {
    $operation = validatedQueryOperation(validatedQueryDocument(), 'get', 'declared');

    // The declaration is published — the route has a query key — and still nothing validates it.
    expect(collect($operation['parameters'] ?? [])->pluck('name')->all())->toContain('status')
        ->and(array_map(strval(...), array_keys($operation['responses'] ?? [])))->toBe(['200']);
});

it('publishes the same 422s from a warm build as from a cold one', function (): void {
    // The 422 is decided a phase after the rules are recovered, on the same draft; a warm hit restores
    // the whole operation, so the cached fragment has to carry it.
    $warm = assertWarmEqualsCold(validatedQueryRoutes(), validatedQueryRoutes(), validatedQueryEngine());

    expect(validatedQueryOperation($warm, 'get', 'form-request')['responses'] ?? [])->toHaveKey('422');
});

/**
 * The artifact: validated reads beside a declared one and the write twin, in bytes. A validated read
 * losing its 422, or a declared one gaining it, moves this file.
 */
it('emits the validated-query document byte-identically', function (): void {
    assertGolden('workbench-validated-query.uir.json', (new UirEmitter)->emit(validatedQueryDocument()->document));
});
