<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\DescribedFilterController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\ListingController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\ListingRequest;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\RangeData;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\RangeDataController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\TypeDescribedListingRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Fields whose validation states a fact no keyword can carry — a date bound, a set of prefixes, a
 * condition that requires or refuses the field — read as query parameters and as a body, from a Form
 * Request and from a Data class, with and without a description their author wrote: on the action, on
 * the request type, on a Data property's docblock or `#[Description]`, and on an enum. Beside them, a
 * Query Builder filter whose list and `null` its schema cannot state. No golden held a field that had
 * both.
 */
function describedRuleNotesBuild(): GenerationResult
{
    bootLaravelData(null);
    // An enum published inline, where its own description and the field's rules share the one field.
    setBuild('documents.default.representation.enums.components', false);

    $string = ScalarT::string();
    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $returns = new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))]);
    $rules = static fn (string $class): Closure => TraceScript::forMethod((string) (new ReflectionClass($class))->getFileName(), $class, 'rules');

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            RangeData::class => new ClassMetadata(RangeData::class, [
                new PropertyMetadata('from', $string),
                new PropertyMetadata('until', UnionT::of([$string, $optional]), summary: 'End of the range.'),
                new PropertyMetadata('kind', UnionT::of([$string, $optional])),
                new PropertyMetadata('nickname', UnionT::of([$string, $optional])),
            ]),
        ],
        analysisOverrides: [
            ListingController::class.'::index' => $returns,
            ListingController::class.'::search' => $returns,
            ListingController::class.'::store' => $returns,
            ListingController::class.'::create' => $returns,
            RangeDataController::class.'::index' => $returns,
            RangeDataController::class.'::store' => $returns,
        ],
        traceOverrides: [
            ListingRequest::class.'::rules' => $rules(ListingRequest::class),
            TypeDescribedListingRequest::class.'::rules' => $rules(TypeDescribedListingRequest::class),
            ...array_map(static fn (string $action): Closure => TraceScript::forMethod(
                (string) (new ReflectionClass(DescribedFilterController::class))->getFileName(),
                DescribedFilterController::class,
                $action,
                receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
            ), [
                DescribedFilterController::class.'::index' => 'index',
                DescribedFilterController::class.'::browse' => 'browse',
            ]),
        ],
    );

    return localityBuild(static function (Router $router): void {
        $router->get('api/zz-listings', [ListingController::class, 'index']);
        $router->get('api/zz-listings/search', [ListingController::class, 'search']);
        $router->post('api/zz-listings', [ListingController::class, 'store']);
        $router->post('api/zz-listings/create', [ListingController::class, 'create']);
        $router->get('api/zz-ranges', [RangeDataController::class, 'index']);
        $router->post('api/zz-ranges', [RangeDataController::class, 'store']);
        $router->get('api/zz-gadgets', [DescribedFilterController::class, 'index']);
        $router->get('api/zz-gadgets/browse', [DescribedFilterController::class, 'browse']);
    }, $engine);
}

it('emits validated fields their author described byte-identical to their committed golden', function (): void {
    assertGolden('described-rule-notes.uir.json', (new UirEmitter)->emit(describedRuleNotesBuild()->document));
});

/**
 * The published description of one field: a query parameter's own, or a body property's, the body
 * followed through its component.
 *
 * @param  array<string, mixed>  $document
 */
function describedRuleNote(array $document, string $path, string $method, string $field): mixed
{
    $operation = $document['paths'][$path][$method];

    if ($method === 'get') {
        return paramsByName($operation)[$field]['description'] ?? null;
    }

    $schema = resolveSchema($document, $operation['requestBody']['content']['application/json']['schema']);

    return $schema['properties'][$field]['description'] ?? null;
}

/*
 * A rule's sentence states what the server enforces, which is true whatever prose the field carries, and
 * a reader cannot see the rules to recover it. So an author's description leads and the sentence stands
 * beneath it, as a paragraph of its own — and the query parameter and the body property a rule set
 * becomes say the same thing, since one set of rules decides both.
 */
it('keeps what the rules enforce beneath the description an author writes', function (string $path, string $method, string $field, string $expected): void {
    expect(describedRuleNote(emittedArray(describedRuleNotesBuild()), $path, $method, $field))->toBe($expected);
})->with([
    'a #[QueryParameter] over a date bound' => ['/api/zz-listings', 'get', 'until', "End of the range.\n\nMust be a date after from."],
    'a #[QueryParameter] over a condition that requires' => ['/api/zz-listings', 'get', 'stage', "The stage to list.\n\nRequired when name is present."],
    'a #[QueryParameter] over a condition that refuses' => ['/api/zz-listings', 'get', 'nickname', "What the lister goes by.\n\nMust not be sent when kind is business."],
    'a #[BodyParameter] on the action over a date bound' => ['/api/zz-listings', 'post', 'until', "End of the range.\n\nMust be a date after from."],
    'a #[BodyParameter] on the action over a set of prefixes' => ['/api/zz-listings', 'post', 'name', "Who to look for.\n\nMust start with one of: ab, cd."],
    'a #[BodyParameter] on the request type' => ['/api/zz-listings/create', 'post', 'until', "End of the range.\n\nMust be a date after from."],
    'an inline enum\'s own description, in the query' => ['/api/zz-listings/search', 'get', 'stage', "How far through review a submission has got.\n\nRequired when name is present."],
    'an inline enum\'s own description, in the body' => ['/api/zz-listings', 'post', 'stage', "How far through review a submission has got.\n\nRequired when name is present."],
    'a Data property docblock, in the query' => ['/api/zz-ranges', 'get', 'until', "End of the range.\n\nMust be a date after from."],
    'a Data property docblock, in the body' => ['/api/zz-ranges', 'post', 'until', "End of the range.\n\nMust be a date after from."],
    'a Data property #[Description], in the query' => ['/api/zz-ranges', 'get', 'nickname', "What the lister goes by.\n\nMust not be sent when kind is business."],
    'a Data property #[Description], in the body' => ['/api/zz-ranges', 'post', 'nickname', "What the lister goes by.\n\nMust not be sent when kind is business."],
    'a #[QueryParameter] over a filter\'s list and null' => ['/api/zz-gadgets', 'get', 'filter[status]', "The state to list.\n\nAccepts a comma-separated list of values (matched as `whereIn`). Accepts `null` to filter for absent values."],
]);

it('publishes the rules\' sentences alone where nobody described the field', function (string $field, string $expected): void {
    $document = emittedArray(describedRuleNotesBuild());

    expect(describedRuleNote($document, '/api/zz-listings/search', 'get', $field))->toBe($expected);
})->with([
    'a date bound' => ['until', 'Must be a date after from.'],
    'a set of prefixes' => ['name', 'Must start with one of: ab, cd.'],
    'a condition that refuses' => ['nickname', 'Must not be sent when kind is business.'],
]);

it('names the author and the rules as joint writers of a described parameter', function (): void {
    $until = paramsByName(emittedArray(describedRuleNotesBuild())['paths']['/api/zz-listings']['get'])['until'];
    $records = array_column($until['x-docuccino']['provenance'], null, 'producer');

    // Each wrote part of what the parameter publishes, and neither displaced the other.
    expect($records['attribute']['fields'])->toBe(['description'])
        ->and($records['attribute'])->not->toHaveKey('overrode')
        ->and($records['integration:form-request']['fields'])->toBe(['description', 'required']);
});
