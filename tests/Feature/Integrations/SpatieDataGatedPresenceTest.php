<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\GatedPaymentData;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\Validation\RequiringRule;

/*
 * Where a Data property's `required` comes from, checked against spatie itself. Spatie PREPENDS the
 * `required` a property's type implies, so an exclude attribute after it switches off nothing, while an
 * explicit `#[Required]` written after one replaces the inferred rule in that later position — and any
 * conditional-required attribute replaces the inferred rule outright. The documented schema has to
 * accept every body the rules spatie really hands Laravel accept, so spatie's own rules decide here.
 */
beforeEach(function (): void {
    app()->register(LaravelDataServiceProvider::class);
    app()->forgetInstance(DataConfig::class);
});

it('requires a Data property exactly where the rules spatie builds require it', function (array $body, bool $accepted, bool $documented): void {
    expect(Validator::make($body, GatedPaymentData::getValidationRules($body))->passes())->toBe($accepted);

    $metadata = new ClassMetadata(GatedPaymentData::class, array_map(
        static fn (string $name): PropertyMetadata => new PropertyMetadata($name, ScalarT::string()),
        ['method', 'cardNumber', 'iban', 'reference'],
    ));
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);
    $rules = (new DataValidationRules)->build(GatedPaymentData::class, $metadata, new NullTypeEngine, null, $context);
    $schema = (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))
        ->convert((new RuleOrdering)->order((new RuleSetNormalizer)->normalize($rules)), $context)->schema;

    $verdict = (new SchemaValidator)->validate(json_decode((string) json_encode($body)), (string) json_encode($schema));

    expect($verdict->isValid())->toBe($documented);
})->with([
    // `cardNumber` carries the inferred `required` AHEAD of its exclude attribute, so it is always
    // required; `iban`'s explicit `#[Required]` sits after its own, so the card branch may omit it.
    'the card branch' => [['method' => 'card', 'cardNumber' => '4242', 'reference' => 'r-1'], true, true],
    'the transfer branch still owes cardNumber' => [['method' => 'transfer', 'iban' => 'GB00'], false, false],
    // `#[RequiredIf]` takes the place of the inferred `required`, so reference is owed on cards only.
    'the transfer branch' => [['method' => 'transfer', 'cardNumber' => '4242', 'iban' => 'GB00'], true, true],
    // …a condition no schema keyword states, so the document leaves it optional and says when in prose.
    'the card branch without reference' => [['method' => 'card', 'cardNumber' => '4242'], false, true],
]);

it('stands the inferred required down for every requiring attribute spatie ships', function (): void {
    // Spatie's `RequiringRule` interface is the source of truth for which attributes replace the inferred
    // rule; the list the recovery keeps has to name every one, or a property stating the missing one is
    // published as required outright.
    $dir = dirname((string) (new ReflectionClass(Spatie\LaravelData\Attributes\Validation\Required::class))->getFileName());
    $keywords = [];
    foreach (glob($dir.'/*.php') ?: [] as $file) {
        $class = 'Spatie\\LaravelData\\Attributes\\Validation\\'.basename($file, '.php');
        if (class_exists($class) && is_subclass_of($class, RequiringRule::class) && method_exists($class, 'keyword')) {
            $keywords[] = $class::keyword();
        }
    }

    expect($keywords)->not->toBeEmpty()
        ->and((array) (new ReflectionClassConstant(DataValidationRules::class, 'REQUIRING_RULES'))->getValue())->toEqualCanonicalizing($keywords);
});
