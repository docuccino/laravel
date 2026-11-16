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
use Docuccino\Laravel\Tests\Fixtures\TypedChoices\ChoiceController;
use Docuccino\Laravel\Tests\Fixtures\TypedChoices\StoreChoiceRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Choice lists — `in`, `Rule::in`, `Rule::enum`, `Rule::notIn` — beside a type rule that types the field,
 * locked in emitted bytes. Nothing else in the golden corpus publishes a list whose values read as another
 * type than the one the field declares.
 */
function typedChoicesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-choices', [ChoiceController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [ChoiceController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreChoiceRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreChoiceRequest::class))->getFileName(),
            StoreChoiceRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits typed choice lists byte-identical to their committed goldens', function (): void {
    $result = typedChoicesBuild();

    assertGolden('typed-choices.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('typed-choices.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('typed-choices.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});

/*
 * Each row says whether the document takes the body. It never takes one the server refuses; a row marked
 * `loose` is one the server may take where the document does not — in another type's spelling, as a bare
 * `integer` takes `"3"`, or through Laravel 12's loose comparison. Every other row the server answers alike.
 */
it('takes every value the server takes in the declared type, and refuses the rest', function (array $fields, bool $published, bool $loose): void {
    // The server's answer, by Laravel itself.
    $accepted = Validator::make($fields, (new StoreChoiceRequest)->rules())->passes();

    $document = json_decode((new UirEmitter)->emit(typedChoicesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StoreChoiceRequest';

    expect((new SchemaValidator)->validate(json_decode((string) json_encode($fields), flags: JSON_THROW_ON_ERROR), $document)->isValid())->toBe($published);

    if ($published || ! $loose) {
        expect($accepted)->toBe($published);
    }
})->with([
    // Laravel compares the sent value's string with the list, so a list beside `string` holds strings.
    'a listed code' => [['code' => '1'], true, false],
    'a code sent as a number' => [['code' => 1], false, false],
    'a code not listed' => [['code' => '3'], false, false],
    'a code spelled with a leading zero' => [['code' => '01'], false, true],
    // An entry no integer spells can never pass `integer`, so it is not published.
    'a listed count' => [['count' => 1], true, false],
    'the count no integer is' => [['count' => 'many'], false, false],
    'a count sent as a string' => [['count' => '1'], false, true],
    'a listed ratio' => [['ratio' => 0.5], true, false],
    'a whole ratio' => [['ratio' => 1], true, false],
    'a ratio not listed' => [['ratio' => 2], false, false],
    'a ratio sent as a string' => [['ratio' => '0.5'], false, true],
    // `Rule::in([true, false])` lists `"1"` and `""`: the strings PHP makes of the two booleans.
    'consent given' => [['consent' => true], true, false],
    'consent refused' => [['consent' => false], true, false],
    'consent as a zero' => [['consent' => 0], false, false],
    // `in:0,1` lists no `""`, so `false`, which Laravel compares as `""`, is refused.
    'an opt-in' => [['opt_in' => true], true, false],
    'an opt-out' => [['opt_in' => false], false, false],
    'an opt-out as a zero' => [['opt_in' => 0], false, true],
    // `tryFrom()` reads the string as the case it spells, and `string` refuses the integer itself.
    'a grade as a string' => [['grade' => '2'], true, false],
    'a grade as an integer' => [['grade' => 2], false, false],
    'a grade no case is' => [['grade' => '4'], false, false],
    'a rank' => [['rank' => 2], true, false],
    'a rank sent as a string' => [['rank' => '2'], false, true],
    // An exclusion beside `string` excludes strings.
    'a blocked code' => [['blocked' => '1'], false, false],
    'a code not blocked' => [['blocked' => '3'], true, false],
    // With no type rule, an all-integer list still reads as integers.
    'a listed tier' => [['tier' => 1], true, false],
    'a tier sent as a string' => [['tier' => '1'], false, true],
    // No integer is `a` or `b`, so every value is refused.
    'an integer where none is listed' => [['none' => 1], false, false],
    'a word where no integer is listed' => [['none' => 'a'], false, false],
    // Laravel keeps the empty entry `in:1,` ends with, which is how it compares `false`.
    'either, true' => [['either' => true], true, false],
    'either, false' => [['either' => false], true, false],
    // Laravel reads the list as CSV, so a quoted entry keeps its comma and loses its quotes.
    'a quoted entry' => [['quoted' => 'x,y'], true, false],
    'half of that entry' => [['quoted' => 'x'], false, false],
    'that entry with its quotes' => [['quoted' => '"x'], false, false],
]);
