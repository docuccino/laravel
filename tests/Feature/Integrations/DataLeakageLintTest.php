<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;

/**
 * Real-path coverage for the data-leakage lint through the full pipeline (design §Phase 4): the
 * workbench's ArticleData carries a `secret` property that is #[Hidden] from OUTPUT but still appears
 * in the documented request body — exactly the accidental exposure the lint exists to catch — so a
 * normal build surfaces a lint.data-leakage warning, and the config safelist / off-switch control it.
 * The lint never mutates the emitted document.
 */
function leakageDiagnostics(?callable $mutateConfig = null): array
{
    bindStubEngine();
    $result = generateDocument($mutateConfig);

    return array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'lint.data-leakage'));
}

it('warns about the ArticleData secret property leaking into the request body by default', function (): void {
    $diagnostics = leakageDiagnostics();

    $messages = implode("\n", array_map(static fn ($d): string => $d->message, $diagnostics));
    expect($diagnostics)->not->toBeEmpty()
        ->and($messages)->toContain('"secret"')
        ->and($messages)->toContain('looks like a secret');

    foreach ($diagnostics as $diagnostic) {
        expect($diagnostic->severity)->toBe(Severity::Warning);
    }
});

it('names the hide that would actually remove this finding', function (): void {
    // ArticleData's `secret` already carries #[Hidden] and is still in the request body, so a help
    // naming only #[Hidden] points at the attribute that is already there and did not help.
    $help = implode("\n", array_map(static fn ($d): string => (string) $d->help, leakageDiagnostics()));

    expect($help)->toContain('#[HiddenFromRequest]');
});

it('does not mutate the emitted document (diagnostics only)', function (): void {
    bindStubEngine();
    $withLint = generateDocument()->document->toArray();

    $withoutLint = generateDocument(function (array $raw): array {
        // Turning the lint off must not change the document body — only the diagnostics.
        config()->set('docuccino.lint.leakage.enabled', false);

        return $raw;
    })->document->toArray();

    expect(graphDifferences($withLint, $withoutLint))->toBe([]);
});

it('silences a property via the config safelist', function (): void {
    $diagnostics = leakageDiagnostics(function (array $raw): array {
        config()->set('docuccino.lint.leakage.allow', ['secret']);

        return $raw;
    });

    $messages = implode("\n", array_map(static fn ($d): string => $d->message, $diagnostics));
    expect($messages)->not->toContain('"secret"');
});

it('turns off entirely via the config off-switch', function (): void {
    $diagnostics = leakageDiagnostics(function (array $raw): array {
        config()->set('docuccino.lint.leakage.enabled', false);

        return $raw;
    });

    expect($diagnostics)->toBe([]);
});

it('flags an extra property via a custom lint.leakage.patterns heuristic', function (): void {
    // `title` is not sensitive by default; a custom pattern merged over the built-in table flags it.
    $diagnostics = leakageDiagnostics(function (array $raw): array {
        config()->set('docuccino.lint.leakage.patterns', ['title' => 'a document title']);

        return $raw;
    });

    $messages = implode("\n", array_map(static fn ($d): string => $d->message, $diagnostics));
    expect($messages)->toContain('"title"')
        ->and($messages)->toContain('looks like a document title')
        // The built-in heuristics still fire alongside the custom one.
        ->and($messages)->toContain('"secret"');
});
