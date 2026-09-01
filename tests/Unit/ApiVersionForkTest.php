<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Laravel\Versioning\ApiVersionTransformer;
use Docuccino\Laravel\Versioning\VersionChangeCollector;
use Workbench\App\Data\FormData;
use Workbench\App\Data\FormTreeData;

/**
 * What a scoped change REFUSES to do, and the documents that make it refuse — a schema that contains
 * itself, and a scope that reaches no operation at all. `docs/design/api-versioning.md` says why each
 * is a refusal; this proves the document comes out at the shape the code publishes, with the diagnostic
 * that says so.
 *
 * Built by hand rather than through a build: the document is the input, and the recovery chain publishes
 * neither a self-referential component off a plain data class nor a path item written as a `$ref`, so a
 * fixture app would prove the chain rather than this. An OVERLAY introduces the second — overlays run
 * before transformers. The fork's ordinary branches are proven against real builds in
 * `Feature/Versioning/VersionChangeScopeTest`.
 */
function treeOperation(string $operationId): array
{
    return [
        'x-docuccino' => ['id' => 'op:v1:'.$operationId],
        'operationId' => $operationId,
        'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/FormTree'],
        ]]]],
    ];
}

/**
 * @param  array<string, mixed>  $schemas
 * @return array<string, mixed>
 */
function treeDocument(array $schemas): array
{
    return [
        'info' => ['title' => 'Trees', 'version' => '2026-06-01'],
        'paths' => [
            '/api/versioned-trees' => ['get' => treeOperation('listTrees')],
            '/api/versioned-trees/archived' => ['get' => treeOperation('listArchivedTrees')],
        ],
        'components' => ['schemas' => $schemas],
    ];
}

/**
 * `FormTree` with nothing pointing back at it: the ordinary shape, so the scoped path can be exercised
 * without the cycle guard answering first.
 */
function plainTreeSchemas(): array
{
    return ['FormTree' => [
        'x-docuccino' => ['id' => (new IdentityGenerator)->namedSchemaId(FormTreeData::class)],
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']],
        'required' => ['id', 'title'],
    ]];
}

function selfReferentialDocument(): array
{
    $id = (new IdentityGenerator)->namedSchemaId(FormTreeData::class);

    return treeDocument(['FormTree' => [
        'x-docuccino' => ['id' => $id],
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FormTree']],
        ],
        'required' => ['id', 'title'],
    ]]);
}

/**
 * The same cycle taken the long way round: `FormTree` holds a `Branch`, and a `Branch` holds
 * `FormTree`s. Nothing at either node points at itself, so a guard that only recognised the direct
 * spelling would fork this one — and write a copy of `FormTree` that still `$ref`s the shared
 * `FormTree` two levels down, publishing the old name at the top and today's name inside it.
 */
function indirectlyCyclicDocument(): array
{
    $id = (new IdentityGenerator)->namedSchemaId(FormTreeData::class);

    return treeDocument([
        'FormTree' => [
            'x-docuccino' => ['id' => $id],
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'branch' => ['$ref' => '#/components/schemas/Branch'],
            ],
            'required' => ['id', 'title'],
        ],
        'Branch' => [
            'x-docuccino' => ['id' => 'sch:v1:branchbranchbra'],
            'type' => 'object',
            'properties' => [
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FormTree']],
            ],
        ],
    ]);
}

/**
 * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
 */
function transformedVersion(array $document, string $dir): array
{
    $config = new DocumentConfig(
        key: 'v',
        info: ['title' => 'Trees', 'version' => '2026-06-01'],
        raw: ['info' => ['version' => '2026-06-01'], 'api_version' => ['changes' => ['dir' => $dir]]],
    );

    $draft = new UirDocumentDraft($document);
    $context = new DocumentContext($config, 'doc:v');

    (new ApiVersionTransformer(new VersionChangeCollector(dirname(__DIR__, 2))))->transform($draft, $context);

    return [$draft->toArray(), $context->diagnostics->all()];
}

