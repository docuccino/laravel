<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Workbench\App\Http\Controllers\AnnotatedValidationController;
use Workbench\App\Http\Controllers\NestedBodyParameterController;
use Workbench\App\Http\Controllers\ValidationController;
use Workbench\App\Http\Requests\StoreWidgetRequest;
use Workbench\App\Http\Requests\UpdateScoringRequest;

/**
 * Builds a stub engine that returns the FormRequest's `rules()` as a constant array shape — the same
 * shape the real PHPStan engine recovers by analysing the literal `rules()` array (see the fixture
 * integration test for the real-engine path).
 */
function stubRulesEngine(): StubTypeEngine
{
    $shape = new ArrayShapeT([
        new ArrayShapeField('name', new LiteralT('required|string|max:100')),
        new ArrayShapeField('quantity', new LiteralT('required|integer|min:1')),
        new ArrayShapeField('avatar', new LiteralT('nullable|image')),
        new ArrayShapeField('role', new LiteralT('required|in:admin,user')),
    ]);

    return new StubTypeEngine(analyses: [
        StoreWidgetRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite($shape, new SourceLocation(''))]),
    ]);
}

/**
 * The nested-key FormRequest's `rules()` as the engine recovers it: dotted keys, and a parent whose bare
 * `array` rule leaves its container open.
 */
