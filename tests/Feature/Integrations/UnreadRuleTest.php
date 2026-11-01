<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\CustomRuleRequest;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\UnreadRuleData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\UnreadRuleDataController;
use Docuccino\Laravel\Tests\Support\RulesTraceScript;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/*
 * A rule the build can see but not read is never dropped quietly. One Laravel runs as a check can only
 * refuse values, so the field is documented without it — wider than the endpoint, still true — and the
 * author is told which rule. One whose content is unknown can make the field nullable, optional or
 * unchecked, so the rules beside it are not true on their own: the field is published by name with no
 * constraints, optional, and the rule is named.
 */

function unreadRuleContext(string $symbol, string $snippet, array $analyses = [], string $verb = 'POST'): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor([$verb], 'api/payments'),
        actionRef: new ActionRef('', 'App\\PaymentController', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(analyses: $analyses, traces: [$symbol => RulesTraceScript::forPhp($snippet)]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );
}

/** @return list<Diagnostic> */
function unreadRuleNotes(RouteContext $context): array
{
    return array_values(array_filter(
        $context->components->diagnostics(),
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'validation.'),
    ));
}

/** @return array<string, list<string>> */
function unreadRuleNames(?RuleSet $rules): array
{
    return array_map(
        static fn (array $fieldRules): array => array_map(static fn ($rule): string => $rule->name, $fieldRules),
        $rules->fields ?? [],
    );
}

it('is right to keep the rules beside a check and drop the rules beside a conditional, by Laravel', function (): void {
    // The contract behind both answers. Without the check the field accepts a superset of what the
    // endpoint does, so `string` alone is true. A conditional that turns out `nullable` makes the endpoint
    // take null, which a published `string` refuses.
    $samples = ['text', '', null, 7, ['a']];
    foreach ($samples as $value) {
        $full = Validator::make(['token' => $value], ['token' => ['string', new OpaqueCheck]])->passes();
        $published = Validator::make(['token' => $value], ['token' => ['string']])->passes();

        expect(! $full || $published)->toBeTrue();
    }

    expect(Validator::make(['note' => null], ['note' => ['string', Rule::when(true, ['nullable'])]])->passes())->toBeTrue()
        ->and(Validator::make(['note' => null], ['note' => ['string']])->passes())->toBeFalse();
});

it('is right to publish a given-up field optional and unconstrained, whatever the hidden rule turns out to be', function (array $written, string $hidden, array $body): void {
    // The server takes each body below, which the rules written beside the hidden one would refuse on
    // their own. `{}`, not required, refuses none of them. Under `exclude` the key is still accepted, only
    // left out of the validated data, so naming the field stays true there too.
    expect(Validator::make($body, ['note' => [Rule::when(true, [$hidden]), ...$written]])->passes())->toBeTrue()
        ->and(Validator::make($body, ['note' => $written])->passes())->toBeFalse();
})->with([
    'nullable takes null' => [['string'], 'nullable', ['note' => null]],
    'sometimes takes no value' => [['required', 'string'], 'sometimes', []],
    'exclude takes anything' => [['required', 'string'], 'exclude', ['note' => ['not', 'a', 'string']]],
]);

