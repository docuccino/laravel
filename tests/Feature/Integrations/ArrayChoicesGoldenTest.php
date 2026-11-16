<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ArrayChoices\ChoiceListController;
use Docuccino\Laravel\Tests\Fixtures\ArrayChoices\StoreChoiceListRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Choice lists — `in`, `Rule::in`, `not_in`, `Rule::notIn` — beside an `array` rule, locked in emitted bytes.
 * Nothing else in the golden corpus publishes a choice list on a field that is an array.
 */
function arrayChoicesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-choice-lists', [ChoiceListController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [ChoiceListController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreChoiceListRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreChoiceListRequest::class))->getFileName(),
            StoreChoiceListRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits choice lists beside an array rule byte-identical to their committed goldens', function (): void {
    $result = arrayChoicesBuild();

    assertGolden('array-choices.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('array-choices.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('array-choices.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});

/*
 * Each row says whether the server takes the body, by Laravel itself, and whether the document does. Beside
 * `array`, Laravel compares each element with the list, so the document lists the elements; the two differ
 * only where a row's comment says why.
 */
it('takes every list whose elements the server takes, and refuses the rest', function (array $fields, bool $accepted, bool $published): void {
    expect(Validator::make($fields, (new StoreChoiceListRequest)->rules())->passes())->toBe($accepted);

    $document = json_decode((new UirEmitter)->emit(arrayChoicesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StoreChoiceListRequest';

    expect((new SchemaValidator)->validate(json_decode((string) json_encode($fields), flags: JSON_THROW_ON_ERROR), $document)->isValid())->toBe($published);
})->with([
    'listed tags' => [['tags' => ['news', 'sport']], true, true],
    'no tags' => [['tags' => []], true, true],
    'a tag not listed' => [['tags' => ['news', 'weather']], false, false],
    'a tag that is a list' => [['tags' => [['news']]], false, false],
    'one tag, not in a list' => [['tags' => 'news'], false, false],
    // `array` takes an object, whose values Laravel compares; the document says nothing of them.
    'tags keyed by name' => [['tags' => ['first' => 'weather']], false, true],
    // The item rule types the list: `string` refuses the number Laravel would compare as "1".
    'listed codes' => [['codes' => ['1', '2']], true, true],
    'a code sent as a number' => [['codes' => [1]], false, false],
    'a code repeated' => [['codes' => ['1', '1']], false, false],
    // Every case listed, so the items are the enum's own component.
    'listed grades' => [['grades' => [1, 3]], true, true],
    'a grade no case is' => [['grades' => [4]], false, false],
    // `not_in` beside `array` refuses a list whose every element it lists, and no other.
    'a muted word among others' => [['muted' => ['spam', 'politics']], true, true],
    'only muted words' => [['muted' => ['spam', 'ads']], false, false],
    'nothing muted, which every listed element is' => [['muted' => []], false, false],
    'muted null' => [['muted' => null], true, true],
    'only excluded ids' => [['ids' => [1, 2]], false, false],
    'an id not excluded' => [['ids' => [1, 3]], true, true],
    // `list` is not `array`, so `in` compares the value itself, which no list is.
    'a listed list' => [['listed' => ['a']], false, false],
]);
