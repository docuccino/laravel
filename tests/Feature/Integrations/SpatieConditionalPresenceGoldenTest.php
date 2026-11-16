<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ConditionalPresenceAddressData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ConditionalPresenceController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ConditionalPresenceData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;

/*
 * Spatie's `#[Required]` and `#[Prohibited]` handed a condition, beside the cross-field `#[RequiredIf]` and
 * `#[ProhibitedIf]`, on scalars and on nested Data, locked in emitted bytes. No golden held one.
 */
function spatieConditionalPresenceBuild(): GenerationResult
{
    bootLaravelData(null);

    $string = ScalarT::string();
    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $address = new ClassT(ConditionalPresenceAddressData::class);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            ConditionalPresenceData::class => new ClassMetadata(ConditionalPresenceData::class, [
                new PropertyMetadata('name', $string),
                new PropertyMetadata('kind', UnionT::of([$string, $optional])),
                new PropertyMetadata('email', $string),
                new PropertyMetadata('phone', $string),
                new PropertyMetadata('vat', $string),
                new PropertyMetadata('legacy', UnionT::of([$string, $optional])),
                new PropertyMetadata('nickname', UnionT::of([$string, $optional])),
                new PropertyMetadata('referrer', UnionT::of([$string, $optional])),
                new PropertyMetadata('home', $address),
                new PropertyMetadata('office', $address),
                new PropertyMetadata('billing', UnionT::of([$address, $optional])),
                new PropertyMetadata('archive', UnionT::of([$address, $optional])),
            ]),
            ConditionalPresenceAddressData::class => new ClassMetadata(ConditionalPresenceAddressData::class, [
                new PropertyMetadata('line', $string),
            ]),
        ],
        analysisOverrides: [
            ConditionalPresenceController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-conditional-presence', [ConditionalPresenceController::class, 'store']),
        $engine,
    );
}

it('emits Spatie presence attributes handed a condition byte-identical to their committed golden', function (): void {
    assertGolden('spatie-conditional-presence.uir.json', (new UirEmitter)->emit(spatieConditionalPresenceBuild()->document));
});

/*
 * Each row sends or leaves out one field. The document never refuses a body laravel-data takes, and refuses
 * every one it does not wherever a settled condition lets it say so; a row marked inexact is one only the
 * request settles, which the document leaves optional or sendable.
 */
it('requires and refuses a Data property as its condition settles it', function (array $fields, bool $accepted, bool $exact): void {
    $document = json_decode((new UirEmitter)->emit(spatieConditionalPresenceBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/ConditionalPresenceDataRequest';

    $body = ['name' => 'ada', 'email' => 'ada@example.com', 'home' => ['line' => '1 Park Row'], 'office' => ['line' => '2 Wharf St']];
    foreach ($fields as $field => $value) {
        if ($value === null) {
            unset($body[$field]);
        } else {
            $body[$field] = $value;
        }
    }

    // The server's answer, by the rules laravel-data itself hands Laravel.
    app()->register(LaravelDataServiceProvider::class);
    app()->forgetInstance(DataConfig::class);
    expect(Validator::make($body, ConditionalPresenceData::getValidationRules($body))->passes())->toBe($accepted);

    $published = (new SchemaValidator)->validate(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR), $document)->isValid();

    expect($published)->toBe($accepted || ! $exact);
})->with([
    'every field as listed' => [[], true, true],
    // `#[Required(new RequiredIf(true))]` requires the property.
    'an email left out' => [['email' => null], false, true],
    // `#[Required(new RequiredIf(false))]` takes the place of the `required` the type implies, and requires nothing.
    'a phone left out' => [[], true, true],
    'a phone sent' => [['phone' => '0113'], true, true],
    'a vat left out by a business' => [['kind' => 'business'], false, false],
    // `#[Prohibited(new ProhibitedIf(false))]` prohibits nothing, so the property is sendable.
    'a nickname sent' => [['nickname' => 'ace'], true, true],
    'a legacy value sent' => [['legacy' => 'x'], false, false],
    'a referrer sent by a business' => [['kind' => 'business', 'vat' => 'GB1', 'referrer' => 'bob'], false, false],
    // laravel-data writes a nested Data property's own rules whatever presence rule it states, so the child's
    // required `line` requires the property too.
    'a home left out' => [['home' => null], false, true],
    'an office left out' => [['office' => null], false, true],
    'a billing address sent' => [['billing' => ['line' => '3 Dock St']], true, true],
    'an archived address sent' => [['archive' => ['line' => '4 Mill Ln']], false, false],
]);

it('reads a Spatie presence attribute handed a closure as a rule on a condition, without running it', function (): void {
    // A closure in an attribute is PHP 8.5 syntax, so the class is compiled here rather than kept as a file. Each
    // closure throws: it answers for one request, which no build is, so it must never be asked.
    $class = 'Docuccino\\Laravel\\Tests\\Fixtures\\SpatieData\\ClosurePresenceData';
    if (! class_exists($class)) {
        eval('namespace Docuccino\\Laravel\\Tests\\Fixtures\\SpatieData; use Illuminate\\Validation\\Rules\\ProhibitedIf; use Illuminate\\Validation\\Rules\\RequiredIf; use Spatie\\LaravelData\\Attributes\\Validation\\Prohibited; use Spatie\\LaravelData\\Attributes\\Validation\\Required; final class ClosurePresenceData extends \\Spatie\\LaravelData\\Data { public function __construct(
            #[Required(new RequiredIf(static function (): bool { throw new \\LogicException("asked"); }))] public string $email,
            #[Prohibited(new ProhibitedIf(static function (): bool { throw new \\LogicException("asked"); }))] public string|\\Spatie\\LaravelData\\Optional $nickname,
        ) {} }');
    }

    $string = ScalarT::string();
    $set = (new DataValidationRules)->build($class, new ClassMetadata($class, [
        new PropertyMetadata('email', $string),
        new PropertyMetadata('nickname', UnionT::of([$string, new ClassT(DataClassReflector::OPTIONAL)])),
    ]), new NullTypeEngine, null, schemaConverter());
    $names = static fn (string $key): array => array_map(static fn (ValidationRule $rule): string => $rule->name, $set->fields[$key] ?? []);

    // Required on a condition the build cannot read: optional, the `required` its type implies replaced. And
    // prohibited on one: sendable.
    expect($names('email'))->toContain('required_if')->not->toContain('required')
        ->and($set->fields)->toHaveKey('nickname')
        ->and($names('nickname'))->toContain('prohibited_if')->not->toContain('prohibited');
})->skip(PHP_VERSION_ID < 80500, 'A closure in an attribute argument needs PHP 8.5.');