it('documents a FormRequest field without the rule it cannot read, and names the rule', function (): void {
    $context = unreadRuleContext(CustomRuleRequest::class.'::rules', <<<'PHP'
        return [
            'token' => ['required', 'string', new \Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck],
            'slug' => ['required', 'string', static fn (string $attribute, mixed $value, \Closure $fail) => null],
            'member' => ['required', 'integer', new \Docuccino\Laravel\Tests\Fixtures\Rules\TenantMember],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()],
        ];
        PHP);

    $rules = (new RulesFromClass)->analyse($context, CustomRuleRequest::class);

    expect(unreadRuleNames($rules))->toBe([
        'token' => ['required', 'string'],
        'slug' => ['required', 'string'],
        'member' => ['required', 'integer'],
        'password' => ['required', 'string'],
    ]);

    $notes = unreadRuleNotes($context);
    $messages = array_map(static fn (Diagnostic $d): string => $d->message, $notes);

    // `member`'s rule says, with an empty #[RuleSchema], that it has nothing to publish: nothing was lost.
    expect(array_map(static fn (Diagnostic $d): string => $d->code, $notes))->toBe(array_fill(0, 3, 'validation.rule-unrecoverable'))
        ->and(array_map(static fn (Diagnostic $d): string => $d->severity->value, $notes))->toBe(['info', 'info', 'info'])
        ->and($messages[0])->toBe('Validation field "token" on '.CustomRuleRequest::class.' has a rule this build cannot read (new '.OpaqueCheck::class.'), so that rule is left off the request schema; the rest of its rules are documented.')
        ->and($messages[1])->toContain('"slug"')->toContain('(a closure)')
        ->and($messages[2])->toContain('"password"')->toContain('(Password::min(…))')
        ->and($notes[0]->help)->toContain('an empty #[RuleSchema]')
        ->and($notes[0]->routeSignature)->toBe($context->route->signature());
});

it('gives up a FormRequest field whose rule could loosen the rest, and names that rule', function (): void {
    $context = unreadRuleContext(CustomRuleRequest::class.'::rules', <<<'PHP'
        return [
            'note' => ['string', \Illuminate\Validation\Rule::when($this->isMethod('PATCH'), ['nullable'])],
            'tier' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string'],
            'title' => ['required', 'string'],
        ];
        PHP);

    $rules = (new RulesFromClass)->analyse($context, CustomRuleRequest::class);

    // Each keeps its name and loses every rule, so it is published unconstrained and optional.
    expect(unreadRuleNames($rules))->toBe(['note' => [], 'tier' => [], 'title' => ['required', 'string']]);

    $messages = array_map(static fn (Diagnostic $d): string => $d->message, unreadRuleNotes($context));

    expect($messages)->toBe([
        'Validation field "note" on '.CustomRuleRequest::class.' has a rule whose content this build cannot read (Rule::when(…)), which can make the field optional, nullable or excluded; none of its rules are documented, so it is published in the request schema, optional and unconstrained.',
        'Validation field "tier" on '.CustomRuleRequest::class.' has a rule whose content this build cannot read (a value this build cannot read), which can make the field optional, nullable or excluded; none of its rules are documented, so it is published in the request schema, optional and unconstrained.',
    ])
        ->and(unreadRuleNotes($context)[0]->help)->toContain('Rule::when()');
});

it('lets the literal reading restate neither a field the trace gave up nor a rule it could not read', function (): void {
    // The literal reading sees the same list as a constant shape, where a rule object is a bare class and a
    // conditional a ConditionalRules. It used to keep the strings and skip the rest, so it published the
    // `string` the trace had just refused to.
    $shape = new ArrayShapeT([
        new ArrayShapeField('note', new ArrayShapeT([
            new ArrayShapeField(0, new LiteralT('string')),
            new ArrayShapeField(1, new ClassT('Illuminate\\Validation\\ConditionalRules')),
        ], true)),
        new ArrayShapeField('token', new ArrayShapeT([
            new ArrayShapeField(0, new LiteralT('string')),
            new ArrayShapeField(1, new ClassT(OpaqueCheck::class)),
        ], true)),
    ]);
    $analyses = [CustomRuleRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite($shape, new SourceLocation(''))])];

    // Traced: the trace answers for both fields.
    $traced = unreadRuleContext(CustomRuleRequest::class.'::rules', <<<'PHP'
        return [
            'note' => ['string', \Illuminate\Validation\Rule::when($this->isMethod('PATCH'), ['nullable'])],
            'token' => ['string', new \Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck],
        ];
        PHP, $analyses);

    // Assembled in a variable: the trace sees no returned array, and the literal reading answers alone.
    $assembled = unreadRuleContext(CustomRuleRequest::class.'::rules', <<<'PHP'
        $rules = [];
        return $rules;
        PHP, $analyses);

    // Each reading names the conditional as it sees it: the call, or the object it built.
    foreach (['Rule::when(…)' => $traced, 'new Illuminate\\Validation\\ConditionalRules' => $assembled] as $named => $context) {
        $rules = (new RulesFromClass)->analyse($context, CustomRuleRequest::class);

        expect(unreadRuleNames($rules))->toBe(['token' => ['string'], 'note' => []])
            ->and(array_map(static fn (Diagnostic $d): string => $d->message, unreadRuleNotes($context)))->toBe([
                'Validation field "note" on '.CustomRuleRequest::class.' has a rule whose content this build cannot read ('.$named.'), which can make the field optional, nullable or excluded; none of its rules are documented, so it is published in the request schema, optional and unconstrained.',
                'Validation field "token" on '.CustomRuleRequest::class.' has a rule this build cannot read (new '.OpaqueCheck::class.'), so that rule is left off the request schema; the rest of its rules are documented.',
            ]);
    }
});