it('refuses to fork a schema that contains itself, and leaves the operation at the shape the code publishes', function (): void {
    [$document, $diagnostics] = transformedVersion(selfReferentialDocument(), 'tests/Fixtures/Versioning/ScopedSelfReferential');

    $codes = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics);
    $messages = implode("\n", array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $diagnostics));
    $helps = implode("\n", array_map(static fn (Diagnostic $diagnostic): string => (string) $diagnostic->help, $diagnostics));

    // Its own code, not `versioning.change-invalid`: nothing about the declaration is written wrong, so
    // the remedy is the SCOPE, and a help telling the author to fix `from:`/`to:` would send them to a
    // line that is already right.
    expect($codes)->toContain('versioning.scope-unforkable')
        ->and($codes)->not->toContain('versioning.change-invalid')
        ->and($messages)->toContain('would point back at the shared component')
        ->and($messages)->toContain('GET /api/versioned-trees')
        ->and($helps)->toContain('Drop the #[AppliesTo]')
        // Nothing half-written: both operations still share the component, and it still says `title`.
        ->and($document['paths']['/api/versioned-trees']['get']['responses']['200']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/FormTree'])
        ->and(array_keys($document['components']['schemas']['FormTree']['properties']))->toBe(['id', 'title', 'children']);
});

/*
 * The indirect cycle, EXECUTED. `A → B → A` is the shape a guard reading only `$ref`s that name their
 * own component would fork happily, and the copy would publish `name` at the top and `title` two levels
 * down. Hand-tracing `inline()`'s visited set said it was safe; that is a claim until a test runs it.
 */
it('refuses to fork a schema that contains itself the long way round', function (): void {
    [$document, $diagnostics] = transformedVersion(indirectlyCyclicDocument(), 'tests/Fixtures/Versioning/ScopedSelfReferential');

    $codes = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics);

    expect($codes)->toContain('versioning.scope-unforkable')
        ->and(implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $diagnostics)))
        ->toContain('would point back at the shared component')
        // Both operations still share the component, and neither carries a half-expanded copy of it.
        ->and($document['paths']['/api/versioned-trees']['get']['responses']['200']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/FormTree'])
        ->and($document['paths']['/api/versioned-trees/archived']['get']['responses']['200']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/FormTree'])
        ->and(array_keys($document['components']['schemas']['FormTree']['properties']))->toBe(['id', 'title', 'branch']);
});

/*
 * The counter-case, and the reason the guard is not simply "self-referential schemas are never
 * touched": covering every operation renames the component in place, which a self-referential schema
 * takes exactly as well as any other because there is only one copy of it.
 */
it('renames a self-referential component in place when nothing has to fork', function (): void {
    [$document, $diagnostics] = transformedVersion(selfReferentialDocument(), 'tests/Fixtures/Versioning/SelfReferentialEverywhere');

    expect(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics))->toBe([])
        ->and(array_keys($document['components']['schemas']['FormTree']['properties']))->toBe(['id', 'name', 'children'])
        ->and($document['components']['schemas']['FormTree']['required'])->toBe(['id', 'name']);
});

/*
 * A path item written as a `$ref` into `components.pathItems` — which an overlay can introduce, since
 * overlays run before transformers. Read literally it states no method at all, so the operation behind
 * it was invisible to the scope while `componentsReaching()` cheerfully followed the same kind of
 * pointer. The scope then reached only ONE operation, that one was matched, and "every operation matched"
 * renamed the shared component in place — for the operation `#[AppliesTo]` had excluded.
 */
