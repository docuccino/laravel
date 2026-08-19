<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\CustomRuleRequest;
use Docuccino\Laravel\Tests\Support\RulesTraceScript;

/**
 * A rule that spreads its values in from somewhere the build cannot read states MORE legal values than the
 * document can. Publishing the half that is written makes a generated client reject what the API accepts,
 * so the constraint is left off entirely — and left off quietly is a document that is merely vaguer than
 * the code, which nobody would ever find. `validation.rule-values-unread` is where the author finds it.
 */
function unreadValuesContext(string $symbol, string $snippet): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/listings'),
        actionRef: new ActionRef('', 'App\\ListingController', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(traces: [$symbol => RulesTraceScript::forPhp($snippet)]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );
}

it('keeps the rules it read off a FormRequest field, drops the short value list, and says so', function (): void {
    $context = unreadValuesContext(
        CustomRuleRequest::class.'::rules',
        <<<'PHP'
        return [
            'status' => ['required', \Illuminate\Validation\Rule::in('any', ...$this->statuses())],
            'visibility' => ['required', \Illuminate\Validation\Rule::in('public', 'private')],
        ];
        PHP,
    );

    $rules = (new RulesFromClass)->analyse($context, CustomRuleRequest::class);
    $names = [];
    foreach (($rules?->fields ?? []) as $field => $fieldRules) {
        $names[$field] = array_map(static fn ($rule): string => $rule->name, $fieldRules);
    }

    // `status` keeps what is true of it and loses only the list that would have been short; `visibility`
    // writes everything at the rule and is untouched.
    expect($names)->toBe([
        'status' => ['required'],
        'visibility' => ['required', 'in'],
    ]);

    $diagnostics = array_values(array_filter(
        $context->components->diagnostics(),
        static fn ($d): bool => $d->code === 'validation.rule-values-unread',
    ));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity->value)->toBe('info')
        ->and($diagnostics[0]->message)->toContain('"status"')
        ->and($diagnostics[0]->message)->toContain(CustomRuleRequest::class)
        ->and($diagnostics[0]->help)->toContain('reject a value the API accepts');

    // Not the unrecoverable one: the field DID recover rules, which is a different loss and a different
    // thing to do about it.
    expect(array_filter(
        $context->components->diagnostics(),
        static fn ($d): bool => $d->code === 'validation.rule-unrecoverable',
    ))->toBe([]);
});

it('reports the same loss on an inline validate(), against the route it happened on', function (): void {
    $context = unreadValuesContext(
        'App\\ListingController::store',
        <<<'PHP'
        $request->validate([
            'status' => ['required', \Illuminate\Validation\Rule::in('any', ...$this->statuses())],
        ]);
        PHP,
    );

    (new ValidationRequestExtension)->handle(new OperationDraft, $context);

    $diagnostics = array_values(array_filter(
        $context->components->diagnostics(),
        static fn ($d): bool => $d->code === 'validation.rule-values-unread',
    ));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('Inline validation field "status"')
        ->and($diagnostics[0]->routeSignature)->toBe($context->route->signature());
});

it('reports nothing where every value is written at the rule', function (): void {
    $context = unreadValuesContext(
        'App\\ListingController::store',
        <<<'PHP'
        $request->validate([
            'status' => ['required', \Illuminate\Validation\Rule::in('open', 'closed')],
        ]);
        PHP,
    );

    (new ValidationRequestExtension)->handle(new OperationDraft, $context);

    expect(array_map(
        static fn ($d): string => $d->code,
        $context->components->diagnostics(),
    ))->not->toContain('validation.rule-values-unread');
});
