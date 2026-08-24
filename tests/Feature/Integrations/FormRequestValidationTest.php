<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
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
use Workbench\App\Http\Controllers\ValidationController;
use Workbench\App\Http\Requests\StoreWidgetRequest;

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

it('does not add a request body to a route with no recoverable rules', function (): void {
    app('router')->get('api/plain', static fn () => response()->json(['ok' => true]));

    $document = generateWith(stubRulesEngine());

    expect($document['paths']['/api/plain']['get'] ?? [])->not->toHaveKey('requestBody');
});
