<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Exchange;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Workbench\App\Http\Controllers\ValidationController;
use Workbench\App\Http\Requests\StoreWidgetRequest;

/**
 * The coercion that reads a query string back as the type the contract documents, proved against a
 * document this product BUILT rather than one written to suit the assertion.
 *
 * `representation.nullable = 'anyof'` is a shipped option, and under it a nullable validated field
 * reaches the parameter as `anyOf: [{type: integer}, {type: null}]` — the type is in the document and
 * nowhere on the node a reader lands on. A reader that saw only a literal `type` handed the wire
 * string to the validator, and every request to every such endpoint failed on its own page size.
 */
it('reads a query value back as the type a generated document wrote inside an anyOf', function (): void {
    app('router')->get('api/coerced-widgets', [ValidationController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
        StoreWidgetRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite(
            new ArrayShapeT([new ArrayShapeField('per_page', new LiteralT('nullable|integer|min:1'))]),
            new SourceLocation(''),
        )]),
    ]));

    $json = (new UirEmitter)->emit(generateDocument(static function (array $raw): array {
        $representation = is_array($raw['representation'] ?? null) ? $raw['representation'] : [];
        $representation['nullable'] = 'anyof';
        $raw['representation'] = $representation;

        return $raw;
    })->document);

    /** @var array<string, mixed> $document */
    $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $parameter = $document['paths']['/api/coerced-widgets']['get']['parameters'][0];

    // The premise, pinned: the generator really does put the type inside the anyOf under this policy.
    // Without this the two checks below would pass over a schema that never had the shape at issue.
    expect($parameter['name'])->toBe('per_page')
        ->and($parameter['schema'])->toHaveKey('anyOf')
        ->and($parameter['schema'])->not->toHaveKey('type');

    $index = ContractIndex::fromJson($json);
    $checker = new ContractChecker($index);
    $operation = $index->match('GET', '/api/coerced-widgets');

    $sent = $checker->request($operation, new Exchange('GET', '/api/coerced-widgets', 200, query: ['per_page' => '1000']));
    $nonsense = $checker->request($operation, new Exchange('GET', '/api/coerced-widgets', 200, query: ['per_page' => 'abc']));

    expect($sent->violations)->toBe([])
        // Widening what the reader understands must not widen what passes: `abc` is still the type
        // problem it is, rather than the integer zero the naive conversion would have made of it.
        ->and(array_map(static fn ($violation): string => $violation->message, $nonsense->violations))
        ->toContain('The data (string) must match the type: integer');
});
