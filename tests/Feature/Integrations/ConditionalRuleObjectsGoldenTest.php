<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ConditionalRuleObjects\ApplicationController;
use Docuccino\Laravel\Tests\Fixtures\ConditionalRuleObjects\StoreApplicationRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\AcceptedIf;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Rules that hold on another field — accepted or declined on a value, missing or present on a value, required
 * with another field or once one is accepted, prohibiting another — written as the rule objects Laravel 13
 * builds them as, beside its timezone, url and uuid objects, locked in emitted bytes. No golden held one.
 */
/** What a business sends as {@see StoreApplicationRequest} asks: each field it conditions on `kind` or on `vat`. */
const APPLYING_BUSINESS = ['kind' => 'business', 'terms' => true, 'contract' => true, 'marketing' => false, 'vat' => 'GB1', 'company' => 'Acme', 'office' => 'Leeds'];

function conditionalRuleObjectsBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-applications', [ApplicationController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [ApplicationController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreApplicationRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreApplicationRequest::class))->getFileName(),
            StoreApplicationRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits rule objects that hold on another field byte-identical to their committed golden', function (): void {
    assertGolden('conditional-rule-objects.uir.json', (new UirEmitter)->emit(conditionalRuleObjectsBuild()->document));
})->skip(! class_exists(AcceptedIf::class), 'This Laravel builds these rules as strings, not objects.');

/*
 * Each rule object is the rule string it casts to, so the document owes what the string publishes: it never
 * refuses a body the server takes. A condition on another field is no keyword a schema has, so where the
 * server refuses on one the document takes the body, and the row is marked inexact; where a rule is a fact
 * about the value alone, the document refuses what the server refuses.
 */
it('takes every body the server takes, and refuses what a rule on the value alone refuses', function (array $body, bool $accepted, bool $exact): void {
    // The server's answer, by Laravel itself.
    expect(Validator::make($body, (new StoreApplicationRequest)->rules())->passes())->toBe($accepted);

    $document = json_decode((new UirEmitter)->emit(conditionalRuleObjectsBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StoreApplicationRequest';
    $published = (new SchemaValidator)->validate(json_decode((string) json_encode((object) $body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    expect($published)->toBe($accepted || ! $exact);
})->with([
    'nothing sent' => [[], true, true],
    'a business sending all it is asked for' => [APPLYING_BUSINESS, true, true],
    // Accepted or declined only for a business: a person may send any value, and a business must accept.
    'a person accepting nothing' => [['kind' => 'person', 'terms' => false, 'contract' => 'no', 'marketing' => true], true, true],
    'a business leaving the terms unaccepted' => [[...APPLYING_BUSINESS, 'terms' => false], false, false],
    'a business leaving the contract out' => [array_diff_key(APPLYING_BUSINESS, ['contract' => true]), false, false],
    'a business accepting marketing' => [[...APPLYING_BUSINESS, 'marketing' => true], false, false],
    // Missing and present only for a business.
    'a business sending a referral' => [[...APPLYING_BUSINESS, 'referral' => 'bob'], false, false],
    'a person sending a referral' => [['kind' => 'person', 'referral' => 'bob'], true, true],
    'a business leaving out its vat number' => [array_diff_key(APPLYING_BUSINESS, ['vat' => true, 'company' => true, 'office' => true]), false, false],
    // Required beside another field, or once one is accepted.
    'a vat number without a company' => [['vat' => 'GB1', 'office' => 'Leeds'], false, false],
    'a company without an office' => [['vat' => 'GB1', 'company' => 'Acme'], false, false],
    'a newsletter accepted without an email' => [['newsletter' => true], false, false],
    'a newsletter accepted with an email' => [['newsletter' => true, 'email' => 'ada@example.com'], true, true],
    // A nickname prohibits an alias.
    'a nickname beside an alias' => [['nickname' => 'ace', 'alias' => 'ada'], false, false],
    'a nickname alone' => [['nickname' => 'ace'], true, true],
    // Facts about the value alone: a string, a uuid. Which zones and protocols are taken is the server's.
    'a zone that is a number' => [['zone' => 5], false, true],
    'a zone that is no timezone' => [['zone' => 'Mars/Olympus'], false, false],
    'a website on http' => [['website' => 'http://example.com'], false, false],
    'a website on https' => [['website' => 'https://example.com'], true, true],
    'a reference that is no uuid' => [['reference' => 'abc'], false, true],
    'a reference that is a uuid' => [['reference' => '3fa85f64-5717-4562-b3fc-2c963f66afa6'], true, true],
])->skip(! class_exists(AcceptedIf::class), 'This Laravel builds these rules as strings, not objects.');