it('reads a rule list the literal reading alone answers for with the grammar the trace uses', function (): void {
    // An annotated rule object reads; a rule the type does not pin to one string — a ternary of two — is
    // as unknown here as it is to the trace.
    $shape = new ArrayShapeT([
        new ArrayShapeField('reference', new ArrayShapeT([
            new ArrayShapeField(0, new LiteralT('required')),
            new ArrayShapeField(1, new ClassT('Docuccino\\Laravel\\Tests\\Fixtures\\Rules\\BankReference')),
        ], true)),
        new ArrayShapeField('tier', new ArrayShapeT([
            new ArrayShapeField(0, new UnionT([new LiteralT('required'), new LiteralT('sometimes')])),
            new ArrayShapeField(1, new LiteralT('string')),
        ], true)),
        new ArrayShapeField('token', new ArrayShapeT([
            new ArrayShapeField(0, new ClassT(OpaqueCheck::class)),
        ], true)),
    ]);
    $context = unreadRuleContext(CustomRuleRequest::class.'::rules', "\$rules = [];\nreturn \$rules;", [
        CustomRuleRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite($shape, new SourceLocation(''))]),
    ]);

    $rules = (new RulesFromClass)->analyse($context, CustomRuleRequest::class);

    expect(unreadRuleNames($rules))->toBe([
        'reference' => ['required', 'string', 'regex', 'min', 'max', 'format', 'description', 'example'],
        'tier' => [],
    ])
        ->and(array_map(static fn (Diagnostic $d): string => $d->message, unreadRuleNotes($context)))->toBe([
            'Validation field "tier" on '.CustomRuleRequest::class.' has a rule whose content this build cannot read (a value this build cannot read), which can make the field optional, nullable or excluded; none of its rules are documented, so it is published in the request schema, optional and unconstrained.',
            'Validation field "token" on '.CustomRuleRequest::class.' has no statically recoverable rules; it is omitted from the request schema.',
        ])
        ->and($context->dependencyFiles())->toContain((string) (new ReflectionClass('Docuccino\\Laravel\\Tests\\Fixtures\\Rules\\BankReference'))->getFileName());
});

