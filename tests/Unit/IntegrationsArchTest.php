<?php

declare(strict_types=1);

/**
 * The dogfooding rule: built-in integrations under `Docuccino\Laravel\Integrations\*` may consume only
 * the public extension surface — core extension contracts, the type/rule/validation value objects,
 * drafts, inference, diagnostics, provenance — plus Illuminate and the shared php-parser boundary.
 * Never a core internal (Document, Identity, Emit, Canonical, Overlay, the core Validator) or the
 * adapter's own pipeline/registry/routing wiring. That forces a new integration through the same
 * public API a third-party package would use.
 */
arch('built-in integrations consume only the public extension surface')
    ->expect('Docuccino\Laravel\Integrations')
    ->toOnlyUse([
        'Docuccino\Core\Extensions\Contracts',
        // Schema + Validation are allow-listed per class, not per namespace: both contain an @internal
        // class (Schema\SchemaConverter, Validation\FieldNode) the frozen extension-author surface must
        // not silently expose. Adding a class here widens the public surface — never add an @internal one.
        'Docuccino\Core\Extensions\Schema\ComponentHoist',
        'Docuccino\Core\Extensions\Schema\ComponentRegistry',
        'Docuccino\Core\Extensions\Schema\EnumReflection',
        'Docuccino\Core\Extensions\Schema\SchemaIdentity',
        'Docuccino\Core\Extensions\Schema\SchemaResult',
        'Docuccino\Core\Extensions\Validation\RecoveredRequest',
        'Docuccino\Core\Extensions\Validation\ResponseDraftApplier',
        'Docuccino\Core\Extensions\Validation\RuleSet',
        'Docuccino\Core\Extensions\Validation\ValidationField',
        'Docuccino\Core\Extensions\Validation\ValidationRule',
        'Docuccino\Core\Extensions\Context',
        'Docuccino\Core\Extensions\Ordering',
        // The document draft handed to DocumentTransformer::transform() — already public surface (it's
        // that contract's parameter type, and not @internal). An integration contributing a
        // whole-document diagnostic (the inferred-handler render-callback skip report) implements the
        // contract and so references it. This widening was signed off by the maintainer; allow-list
        // entries are never added without that.
        'Docuccino\Core\Extensions\Document\UirDocumentDraft',
        'Docuccino\Core\Draft',
        'Docuccino\Core\Inference',
        // Patch is allow-listed per class too: it holds @internal classes (PatchGuard, FieldState) the
        // frozen surface must not expose. Integrations write provenance via Contribution and delete via
        // Remove — nothing else.
        'Docuccino\Core\Patch\Contribution',
        'Docuccino\Core\Patch\Remove',
        'Docuccino\Core\Diagnostics',
        'Docuccino\Core\Provenance',
        'Docuccino\Attributes',
        'Docuccino\Laravel\Integrations',
        'Illuminate',
        'PhpParser',
        // A pure, stable string helper (last namespace segment of an FQCN). Integrations shorten an FQCN
        // to a component name exactly as core does, so allow-listing the one class beats every
        // integration inlining a private copy.
        'Docuccino\Core\Support\Fqcn',
    ]);

arch('built-in integrations never reach into core internals or adapter wiring')
    ->expect('Docuccino\Laravel\Integrations')
    ->not->toUse([
        'Docuccino\Core\Document',
        'Docuccino\Core\Identity',
        'Docuccino\Core\Emit',
        'Docuccino\Core\Canonical',
        'Docuccino\Core\Overlay',
        'Docuccino\Core\Validation',
        'Docuccino\Core\Pipeline',
        'Docuccino\Laravel\Pipeline',
        'Docuccino\Laravel\Registry',
        'Docuccino\Laravel\Routing',
    ]);

/**
 * The reverse direction: the adapter's built-in extensions and its routing/support wiring must not reach
 * into an integration, or a *disabled* integration could still shape output through a static call the
 * per-document toggle never gates. Everything an integration contributes flows the other way, through
 * the gated context chains (response-analysis target, response-status resolver, payload media-type
 * resolver, route-binding schema resolver) and the exception-mapper chain. The Registry is the one
 * sanctioned place that wires integrations into the resolved set, so it's exempt; the Pipeline reads the
 * gated EnvironmentDigestContributor chain for its cache digest, so it isn't.
 */
arch('adapter built-in extensions never reach into integrations')
    ->expect('Docuccino\Laravel\Extensions')
    ->not->toUse('Docuccino\Laravel\Integrations');

arch('routing, pipeline and support wiring never reach into integrations')
    ->expect(['Docuccino\Laravel\Routing', 'Docuccino\Laravel\Pipeline', 'Docuccino\Laravel\Support'])
    ->not->toUse('Docuccino\Laravel\Integrations');
