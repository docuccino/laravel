<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\CredentialRules\AccountController;
use Docuccino\Laravel\Tests\Fixtures\CredentialRules\StoreAccountRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Credentials checked by the framework's password and email builders, as `new`, a static of the rule's own
 * class and a `Rule::` factory, locked in emitted bytes. No golden held one.
 */
/** An account {@see StoreAccountRequest} takes, with every credential its builders check. */
const OPENED_ACCOUNT = ['email' => 'ada@example.com', 'password' => 'abcdefgh', 'new_password' => 'Abcdefghij'];

function credentialRulesBuild(): GenerationResult
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

it('emits credentials checked by the framework builders byte-identical to their committed golden', function (): void {
    assertGolden('credential-rules.uir.json', (new UirEmitter)->emit(credentialRulesBuild()->document));
});

/*
 * A password or email builder is validated as the rules its state holds, so the document owes what those rule
 * strings publish: it never refuses a body the server takes, and refuses what a type or a length refuses. A row
 * marked inexact is refused by a check no schema keyword states — letters in both cases — or by the password
 * the application registered at boot, which the document leaves out.
 */
it('takes every account the server takes, and refuses what a type or a length refuses', function (array $fields, bool $accepted, bool $exact): void {
    $body = [...OPENED_ACCOUNT, ...$fields];

    // The server's answer, by Laravel itself.
    expect(Validator::make($body, (new StoreAccountRequest)->rules())->passes())->toBe($accepted);

    $document = json_decode((new UirEmitter)->emit(credentialRulesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StoreAccountRequest';
    $published = (new SchemaValidator)->validate(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    expect($published)->toBe($accepted || ! $exact);
})->with([
    'every credential as listed' => [[], true, true],
    'an email that is no address' => [['email' => 'ada'], false, true],
    'an email that is a number' => [['email' => 5], false, true],
    'a password of seven characters' => [['password' => 'abcdefg'], false, true],
    'a password that is a number' => [['password' => 12345678], false, true],
    'a new password of nine characters' => [['new_password' => 'Abcdefghi'], false, true],
    'a new password past its maximum' => [['new_password' => 'A'.str_repeat('b', 64)], false, true],
    'a new password in one case' => [['new_password' => 'abcdefghij'], false, false],
    'a recovery password long enough' => [['recovery_password' => 'abcdefgh'], true, true],
    'a recovery password shorter than the registered default' => [['recovery_password' => 'abc'], false, false],
]);
