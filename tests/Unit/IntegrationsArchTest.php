<?php

declare(strict_types=1);

/**
 * The dogfooding rule as a LIST: built-in integrations under `Docuccino\Laravel\Integrations\*` may
 * consume only the public extension surface — core extension contracts, the type/rule/validation value
 * objects, drafts, inference, diagnostics, provenance — plus Illuminate and the shared php-parser
 * boundary. Never a core internal (Document, Identity, Emit, Canonical, Overlay, the core Validator) or
 * the adapter's own pipeline/registry/routing wiring. That forces a new integration through the same
 * public API a third-party package would use.
 *
 * Named rather than inlined so the guard below holds the SAME list the rule enforces, never a copy.
 *
 * @return list<string>
 */
function publicExtensionSurface(): array
{
    return [
        'Docuccino\Core\Extensions\Contracts',
        // Schema + Validation are allow-listed per class, not per namespace: both contain an @internal
        // class (Schema\SchemaConverter, Validation\FieldNode) the frozen extension-author surface must
        // not silently expose. Adding a class here widens the public surface — never add an @internal one.
        // This scan sees IMPORTS, so it cannot see an internal type reached through a public method's
        // return value (`$context->converter()->…` imports nothing); core's boundary test guards that
        // half — it is why the converter is handed out as Contracts\TypeSchemaConverter.
        'Docuccino\Core\Extensions\Schema\ComponentHoist',
        'Docuccino\Core\Extensions\Schema\ComponentRegistry',
        // Same exemption as EnumReflection beside it, and for the same reason: the one answer to "which
        // files does this class's declaration span", which an integration needs whenever it records a
        // fact inheritance decided (a static `$wrap`, a `render()` on a parent, an action trait). Every
        // integration inlining its own hierarchy walk is precisely what this list exists to prevent.
        'Docuccino\Core\Extensions\Schema\DeclarationFiles',
        // The summary front for core's one docblock reader, and the one enum-decoration rulebook: the
        // QB integration describing include/sort values must read prose and emit hint keys EXACTLY as
        // core's enum mapper does, or the document carries two decoration standards.
        'Docuccino\Core\Extensions\Schema\DocSummary',
        'Docuccino\Core\Extensions\Schema\EnumDecoration',
        'Docuccino\Core\Extensions\Schema\EnumReflection',
        // The one reader of #[Mock], for the same reason SchemaIdentity is here: every class-hoisting
        // mapper owes the same answer, and an integration rolling its own would fork the attribute's
        // meaning per package.
        // The one reader of a property's docblock `@example`, here for the same reason MockHints is.
        'Docuccino\Core\Extensions\Schema\DocumentedExamples',
        'Docuccino\Core\Extensions\Schema\MockHints',
        // The one reader of the property-target prose attributes, here for the same reason MockHints is.
        'Docuccino\Core\Extensions\Schema\PropertyAnnotations',
        'Docuccino\Core\Extensions\Schema\SchemaIdentity',
        'Docuccino\Core\Extensions\Schema\SchemaResult',
        // The ONE expression of a union, for the reason EnumDecoration is here: an integration that
        // special-cases one member of one — a serialised date-time, a cast column's wire shape — has to
        // contribute it to the same assembly core's union mapper uses. Rolling its own is how a member
        // gets dropped (a `?CarbonImmutable` publishing a non-nullable string) and how one producer
        // expresses nullability in a shape the document's `nullable` policy did not ask for.
        'Docuccino\Core\Extensions\Schema\SchemaUnion',
        // Same exemption, same reason as EnumDecoration above: the ONE reading of an authored example
        // literal against the type it will sit beside. A docblock `@example` and a `#[RuleSchema]`
        // example rule both arrive as text, and the two integrations holding them — spatie-data on the
        // response side, validation on the request side — must read `false` identically, or whichever
        // one rolls its own publishes a string against `type: boolean`.
        'Docuccino\Core\Extensions\Schema\TypedExample',
        // Same exemption, same reason as EnumDecoration: the ONE reading of a body field path, escapes
        // included. A body is assembled from validation keys and patched by attributes that name one, so
        // an integration folding an escaped dot its own way is how a field whose name holds one and a
        // nested pair of fields start being told apart differently on each side of the build.
        'Docuccino\Core\Extensions\Validation\FieldPath',
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
        // Same exemption, same reason: the one PHP-scalar → JSON-Schema `type` table, which the built-in
        // mappers already share. An integration typing a recovered scalar itself (a route-bound column
        // becoming a path parameter) must answer exactly what a response body would, and allow-listing
        // the table is how that stays true — a private copy is how the two drift apart.
        'Docuccino\Core\Extensions\BuiltIn\JsonTypes',
        // Same exemption, same reason: the ONE sample per JSON Schema `format`. An integration filling a
        // member the build could not read has to illustrate a `date-time` with the same value the
        // validation side and the collection exporter do — a private copy is how one producer starts
        // publishing a different email address for the same keyword. It also carries the document's own
        // `representation.examples.formats` overrides at the single lookup, which a copy would not.
        'Docuccino\Core\Support\FormatSamples',
        // Same exemption, same reason: the ONE ladder from a set of numeric bounds to a value they admit.
        // A bound both constrains a value and names one, so `minimum: 5` has a legal illustration where a
        // `pattern` has none — and an integration filling an unread member has to reach for the same one
        // the validated field's example and the collection exporter reach for. A private copy is how one
        // producer starts publishing `0` beside a floor of 5, which is a value that schema rejects.
        'Docuccino\Core\Support\BoundedNumber',
        // Same exemption, same reason as FieldPath above: the ONE reading of a hand-written type string.
        // An attribute's `type:` is folded into a schema by an extension, and the notice that stands
        // down when a declaration has settled a container has to ask that same fold what the type
        // resolves to — a guard recognising fewer spellings than the fold it protects is a hole, not a
        // conservative default.
        'Docuccino\Core\TypeGrammar\TypeStringParser',
        // Same shape of exemption, same reason: a constants-and-pure-predicates class naming the
        // framework classes the adapter matches by string. Its consumers span both sides of the
        // Extensions/Integrations line (the response guard needs the same list the JsonResponse
        // unwrappers do), and the Extensions side cannot reach into Integrations — so it lives under
        // Laravel\Support and is allow-listed here rather than existing twice.
        'Docuccino\Laravel\Support\FrameworkClasses',
        // And again: the one rule for "this value came from the build machine, say so". Its callers sit
        // on both sides of the line — a route's host-bound `servers` URL is an adapter extension's, the
        // OAuth and cookie values are integrations' — and an extension may not import an integration, so
        // the rule lives under Laravel\Support and is allow-listed here rather than existing twice.
        'Docuccino\Laravel\Support\MachineDependentValue',
        // And again: the single statement of how HTML is documented — a `text/html` body whose schema is
        // a plain `string`. A built-in extension says it for a rendered view and this integration says it
        // for a laravel-actions `htmlResponse()`; an extension may not import an integration, so the one
        // sentence lives under Laravel\Support rather than being written twice and drifting.
        'Docuccino\Laravel\Support\HtmlRepresentation',
        // And again: the ONE reading of #[IgnoreResponse]. Every producer that writes a response owes the
        // same answer BEFORE it converts a body, because a response dropped after conversion leaves the
        // components it hoisted behind — and the producers span both sides of the line (inference and the
        // response attributes are extensions, the rate-limit 429 and the paginated rewraps are
        // integrations). An integration rolling its own reading is how one producer stops honouring it.
        'Docuccino\Laravel\Support\IgnoredResponses',
        // And again: the ONE report for an `#[ErrorComponent]` naming something no component key can
        // carry. Two producers read that attribute — a built-in extension on the exception class, this
        // integration on a render method — and a private copy per integration is how one mistake starts
        // being described two ways: the two had already drifted into byte-identical duplicates of a
        // format string, a severity and a help sentence, with one reference row between them. An
        // extension may not import an integration, so the statement lives under Laravel\Support. This
        // widening was signed off by the maintainer; allow-list entries are never added without that.
        'Docuccino\Laravel\Support\ErrorComponentDiagnostic',
        // And again: the ONE minting of SDK member names for a published enum's values. The QB
        // allow-lists and the set of API versions a request may pin are both enums of strings no
        // generator could name a constant after, and a second minting is how one of them starts
        // renaming a neighbour when a value is added. Versioning may not import an integration, so the
        // rule lives under Laravel\Support rather than being written twice.
        'Docuccino\Laravel\Support\ListValueNames',
    ];
}