it('reports the same two losses on an inline validate(), and publishes only what is true', function (): void {
    $context = unreadRuleContext('App\\PaymentController::store', <<<'PHP'
        $request->validate([
            'token' => ['required', 'string', new \Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck],
            'note' => ['required', 'string', \Illuminate\Validation\Rule::when($request->isMethod('PATCH'), ['nullable'])],
        ]);
        PHP);

    (new ValidationRequestExtension)->handle($operation = new OperationDraft, $context);

    $body = $operation->freeze()->toArray()['requestBody'] ?? [];
    $schema = is_array($body) ? $body['content']['application/json']['schema'] : [];

    // `note` keeps its name, with no type the endpoint does not insist on, and is not required.
    expect($schema['properties'])->toBe(['token' => ['type' => 'string'], 'note' => []])
        ->and($schema['required'] ?? [])->toBe(['token'])
        ->and(array_map(static fn (Diagnostic $d): string => $d->message, unreadRuleNotes($context)))->toBe([
            'Inline validation field "note" has a rule whose content this build cannot read (Rule::when(…)), which can make the field optional, nullable or excluded; none of its rules are documented, so it is published in the request schema, optional and unconstrained.',
            'Inline validation field "token" has a rule this build cannot read (new '.OpaqueCheck::class.'), so that rule is left off the request schema; the rest of its rules are documented.',
        ]);
});

it('names a given-up field as an optional, unconstrained query parameter on a read verb', function (): void {
    $context = unreadRuleContext('App\\PaymentController::store', <<<'PHP'
        $request->validate([
            'note' => ['required', 'string', \Illuminate\Validation\Rule::when($request->boolean('lax'), ['nullable'])],
        ]);
        PHP, verb: 'GET');

    (new ValidationRequestExtension)->handle($operation = new OperationDraft, $context);

    $parameters = array_map(
        static fn (array $parameter): array => array_intersect_key($parameter, array_flip(['name', 'in', 'required', 'schema'])),
        json_decode((string) json_encode($operation->freeze()->toArray()['parameters'] ?? []), true),
    );

    expect($parameters)->toBe([['name' => 'note', 'in' => 'query', 'required' => false, 'schema' => []]])
        ->and(unreadRuleNotes($context)[0]->message)->toContain('so it is published in the query parameters, optional and unconstrained');
});

it('names a spatie #[Rule(new X)] it cannot read, against the Data property that carries it', function (): void {
    $metadata = new ClassMetadata(UnreadRuleData::class, [
        new PropertyMetadata('token', ScalarT::string()),
        new PropertyMetadata('member', ScalarT::int()),
        new PropertyMetadata('note', ScalarT::string()),
    ]);
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/tokens'),
        actionRef: new ActionRef('', UnreadRuleDataController::class, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [UnreadRuleData::class => $metadata]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension)->handle(new OperationDraft, $context);

    $properties = $context->components->schemas()['UnreadRuleData']['properties'] ?? [];

    // `token` keeps its `max:40`, `member` is annotated as stating nothing, and `note`'s conditional takes
    // its `max:200` with it, leaving what the type says.
    expect($properties['token']['type'] ?? null)->toBe('string')
        ->and($properties['token']['maxLength'] ?? null)->toBe(40)
        ->and($properties['note']['type'] ?? null)->toBe('string')
        ->and($properties['note'] ?? [])->not->toHaveKey('maxLength')
        ->and(array_map(static fn (Diagnostic $d): string => $d->message, unreadRuleNotes($context)))->toBe([
            'Data property '.UnreadRuleData::class.'::$token has a rule this build cannot read (new '.OpaqueCheck::class.'), so that rule is left off the request schema; the rest of its rules are documented.',
            'Data property '.UnreadRuleData::class.'::$note has a rule whose content this build cannot read (new Illuminate\\Validation\\ConditionalRules), which can make the field optional, nullable or excluded; it is documented from its type alone, without the constraints its attributes state.',
        ]);
});

