<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use Docuccino\Laravel\Tests\Fixtures\Rules\BankReference;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Workbench\App\Enums\WidgetStatus;

/**
 * Covers the inline-validation folding crux: turning a statically-recovered {@see ConstValue} (a
 * field's rules from `$request->validate([...])`) into rules, including `Rule::*` descriptors that
 * are folded at the AST level before PHPStan would collapse them to a bare object, and `new` rule
 * objects documented by their class's `#[RuleSchema]`.
 */
it('folds a pipe-string value into rules', function (): void {
    $rules = (new ConstValueToRules)->fold(ConstValue::scalar('required|string|max:100'));

    expect(array_map(fn ($r) => [$r->name, $r->parameters], $rules))->toBe([
        ['required', []],
        ['string', []],
        ['max', ['100']],
    ]);
});

it('folds an array of scalar tokens and Rule::enum / Rule::in descriptors', function (): void {
    $value = ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)]),
    ]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules[0]->name)->toBe('required')
        ->and($rules[1]->name)->toBe('enum')
        ->and($rules[1]->parameters)->toBe(['draft', 'published', 'archived'])
        ->and($rules[1]->note)->toBe(WidgetStatus::class);
});

it('folds Rule::in with an array argument into an in rule', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
        ConstValue::array([ConstValue::scalar('a'), ConstValue::scalar('b')]),
    ]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->name)->toBe('in')
        ->and($rules[0]->parameters)->toBe(['a', 'b']);
});

it('folds a choice descriptor whose values do not fold to nothing at all', function (string $method, array $args): void {
    // `Rule::in(MediaCollections::validNames())` / `Rule::enum($runtimeClass)`: the values are only known
    // at runtime. An empty `in`/`enum` rule would be worse than none — it wins the per-field merge over
    // whatever else documents the field, then contributes no keyword — so it folds to nothing and the
    // field is reported unrecoverable instead.
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::'.$method, $args);

    expect((new ConstValueToRules)->fold($value))->toBe([]);
})->with([
    'in with an unfoldable argument' => ['in', []],
    'in with a non-scalar argument' => ['in', [ConstValue::array([])]],
    'enum naming no class' => ['enum', []],
    'enum naming a class that is not an enum' => ['enum', [ConstValue::scalar(ConstValueToRules::class)]],
]);

it('folds a Rule::enum narrowed to nothing to no rule', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('only', [ConstValue::array([ConstValue::scalar('NoSuchCase')])]);

    expect((new ConstValueToRules)->fold($value))->toBe([]);
});

it('keeps exists and unique, which legitimately carry no values', function (string $method): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::'.$method, [ConstValue::scalar('users')]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->name)->toBe($method)
        ->and($rules[0]->parameters)->toBe([]);
})->with(['exists', 'unique']);

it('ignores a descriptor it does not recognise', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::mystery', [ConstValue::scalar('x')]);

    expect((new ConstValueToRules)->fold($value))->toBe([]);
});

it('narrows a Rule::enum case list with an ->only([...]) chain (validation §4 #10)', function (): void {
    // Rule::enum(WidgetStatus::class)->only([WidgetStatus::Draft, WidgetStatus::Published]) — the chain
    // args fold to case NAMES; narrowEnum keeps their backing values in declaration order.
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('only', [ConstValue::array([ConstValue::scalar('Draft'), ConstValue::scalar('Published')])]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->name)->toBe('enum')
        ->and($rules[0]->parameters)->toBe(['draft', 'published'])
        ->and($rules[0]->note)->toBe(WidgetStatus::class);
});

it('narrows a Rule::enum case list with an ->except([...]) chain', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('except', [ConstValue::array([ConstValue::scalar('Archived')])]);

    $rules = (new ConstValueToRules)->fold($value);

    // Declaration order preserved; only the excepted case is dropped.
    expect($rules[0]->parameters)->toBe(['draft', 'published'])
        ->and($rules[0]->note)->toBe(WidgetStatus::class);
});

it('applies chained only() then except() left to right, spread-arg form included', function (): void {
    // only(Draft, Published, Archived) as spread scalar args, then except([Published]).
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('only', [ConstValue::scalar('Draft'), ConstValue::scalar('Published'), ConstValue::scalar('Archived')])
        ->withChainedCall('except', [ConstValue::array([ConstValue::scalar('Published')])]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules[0]->parameters)->toBe(['draft', 'archived']);
});

it('folds a rule object whose class carries a RuleSchema, ignoring its constructor args', function (): void {
    $folder = new ConstValueToRules;

    // `new BankReference('GB')` — the argument is not part of the documented contract.
    $rules = $folder->fold(ConstValue::instance(BankReference::class, [ConstValue::scalar('GB')]));

    expect(array_map(fn ($r) => $r->name, $rules))
        ->toBe(['string', 'regex', 'min', 'max', 'format', 'description', 'example'])
        ->and($folder->dependencyFiles())->toBe([(new ReflectionClass(BankReference::class))->getFileName()]);
});

it('folds a rule object beside string rules in an array-form rule list', function (): void {
    $value = ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::scalar('nullable'),
        ConstValue::instance(BankReference::class, []),
    ]);

    $rules = (new ConstValueToRules)->fold($value);

    expect(array_map(fn ($r) => $r->name, $rules))
        ->toBe(['required', 'nullable', 'string', 'regex', 'min', 'max', 'format', 'description', 'example']);
});

it('folds an unannotated rule object to nothing, leaving the field unrecoverable', function (): void {
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::instance(OpaqueCheck::class, [])))->toBe([])
        // Its file is still a dependency: adding the attribute has to re-document the field.
        ->and($folder->dependencyFiles())->toBe([(new ReflectionClass(OpaqueCheck::class))->getFileName()]);
});