function stubScoringRulesEngine(): StubTypeEngine
{
    $shape = new ArrayShapeT([
        new ArrayShapeField('is_required', new LiteralT('sometimes|boolean')),
        new ArrayShapeField('meta', new LiteralT('sometimes|nullable|array')),
        new ArrayShapeField('meta.validation_overrides', new LiteralT('sometimes|nullable|array')),
        new ArrayShapeField('meta.scoring.scores', new LiteralT('sometimes|array')),
    ]);

    return new StubTypeEngine(analyses: [
        UpdateScoringRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite($shape, new SourceLocation(''))]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function generateWith(TypeEngine $engine): array
{
    $raw = config('docuccino.documents.default');
    $config = app(DocumentConfigFactory::class)->make('default', is_array($raw) ? $raw : [], 'skeleton');

    return app(DocumentGenerator::class)->generate($config, $engine)->document->toArray();
}

it('documents a FormRequest as a request body recovered from rules()', function (): void {
    app('router')->post('api/validated-widgets', [ValidationController::class, 'store']);

    $document = generateWith(stubRulesEngine());
    $body = $document['paths']['/api/validated-widgets']['post']['requestBody'];

    expect($body['required'])->toBeTrue()
        ->and($body['content'])->toHaveKey('multipart/form-data');

    // The FormRequest-derived body hoists to a component the operation $refs (single source class).
    expect($body['content']['multipart/form-data']['schema'])->toBe(['$ref' => '#/components/schemas/StoreWidgetRequest']);

    $schema = $document['components']['schemas']['StoreWidgetRequest'];

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['name', 'quantity', 'role'])
        ->and($schema['properties']['name'])->toBe(['type' => 'string', 'maxLength' => 100, 'example' => 'example'])
        ->and($schema['properties']['quantity'])->toBe(['type' => 'integer', 'minimum' => 1, 'example' => 1])
        // An upload gets no example: bytes are not an illustration.
        ->and($schema['properties']['avatar'])->toBe(['type' => ['string', 'null'], 'format' => 'binary', 'description' => 'An image file.'])
        ->and($schema['properties']['role'])->toBe(['type' => 'string', 'enum' => ['admin', 'user'], 'example' => 'admin']);
});

/**
 * The end of the chain: a synthesized example is only worth anything if the document a client
 * validates against agrees with it. This audits the whole document through the same check an authored
 * example goes through, and pins that the walk actually found some — a scan matching nothing would
 * otherwise pass forever.
 */
it('publishes request-body examples the document itself validates', function (): void {
    app('router')->post('api/validated-widgets', [ValidationController::class, 'store']);

    $document = generateWith(stubRulesEngine());
    $properties = $document['components']['schemas']['StoreWidgetRequest']['properties'];

    $examples = array_filter($properties, static fn (array $property): bool => array_key_exists('example', $property));

    expect(array_keys($examples))->toBe(['name', 'quantity', 'role']);

    $report = (new ExampleAudit(ContractIndex::fromArray($document)))->run();

    expect($report->checked)->toBeGreaterThanOrEqual(count($examples))
        ->and($report->findings)->toBe([]);
});

/**
 * `requestBody` is one guarded field every producer writes whole, so a `#[BodyParameter]` can only
 * patch what it can already read — which makes the attribute extension's position behind the
 * recoverers load-bearing rather than incidental. Ahead of them it wrote a one-property body that won
 * the field at layer 40 and shadowed the recovered one, which also took the 422 with it: the implicit
 * 422 asks who produced the body.
 */
it('patches a recovered FormRequest body with one #[BodyParameter] and keeps its 422', function (): void {
    app('router')->post('api/annotated-widgets', [AnnotatedValidationController::class, 'storeAnnotated']);

    $document = generateWith(stubRulesEngine());
    $operation = $document['paths']['/api/annotated-widgets']['post'];
    $schema = $operation['requestBody']['content']['multipart/form-data']['schema'];

    // Every recovered property survives, and the attribute's own is added beside them.
    expect(array_keys($schema['properties']))->toBe(['name', 'quantity', 'avatar', 'role', 'note'])
        ->and($schema['properties']['name'])->toBe(['type' => 'string', 'maxLength' => 100, 'example' => 'example'])
        ->and($schema['properties']['note'])->toBe(['type' => 'string', 'description' => 'A free-text note.'])
        ->and($schema['required'])->toBe(['name', 'quantity', 'role'])
        ->and($operation['requestBody']['required'])->toBeTrue();

    // The attribute wins the field, so the recovered body is patched rather than hoisted to a $ref.
    expect($operation['requestBody']['content']['multipart/form-data']['schema'])->not->toHaveKey('$ref');

    // The route still validates, so it still answers 422.
    expect($operation['responses'])->toHaveKey(422);
});

/**
 * The trail, not the winner, is what says a body was recovered — and the trail is what carries the
 * attribute's own contribution too, so `--provenance=full` names both halves of the merge.
 */
it('records both halves of a patched body in provenance', function (): void {
    app('router')->post('api/annotated-widgets', [AnnotatedValidationController::class, 'storeAnnotated']);

    $document = generateWith(stubRulesEngine());
    $records = $document['paths']['/api/annotated-widgets']['post']['x-docuccino']['provenance'];

    $body = array_values(array_filter(
        $records,
        static fn (array $record): bool => in_array('requestBody', $record['fields'], true),
    ));

    expect($body)->toHaveCount(1)
        ->and($body[0]['producer'])->toBe('attribute')
        ->and(array_column($body[0]['overrode'], 'producer'))->toBe(['integration:form-request']);
});

it('does not add a request body to a route with no recoverable rules', function (): void {
    app('router')->get('api/plain', static fn () => response()->json(['ok' => true]));

    $document = generateWith(stubRulesEngine());

    expect($document['paths']['/api/plain']['get'] ?? [])->not->toHaveKey('requestBody');
});

/**
 * The shape a nested body key actually arrives in: dots are the only vocabulary the rules have, so a
 * key that needs declaring is named with them, and the parent above it is a bare `array` rule whose
 * container nothing decided. The whole point is that the declaration reaches the key it names — a
 * literal top-level `meta.scoring.scores` beside `meta` describes a key the endpoint does not accept,
 * and a declaration that quietly reaches nothing is the same defect with no evidence left behind.
 */
it('reaches the nested key a dotted #[BodyParameter] names, and settles the container above it', function (): void {
    app('router')->post('api/scoring', [NestedBodyParameterController::class, 'update']);

    $document = generateWith(stubScoringRulesEngine());
    $schema = $document['paths']['/api/scoring']['post']['requestBody']['content']['application/json']['schema'];

    // Nothing new at the top level: the dotted name is a path, not a property name.
    expect(array_keys($schema['properties']))->toBe(['is_required', 'meta']);

    expect($schema['properties']['meta']['properties']['scoring'])->toBe([
        'type' => 'object',
        'properties' => ['scores' => [
            'type' => 'object',
            'additionalProperties' => [],
            'description' => 'Scores keyed by criterion id.',
        ]],
    ]);

    // `meta` is a nullable field the rules read as an object (it has a named child key). Documenting a
    // key deeper inside it does not stop the server taking a null.
    expect($schema['properties']['meta']['type'])->toBe(['object', 'null']);
});

/**
 * The diagnostic half of the same build. A declaration answers the container question the rules left
 * open, so the note stops firing for that field — and keeps firing for the sibling nobody answered,
 * which is what says the suppression is scoped to what was actually declared.
 */
it('stops asking about the container a declaration answered, and keeps asking about the one it did not', function (): void {
    app('router')->post('api/scoring', [NestedBodyParameterController::class, 'update']);

    $raw = config('docuccino.documents.default');
    $config = app(DocumentConfigFactory::class)->make('default', is_array($raw) ? $raw : [], 'skeleton');
    $result = app(DocumentGenerator::class)->generate($config, stubScoringRulesEngine());

    $undecided = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'validation.container-undecided',
    ));

    expect($undecided)->toHaveCount(1)
        ->and($undecided[0]->message)->toContain('"meta.validation_overrides"');
});
