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
use Docuccino\Laravel\Tests\Fixtures\ExclusionRules\AccountController;
use Docuccino\Laravel\Tests\Fixtures\ExclusionRules\StoreAccountRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Rules that only exclude values — `not_in`, `different`, `not_regex`, `doesnt_start_with`,
 * `doesnt_end_with`, the prohibitions — locked in emitted bytes, on fields no type rule types and beside
 * ones that do. Nothing else in the golden corpus publishes an exclusion on an untyped field.
 */
function exclusionRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-accounts', [AccountController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [AccountController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreAccountRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreAccountRequest::class))->getFileName(),
            StoreAccountRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits exclusion-only rules byte-identical to their committed goldens', function (): void {
    $result = exclusionRulesBuild();

    assertGolden('exclusion-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('exclusion-rules.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('exclusion-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});

it('takes every body the server takes, and refuses the values it excludes', function (array $fields, bool $accepted, bool $exact): void {
    $body = ['username' => 'ada', ...$fields];

    // The server's answer, by Laravel itself.
    expect(Validator::make($body, (new StoreAccountRequest)->rules())->passes())->toBe($accepted);

    $document = json_decode((new UirEmitter)->emit(exclusionRulesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StoreAccountRequest';
    $published = (new SchemaValidator)->validate(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    // Never narrower than the server; as narrow wherever a keyword can say so.
    expect($published)->toBe($accepted || ! $exact);
})->with([
    // `not_in` only excludes: a value of any other type is one the server takes.
    'a role not_in excludes' => [['role' => 'admin'], false, true],
    'a role it does not' => [['role' => 'support'], true, true],
    'a numeric role' => [['role' => 5], true, true],
    'a role that is a list' => [['role' => ['support']], true, true],
    'a tier Rule::notIn excludes' => [['tier' => 1], false, true],
    'a tier it does not' => [['tier' => 3], true, true],
    'a tier that is a word' => [['tier' => 'gold'], true, true],
    // Laravel compares as strings; no keyword beside a bare exclusion says so, and the document is wider.
    'an excluded tier, as a string' => [['tier' => '1'], false, false],
    // Where a type rule stands beside it, the type is still published.
    'a numeric handle' => [['handle' => 5], false, true],
    'a handle Rule::notIn excludes' => [['handle' => 'root'], false, true],
    'a level that is a word' => [['level' => 'high'], false, true],
    'a level not_in excludes' => [['level' => 2], false, true],
    // The rest publish no constraint, so they never refuse a body the server takes.
    'a numeric alias' => [['alias' => 5], true, true],
    'a numeric nickname' => [['nickname' => 5], true, true],
    'a numeric prefix' => [['prefix' => 5], true, true],
    'a numeric suffix' => [['suffix' => 5], true, true],
    'an alias equal to the username' => [['alias' => 'ada'], false, false],
    'a nickname not_regex excludes' => [['nickname' => 'admin1'], false, false],
    'a prefix doesnt_start_with excludes' => [['prefix' => 'sysop'], false, false],
    'a suffix doesnt_end_with excludes' => [['suffix' => 'chatbot'], false, false],
    'a referrer from a guest' => [['role' => 'guest', 'referrer' => 'x'], false, false],
]);
