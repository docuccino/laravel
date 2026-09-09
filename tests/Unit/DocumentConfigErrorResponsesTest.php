<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Registry\ConfigDiagnostics;

/*
 * `error_responses` is read at TWO sites: DocumentConfigFactory, which resolves the strategy every error
 * producer gates on, and ConfigDiagnostics, which reports a value naming no strategy. Covering both is
 * not the same as the two agreeing, and they did not: a present-but-null key resolved to `none` at the
 * factory — dropping every 4xx and 5xx from the document — while the diagnostic stayed quiet because the
 * value was null. So the rule is stated here as rows rather than read back off either reader, and every
 * row asserts both answers at once.
 *
 * The rule: only an ABSENT key falls back to `none`. A key that is PRESENT and holds anything but the
 * literal `none` is read as `default` and reported, because the alternative is a document that silently
 * says this API returns no errors at all.
 */

/** The strategy the `default` document resolves to when `error_responses` holds $value. */
function resolvedErrorResponses(mixed $value): string
{
    /** @var array<string, mixed> $raw */
    $raw = documentSettings();
    $raw['error_responses'] = $value;

    return app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton')->errorResponses;
}

/**
 * The `config.unknown-value` codes raised for a document whose `error_responses` holds $value.
 *
 * @return list<string>
 */
function errorResponsesDiagnosticCodes(mixed $value): array
{
    /** @var array<string, mixed> $raw */
    $raw = documentSettings();
    $raw['error_responses'] = $value;
    $document = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    return array_values(array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        array_filter(
            ConfigDiagnostics::for($document),
            static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'config.unknown-value',
        ),
    ));
}

it('resolves a present error_responses key, and says so wherever it is not one of the two', function (mixed $value, string $strategy, bool $reported): void {
    expect(resolvedErrorResponses($value))->toBe($strategy)
        ->and(errorResponsesDiagnosticCodes($value))->toBe($reported ? ['config.unknown-value'] : []);
})->with([
    // The two the key accepts. Neither is reported, and each resolves to itself.
    'the shipped strategy' => ['default', 'default', false],
    'the opt-out' => ['none', 'none', false],
    // A key written with nothing after the colon, which YAML reads as null. The whole reason absence
    // and null are read apart: `none` here would take every error response out of a document nobody
    // asked to have them removed from, and an isset-based fallback could not report itself either.
    'a key with nothing after the colon' => [null, 'default', true],
    // Everything else a present key can hold degrades the same way, so no shape is a special case.
    'a misspelling' => ['defualt', 'default', true],
    'a strategy name nothing recognises' => ['problem-details', 'default', true],
    'the empty string' => ['', 'default', true],
    'a bag where a strategy name belongs' => [['preset' => 'problem-details'], 'default', true],
    'false' => [false, 'default', true],
    'true' => [true, 'default', true],
    'a number' => [0, 'default', true],
]);

it('reads an absent error_responses key as the opt-out, and says nothing about it', function (): void {
    // The documented fallback for a document that never names the key: the shipped file says `default`,
    // and a second document inherits none of the first's configuration — which is why deleting the key
    // is how you get a document with no error responses, and why it is not a misconfiguration to report.
    /** @var array<string, mixed> $raw */
    $raw = documentSettings();
    unset($raw['error_responses']);
    $document = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    expect($document->errorResponses)->toBe('none')
        ->and(array_filter(
            ConfigDiagnostics::for($document),
            static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'config.unknown-value',
        ))->toBe([]);
});

it('warns rather than informs, because the value it names decides every error in the document', function (): void {
    /** @var array<string, mixed> $raw */
    $raw = documentSettings();
    $raw['error_responses'] = null;
    $diagnostics = array_values(array_filter(
        ConfigDiagnostics::for(app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton')),
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'config.unknown-value',
    ));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        // The message has to name the value as the author would recognise it — a key they wrote holding
        // nothing — and the strategy the build settled on, which is the half they can act against.
        ->and($diagnostics[0]->message)->toContain('is empty')
        ->and($diagnostics[0]->message)->toContain('read as "default", its default');
});
