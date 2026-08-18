<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\CustomRuleRequest;
use Docuccino\Laravel\Tests\Fixtures\Rules\BankReference;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CustomRuleData;
use Docuccino\Laravel\Tests\Support\RulesTraceScript;

/**
 * A `#[RuleSchema]` on a custom rule class documents every field that uses the rule, through each of
 * the three recovery paths — a `rules()` method, an inline `validate()`, and a spatie `#[Rule(new X)]`
 * attribute. A rule object with no attribute keeps today's `validation.rule-unrecoverable` diagnostic,
 * and the rule class file always joins the fragment dependencies.
 */
function customRuleContext(string $symbol, string $snippet): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/payments'),
        actionRef: new ActionRef('', 'App\\PaymentController', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(traces: [$symbol => RulesTraceScript::forPhp($snippet)]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );
}

/** The recovered rule names per field, for compact assertions. */
function customRuleNames(RuleSet $rules): array
{
    $out = [];
    foreach ($rules->fields as $field => $fieldRules) {
        $out[$field] = array_map(static fn ($rule): string => $rule->name, $fieldRules);
    }

    return $out;
}

it('documents rule objects recovered from a FormRequest rules() and diagnoses the undocumented one', function (): void {
    $context = customRuleContext(
        CustomRuleRequest::class.'::rules',
        <<<'PHP'
        return [
            'reference' => ['required', new \Docuccino\Laravel\Tests\Fixtures\Rules\BankReference('GB')],
            'currency' => ['required', new \Docuccino\Laravel\Tests\Fixtures\Rules\VendorCurrencyRule],
            'token' => new \Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck,
        ];
        PHP,
    );

    $rules = (new RulesFromClass)->analyse($context, CustomRuleRequest::class);

    expect(customRuleNames($rules ?? new RuleSet))->toBe([
        'reference' => ['required', 'string', 'regex', 'min', 'max', 'format', 'description', 'example'],
        'currency' => ['required', 'string', 'in', 'description'],
    ]);

    $diagnostics = array_values(array_filter(
        $context->components->diagnostics(),
        static fn ($d): bool => $d->code === 'validation.rule-unrecoverable',
    ));

    // The unannotated rule keeps the existing negative path — one diagnostic, naming only that field.
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('"token"');

    // Both rule classes are fragment dependencies, so editing either re-documents the request.
    expect($context->dependencyFiles())
        ->toContain((string) (new ReflectionClass(BankReference::class))->getFileName())
        ->toContain((string) (new ReflectionClass(OpaqueCheck::class))->getFileName());
});

it('documents a rule object recovered from an inline validate() through to the body schema', function (): void {
    $context = customRuleContext(
        'App\\PaymentController::store',
        <<<'PHP'
        $request->validate([
            'reference' => ['required', new \Docuccino\Laravel\Tests\Fixtures\Rules\BankReference],
        ]);
        PHP,
    );

    (new ValidationRequestExtension)->handle($operation = new OperationDraft, $context);

    $body = $operation->freeze()->toArray()['requestBody'] ?? [];
    $schema = is_array($body) ? $body['content']['application/json']['schema'] : [];

    // Every field of the attribute lands on the property, through the ordinary transformer chain.
    expect($schema['properties']['reference'])->toBe([
        'type' => 'string',
        'pattern' => '[A-Z]{2}[0-9]{6}',
        'minLength' => 8,
        'maxLength' => 8,
        'format' => 'bank-reference',
        'description' => 'A bank reference: two country letters then six digits.',
        'example' => 'GB123456',
    ])
        ->and($schema['required'])->toBe(['reference'])
        ->and($context->dependencyFiles())->toContain((string) (new ReflectionClass(BankReference::class))->getFileName());
});

it('documents a rule object carried by a spatie #[Rule(new X)] attribute', function (): void {
    $metadata = new ClassMetadata(CustomRuleData::class, [
        new PropertyMetadata('reference', ScalarT::string()),
        new PropertyMetadata('token', ScalarT::string()),
    ]);

    $rules = new DataValidationRules;
    $set = $rules->build(CustomRuleData::class, $metadata, new NullTypeEngine);

    expect(customRuleNames($set))->toBe([
        'reference' => ['required', 'string', 'regex', 'min', 'max', 'format', 'description', 'example'],
        // The undocumented rule object adds nothing; the property's own type still documents the field.
        'token' => ['required', 'string'],
    ])
        ->and($rules->dependencyFiles())
        ->toContain((string) (new ReflectionClass(BankReference::class))->getFileName());
});