it('names a closure in a spatie #[Rule] as the other rule readers do', function (): void {
    // A closure in an attribute is PHP 8.5 syntax, so the class is compiled here rather than kept as a file.
    $class = 'Docuccino\\Laravel\\Tests\\Fixtures\\SpatieData\\ClosureRuleData';
    if (! class_exists($class)) {
        eval('namespace Docuccino\\Laravel\\Tests\\Fixtures\\SpatieData; final class ClosureRuleData extends \\Spatie\\LaravelData\\Data { public function __construct(#[\\Spatie\\LaravelData\\Attributes\\Validation\\Rule(static function (string $attribute, mixed $value, \\Closure $fail): void {})] public string $slug) {} }');
    }

    $rules = new DataValidationRules;
    $rules->build($class, new ClassMetadata($class, [new PropertyMetadata('slug', ScalarT::string())]), new NullTypeEngine, null, schemaConverter());

    // Laravel runs a closure as a check, so it is left off and the field's other rules stand.
    expect($rules->unreadRuleObjects())->toBe([['key' => 'slug', 'class' => $class, 'property' => 'slug', 'rule' => 'a closure', 'narrows' => true]]);
})->skip(PHP_VERSION_ID < 80500, 'A closure in an attribute argument needs PHP 8.5.');

it('is right to read a Rule static that returns a rule string as that string, by Laravel', function (string $static, array $written, array $body, Closure $admits): void {
    // Laravel runs the string the static returns. Each body passes with it and fails on the rules beside it
    // alone, so leaving the static out as a check would publish a field stricter than the endpoint.
    expect(Validator::make($body, ['note' => [...$written, Rule::{$static}()]])->passes())->toBeTrue()
        ->and(Validator::make($body, ['note' => $written])->passes())->toBeFalse();

    $rules = implode(', ', [...array_map(static fn (string $rule): string => var_export($rule, true), $written), '\\Illuminate\\Validation\\Rule::'.$static.'()']);
    $context = unreadRuleContext('App\\PaymentController::store', "\$request->validate(['note' => [{$rules}]]);");
    (new ValidationRequestExtension)->handle($operation = new OperationDraft, $context);
    $body = $operation->freeze()->toArray()['requestBody'] ?? [];

    expect($admits(is_array($body) ? $body['content']['application/json']['schema'] : []))->toBeTrue()
        ->and(unreadRuleNotes($context))->toBe([]);
})->with([
    'nullable takes null' => ['nullable', ['string'], ['note' => null], static fn (array $schema): bool => $schema['properties']['note']['type'] === ['string', 'null']],
    'sometimes takes no value' => ['sometimes', ['required', 'string'], [], static fn (array $schema): bool => ! in_array('note', $schema['required'] ?? [], true)],
])->skip(! method_exists(Rule::class, 'nullable'), 'This Laravel declares no Rule static that returns a rule string.');

it('publishes every Rule static the installed Laravel declares to return a rule string as that string', function (): void {
    // Read off the installed framework by what each static declares it returns, so one Laravel adds is
    // held to the same answer the day it ships.
    $statics = array_values(array_filter(
        (new ReflectionClass(Rule::class))->getMethods(ReflectionMethod::IS_STATIC),
        static fn (ReflectionMethod $method): bool => ($method->getReturnType()?->__toString() ?? (preg_match('/@return\s+(\S+)/', (string) $method->getDocComment(), $m) === 1 ? $m[1] : '')) === 'string',
    ));

    expect(count($statics))->toBeGreaterThan(15);

    $published = static function (string $rule): array {
        $context = unreadRuleContext('App\\PaymentController::store', "\$request->validate(['note' => ['string', {$rule}], 'title' => 'string']);");
        (new ValidationRequestExtension)->handle($operation = new OperationDraft, $context);

        return [
            $operation->freeze()->toArray()['requestBody'] ?? [],
            array_map(static fn (Diagnostic $d): string => $d->code.' '.$d->message, $context->components->diagnostics()),
        ];
    };

    // The same body and the same notes: a rule the string form leaves unhandled is unhandled here too.
    foreach ($statics as $method) {
        $string = Rule::{$method->getName()}();

        expect($string)->toBeString()
            ->and($published('\\Illuminate\\Validation\\Rule::'.$method->getName().'()'))->toBe($published(var_export($string, true)), $method->getName());
    }
})->skip(! method_exists(Rule::class, 'nullable'), 'This Laravel declares no Rule static that returns a rule string.');
