<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Validation\CustomRuleReader;
use Docuccino\Laravel\Tests\Fixtures\Rules\BankReference;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Docuccino\Laravel\Tests\Fixtures\Rules\VendorCurrencyRule;

/**
 * The class-level read: an annotated rule contributes rules, an unannotated one contributes none, and
 * the declaring file always comes back so the fragment cache tracks the class either way.
 */
it('reads an annotated rule class into rules plus its file', function (): void {
    $facts = (new CustomRuleReader)->read(BankReference::class);

    $names = array_map(static fn ($rule): string => $rule->name, $facts->rules);

    expect($names)->toBe(['string', 'regex', 'min', 'max', 'format', 'description', 'example'])
        ->and($facts->file)->toBe((new ReflectionClass(BankReference::class))->getFileName());
});

it('reads a rule class that implements no Laravel interface — the attribute is the contract', function (): void {
    $facts = (new CustomRuleReader)->read(VendorCurrencyRule::class);

    expect(array_map(static fn ($rule): string => $rule->name, $facts->rules))
        ->toBe(['string', 'in', 'description']);
});

it('returns no rules for an unannotated rule class, but still its file', function (): void {
    $facts = (new CustomRuleReader)->read(OpaqueCheck::class);

    // The file is recorded so ADDING the attribute later invalidates the cached fragment.
    expect($facts->rules)->toBe([])
        ->and($facts->file)->toBe((new ReflectionClass(OpaqueCheck::class))->getFileName());
});

it('degrades to no facts for a class that does not exist', function (): void {
    $facts = (new CustomRuleReader)->read('App\\Rules\\NotHere');

    expect($facts->rules)->toBe([])
        ->and($facts->file)->toBeNull();
});
