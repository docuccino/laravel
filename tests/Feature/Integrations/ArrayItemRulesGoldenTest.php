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
use Docuccino\Laravel\Tests\Fixtures\ArrayItemRules\IssueTokenRequest;
use Docuccino\Laravel\Tests\Fixtures\ArrayItemRules\TokenController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Lists validated item by item through `field.*` keys, locked in emitted bytes: closed sets written as
 * rule objects and as `Rule::` factories, and `distinct` at every depth a `*` can put it. Nothing else in
 * the golden corpus publishes a `distinct` rule or a rule object's value set.
 */
function arrayItemRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-tokens', [TokenController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [TokenController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [IssueTokenRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(IssueTokenRequest::class))->getFileName(),
            IssueTokenRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits item-level rules byte-identical to their committed goldens', function (): void {
    $result = arrayItemRulesBuild();

    assertGolden('array-item-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('array-item-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});

it('takes every body the server takes, and refuses the repeats and outsiders it can name', function (array $fields, bool $accepted, bool $exact): void {
    $body = ['abilities' => ['read'], ...$fields];

    // The server's answer, by Laravel itself.
    expect(Validator::make($body, (new IssueTokenRequest)->rules())->passes())->toBe($accepted);

    // The document's, `$ref`s resolved through the document the body schema stands in.
    $document = json_decode((new UirEmitter)->emit(arrayItemRulesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/IssueTokenRequest';
    $published = (new SchemaValidator)->validate(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    // Never narrower than the server; as narrow wherever a keyword can say so.
    expect($published)->toBe($accepted || ! $exact);
})->with([
    'two abilities' => [['abilities' => ['read', 'write']], true, true],
    'an ability repeated' => [['abilities' => ['read', 'read']], false, true],
    'an ability new Enum does not hold' => [['abilities' => ['root']], false, true],
    'a role repeated under strict' => [['roles' => ['read', 'read']], false, true],
    'a channel new In does not hold' => [['channels' => ['fax']], false, true],
    'a default ability new Enum does not hold' => [['default_ability' => 'root'], false, true],
    'a label repeated in another case' => [['labels' => ['ops', 'OPS']], false, false],
    'a label repeated' => [['labels' => ['ops', 'ops']], false, true],
    'two null aliases, which Laravel skips' => [['aliases' => [null, null]], true, true],
    'a tag repeated, under a key Laravel compares only with itself' => [['tags' => ['a', 'a']], true, true],
    'one ability granted twice, across objects' => [['grants' => [['ability' => 'read'], ['ability' => 'read']]], false, false],
    'two abilities granted' => [['grants' => [['ability' => 'read'], ['ability' => 'write']]], true, true],
    'a cell repeated in its row' => [['matrix' => [[1, 1]]], false, true],
    'a cell repeated in another row' => [['matrix' => [[1], [1]]], false, false],
    'a handle Rule::notIn excludes' => [['handles' => ['ops', 'root']], false, true],
    'handles Rule::notIn does not exclude' => [['handles' => ['ops', 'support']], true, true],
    'a nickname new NotIn excludes' => [['nicknames' => ['admin']], false, true],
    'settings with the key Rule::array names' => [['settings' => ['theme' => 'dark']], true, true],
    'settings with a key Rule::array does not name' => [['settings' => ['colour' => 'red']], false, false],
    'settings that are not an array' => [['settings' => 'dark'], false, true],
]);
