<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Validation\RuleLosses;
use Docuccino\Laravel\Integrations\Validation\UnreadRule;
use Docuccino\Laravel\Tests\Fixtures\Rules\LegacyStringRule;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rule;

it('classifies a Rule static by what leaving it out does', function (string $method, bool $narrows): void {
    expect(UnreadRule::factoryNarrows('Illuminate\\Validation\\Rule::'.$method))->toBe($narrows)
        ->and(UnreadRule::factoryNarrows('\\Illuminate\\Validation\\Rule::'.strtolower($method)))->toBe($narrows);
})->with([
    // Checks on the value: each builds one of Laravel's rule objects, run as a check.
    'can' => ['can', true], 'in' => ['in', true], 'enum' => ['enum', true], 'requiredIf' => ['requiredIf', true],
    // Later Laravel 12 releases added these; where the installed one lacks them they are macros.
    ...array_filter(
        ['anyOf' => ['anyOf', true], 'dateTime' => ['dateTime', true]],
        static fn (array $row): bool => method_exists(Rule::class, $row[0]),
    ),
    // Rules chosen at runtime, or applied to each item by a callback.
    'when' => ['when', false], 'unless' => ['unless', false], 'forEach' => ['forEach', false], 'compile' => ['compile', false],
    // `exclude` stops the field's other rules running at all.
    'excludeIf' => ['excludeIf', false], 'excludeUnless' => ['excludeUnless', false],
    // Macro plumbing: a call through it builds whatever was registered.
    'macro' => ['macro', false], 'mixin' => ['mixin', false], 'hasMacro' => ['hasMacro', false],
    'flushMacros' => ['flushMacros', false], '__callStatic' => ['__callStatic', false],
]);

it('classifies every static the installed Rule class declares by what it returns', function (): void {
    // Read off the installed framework, so a static Laravel adds is classified the day it ships. Stated
    // from what Laravel does with each return: an object it runs as a check can only refuse values; a
    // conditional, nested or exclude rule can loosen the rules beside it; and a rule string left unread
    // could be `nullable` itself. Only the first keeps the rules beside it.
    $counts = ['check' => 0, 'loosens' => 0, 'other' => 0];
    foreach ((new ReflectionClass(Rule::class))->getMethods(ReflectionMethod::IS_STATIC) as $method) {
        $returns = ltrim($method->getReturnType()?->__toString()
            ?? (preg_match('/@return\s+(\S+)/', (string) $method->getDocComment(), $match) === 1 ? $match[1] : ''), '\\');
        $isClass = class_exists($returns);
        $loosens = $isClass && (is_a($returns, ConditionalRules::class, true)
            || is_a($returns, NestedRules::class, true)
            || str_starts_with(class_basename($returns), 'Exclude'));
        $counts[$isClass ? ($loosens ? 'loosens' : 'check') : 'other']++;

        expect(UnreadRule::factoryNarrows('Illuminate\\Validation\\Rule::'.$method->getName()))->toBe($isClass && ! $loosens, $method->getName());
    }

    // What Laravel 12.0, the lowest supported, declares: 15 checks, 4 loosening, and Macroable's 5.
    expect($counts['check'])->toBeGreaterThanOrEqual(15)
        ->and($counts['loosens'])->toBeGreaterThanOrEqual(4)
        ->and($counts['other'])->toBeGreaterThanOrEqual(5);
});

it('takes any factory on a framework rule class as a check, and any other factory as unknown', function (string $factory, bool $narrows): void {
    expect(UnreadRule::factoryNarrows($factory))->toBe($narrows);
})->with([
    'Password::min' => ['Illuminate\\Validation\\Rules\\Password::min', true],
    'Password::defaults' => ['Illuminate\\Validation\\Rules\\Password::defaults', true],
    'File::types' => ['Illuminate\\Validation\\Rules\\File::types', true],
    'an undeclared Rule static (a macro)' => ['Illuminate\\Validation\\Rule::optionalSlug', false],
    'an application class also named Rule' => ['App\\Validation\\Rule::in', false],
    'an application helper' => ['App\\Support\\Rules::slug', false],
    'a function name with no class' => ['strtolower', false],
]);

it('takes every framework rule object as a check, except the exclude rules', function (): void {
    // Read off the installed framework, so a rule class Laravel adds is classified the day it ships.
    $classes = array_map(
        static fn (string $file): string => 'Illuminate\\Validation\\Rules\\'.basename($file, '.php'),
        glob(dirname((string) (new ReflectionClass(Rule::class))->getFileName()).'/Rules/*.php') ?: [],
    );

    // Laravel 12.0, the lowest supported, ships 18.
    expect(count($classes))->toBeGreaterThanOrEqual(18);
    foreach ($classes as $class) {
        $excludes = str_starts_with(substr($class, (int) strrpos($class, '\\') + 1), 'Exclude');

        expect(UnreadRule::objectNarrows($class))->toBe(! $excludes, $class);
    }
});

it('classifies a rule object by what Laravel does with it', function (string $class, bool $narrows): void {
    expect(UnreadRule::objectNarrows($class))->toBe($narrows);
})->with([
    'a closure, run as a check' => ['Closure', true],
    'a ValidationRule, run as a check' => [OpaqueCheck::class, true],
    'a leading backslash' => ['\\'.OpaqueCheck::class, true],
    'a class this build cannot load' => ['App\\Rules\\Missing', true],
    'conditional rules, expanded at runtime' => ['Illuminate\\Validation\\ConditionalRules', false],
    'nested rules, compiled per item' => ['Illuminate\\Validation\\NestedRules', false],
    'an object Laravel casts to a rule string' => [LegacyStringRule::class, false],
]);

it('names a rule object the way a diagnostic shows it, beside what leaving it out does', function (string $class, array $expected): void {
    expect(UnreadRule::classify($class))->toBe($expected);
})->with([
    'a closure' => ['Closure', ['label' => 'a closure', 'narrows' => true]],
    'a rule object, by the new that built it' => [OpaqueCheck::class, ['label' => 'new '.OpaqueCheck::class, 'narrows' => true]],
    'a leading backslash' => ['\\'.OpaqueCheck::class, ['label' => 'new '.OpaqueCheck::class, 'narrows' => true]],
    'an object Laravel casts to a rule string' => [LegacyStringRule::class, ['label' => 'new '.LegacyStringRule::class, 'narrows' => false]],
]);

it('keeps each rule that only refuses values once, and the first that could loosen the rest', function (): void {
    // A field gives up on its first loosening rule, so a later one names nothing new for the author.
    $losses = new RuleLosses;
    $losses->add('new App\\Rules\\Slug', true);
    $losses->add('Rule::when(…)', false);
    $losses->add('new App\\Rules\\Slug', true);
    $losses->add('a spread', false);
    $losses->add('a closure', true);

    expect($losses->unread())->toBe(['new App\\Rules\\Slug', 'a closure'])
        ->and($losses->opaque())->toBe('Rule::when(…)')
        ->and((new RuleLosses)->opaque())->toBeNull();
});
