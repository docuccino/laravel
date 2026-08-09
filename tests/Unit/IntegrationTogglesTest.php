<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Integrations\Permission\PermissionExtension;
use Docuccino\Laravel\Integrations\Sanctum\SanctumSecurityExtension;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Registry\IntegrationToggles;

/**
 * The per-integration enable/disable gate (feature: per-integration `enabled` config). Every
 * integration's extensions are contributed only when its package is installed AND the document
 * enables it; every integration defaults on when installed EXCEPT `permission`, which is opt-in.
 * The toggle table is the single lookup table — exercised here over every entry.
 *
 * These build DocumentConfig instances directly (bypassing the testbench workbench config, which
 * opts permission on), so the true per-integration defaults are what is under test. In this suite
 * every target package IS installed, so a dropped contribution proves the `enabled` gate, not absence.
 *
 * @param  array<string, mixed>  $integrations
 */
function toggleDocument(array $integrations = []): DocumentConfig
{
    return new DocumentConfig('default', [], raw: $integrations === [] ? [] : ['integrations' => $integrations]);
}

it('drops an integration from a document that disables it (enabled => false contributes nothing)', function (string $key): void {
    $document = toggleDocument([$key => ['enabled' => false]]);

    // The package is installed in this suite, so an empty contribution is the `enabled` gate at work.
    expect(IntegrationToggles::descriptors()[$key]->installed())->toBeTrue()
        ->and(IntegrationToggles::contribute($document, $key))->toBe([]);
})->with(array_map(static fn (string $key): array => [$key], array_keys(IntegrationToggles::descriptors())));

it('applies the per-integration default when the bag is absent: permission OFF, every other integration ON', function (string $key): void {
    $descriptor = IntegrationToggles::descriptors()[$key];
    $contribution = IntegrationToggles::contribute(toggleDocument(), $key);

    if ($key === 'permission') {
        // Sensitive-by-activation: default OFF, so an untouched document documents no permissions.
        expect($descriptor->defaultEnabled)->toBeFalse()
            ->and($contribution)->toBe([]);

        return;
    }

    // Every other integration defaults ON when installed, contributing its full extension set.
    expect($descriptor->defaultEnabled)->toBeTrue()
        ->and($contribution)->toBe($descriptor->extensions())
        ->and($contribution)->not->toBe([]);
})->with(array_map(static fn (string $key): array => [$key], array_keys(IntegrationToggles::descriptors())));

it('contributes the permission integration once the document opts in (enabled => true)', function (): void {
    $document = toggleDocument(['permission' => ['enabled' => true]]);

    expect(IntegrationToggles::contribute($document, 'permission'))->toBe([PermissionExtension::class]);
});

it('emits one opt-in discovery diagnostic for installed-but-default-off permission', function (): void {
    $diagnostics = IntegrationToggles::diagnostics(toggleDocument());

    // Exactly one — permission is the only installed integration this document leaves disabled.
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('integration.disabled')
        ->and($diagnostics[0]->message)->toBe(
            'spatie/laravel-permission detected; the permission integration is opt-in — set integrations.permission.enabled = true to document permission requirements',
        );
});

it('emits an explicit-opt-out diagnostic when a default-on integration is turned off', function (): void {
    // Permission enabled (no permission diagnostic); sanctum explicitly disabled.
    $document = toggleDocument([
        'permission' => ['enabled' => true],
        'sanctum' => ['enabled' => false],
    ]);

    $diagnostics = IntegrationToggles::diagnostics($document);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('integration.disabled')
        ->and($diagnostics[0]->message)->toBe(
            'laravel/sanctum detected; the sanctum integration is disabled (integrations.sanctum.enabled = false) — its contributions are omitted from this document',
        );
});

it('does not diagnose an integration whose package is not installed', function (): void {
    // Force every package-backed probe absent: permission is default-off but, being uninstalled, must
    // NOT nag — an app without the package is never told to enable an integration it cannot use.
    $absent = static fn (string $class): bool => false;

    expect(IntegrationToggles::diagnostics(toggleDocument(), $absent))->toBe([])
        ->and(IntegrationToggles::contribute(toggleDocument(['permission' => ['enabled' => true]]), 'permission', $absent))->toBe([]);
});

it('changes the resolved-extension cache signature when an integration is toggled (fragment-cache soundness)', function (): void {
    $registry = app(ExtensionRegistry::class);

    $withPermission = $registry->resolve(app(), DefaultExtensions::all(toggleDocument(['permission' => ['enabled' => true]])), []);
    $withoutPermission = $registry->resolve(app(), DefaultExtensions::all(toggleDocument(['permission' => ['enabled' => false]])), []);

    // Flipping `enabled` adds/removes PermissionExtension from the resolved set, so the FQCN-list
    // cache-key input differs — the fragment cache cannot serve a stale cross-toggle hit.
    expect($withPermission->classSignature())->toContain(PermissionExtension::class)
        ->and($withoutPermission->classSignature())->not->toContain(PermissionExtension::class)
        ->and($withPermission->cacheSignature())->not->toBe($withoutPermission->cacheSignature());

    // Sanctum is likewise a cache-key discriminator: its security extension leaves the signature.
    $withoutSanctum = $registry->resolve(app(), DefaultExtensions::all(toggleDocument(['sanctum' => ['enabled' => false]])), []);
    expect($withoutSanctum->classSignature())->not->toContain(SanctumSecurityExtension::class);
});
