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
use Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance\ConsentController;
use Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance\ConsentData;
use Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance\StoreConsentRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;

/*
 * Fields that must be accepted or declined while another field holds a value — written as the rule strings
 * and as laravel-data's validation attributes — beside the unconditional forms, locked in emitted bytes. No
 * golden held a conditional acceptance.
 */
/** What a business sends to consent as {@see StoreConsentRequest} asks: each field it conditions on `kind`. */
const CONSENTING_BUSINESS = ['kind' => 'business', 'terms' => true, 'contract' => true, 'newsletter' => true, 'marketing' => false, 'tracking' => false];

function conditionalAcceptanceBuild(): GenerationResult
{
    bootLaravelData(null);

    $returns = new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))]);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            ConsentData::class => new ClassMetadata(ConsentData::class, [
                new PropertyMetadata('kind', ScalarT::string()),
                new PropertyMetadata('terms', ScalarT::bool()),
                new PropertyMetadata('marketing', UnionT::of([ScalarT::bool(), new ClassT(DataClassReflector::OPTIONAL)])),
            ]),
        ],
        analysisOverrides: [
            ConsentController::class.'::store' => $returns,
            ConsentController::class.'::storeData' => $returns,
        ],
        traceOverrides: [StoreConsentRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreConsentRequest::class))->getFileName(),
            StoreConsentRequest::class,
            'rules',
        )],
    );

    return localityBuild(static function (Router $router): void {
        $router->post('api/zz-consents', [ConsentController::class, 'store']);
        $router->post('api/zz-consent-data', [ConsentController::class, 'storeData']);
    }, $engine);
}

it('emits conditionally accepted and declined fields byte-identical to their committed golden', function (): void {
    assertGolden('conditional-acceptance.uir.json', (new UirEmitter)->emit(conditionalAcceptanceBuild()->document));
});

/*
 * `accepted_if:kind,business` asks nothing of the value until `kind` is `business`: with `kind` anything else,
 * or absent, Laravel takes `false`, `"x"` and no value at all. The document cannot state the condition, so it
 * must take what the server takes on either side of it. Each row is the server's answer, by Laravel itself;
 * where `exact`, the document refuses what the server refuses too, and elsewhere the condition holds and the
 * refusal is the note's to state.
 */
it('takes every value the server takes, whether or not the condition holds', function (array $fields, bool $accepted, bool $exact): void {
    $body = ['privacy' => true, ...$fields];
    $body = array_filter($body, static fn (mixed $value): bool => $value !== '(absent)');

    expect(Validator::make($body, (new StoreConsentRequest)->rules())->passes())->toBe($accepted);

    $document = json_decode((new UirEmitter)->emit(conditionalAcceptanceBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/StoreConsentRequest';
    $published = (new SchemaValidator)->validate(json_decode((string) json_encode((object) $body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    expect($published)->toBe($accepted || ! $exact);
})->with([
    'nothing conditional sent' => [[], true, true],
    // The condition fails, so the value is anything, or nothing.
    'terms declined by a person' => [['kind' => 'person', 'terms' => false], true, true],
    'terms as a word by a person' => [['kind' => 'person', 'terms' => 'x'], true, true],
    'terms declined with no kind' => [['terms' => false], true, true],
    'terms as null with no kind' => [['terms' => null], true, true],
    'marketing accepted by a person' => [['kind' => 'person', 'marketing' => true], true, true],
    'marketing as a number with no kind' => [['marketing' => 5], true, true],
    'a contract declined by a person' => [['kind' => 'person', 'contract' => false], true, true],
    'tracking accepted with no kind' => [['tracking' => true], true, true],
    'tracking as null by a person' => [['kind' => 'person', 'tracking' => null], true, true],
    // A type rule beside it holds on every request.
    'a newsletter declined by a person' => [['kind' => 'person', 'newsletter' => false], true, true],
    'a newsletter as a word by a person' => [['kind' => 'person', 'newsletter' => 'x'], false, true],
    // The condition holds, so each field is accepted or declined as its rule says.
    'all of it as a business must' => [CONSENTING_BUSINESS, true, true],
    'terms declined by a business' => [[...CONSENTING_BUSINESS, 'terms' => false], false, false],
    'terms as a word by a business' => [[...CONSENTING_BUSINESS, 'terms' => 'x'], false, false],
    'terms left out by a business' => [[...CONSENTING_BUSINESS, 'terms' => '(absent)'], false, false],
    'marketing accepted by a business' => [[...CONSENTING_BUSINESS, 'marketing' => true], false, false],
    'tracking as null by a business' => [[...CONSENTING_BUSINESS, 'tracking' => null], false, false],
    // Each value the condition lists holds it.
    'a contract declined by a charity' => [['kind' => 'charity', 'contract' => false], false, false],
    'a contract accepted by a charity' => [['kind' => 'charity', 'contract' => true], true, true],
    // Unconditional, `accepted` holds on every request. It refuses a missing field too, which the document
    // leaves optional.
    'privacy declined' => [['privacy' => false], false, true],
    'privacy left out' => [['privacy' => '(absent)'], false, false],
]);

/*
 * laravel-data hands Laravel `#[AcceptedIf('kind', 'business')]` as `accepted_if:kind,business`, beside the
 * `boolean` a `bool` property brings, so the same holds through a Data class.
 */
it('takes every value laravel-data takes through a conditional acceptance attribute', function (array $body, bool $accepted, bool $exact): void {
    $document = json_decode((new UirEmitter)->emit(conditionalAcceptanceBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/ConsentDataRequest';

    app()->register(LaravelDataServiceProvider::class);
    app()->forgetInstance(DataConfig::class);

    expect(Validator::make($body, ConsentData::getValidationRules($body))->passes())->toBe($accepted);

    $published = (new SchemaValidator)->validate(json_decode((string) json_encode((object) $body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    expect($published)->toBe($accepted || ! $exact);
})->with([
    'terms declined by a person' => [['kind' => 'person', 'terms' => false], true, true],
    'marketing accepted by a person' => [['kind' => 'person', 'terms' => false, 'marketing' => true], true, true],
    'terms as a word by a person' => [['kind' => 'person', 'terms' => 'x'], false, true],
    'terms accepted by a business' => [['kind' => 'business', 'terms' => true], true, true],
    'marketing declined by a business' => [['kind' => 'business', 'terms' => true, 'marketing' => false], true, true],
    'terms declined by a business' => [['kind' => 'business', 'terms' => false], false, false],
    'marketing accepted by a business' => [['kind' => 'business', 'terms' => true, 'marketing' => true], false, false],
]);
