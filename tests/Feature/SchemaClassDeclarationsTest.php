<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\AttributeRequestBodyExtension;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SchemaClass\MisplacedDeclarationRequest;
use Docuccino\Laravel\Tests\Fixtures\SchemaClass\NestedRequiredRequest;
use Docuccino\Laravel\Tests\Fixtures\SchemaClass\PreferencesRequest;
use Docuccino\Laravel\Tests\Fixtures\SchemaClass\RefusedDeclarationRequest;
use Docuccino\Laravel\Tests\Support\RulesTraceScript;

/**
 * A `#[BodyParameter]` written on the request TYPE patches the component the operation `$ref`s, instead
 * of being dropped in silence for not being in the route's attribute bag. Which is a CONSUMER's
 * problem, not an author's: the only place to say it used to be the action, and a declaration there
 * dereferences the body — so the named `…Request` type left the document, and two operations accepting
 * the same shape stopped saying so.
 *
 * `runFormRequestBody()` drives the real recovery path: a FormRequest's `rules()` traced from source,
 * through the integration extension that hoists the body, and then the attribute extension over it.
 */
function runFormRequestBody(string $formRequest, string $rules, array $actionAttributes = []): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/tenants'),
        actionRef: new ActionRef('', 'App\\TenantController', 'store'),
        attributes: new AttributeSet($actionAttributes),
        engine: new StubTypeEngine(traces: [$formRequest.'::rules' => RulesTraceScript::forPhp($rules)]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
        formRequestClass: $formRequest,
    );

    $operation = new OperationDraft;
    (new ValidationRequestExtension)->handle($operation, $context);
    (new AttributeRequestBodyExtension)->handle($operation, $context);

    /** @var array<string, mixed> $body */
    $body = $operation->resolvedField('requestBody') ?? [];

    return [
        $body,
        $context->components->schemas(),
        array_map(static fn (Diagnostic $d): string => $d->code, $context->components->diagnostics()),
    ];
}

it('keeps the operation on a $ref and patches the component a type-level declaration describes', function (): void {
    [$body, $schemas, $codes] = runFormRequestBody(
        PreferencesRequest::class,
        "return ['nickname' => 'required|string', 'overrides' => 'array'];",
    );

    // The whole point: the operation still points at the named component.
    expect($body['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/PreferencesRequest'])
        ->and(array_keys($schemas))->toBe(['PreferencesRequest']);

    $schema = $schemas['PreferencesRequest'];

    // The declaration is IN the component, so every operation accepting the type carries it.
    expect($schema['properties']['overrides'])->toBe([
        'type' => 'object',
        // What `type: 'object'` publishes for a map with no keys named — the whole reason the
        // declaration exists — with the key one declaration DID name written inside it.
        'additionalProperties' => [],
        'description' => 'Arbitrary per-tenant overrides.',
        'properties' => ['locale' => ['type' => 'string']],
    ])
        // The recovered sibling survives, and the recovered `required` is untouched: the declarations
        // said nothing about it.
        ->and($schema['properties']['nickname'])->toBe(['type' => 'string'])
        ->and($schema['required'])->toBe(['nickname'])
        // A declaration naming a key inside the container answers "array or object", so the note asking
        // for rules that would answer it again is stood down.
        ->and($codes)->not->toContain('validation.container-undecided');
});

/**
 * Depth ordering is the shared walk's, not a second copy of it: the fixture declares the parent FIRST
 * and the child second, and a shallowest-first pass has to leave the child standing either way. This is
 * the assertion that fails if the component path grew its own ordering.
 */
it('applies a type-level parent declaration before the child inside it', function (): void {
    [, $schemas] = runFormRequestBody(
        PreferencesRequest::class,
        "return ['nickname' => 'required|string', 'overrides' => 'array'];",
    );

    expect($schemas['PreferencesRequest']['properties']['overrides']['properties'])
        ->toBe(['locale' => ['type' => 'string']]);
});