it('sees an operation behind a path item $ref, and forks rather than renaming for it', function (): void {
    $document = treeDocument(plainTreeSchemas());
    $document['paths']['/api/versioned-trees/archived'] = ['$ref' => '#/components/pathItems/ArchivedTrees'];
    $document['components']['pathItems'] = ['ArchivedTrees' => ['get' => treeOperation('listArchivedTrees')]];

    [$transformed, $diagnostics] = transformedVersion($document, 'tests/Fixtures/Versioning/ScopedSelfReferential');

    $inScope = $transformed['paths']['/api/versioned-trees']['get']['responses']['200']['content']['application/json']['schema'];
    $behindRef = $transformed['components']['pathItems']['ArchivedTrees']['get']['responses']['200']['content']['application/json']['schema'];

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe([])
        // The one in scope gets the private copy…
        ->and($inScope)->not->toHaveKey('$ref')
        ->and(array_keys($inScope['properties']))->toBe(['id', 'name'])
        // …and the one behind the pointer keeps the component, which still says `title`.
        ->and($behindRef)->toBe(['$ref' => '#/components/schemas/FormTree'])
        ->and(array_keys($transformed['components']['schemas']['FormTree']['properties']))->toBe(['id', 'title']);
});

/*
 * The other half of the same hole: two use sites addressing ONE path item. A copy written there is
 * written for the operation the scope left out, so it is refused rather than widened.
 */
it('refuses to fork an operation published through a path item the scope does not cover whole', function (): void {
    $document = treeDocument(plainTreeSchemas());
    $document['paths'] = [
        '/api/versioned-trees' => ['$ref' => '#/components/pathItems/Trees'],
        '/api/versioned-trees/archived' => ['$ref' => '#/components/pathItems/Trees'],
    ];
    $document['components']['pathItems'] = ['Trees' => ['get' => treeOperation('listTrees')]];

    [$transformed, $diagnostics] = transformedVersion($document, 'tests/Fixtures/Versioning/ScopedSelfReferential');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.scope-unforkable'])
        ->and($diagnostics[0]->message)->toContain('shares with operations the scope leaves out')
        // Nothing moved: the shared path item still publishes what the code publishes.
        ->and(array_keys($transformed['components']['schemas']['FormTree']['properties']))->toBe(['id', 'title']);
});

/*
 * A scope that reaches no operation is where the document-wide rename used to happen — silently, with no
 * diagnostic, for every operation the scope excluded. It is refused instead, and which of the two things
 * is wrong is said out loud.
 */
it('refuses a scoped rename over a schema no operation publishes, rather than renaming it everywhere', function (): void {
    // The schema is in `components` and nothing reaches it: the operations publish a bare object.
    $document = treeDocument(plainTreeSchemas());
    foreach (array_keys($document['paths']) as $path) {
        $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'] = ['type' => 'object'];
    }

    [$transformed, $diagnostics] = transformedVersion($document, 'tests/Fixtures/Versioning/ScopedSelfReferential');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.scope-matches-nothing'])
        ->and($diagnostics[0]->message)->toContain('for no operation at all')
        ->and(array_keys($transformed['components']['schemas']['FormTree']['properties']))->toBe(['id', 'title']);
});

it('says a scoped change names a shape the document publishes nowhere', function (): void {
    $document = treeDocument([]);
    foreach (array_keys($document['paths']) as $path) {
        $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'] = ['type' => 'object'];
    }

    [, $diagnostics] = transformedVersion($document, 'tests/Fixtures/Versioning/ScopedSelfReferential');

    // The same answer the unscoped path gives, from one mint: the scope decided nothing, the SCHEMA did.
    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.schema-unresolved']);
});

/*
 * The fixpoint, EXERCISED past one pass. A paginated envelope over a resource — `Envelope` holds a
 * `Page`, a `Page` holds the resource — is a shape this product componentizes on purpose, and the
 * components map is walked in NAME order, so `Envelope` is asked whether it reaches `FormTree` before
 * anything has worked out that `Page` does. One pass answers "no" for `Envelope`, the scope reaches no
 * operation at all, and the change is refused for a document that could have been narrowed perfectly.
 */
