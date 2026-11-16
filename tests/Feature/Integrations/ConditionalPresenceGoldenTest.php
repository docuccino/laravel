<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ConditionalPresence\ProfileController;
use Docuccino\Laravel\Tests\Fixtures\ConditionalPresence\StoreProfileRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Required and prohibited rules that hold on a condition — a literal, a closure, a value only the request
 * decides — written as `new` objects, as `Rule::` factories and as the cross-field strings, locked in
 * emitted bytes. No golden held a conditional rule object.
 */
function conditionalPresenceBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-profiles', [ProfileController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [ProfileController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreProfileRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreProfileRequest::class))->getFileName(),
            StoreProfileRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits conditional required and prohibited rules byte-identical to their committed golden', function (): void {
    assertGolden('conditional-presence.uir.json', (new UirEmitter)->emit(conditionalPresenceBuild()->document));
});

/*
 * Each row sends or leaves out one field. The document never refuses a body the server takes, and refuses
 * every one it does not wherever a settled condition lets it say so; a row marked inexact is one only the
 * request settles, which the document leaves optional or sendable.
 */
it('requires and refuses a field as its condition settles it, and takes it where the request decides', function (array $fields, bool $accepted, bool $exact): void {
    $body = ['name' => 'ada', 'email' => 'ada@example.com', 'city' => 'Leeds', 'street' => 'Park Row'];
    foreach ($fields as $field => $value) {
        if ($value === null) {
            unset($body[$field]);
        } else {
            $body[$field] = $value;
        }
    }

    // The server's answer, by Laravel itself, with the request its runtime conditions read.
    expect(Validator::make($body, StoreProfileRequest::create('/', 'POST', $body)->rules())->passes())->toBe($accepted);

    $document = json_decode((new UirEmitter)->emit(conditionalPresenceBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StoreProfileRequest';
    $published = (new SchemaValidator)->validate(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    expect($published)->toBe($accepted || ! $exact);
})->with([
    'every field as listed' => [[], true, true],
    // A literal true requires the field, whether written with new or with the factory.
    'an email left out' => [['email' => null], false, true],
    'a city left out' => [['city' => null], false, true],
    // A literal false requires nothing: the field may be sent or left out.
    'a phone sent' => [['phone' => '0113'], true, true],
    'a country sent' => [['country' => 'UK'], true, true],
    // A closure answers per request, so the field is optional, whatever this one says.
    'a street left out' => [['street' => null], false, false],
    'a postcode left out' => [[], true, true],
    // A value only the request decides.
    'a company left out by a business' => [['kind' => 'business'], false, false],
    'a company left out by a person' => [['kind' => 'person'], true, true],
    'a vat left out by a business' => [['kind' => 'business', 'company' => 'Acme'], false, false],
    // A literal true prohibits the field; a literal false leaves it sendable.
    'a legacy id sent' => [['legacy_id' => 7], false, false],
    'a nickname sent' => [['nickname' => 'ace'], true, true],
    'an alias sent' => [['alias' => 'ace'], false, false],
    'a handle sent' => [['handle' => 'ace'], true, true],
    'an avatar sent' => [['avatar' => 'a.png'], false, false],
    'a banner sent' => [['banner' => 'b.png'], true, true],
    'a discount sent by a business' => [['kind' => 'business', 'company' => 'Acme', 'vat' => 'GB1', 'discount' => '10'], false, false],
    'a discount sent by a person' => [['kind' => 'person', 'discount' => '10'], true, true],
    'a referrer sent by a business' => [['kind' => 'business', 'company' => 'Acme', 'vat' => 'GB1', 'referrer' => 'bob'], false, false],
]);
