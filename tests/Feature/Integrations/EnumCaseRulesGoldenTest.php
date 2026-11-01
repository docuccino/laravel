<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\EnumCaseRules\PostController;
use Docuccino\Laravel\Tests\Fixtures\EnumCaseRules\StorePostRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Choice rules naming enum cases, locked in emitted bytes: `Rule::in`, `new In`, `Rule::notIn`, `new NotIn`
 * and an enum rule's `->only()`/`->except()`, over string-backed, int-backed and pure enums. Nothing else in
 * the golden corpus lists a case where a value goes.
 */
function enumCaseRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-posts', [PostController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [PostController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StorePostRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StorePostRequest::class))->getFileName(),
            StorePostRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits choice rules naming enum cases byte-identical to their committed goldens', function (): void {
    $result = enumCaseRulesBuild();

    assertGolden('enum-case-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('enum-case-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});

it('takes every body the server takes, and refuses what it refuses, value for value', function (array $fields, bool $accepted): void {
    $body = ['status' => 'draft', ...$fields];

    // The server's answer, by Laravel itself, which compares a case as the value it casts it to.
    expect(Validator::make($body, (new StorePostRequest)->rules())->passes())->toBe($accepted);

    // The document's, `$ref`s resolved through the document the body schema stands in.
    $document = json_decode((new UirEmitter)->emit(enumCaseRulesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StorePostRequest';
    $published = (new SchemaValidator)->validate(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    expect($published)->toBe($accepted);
})->with([
    'a listed case, by its backing value' => [['status' => 'published'], true],
    'a listed case, by its name' => [['status' => 'Draft'], false],
    'a case left out' => [['status' => 'archived'], false],
    'every case of the enum' => [['state' => 'archived'], true],
    'the excluded case' => [['phase' => 'archived'], false],
    'the excluded case\'s name, which is not excluded' => [['phase' => 'Archived'], true],
    'the excluded case, by the object' => [['stage' => 'archived'], false],
    'an int-backed case' => [['priority' => 3], true],
    'an int-backed case left out' => [['priority' => 2], false],
    'an int-backed case by its name' => [['priority' => 'High'], false],
    'a pure case, by its name' => [['visibility' => 'Public'], true],
    'a pure case left out' => [['visibility' => 'Unlisted'], false],
    'a plain value beside a case' => [['schedule' => 'scheduled'], true],
    'the case beside it' => [['schedule' => 'draft'], true],
    'the case beside it, by its name' => [['schedule' => 'Draft'], false],
    'a case only() keeps' => [['review' => 'published'], true],
    'a case only() leaves out' => [['review' => 'archived'], false],
    'a case except() keeps' => [['urgency' => 2], true],
    'the case except() drops' => [['urgency' => 1], false],
]);