it('follows a component chain further than one hop, so an envelope over a page still forks', function (): void {
    $document = treeDocument([
        // Insertion order is the unfavourable one, and it is also the order the emitter sorts to.
        'Envelope' => [
            'x-docuccino' => ['id' => 'sch:v1:envelopeenvelop'],
            'type' => 'object',
            'properties' => ['data' => ['$ref' => '#/components/schemas/Page']],
        ],
        'FormTree' => plainTreeSchemas()['FormTree'],
        'Page' => [
            'x-docuccino' => ['id' => 'sch:v1:pagepagepagepag'],
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FormTree']]],
        ],
    ]);

    foreach (array_keys($document['paths']) as $path) {
        $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema']
            = ['$ref' => '#/components/schemas/Envelope'];
    }

    [$transformed, $diagnostics] = transformedVersion($document, 'tests/Fixtures/Versioning/ScopedSelfReferential');

    $schema = static fn (string $path): array => $transformed['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];
    $inScope = $schema('/api/versioned-trees');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe([])
        // The whole chain is expanded at the operation in scope — a copy still pointing at a shared
        // component two hops down would be the shared component.
        ->and($inScope)->not->toHaveKey('$ref')
        ->and(array_keys($inScope['properties']['data']['properties']['items']['items']['properties']))->toBe(['id', 'name'])
        // And everybody else keeps the envelope, whose page still reaches a `FormTree` saying `title`.
        ->and($schema('/api/versioned-trees/archived'))->toBe(['$ref' => '#/components/schemas/Envelope'])
        ->and(array_keys($transformed['components']['schemas']['FormTree']['properties']))->toBe(['id', 'title']);
});

/*
 * What a fork keeps rather than expands, and the reason it matters. The walk expands every `$ref` on the
 * way DOWN to the schema it is copying, because a copy still pointing at the shared component would BE
 * the shared component. A pointer at something the schema merely HOLDS is a different fact: it leads
 * nowhere near the forked schema, so it resolves to the shape this version's document publishes for
 * that component — and left as a pointer it is one more type a generated client can name.
 *
 * This is the case the removal verb makes reachable: `#[RemovedResponseField(type: SomeClass::class)]`
 * writes a `$ref` INTO the copy, after everything on the way down has already been expanded.
 */
function treeHoldingAFormDocument(): array
{
    $identity = new IdentityGenerator;

    return treeDocument([
        'FormTree' => [
            'x-docuccino' => ['id' => $identity->namedSchemaId(FormTreeData::class)],
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']],
            'required' => ['id', 'title'],
        ],
        'FormData' => [
            'x-docuccino' => ['id' => $identity->namedSchemaId(FormData::class)],
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']],
        ],
    ]);
}

it('leaves a pointer at a component the copy merely holds, and forks the rest', function (): void {
    [$document, $diagnostics] = transformedVersion(treeHoldingAFormDocument(), 'tests/Fixtures/Versioning/RemovedScopedRef');

    $inScope = $document['paths']['/api/versioned-trees']['get']['responses']['200']['content']['application/json']['schema'];
    $outOfScope = $document['paths']['/api/versioned-trees/archived']['get']['responses']['200']['content']['application/json']['schema'];

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe([])
        // The copy is a schema of its own — no `$ref` back at `FormTree` — and the field put back into it
        // still names `FormData`, which is a component this document publishes and a client can name.
        ->and($inScope)->not->toHaveKey('$ref')
        ->and(array_keys($inScope['properties']))->toBe(['form', 'id', 'title'])
        ->and($inScope['properties']['form'])->toBe(['$ref' => '#/components/schemas/FormData'])
        // Everybody else is untouched, component included.
        ->and($outOfScope)->toBe(['$ref' => '#/components/schemas/FormTree'])
        ->and(array_keys($document['components']['schemas']['FormTree']['properties']))->toBe(['id', 'title']);
});
