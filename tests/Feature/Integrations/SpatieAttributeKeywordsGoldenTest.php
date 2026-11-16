<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AttributeKeywordController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AttributeKeywordData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;

/*
 * Validation attributes whose rule is not their class name snake-cased, ones whose arguments are named out of
 * order, and ones that build a rule object, locked in emitted bytes. No golden held one.
 */
function spatieAttributeKeywordsBuild(): GenerationResult
{
    bootLaravelData(null);

    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $of = static fn ($type): UnionT => UnionT::of([$type, $optional]);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            AttributeKeywordData::class => new ClassMetadata(AttributeKeywordData::class, [
                new PropertyMetadata('items', $of(new UnknownT('untyped array'))),
                new PropertyMetadata('above', $of(ScalarT::int())),
                new PropertyMetadata('atLeast', $of(ScalarT::int())),
                new PropertyMetadata('below', $of(ScalarT::int())),
                new PropertyMetadata('atMost', $of(ScalarT::int())),
                new PropertyMetadata('v4', $of(ScalarT::string())),
                new PropertyMetadata('v6', $of(ScalarT::string())),
                new PropertyMetadata('count', $of(ScalarT::int())),
                new PropertyMetadata('avatar', $of(new ClassT('Illuminate\\Http\\UploadedFile'))),
                new PropertyMetadata('banner', $of(new ClassT('Illuminate\\Http\\UploadedFile'))),
                new PropertyMetadata('passphrase', $of(ScalarT::string())),
                new PropertyMetadata('owner', $of(ScalarT::string())),
                new PropertyMetadata('handle', $of(ScalarT::string())),
            ]),
        ],
        analysisOverrides: [
            AttributeKeywordController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-attribute-keywords', [AttributeKeywordController::class, 'store']),
        $engine,
    );
}

it('emits validation attributes named apart from their rules byte-identical to their committed golden', function (): void {
    assertGolden('spatie-attribute-keywords.uir.json', (new UirEmitter)->emit(spatieAttributeKeywordsBuild()->document));
});

/*
 * Each row says whether laravel-data takes the body, by the rules it hands Laravel, and whether the document does.
 * laravel-data writes an attribute's rule from the attribute's own keyword, so `#[GreaterThan(5)]` is `gt:5` and
 * `#[ListType]` is `list`, and its parameters in the order the attribute declares them, however they were written;
 * an attribute that builds a rule object hands Laravel that object. The two answer alike.
 */
it('takes every value laravel-data takes through an attribute named apart from its rule', function (array $body, bool $accepted): void {
    $document = json_decode((new UirEmitter)->emit(spatieAttributeKeywordsBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/AttributeKeywordDataRequest';

    app()->register(LaravelDataServiceProvider::class);
    app()->forgetInstance(DataConfig::class);

    expect(Validator::make($body, AttributeKeywordData::getValidationRules($body))->passes())->toBe($accepted)
        ->and((new SchemaValidator)->validate(json_decode((string) json_encode((object) $body), flags: JSON_THROW_ON_ERROR), $document)->isValid())->toBe($accepted);
})->with([
    'every field left out' => [[], true],
    // `list` takes an array whose keys run from zero, which JSON writes as an array, and refuses an object.
    'a list' => [['items' => ['a', 'b']], true],
    'an empty list' => [['items' => []], true],
    'an object' => [['items' => ['k' => 'a']], false],
    'a string' => [['items' => 'a'], false],
    'above its bound' => [['above' => 6], true],
    'at the bound gt refuses' => [['above' => 5], false],
    'at the bound gte takes' => [['atLeast' => 5], true],
    'under it' => [['atLeast' => 4], false],
    'under the bound lt sets' => [['below' => 9], true],
    'at the bound lt refuses' => [['below' => 10], false],
    'at the bound lte takes' => [['atMost' => 10], true],
    'past it' => [['atMost' => 11], false],
    // `#[Between(max: 10, min: 1)]` is `between:1,10`, as the attribute declares its parameters.
    'the lower bound' => [['count' => 1], true],
    'the upper bound' => [['count' => 10], true],
    'between the bounds' => [['count' => 5], true],
    'under the lower bound' => [['count' => 0], false],
    'past the upper bound' => [['count' => 11], false],
    'an IPv4 address' => [['v4' => '192.0.2.1'], true],
    'an IPv6 address' => [['v6' => '2001:db8::1'], true],
    // `ipv4` and `ipv6` are JSON Schema formats of their own, so an address of the other family is refused by both.
    'an IPv6 address where IPv4 is asked' => [['v4' => '2001:db8::1'], false],
    'an IPv4 address where IPv6 is asked' => [['v6' => '192.0.2.1'], false],
    // `#[Password(min: 10)]` hands Laravel a password rule, which validates the value as `string|min:10` first.
    'a passphrase at its minimum' => [['passphrase' => '0123456789'], true],
    'a passphrase one short of it' => [['passphrase' => '012345678'], false],
    'a passphrase that is no string' => [['passphrase' => 1234567890], false],
]);
