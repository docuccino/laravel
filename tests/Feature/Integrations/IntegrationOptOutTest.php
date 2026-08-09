<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;

/**
 * End-to-end proof of the per-document integration enable/disable gate through the real pipeline: the
 * same workbench route set, documented once with the permission integration disabled and once with it
 * enabled, must differ only in the permission integration's output — and the disabled build must carry
 * the discoverability diagnostic.
 *
 * The workbench opts permission on by default (TestCase), so `enabled => false` here exercises a
 * per-document opt-out over the api/moderated-forms route (`permission:moderate forms,web`).
 */
it('gates the permission integration per document: one document omits x-permissions, another emits it', function (): void {
    bindStubEngine();

    // Document A opts out; document B (the workbench default) keeps permission on.
    $disabled = generateDocument(function (array $raw): array {
        $raw['integrations']['permission']['enabled'] = false;

        return $raw;
    })->document->toArray();
    $enabled = generateDocument()->document->toArray();

    $disabledOperation = $disabled['paths']['/api/moderated-forms']['get'] ?? [];
    $enabledOperation = $enabled['paths']['/api/moderated-forms']['get'] ?? [];

    // A: the structured permission member is absent — the integration contributed nothing.
    expect($disabledOperation)->not->toHaveKey('x-permissions');

    // B: the permission integration documents the requirement. (The route's controller carries a
    // docblock description, which outranks the integration layer, so only x-permissions differs — that
    // structured member is the authoritative signal the gate turns on and off.)
    expect($enabledOperation['x-permissions'])->toBe([
        ['type' => 'permission', 'values' => ['moderate forms'], 'guard' => 'web'],
    ]);
});

it('emits exactly one integration.disabled diagnostic for the opted-out document', function (): void {
    bindStubEngine();

    $result = generateDocument(function (array $raw): array {
        $raw['integrations']['permission']['enabled'] = false;

        return $raw;
    });

    $disabledDiagnostics = array_values(array_filter(
        $result->diagnostics,
        static fn ($diagnostic): bool => $diagnostic->code === 'integration.disabled',
    ));

    expect($disabledDiagnostics)->toHaveCount(1)
        ->and($disabledDiagnostics[0]->severity)->toBe(Severity::Info)
        ->and($disabledDiagnostics[0]->message)->toBe(
            'spatie/laravel-permission detected; the permission integration is disabled (integrations.permission.enabled = false) — its contributions are omitted from this document',
        );
});

it('emits no integration.disabled diagnostic when every installed integration is enabled', function (): void {
    bindStubEngine();

    // The workbench default enables permission (TestCase) and every other integration defaults on.
    $result = generateDocument();

    $disabledDiagnostics = array_filter(
        $result->diagnostics,
        static fn ($diagnostic): bool => $diagnostic->code === 'integration.disabled',
    );

    expect($disabledDiagnostics)->toBe([]);
});
