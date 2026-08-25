<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Spatie\LaravelData\Attributes\Validation\ValidationAttribute;

/*
 * The guard behind the reflector's spatie-attribute → Laravel-rule table. The table is a curated floor
 * by design — an attribute it does not name degrades honestly — so completeness is not the claim here.
 * What IS claimed is that every row it does name is live and right, and neither can be checked against a
 * dataset: `'Ip' => 'ip'` matched nothing for as long as it shipped, because the class spatie declares is
 * `IP`, and the fixture that "proved" the row imported the misspelling too.
 */

/**
 * Every validation attribute the installed package ships, by DECLARED short name → the Laravel rule its
 * own `keyword()` names. Reflection reports the declared spelling, and PHP class names are
 * case-insensitive, so the file basename is the only case-sensitive source of truth there is.
 *
 * @return array<string, string>
 */
function shippedValidationKeywords(): array
{
    $directory = dirname((string) (new ReflectionClass(ValidationAttribute::class))->getFileName());

    $keywords = [];
    foreach (glob($directory.'/*.php') ?: [] as $file) {
        $short = basename($file, '.php');
        $fqcn = 'Spatie\\LaravelData\\Attributes\\Validation\\'.$short;

        if (! class_exists($fqcn) || (new ReflectionClass($fqcn))->isAbstract() || ! method_exists($fqcn, 'keyword')) {
            continue;
        }

        $keywords[$short] = (string) $fqcn::keyword();
    }

    return $keywords;
}

/**
 * The reflector's rule table. Read off the constant rather than published as API: it is integration
 * internals, and the only reader that needs it is this guard.
 *
 * @return array<string, string>
 */
function reflectorRuleMap(): array
{
    /** @var array<string, string> $map */
    $map = (new ReflectionClass(DataClassReflector::class))->getConstant('RULE_MAP');

    return $map;
}

it('scans a plausible number of shipped validation attributes', function (): void {
    // A scan that matched nothing would pass both assertions below it. spatie has shipped ~90 of these
    // for the whole of v4; the table names ~35 of them.
    expect(count(shippedValidationKeywords()))->toBeGreaterThanOrEqual(80)
        ->and(count(reflectorRuleMap()))->toBeGreaterThanOrEqual(30);
});

it('names a shipped attribute in every row, under the spelling spatie declares', function (): void {
    expect(array_values(array_diff(array_keys(reflectorRuleMap()), array_keys(shippedValidationKeywords()))))
        ->toBe([]);
});

it('gives every row the rule the attribute itself names', function (): void {
    // spatie publishes the Laravel rule on each attribute as `keyword()`, so the table is checkable
    // against the package rather than against someone's memory of Laravel's rule names.
    $shipped = shippedValidationKeywords();

    $wrong = [];
    foreach (reflectorRuleMap() as $short => $rule) {
        if (isset($shipped[$short]) && $shipped[$short] !== $rule) {
            $wrong[] = $short.': mapped to '.$rule.', spatie names '.$shipped[$short];
        }
    }

    expect($wrong)->toBe([]);
});