arch('built-in integrations consume only the public extension surface')
    ->expect('Docuccino\Laravel\Integrations')
    ->toOnlyUse(publicExtensionSurface());

/**
 * The half the allow-list states in prose and nothing was asking: an entry naming a CLASS widens the
 * frozen extension-author surface, so it may not be one core marks `@internal`. Entries have already been
 * un-marked deliberately for exactly this — `Core\Support\Fqcn` says so in its own docblock — and the
 * rule held only for as long as each reviewer remembered it: an `@internal` class added here, or an
 * `@internal` added later to a class already here, was a silent widening either way.
 */
it('allow-lists no class core marks @internal', function (): void {
    $checked = [];
    $internal = [];

    foreach (publicExtensionSurface() as $entry) {
        if (! class_exists($entry) && ! interface_exists($entry)) {
            continue; // a namespace, or a package the adapter allows wholesale
        }

        $checked[] = $entry;

        $doc = (new ReflectionClass($entry))->getDocComment();
        if (is_string($doc) && preg_match('/^\s*\*\s*@internal\b/m', $doc) === 1) {
            $internal[] = $entry;
        }
    }

    // A scan that stopped recognising its shapes must fail rather than pass: the list carries a dozen
    // class entries today, well above this floor.
    expect($internal)->toBe([])
        ->and(count($checked))->toBeGreaterThan(8);
});

arch('built-in integrations never reach into core internals or adapter wiring')
    ->expect('Docuccino\Laravel\Integrations')
    ->not->toUse([
        'Docuccino\Core\Document',
        'Docuccino\Core\Identity',
        'Docuccino\Core\Emit',
        'Docuccino\Core\Canonical',
        'Docuccino\Core\Overlay',
        'Docuccino\Core\SpecValidation',
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

arch('routing, pipeline, versioning and support wiring never reach into integrations')
    ->expect(['Docuccino\Laravel\Routing', 'Docuccino\Laravel\Pipeline', 'Docuccino\Laravel\Support', 'Docuccino\Laravel\Versioning'])
    ->not->toUse('Docuccino\Laravel\Integrations');