/**
 * The refusal is the shared walk's too, and it names the TYPE the declaration was written on rather
 * than the action — which is where the author has to go to correct it.
 */
it('names the type in a refusal a type-level declaration earns', function (): void {
    [, , $codes] = runFormRequestBody(
        RefusedDeclarationRequest::class,
        "return ['nickname' => 'required|string'];",
    );

    expect($codes)->toContain('attribute.body-parameter-parent');
});

it('reports the refusal against the class that declared it', function (): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/tenants'),
        actionRef: new ActionRef('', 'App\\TenantController', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(traces: [
            RefusedDeclarationRequest::class.'::rules' => RulesTraceScript::forPhp("return ['nickname' => 'required|string'];"),
        ]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
        formRequestClass: RefusedDeclarationRequest::class,
    );

    (new ValidationRequestExtension)->handle(new OperationDraft, $context);

    $messages = array_map(
        static fn (Diagnostic $d): string => $d->message,
        array_values(array_filter(
            $context->components->diagnostics(),
            static fn (Diagnostic $d): bool => $d->code === 'attribute.body-parameter-parent',
        )),
    );

    expect($messages)->toBe([
        '#[BodyParameter(name: "nickname.locale")] on '.RefusedDeclarationRequest::class
            .' nests under `nickname`, documented as `string`, so no property was documented.',
    ]);
});

/**
 * An ACTION-level declaration keeps every bit of its meaning: it is one operation's, so it dereferences
 * the body and patches it there. Both sites together are the ladder's own rule — same layer, and the
 * more specific target lands second — so the operation wins the property it names while the type's
 * declaration is still underneath it.
 */
it('lets an action-level declaration inline the body and win over the type-level one', function (): void {
    [$body, $schemas] = runFormRequestBody(
        PreferencesRequest::class,
        "return ['nickname' => 'required|string', 'overrides' => 'array'];",
        [new BodyParameter(name: 'overrides', type: 'string', description: 'Just this endpoint.')],
    );

    $schema = $body['content']['application/json']['schema'];

    expect($schema)->not->toHaveKey('$ref')
        ->and($schemas)->toBe([])
        ->and($schema['properties']['overrides'])->toBe([
            'type' => 'string',
            'description' => 'Just this endpoint.',
        ])
        // The type-level child is still there, under the object the type declared, because the type's
        // declarations were written into the schema the operation then dereferenced.
        ->and($schema['properties']['nickname'])->toBe(['type' => 'string']);
});

/**
 * The class, not the instance. Twenty-one class-target attributes are read somewhere other than a type,
 * and PHP accepts every one of them at that declaration site; before this the whole set was dropped
 * with no report, so a typo and an unusable fact looked the same.
 */
it('reports a declaration nothing reads on a type, once per attribute', function (): void {
    [, , $codes] = runFormRequestBody(
        MisplacedDeclarationRequest::class,
        "return ['nickname' => 'required|string'];",
    );

    // Two attributes, three declarations: a repeatable one is one mistake, not two reports.
    expect(array_values(array_filter($codes, static fn (string $code): bool => $code === 'attribute.schema-class-unread')))
        ->toHaveCount(2);
});

/**
 * Only the ROOT `required` list says the body is one the server insists on, and a declaration can make
 * a field required several levels below it. Left to travel no further than the node it was written to,
 * the document would say a body carrying a required member may be left off entirely.
 */
it('marks the body required when a type-level declaration requires a field inside it', function (): void {
    [$body, $schemas] = runFormRequestBody(
        NestedRequiredRequest::class,
        "return ['nickname' => 'string'];",
    );

    expect($body['required'])->toBeTrue()
        ->and($schemas['NestedRequiredRequest']['properties']['meta']['required'])->toBe(['token'])
        // ...and nothing invented a top-level requirement to say it with.
        ->and($schemas['NestedRequiredRequest'])->not->toHaveKey('required');
});
