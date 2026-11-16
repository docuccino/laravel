<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\RuleDialect;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Illuminate\Validation\ValidationRuleParser;
use Spatie\LaravelData\Support\Validation\RuleDenormalizer;
use Spatie\LaravelData\Support\Validation\ValidationPath;

/*
 * A rule token's parameters are what the server validates with, so they are read as Laravel's own parser reads
 * them: a CSV row, which keeps an empty entry and lets quotes enclose a comma, except a regex, which is one
 * parameter whatever it holds. Laravel's parser is the reference each row is checked against.
 */
it('reads a token\'s parameters as Laravel parses them', function (string $token, array $parameters): void {
    [, $laravel] = ValidationRuleParser::parse($token);

    expect(RuleParsing::token($token)->parameters)->toBe($parameters)
        ->and($laravel)->toBe($parameters);
})->with([
    'no parameters' => ['required', []],
    'plain values' => ['in:a,b', ['a', 'b']],
    'two bounds' => ['between:1,10', ['1', '10']],
    'an empty last entry' => ['in:1,', ['1', '']],
    'an empty first entry' => ['in:,a', ['', 'a']],
    'quoted values' => ['in:"1",""', ['1', '']],
    'a quoted comma' => ['in:"x,y",z', ['x,y', 'z']],
    'a doubled quote' => ['in:"a""b",c', ['a"b', 'c']],
    'a space inside a value' => ['date_format:Y-m-d H:i', ['Y-m-d H:i']],
    'a regex holding commas and quotes' => ['regex:/^"[a-z]+", "[a-z]{1,3}"$/', ['/^"[a-z]+", "[a-z]{1,3}"$/']],
    'a negated regex holding a comma' => ['not_regex:/a,,b/', ['/a,,b/']],
]);

/*
 * laravel-data splits a `#[Rule]` or `rules()` string itself before Laravel sees it: at every pipe, unless `regex:`
 * appears anywhere in the string, which it then hands over whole. Its own denormalizer is the reference; Laravel
 * then trims each rule's name and skips an empty rule, so those differences are not the server's.
 */
it('splits a rule string where laravel-data splits it', function (string $rules, array $pieces): void {
    $laravelData = array_values(array_filter(
        array_map(trim(...), (new RuleDenormalizer)->execute($rules, ValidationPath::create())),
        static fn (string $piece): bool => $piece !== '',
    ));

    expect(RuleParsing::dataPieces($rules))->toBe($pieces)
        ->and($laravelData)->toBe($pieces)
        ->and(array_map(static fn (ValidationRule $rule): string => $rule->name, RuleParsing::dataTokens($rules)))
        ->toBe(array_map(static fn (string $piece): string => RuleParsing::token($piece)->name, $pieces));
})->with([
    'one rule' => ['max:3', ['max:3']],
    'two rules' => ['string|max:3', ['string', 'max:3']],
    'spaces around each' => [' string | max:3 ', ['string', 'max:3']],
    'an empty rule between two' => ['string||max:3', ['string', 'max:3']],
    'a regex holding a pipe' => ['regex:/^(a|b)$/', ['regex:/^(a|b)$/']],
    'a negated regex holding a pipe' => ['not_regex:/a|b/', ['not_regex:/a|b/']],
    // The string is whole wherever the regex sits in it, so Laravel reads one rule named `string|max`.
    'a regex beside other rules' => ['string|max:3|regex:/^a/', ['string|max:3|regex:/^a/']],
    // The test for a regex is case-sensitive, so an upper-case one is split like anything else.
    'an upper-case regex' => ['string|REGEX:/a/', ['string', 'REGEX:/a/']],
]);

/*
 * Laravel's validator splits a field's rule string at every pipe and takes a string inside a field's list as one
 * rule; laravel-data splits both. Each reader's own parser is the reference.
 */
it('splits a rule string as the reader of its rules array does', function (RuleDialect $dialect, string|array $rules, array $names): void {
    $read = is_string($rules) ? $dialect->field($rules) : array_merge(...array_map($dialect->listed(...), $rules));

    $reference = $dialect === RuleDialect::Laravel
        ? (new ValidationRuleParser([]))->explode(['field' => $rules])->rules['field']
        : (new RuleDenormalizer)->execute($rules, ValidationPath::create());

    expect(array_map(static fn (ValidationRule $rule): string => $rule->name, $read))->toBe($names)
        ->and(array_map(static fn (string $rule): string => RuleParsing::token($rule)->name, $reference))->toBe($names);
})->with([
    'Laravel, a field\'s string' => [RuleDialect::Laravel, 'string|max:3', ['string', 'max']],
    'Laravel, a string in a list' => [RuleDialect::Laravel, ['string|max:3', 'min:2'], ['string|max', 'min']],
    'laravel-data, a field\'s string' => [RuleDialect::LaravelData, 'string|max:3', ['string', 'max']],
    'laravel-data, a string in a list' => [RuleDialect::LaravelData, ['string|max:3', 'min:2'], ['string', 'max', 'min']],
    'laravel-data, a regex holding a pipe' => [RuleDialect::LaravelData, 'regex:/^(a|b)$/', ['regex']],
]);
