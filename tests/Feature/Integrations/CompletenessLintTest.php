<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Illuminate\Routing\Router;

/**
 * Real-path coverage for the three completeness lints through the full pipeline: the config bags map
 * onto the rules, the defaults are the measured ones (prose off, the other two on and silent on the
 * workbench), and none of them moves a byte of the emitted document.
 */
it('says nothing on the workbench by default', function (): void {
    bindStubEngine();
    $result = generateDocument();

    expect(diagnosticsCoded($result->diagnostics, 'lint.missing-description'))->toBe([])
        ->and(diagnosticsCoded($result->diagnostics, 'lint.operation-id-style'))->toBe([])
        ->and(diagnosticsCoded($result->diagnostics, 'lint.undocumented-tag'))->toBe([]);
});

it('reports the workbench operations with no prose once lint.descriptions is enabled', function (): void {
    bindStubEngine();
    $result = generateDocument(function (array $raw): array {
        config()->set('docuccino.lint.descriptions.enabled', true);

        return $raw;
    });

    $findings = diagnosticsCoded($result->diagnostics, 'lint.missing-description');
    $messages = array_map(static fn ($d): string => $d->message, $findings);

    // The measured firing population: two of the workbench's operations, both actionable — a closure
    // route in the app's own routes file, and a controller action the message locates by file and line.
    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toContain('GET /api/ping')
        ->and($messages[1])->toContain('POST /api/tickets')
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[1]->source?->file)->toContain('ValidationController.php');
});

it('silences a finding through the config safelist', function (): void {
    bindStubEngine();
    $result = generateDocument(function (array $raw): array {
        config()->set('docuccino.lint.descriptions.enabled', true);
        config()->set('docuccino.lint.descriptions.allow', ['GET /api/ping', 'POST /api/tickets']);

        return $raw;
    });

    expect(diagnosticsCoded($result->diagnostics, 'lint.missing-description'))->toBe([]);
});

it('warns on a route name a generated client cannot name a method after', function (): void {
    // The default operationId is the route name, so a name nobody thought of as an identifier is where
    // this actually bites. Its own route set, so the workbench goldens stay put.
    $result = localityBuild(static function (Router $router): void {
        $router->get('api/users', static fn (): array => [])->name('list users');
    });

    $findings = diagnosticsCoded($result->diagnostics, 'lint.operation-id-style');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->message)->toContain('"list users"')
        ->and($findings[0]->message)->toContain('GET /api/users')
        ->and($findings[0]->message)->toContain('carries characters outside');
});

it('warns on a tag the document declares no entry for', function (): void {
    bindStubEngine();
    $result = generateDocument(function (array $raw): array {
        config()->set('docuccino.lint.tags.enabled', true);
        $raw['tags']['definitions'] = [['name' => 'Invoices', 'description' => 'Billing documents.']];

        return $raw;
    });

    $messages = array_map(static fn ($d): string => $d->message, diagnosticsCoded($result->diagnostics, 'lint.undocumented-tag'));

    expect($messages)->not->toBeEmpty()
        ->and(implode("\n", $messages))->toContain('"Forms"');
});

it('turns each rule off through its own off-switch', function (string $key, string $code): void {
    bindStubEngine();
    $result = generateDocument(function (array $raw) use ($key): array {
        // Every rule ON but the one under test, so the row proves its switch and not its default.
        foreach (['descriptions', 'operation_ids', 'tags'] as $rule) {
            config()->set('docuccino.lint.'.$rule.'.enabled', $rule !== $key);
        }
        $raw['tags']['definitions'] = [['name' => 'Invoices']];

        return $raw;
    });

    expect(diagnosticsCoded($result->diagnostics, $code))->toBe([]);
})->with([
    'descriptions' => ['descriptions', 'lint.missing-description'],
    'operation ids' => ['operation_ids', 'lint.operation-id-style'],
    'tags' => ['tags', 'lint.undocumented-tag'],
]);

it('moves no byte of the emitted document, whatever the rules are set to', function (): void {
    // One tag declared on both sides, so the tag rule has something to fire on and the only
    // difference between the two builds is which lints ran.
    $declare = static function (array $raw): array {
        $raw['tags']['definitions'] = [['name' => 'Invoices']];

        return $raw;
    };

    bindStubEngine();
    $quiet = generateDocument($declare)->document->toArray();

    $loud = generateDocument(function (array $raw) use ($declare): array {
        config()->set('docuccino.lint.descriptions.enabled', true);
        config()->set('docuccino.lint.tags.enabled', true);
        config()->set('docuccino.lint.operation_ids.enabled', false);

        return $declare($raw);
    })->document->toArray();

    expect(graphDifferences($loud, $quiet))->toBe([]);
});

it('lints a webhook the way it lints a route, and names the lever that renames it', function (): void {
    bindStubEngine();

    $result = generateDocument(withLintWebhooks());

    $findings = diagnosticsCoded($result->diagnostics, 'lint.operation-id-style');

    // On by default, so a #[Webhook] name no client can name a method after is caught out of the box.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->message)->toContain('"1 form submitted!"')
        ->and($findings[0]->message)->toContain('POST webhooks.1 form submitted!')
        ->and($findings[0]->help)->toContain('#[Webhook]')
        // The class the attribute was written on, so the reader goes straight there.
        ->and($findings[0]->source?->file)->toContain('Webhooks/Lint/FormSubmitted.php');
});

it('reports the prose and tag holes a webhook has once their rules are enabled', function (): void {
    bindStubEngine();

    $result = generateDocument(withLintWebhooks(static function (array $raw): array {
        config()->set('docuccino.lint.descriptions.enabled', true);
        config()->set('docuccino.lint.tags.enabled', true);
        $raw['tags']['definitions'] = [['name' => 'Invoices', 'description' => 'Billing documents.']];

        return $raw;
    }));

    $prose = array_map(static fn ($d): string => $d->message, diagnosticsCoded($result->diagnostics, 'lint.missing-description'));
    $tags = array_map(static fn ($d): string => $d->message, diagnosticsCoded($result->diagnostics, 'lint.undocumented-tag'));

    // The webhook with no docblock, alongside the workbench routes that have none.
    expect(implode("\n", $prose))->toContain('POST webhooks.1 form submitted!')
        // …and the tag only the well-formed webhook carries, which nothing else would have seen.
        ->and(implode("\n", $tags))->toContain('"Billing"');
});
