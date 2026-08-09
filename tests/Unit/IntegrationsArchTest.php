<?php

declare(strict_types=1);

/**
 * The dogfooding rule (design §6, arch-enforced): built-in integrations under
 * `Docuccino\Laravel\Integrations\*` may consume only the public extension surface — the core
 * extension contracts, the type/rule/validation value objects, drafts, inference, diagnostics and
 * provenance — plus the framework (Illuminate) and the shared php-parser boundary library. They must
 * never reach into a core internal (Document, Identity, Emit, Canonical, Overlay, the core Validator)
 * or the adapter's own pipeline/registry/routing wiring. A new integration is thus forced to go
 * through the same public API a third-party package would.
 */
arch('built-in integrations consume only the public extension surface')
    ->expect('Docuccino\Laravel\Integrations')
    ->toOnlyUse([
        'Docuccino\Core\Extensions\Contracts',
        // Schema + Validation are allow-listed at CLASS granularity, not namespace: both namespaces
        // contain an @internal class (Schema\SchemaConverter, Validation\FieldNode) that the frozen
        // extension-author surface must NOT silently expose. Adding a class here is a deliberate
        // widening of the public surface — never add an @internal one.
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
        // The document draft handed to a DocumentTransformer::transform() — already public
        // extension-author surface (it is the parameter type of the public DocumentTransformer contract,
        // and is not @internal). An integration that contributes a whole-document diagnostic (the
        // inferred-handler render-callback skip report) implements that contract and so references it.
        // WIDENING EXPLICITLY APPROVED BY THE MAINTAINER, 2026-08-07: the standing rule is that an arch/PHPStan allow-list is never
        // widened without explicit human approval, so this entry carries its sign-off inline.
        'Docuccino\Core\Extensions\Document\UirDocumentDraft',
        'Docuccino\Core\Draft',
        'Docuccino\Core\Inference',
        // Patch is allow-listed at CLASS granularity, not namespace: it contains @internal classes
        // (PatchGuard, FieldState) the frozen extension-author surface must NOT silently expose.
        // Integrations write provenance via Contribution and delete via Remove — nothing else.
        'Docuccino\Core\Patch\Contribution',
        'Docuccino\Core\Patch\Remove',
        'Docuccino\Core\Diagnostics',
        'Docuccino\Core\Provenance',
        'Docuccino\Attributes',
        'Docuccino\Laravel\Integrations',
        'Illuminate',
        'PhpParser',
        // A pure, stable string helper (last namespace segment of an FQCN). Integrations short an
        // FQCN for a component name identically to the core; allow-listing the single class beats
        // each integration inlining a private copy (Tom's standing correction).
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
 * The reverse direction (arch review PIN 1): the adapter's built-in extensions and its routing/support
 * wiring must NOT reach into an integration, or a DISABLED integration could still shape output through
 * a static call the per-document toggle never gates. Everything an integration contributes flows the
 * other way — through the gated context chains (response-analysis target, response-status resolver,
 * payload media-type resolver, route-binding schema resolver) and the exception-mapper chain. The
 * Registry is the one sanctioned place that wires integrations into the resolved set, so it is exempt.
 * With the environment-digest seam landed (A4), `Docuccino\Laravel\Pipeline` no longer imports
 * `InferredHandler\HandlerReflector` for the cache digest — it reads the gated
 * EnvironmentDigestContributor chain instead — so it joins this rule.
 */
arch('adapter built-in extensions never reach into integrations')
    ->expect('Docuccino\Laravel\Extensions')
    ->not->toUse('Docuccino\Laravel\Integrations');

arch('routing, pipeline and support wiring never reach into integrations')
    ->expect(['Docuccino\Laravel\Routing', 'Docuccino\Laravel\Pipeline', 'Docuccino\Laravel\Support'])
    ->not->toUse('Docuccino\Laravel\Integrations');
