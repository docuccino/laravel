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