it('folds a rule object of an unknown class to nothing with no dependency', function (): void {
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::instance('App\\Rules\\Missing', [])))->toBe([])
        ->and($folder->dependencyFiles())->toBe([]);
});

it('ignores an unknown chained method and keeps the full case list', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('somethingElse', [ConstValue::array([ConstValue::scalar('Draft')])]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules[0]->parameters)->toBe(['draft', 'published', 'archived']);
});

it('publishes no choice list at all where a rule spreads values in, and says it widened', function (): void {
    // The half that is written is the hazard: `enum: ["any"]` makes a generated client REJECT every
    // status the endpoint accepts. There is no truthful partial answer, so the constraint goes and the
    // rules written beside it stand.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
            ConstValue::scalar('any'),
            ConstValue::spread('unplaceable factory arg'),
        ]),
    ]));

    expect(array_map(static fn ($rule): string => $rule->name, $rules))->toBe(['required'])
        ->and($folder->widened())->toBeTrue();
});

it('keeps every enum case where a narrowing chain spreads its selection in', function (): void {
    // A half-read `->only(...)` drops cases the endpoint accepts, which is narrower than the truth. The
    // full case list is the widening.
    $folder = new ConstValueToRules;
    $descriptor = ConstValue::descriptor(
        'Illuminate\\Validation\\Rule::enum',
        [ConstValue::scalar(WidgetStatus::class)],
    )->withChainedCall('only', [ConstValue::scalar('Active'), ConstValue::spread('unplaceable chained-call arg')]);

    $rules = $folder->fold($descriptor);

    expect(array_map(static fn ($rule): array => $rule->parameters, $rules))
        ->toBe([array_map(static fn (WidgetStatus $case): string => (string) $case->value, WidgetStatus::cases())])
        ->and($folder->widened())->toBeTrue();
});

it('publishes no enum where the class itself is spread in', function (): void {
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::descriptor(
        'Illuminate\\Validation\\Rule::enum',
        [ConstValue::spread('unplaceable factory arg')],
    )))->toBe([])
        ->and($folder->widened())->toBeTrue();
});

it('reports a rules list that spread rules in as widened, keeping the ones written beside them', function (): void {
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::spread('spread array item'),
    ]));

    expect(array_map(static fn ($rule): string => $rule->name, $rules))->toBe(['required'])
        ->and($folder->widened())->toBeTrue();
});

it('reports nothing widened for a fold that lost nothing', function (): void {
    $folder = new ConstValueToRules;
    $folder->fold(ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [ConstValue::scalar('a'), ConstValue::scalar('b')]),
    ]));

    expect($folder->widened())->toBeFalse();
});

it('publishes no choice list where a value comes from a call, and says it widened', function (): void {
    // A spread is only the loudest way a value goes unread. `Rule::in('any', $this->fallback())` folds
    // arg 1 to an `unknown`, and a reader that watched only for spreads published `in: ["any"]` — one
    // legal value out of two, which is the same lie in a quieter shape.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
            ConstValue::scalar('any'),
            ConstValue::unknown('non-constant factory arg'),
        ]),
    ]));

    expect(array_map(static fn ($rule): string => $rule->name, $rules))->toBe(['required'])
        ->and($folder->widened())->toBeTrue();
});

it('keeps every enum case where a narrowing chain names one from a call', function (): void {
    // The narrowing direction, and the dangerous one: a half-read `->only([...])` drops cases the
    // endpoint accepts. The full case list is the widening.
    $folder = new ConstValueToRules;
    $descriptor = ConstValue::descriptor(
        'Illuminate\\Validation\\Rule::enum',
        [ConstValue::scalar(WidgetStatus::class)],
    )->withChainedCall('only', [ConstValue::array([
        ConstValue::scalar('Draft'),
        ConstValue::unknown('non-constant array item'),
    ])]);

    $rules = $folder->fold($descriptor);

    expect($rules[0]->parameters)->toBe(['draft', 'published', 'archived'])
        ->and($folder->widened())->toBeTrue();
});

it('widens for any entry of a value list that names no value', function (ConstValue $entry): void {
    // Every shape the fold can put in a value list, one dataset entry each: whatever the entry is, if it
    // does not name a value then the list is short, and a short list is the one thing never published.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
        ConstValue::scalar('written'),
        $entry,
    ]));

    expect($rules)->toBe([])
        ->and($folder->widened())->toBeTrue();
})->with([
    'a spread' => fn (): ConstValue => ConstValue::spread('unplaceable factory arg'),
    'an argument the fold gave up on' => fn (): ConstValue => ConstValue::unknown('non-constant factory arg'),
    'a nested array' => fn (): ConstValue => ConstValue::array([ConstValue::scalar('a')]),
    'another descriptor' => fn (): ConstValue => ConstValue::descriptor('App\\Choices::all', []),
    'a rule object' => fn (): ConstValue => ConstValue::instance(BankReference::class, []),
    'a bare null' => fn (): ConstValue => ConstValue::scalar(null),
]);

it('publishes an argument list that states nothing as no constraint, and widens nothing', function (array $args): void {
    // `Rule::in()` and `Rule::in([])` state no values at all, so there is nothing to have missed. They
    // stay what they were: no rule, and no report of a loss.
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::descriptor('Illuminate\\Validation\\Rule::in', $args)))->toBe([])
        ->and($folder->widened())->toBeFalse();
})->with([
    'no arguments at all' => [[]],
    'one empty array argument' => [[ConstValue::array([])]],
]);
