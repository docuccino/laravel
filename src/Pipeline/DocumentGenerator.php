<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Content\ContentCompiler;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDependencies;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Context\TagMapperKeying;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Identity\OperationIdentities;
use Docuccino\Core\Inference\ReportsBootFailure;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Overlay\OverlayDocument;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Pipeline\OperationFragment;
use Docuccino\Core\Pipeline\OperationPipeline;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\SpecValidation\Validator;
use Docuccino\Core\Support\RouteOperationId;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Registry\IntegrationToggles;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Routing\OasPath;
use Docuccino\Laravel\Routing\RouteContextBuilder;
use Docuccino\Laravel\Webhooks\WebhookCollector;
use Docuccino\Laravel\Webhooks\WebhookDeclaration;
use Docuccino\Laravel\Webhooks\WebhookOperationBuilder;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The document pipeline (design §5): resolve extensions late, discover routes, build each operation
 * in phased isolation, stamp identities on the way out of the fragment cache, assemble, apply
 * overlays/transformers, validate against the bundled UIR schema. A broken route yields a skeleton
 * (or is omitted) plus an error diagnostic — never a dead build. {@see DocumentBuilder} is the config-facade callers use to feed it.
 *
 * @internal
 */
final class DocumentGenerator
{
    private readonly FragmentCache $cache;

    public function __construct(
        private readonly ExtensionRegistry $registry,
        private readonly Container $container,
        private readonly RouteContextBuilder $contextBuilder,
        private readonly OperationPipeline $pipeline,
        private readonly Assembler $assembler,
        private readonly Validator $validator,
        private readonly ContentCompiler $contentCompiler,
        private readonly WebhookCollector $webhooks,
        private readonly WebhookOperationBuilder $webhookBuilder,
        private readonly string $generatorVersion,
        ?FragmentCache $cache = null,
        private readonly IdentityGenerator $identity = new IdentityGenerator,
        private readonly OperationIdentities $identities = new OperationIdentities,
        private readonly BuildFingerprint $fingerprint = new BuildFingerprint,
        // Foreign text reaches a diagnostic here, and a diagnostic reaches the document. Without a
        // project root the ladder still runs, so the fallback degrades rather than publishing a path.
        private readonly MessagePaths $messagePaths = new MessagePaths(new RootRelativeSourcePathResolver('')),
    ) {
        $this->cache = $cache ?? FragmentCache::disabled();
    }

    /**
     * @param  list<class-string|object>  $configExtensions
     * @param  list<OverlayDocument>  $overlays
     */
    public function generate(
        DocumentConfig $document,
        TypeEngine $engine,
        array $configExtensions = [],
        array $overlays = [],
    ): GenerationResult {
        $resolved = $this->registry->resolve($this->container, DefaultExtensions::all($document), $configExtensions);

        // Before anything reads them: a note collector's aggregate belongs to ONE document, and a
        // container-scoped one outlives a build, so exporting several documents in one process would
        // otherwise report the first document's findings against the second. Emptying them here also
        // keeps them out of the cache signature below, which digests every resolved instance's own state.
        foreach ($resolved->routeNoteCollectors as $collector) {
            $collector->forget();
        }

        $documentId = $this->identity->documentId($document->key);
        $components = new ComponentRegistry;
        $bag = new DiagnosticCollector;

        // Discoverability: one info diagnostic per installed-but-disabled integration (design §4).
        $bag->addAll(IntegrationToggles::diagnostics($document));
        // Config-shape info diagnostics (design §9) — surfaced instead of silently coerced.
        $bag->addAll(ConfigDiagnostics::for($document));

        $unhashableMapper = TagMapperKeying::unhashableMapper($document);
        if ($this->cache->enabled() && $unhashableMapper !== null) {
            $bag->add(self::unhashableTagMapper($unhashableMapper));
        }

        // An extension whose body no file holds keys nothing, and unlike a tag mapper there is no
        // per-route bag to refuse with: the signature it belongs to keys every fragment of the document,
        // so the refusal is the document's too ({@see ResolvedExtensions::unhashableExtensions()}).
        // Every route then rebuilds and reports exactly what a cold build reports, which is the only
        // reading here that cannot serve an old body's answer back.
        $cache = $this->cache;
        if ($cache->enabled()) {
            $unhashable = $resolved->unhashableExtensions();
            if ($unhashable !== []) {
                $bag->add(self::unhashableExtensions($unhashable));
                $cache = FragmentCache::disabled();
            }
        }

        // The narrative content tree is a document-level input, rebuilt every run and kept OUT of the
        // fragment cache key: fragments never read content, so a prose typo mustn't re-run PHPStan
        // across the whole route set. It reaches output via assembly and the document contentHash.
        [$content, $contentDiagnostics] = $this->contentCompiler->compile($document);
        $bag->addAll($contentDiagnostics);

        // Document config, the tag mapper's own state, booted-app facts and the build environment the
        // engine runs in: the document-level inputs every route's fragment-cache key carries. The
        // config half is the FRAGMENT hash and not the published one — `info` and `api_version` reach
        // no fragment ({@see DocumentConfig::fragmentHash()}). The mapper is here rather than in a
        // route's manifest because what it was constructed with is a value and a manifest holds only
        // files ({@see TagMapperKeying::stateDigest()}).
        $fragmentHash = $document->fragmentHash()
            .'|tags:'.TagMapperKeying::stateDigest($document)
            .'|env:'.$this->environmentDigest($resolved)
            .'|build:'.$this->fingerprint->digest($engine);
        $extensionClasses = $resolved->cacheSignature();
        $documentScope = FragmentCache::documentScope($document, $documentId, $resolved);

        $fragments = [];
        foreach ($this->descriptors($resolved, $document, $bag) as $descriptor) {
            if ($descriptor->fallback) {
                $bag->add(self::fallbackOmitted($descriptor));

                continue;
            }

            // A route registered for several verbs documents one operation per method.
            foreach ($descriptor->documentableMethods() as $method) {
                $fragment = $this->processRoute($descriptor, $method, $document, $documentId, $documentScope, $engine, $resolved, $components, $bag, $fragmentHash, $extensionClasses, $cache);
                if ($fragment !== null) {
                    $fragments[] = $fragment;
                    $bag->addAll($fragment->diagnostics);
                    $this->collectNotes($fragment, $resolved);
                }
            }
        }

        // Webhooks are document-level — no route reaches them — but each one is still an operation, so
        // it travels as a fragment and is cached, restored and reported exactly like a route's.
        [$declarations, $webhookDiagnostics] = $this->webhooks->collect($document);
        $bag->addAll($webhookDiagnostics);

        foreach ($declarations as $declaration) {
            $fragment = $this->processWebhook($declaration, $document, $documentId, $documentScope, $engine, $resolved, $components, $bag, $fragmentHash, $extensionClasses, $cache);
            if ($fragment !== null) {
                $fragments[] = $fragment;
                $bag->addAll($fragment->diagnostics);
                // Everything a fragment carries is drained the same way here as in the route loop
                // above. A webhook has no RouteContext, so nothing writes a note while one is BUILT
                // today — but a fragment restored from the cache carries whatever it was stored with,
                // and a consumer that reads one of an object's members and not the other is where the
                // next producer's finding goes missing without anything failing.
                $this->collectNotes($fragment, $resolved);
            }
        }

        $assembly = $this->assembler->assemble(
            $fragments,
            $document,
            $documentId,
            $components,
            $overlays,
            $resolved->documentTransformers,
            $this->generatorVersion,
            $content,
        );
        $bag->addAll($assembly->diagnostics);

        $validation = $this->validator->validate($assembly->document);
        foreach ($validation->errors as $error) {
            $bag->add(new Diagnostic(
                severity: Severity::Error,
                code: 'document.schema-invalid',
                message: trim($error->pointer.' '.$error->message),
            ));
        }

        return new GenerationResult(UirDocument::fromArray($assembly->document), $bag->sorted(), $assembly->schemaSources);
    }

    /**
     * The one line a document owes its author when its `tags.mapper` resolves to a class no file holds:
     * every operation that mapper tags is rebuilt on every build, because the manifest that decides
     * freshness can only name files ({@see TagMapperKeying}). Nothing about the document is wrong, so
     * this reads like its config neighbours — and it is raised once for the document rather than once per
     * operation, since there is one thing to do about it.
     *
     * Gated on the cache being ON, because with it off there is no rebuild to warn about: an author who
     * never turned the cache on would be reading about a cost they are not paying.
     */
    private static function unhashableTagMapper(string $mapper): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Info,
            code: 'config.tag-mapper-unhashable',
            message: sprintf(
                'tags.mapper resolved to %s, which is declared in no file this build can hash, so the fragment cache cannot keep the operations it tags — they are rebuilt every run.',
                $mapper,
            ),
            help: 'Declare the mapper in a file of its own — an ordinary class the autoloader can find. A class defined by eval() has no file to key a cached answer against.',
        );
    }

    /**
     * The one line a document owes its author when a resolved extension is declared in no file this
     * build can hash: the extension signature keys EVERY fragment, so a body nothing keys leaves the
     * whole document uncacheable, and the cache is turned off for it rather than answering from a key
     * that cannot notice the edit.
     *
     * Raised once, naming every such class, because there is one thing to do about it — and gated on the
     * cache being on, since with it off there is no rebuild to warn about.
     *
     * @param  non-empty-list<class-string>  $classes
     */
    private static function unhashableExtensions(array $classes): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Info,
            code: 'extension.unhashable',
            message: sprintf(
                '%s %s declared in no file this build can hash, so the fragment cache cannot tell whether %s changed and is off for this document — every operation is rebuilt every run.',
                implode(', ', $classes),
                count($classes) === 1 ? 'is' : 'are',
                count($classes) === 1 ? 'it has' : 'they have',
            ),
            help: 'Declare the extension in a file of its own — an ordinary class the autoloader can find. A class defined by eval() has no file to key a cached answer against.',
        );
    }

    /**
     * A catch-all route answers whatever no other route matched, so its template is a placeholder
     * (`/{fallbackPlaceholder}`) rather than a path any client can call. Publishing it would hand a code
     * generator a method for an endpoint that does not exist, and OpenAPI has no "any unmatched path"
     * to publish it as honestly — so it is omitted, and said out loud rather than dropped in silence.
     */
    private static function fallbackOmitted(RouteDescriptor $descriptor): Diagnostic
    {
        $signature = $descriptor->signature();

        return new Diagnostic(
            severity: Severity::Info,
            code: 'route.fallback-omitted',
            message: sprintf('%s is a fallback route, so it is omitted: its path is a placeholder for every unmatched request, not an endpoint.', $signature),
            routeSignature: $signature,
            help: 'Document what a client gets for an unknown path as a 404 response on the operations that can produce one, rather than as an operation of its own.',
        );
    }

    /**
     * Hand one fragment's notes to the collectors that own their channels (design §10) — the ONE path
     * into a document-level aggregate, taken for a fragment the pipeline just built and for one that came
     * back warm alike. That is what makes the summary a document transformer publishes identical either
     * way: nothing writes to a collector while a route builds, so a warm hit is not a missing write.
     *
     * Called for every fragment the document is assembled from — routes in route order, then webhooks in
     * theirs — and each fragment's notes are already sorted, so the aggregate a collector ends up with is
     * a function of the fragment set and not of anything's encounter order. Notes for a channel nothing
     * collects are simply dropped — an integration disabled since the fragment was cached contributes
     * nothing, which is what disabling it means.
     */
    private function collectNotes(OperationFragment $fragment, ResolvedExtensions $resolved): void
    {
        if ($fragment->notes === []) {
            return;
        }

        foreach ($resolved->routeNoteCollectors as $collector) {
            foreach ($fragment->notes[$collector->channel()] ?? [] as $key => $values) {
                $collector->collect($key, $values);
            }
        }
    }

    /**
     * Digests the booted-app facts the fragment cache must key on beyond config, routes and
     * extensions (design §10, A4) — morph maps, guards, registered rate limiters and friends. They're
     * global, so any change can alter any fragment: hence document-level. Each is contributed by its
     * owning ENABLED integration via the gated `EnvironmentDigestContributor` chain, so the pipeline
     * never imports an integration and a disabled one never keys the cache. Segments are keyed by
     * contributor class and sorted (order-independent), and a contributor that can't resolve its fact
     * contributes an empty string, keeping the digest total and deterministic.
     */
    private function environmentDigest(ResolvedExtensions $resolved): string
    {
        $segments = [];
        foreach ($resolved->environmentDigestContributors as $contributor) {
            $segments[$contributor::class] = $contributor->digest();
        }
        ksort($segments);

        $parts = [];
        foreach ($segments as $class => $segment) {
            $parts[] = $class.':'.$segment;
        }

        return hash('sha256', implode("\0", $parts));
    }

    /**
     * The discovered routes, deduped by everything that makes one route a different route: method, URI
     * and the host it is bound to. Two resolvers reporting the same route collapse; two routes that
     * differ only by host do NOT — they are two operations, and the host-less one sorts first so which
     * of them a reader meets first is a fact about the routes, never about registration order.
     *
     * @return list<RouteDescriptor>
     */
    private function descriptors(ResolvedExtensions $resolved, DocumentConfig $document, DiagnosticCollector $bag): array
    {
        $descriptors = [];
        foreach ($resolved->routeResolvers as $resolver) {
            foreach ($resolver->resolve($document) as $descriptor) {
                // NUL sorts below every printable byte, so appending the host leaves the host-less
                // routes' order exactly as it was.
                $key = $descriptor->primaryMethod().' '.$descriptor->uri."\0".($descriptor->domain ?? '');
                $descriptors[$key] ??= $descriptor;
            }

            // A route the built-in resolver EXCLUDED leaves nothing downstream to report on, so what it
            // could not say for itself is drained here — after its walk, which the loop above completes.
            if ($resolver instanceof LaravelRouteResolver) {
                $bag->addAll($resolver->takeDiagnostics());
            }
        }

        ksort($descriptors);

        return array_values($descriptors);
    }

    /**
     * @param  string  $documentScope  {@see FragmentCache::documentScope()}
     * @param  list<string>  $extensionClasses
     * @param  FragmentCache  $cache  this document's cache, which is the disabled one when an extension
     *                                the whole signature is keyed on could not be hashed
     */
    private function processRoute(
        RouteDescriptor $descriptor,
        string $method,
        DocumentConfig $document,
        string $documentId,
        string $documentScope,
        TypeEngine $engine,
        ResolvedExtensions $resolved,
        ComponentRegistry $components,
        DiagnosticCollector $bag,
        string $fragmentHash,
        array $extensionClasses,
        FragmentCache $cache,
    ): ?OperationFragment {
        $path = OasPath::of($descriptor->uri);
        // Naming the specific method keeps multi-method routes' diagnostics distinct.
        $signature = $descriptor->signature($method);
        // Minted here rather than read off the stamped node, so an extension keyed on the operation's
        // identity reads the same string the node ends up carrying instead of deriving a second one.
        $operationId = $this->identity->operationId($documentId, $method, $path, $descriptor->domain);

        // The method is part of the cache key: GET query vs POST body are different fragments with
        // different operation identities.
        $cacheKey = $cache->key($descriptor->cacheSignature().'|'.$method, $documentScope, $fragmentHash, $extensionClasses);
        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            // Warm hit: restore components without waking the type engine (design §10), then stamp
            // through the same call the cold path below uses, so warm ids are cold ids.
            return $this->stamped($this->restoreComponents($cached, $components), $operationId);
        }

        // Snapshot the shared registry: a route that throws mid-build rolls back, so it can't leave
        // orphaned schemas behind for a document it never entered.
        $snapshot = $components->snapshot();

        try {
            $context = $this->contextBuilder->build(
                $descriptor,
                $document,
                $engine,
                $resolved,
                $components,
                $method,
                $operationId,
            );

            if ($context === null) {
                $components->restore($snapshot);

                return $this->onFailure($descriptor, $document, $documentId, $path, $method, 'action could not be reflected', $bag);
            }

            $operation = new OperationDraft;
            $this->pipeline->run($operation, $context, $resolved);
            $diagnostics = $this->analysisDiagnostics($context, $signature);

            $frozen = $operation->freeze();
            [$referencedSchemas, $referencedSchemaIds, $referencedResponses, $referencedSchemaBases, $referencedSecuritySchemes, $referencedResponseBases, $referencedSchemeBases] = $this->componentClosure($frozen->toArray(), $components);

            // What this route's component work reported moves onto the fragment, so a warm hit — which
            // restores components without re-registering anything — still replays it.
            $diagnostics = [...$diagnostics, ...$components->takeDiagnosticsSince($snapshot)];

            // …and so does what it found for the whole document to report, for the same reason.
            $fragment = new OperationFragment($path, $method, $frozen, $signature, $diagnostics, $referencedSchemas, $referencedSchemaIds, $referencedResponses, $context->actionRef->class, $referencedSchemaBases, $referencedSecuritySchemes, $referencedResponseBases, $referencedSchemeBases, $context->notes()->all());
            // Trace-derived dependency files widen the key, so a deep chain invalidates when any file
            // it walked changes (design §10 seam). A reader that found an output-shaping input no file
            // hash can express refuses the store outright, and this route rebuilds every build
            // ({@see RouteDependencies::refuseCaching()}).
            if (! self::degraded($engine) && ! $context->dependencies()->cachingRefused()) {
                $cache->put($cacheKey, $fragment, $context->dependencyFiles());
            }

            // Stamped only AFTER storing: what goes in the cache carries no identity, which is what
            // lets one entry answer for every document that shapes this route alike.
            return $this->stamped($fragment, $operationId);
        } catch (Throwable $exception) {
            $components->restore($snapshot);

            return $this->onFailure($descriptor, $document, $documentId, $path, $method, $exception->getMessage(), $bag);
        }
    }

    /**
     * One webhook, through the same cache, component registry and diagnostic channel a route uses.
     *
     * There is no route and no action to analyse, so the fragment's dependency manifest is what the
     * declaration read plus what the payload conversion recorded through `SchemaContext::dependsOn()`
     * — the class's own hierarchy, and every file the schema it produced was built from. Everything
     * the build reports rides the fragment, so a warm hit says what a cold one said.
     *
     * @param  string  $documentScope  as for {@see processRoute()}
     * @param  list<string>  $extensionClasses
     * @param  FragmentCache  $cache  as for {@see processRoute()}
     */
    private function processWebhook(
        WebhookDeclaration $webhook,
        DocumentConfig $document,
        string $documentId,
        string $documentScope,
        TypeEngine $engine,
        ResolvedExtensions $resolved,
        ComponentRegistry $components,
        DiagnosticCollector $bag,
        string $fragmentHash,
        array $extensionClasses,
        FragmentCache $cache,
    ): ?OperationFragment {
        $operationId = $this->identity->webhookId($documentId, $webhook->method, $webhook->name);

        $cacheKey = $cache->key($webhook->cacheSignature(), $documentScope, $fragmentHash, $extensionClasses);
        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            return $this->stamped($this->restoreComponents($cached, $components), $operationId);
        }

        $snapshot = $components->snapshot();

        try {
            $dependencies = new RouteDependencies;
            $dependencies->addFiles($webhook->files);

            $converter = new SchemaConverter(
                $resolved->typeToSchema,
                $engine,
                $components,
                RepresentationPolicy::fromConfig($document->representation, $document->integration('api_resources')['wrap'] ?? null),
                $dependencies,
            );

            $diagnostics = [];
            $operation = $this->webhookBuilder->build($webhook, $document, $converter, $dependencies, $webhook->source, $diagnostics);

            $frozen = $operation->freeze();
            [$referencedSchemas, $referencedSchemaIds, $referencedResponses, $referencedSchemaBases, $referencedSecuritySchemes, $referencedResponseBases, $referencedSchemeBases] = $this->componentClosure($frozen->toArray(), $components);

            $diagnostics = [...$diagnostics, ...$components->takeDiagnosticsSince($snapshot)];

            $fragment = new OperationFragment(
                path: $webhook->name,
                method: $webhook->method,
                operation: $frozen,
                routeSignature: $webhook->signature(),
                diagnostics: $diagnostics,
                componentSchemas: $referencedSchemas,
                componentSchemaIds: $referencedSchemaIds,
                componentResponses: $referencedResponses,
                componentSchemaBases: $referencedSchemaBases,
                componentSecuritySchemes: $referencedSecuritySchemes,
                componentResponseBases: $referencedResponseBases,
                componentSecuritySchemeBases: $referencedSchemeBases,
                webhook: true,
            );

            if (! self::degraded($engine) && ! $dependencies->cachingRefused()) {
                $files = array_values(array_unique($dependencies->files()));
                sort($files);
                $cache->put($cacheKey, $fragment, $files);
            }

            return $this->stamped($fragment, $operationId);
        } catch (Throwable $exception) {
            $components->restore($snapshot);

            $bag->add(new Diagnostic(
                severity: Severity::Error,
                code: 'webhook.build-failed',
                message: sprintf('Failed to document the webhook "%s": %s', $webhook->name, $this->messagePaths->relative($exception->getMessage())),
                routeSignature: $webhook->signature(),
                help: 'The webhook is not in the document.',
            ));

            return null;
        }
    }

    /**
     * Whether the engine answering this build turned out to be a stand-in for one that could not
     * boot — in which case its fragments must not be stored.
     *
     * {@see BuildFingerprint} names the engine before the first route, and a boot fails on the first
     * question a route asks, so a stored fragment would file a docblock-only answer under the real
     * analyser's key: fix the environment, change no file, and the next build serves the degradation
     * back warm. Not storing beats re-keying the degradation because a fragment records what the
     * engine ANSWERED, and what the analysed code says never depended on whether the analyser could
     * run today. Fragments written earlier in this build consumed no answer — nothing had woken the
     * engine yet — so they stay valid, and only what would have been degraded goes unstored.
     */
    private static function degraded(TypeEngine $engine): bool
    {
        return $engine instanceof ReportsBootFailure && $engine->bootFailure() !== null;
    }

    /**
     * The transitive closure of schema and response components this operation `$ref`s, following refs
     * through the components themselves (design §5 hoist), plus the security schemes its `security`
     * requirement names — which is a name, not a `$ref`, but self-sufficiency means the same thing for
     * it. The full closure — not just what this route registered first — is what makes a cached
     * fragment self-sufficient: deleting the route that happened to own a shared component can't leave
     * a survivor with a dangling `$ref`, and a build where every fragment came back warm still has the
     * schemes its operations authenticate with.
     *
     * @param  array<string, mixed>  $operation
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>, 2: array<string, array<string, mixed>>, 3: array<string, string>, 4: array<string, array<string, mixed>>, 5: array<string, string>, 6: array<string, string>}
     */
    private function componentClosure(array $operation, ComponentRegistry $components): array
    {
        $schemaRegistry = $components->schemas();
        $schemaIdMap = $components->schemaIds();
        $schemaBaseMap = $components->schemaBases();
        $responseRegistry = $components->responses();
        $responseBaseMap = $components->responseBases();
        $schemeBaseMap = $components->securitySchemeBases();

        $schemas = [];
        $schemaIds = [];
        $schemaBases = [];
        $responses = [];
        $responseBases = [];
        $schemeBases = [];
        $seenSchema = [];
        $seenResponse = [];
        $schemaQueue = $this->refs($operation, 'schemas');
        $responseQueue = $this->refs($operation, 'responses');

        // Responses first: pulling in a response can reveal further schema (or response) refs.
        while ($responseQueue !== []) {
            $name = array_shift($responseQueue);
            if (isset($seenResponse[$name]) || ! isset($responseRegistry[$name])) {
                continue;
            }
            $seenResponse[$name] = true;
            $responses[$name] = $responseRegistry[$name];
            if (isset($responseBaseMap[$name])) {
                $responseBases[$name] = $responseBaseMap[$name];
            }

            foreach ($this->refs($responseRegistry[$name], 'responses') as $nested) {
                if (! isset($seenResponse[$nested])) {
                    $responseQueue[] = $nested;
                }
            }
            foreach ($this->refs($responseRegistry[$name], 'schemas') as $schemaRef) {
                $schemaQueue[] = $schemaRef;
            }
        }

        while ($schemaQueue !== []) {
            $name = array_shift($schemaQueue);
            if (isset($seenSchema[$name]) || ! isset($schemaRegistry[$name])) {
                continue;
            }
            $seenSchema[$name] = true;

            $schemas[$name] = $schemaRegistry[$name];
            if (isset($schemaIdMap[$name])) {
                $schemaIds[$name] = $schemaIdMap[$name];
            }
            if (isset($schemaBaseMap[$name])) {
                $schemaBases[$name] = $schemaBaseMap[$name];
            }

            foreach ($this->refs($schemaRegistry[$name], 'schemas') as $nested) {
                if (! isset($seenSchema[$nested])) {
                    $schemaQueue[] = $nested;
                }
            }
        }

        $registered = $components->securitySchemes();
        $securitySchemes = [];
        foreach (self::securityNames($operation) as $name) {
            if (isset($registered[$name])) {
                $securitySchemes[$name] = $registered[$name];
                if (isset($schemeBaseMap[$name])) {
                    $schemeBases[$name] = $schemeBaseMap[$name];
                }
            }
        }

        return [$schemas, $schemaIds, $responses, $schemaBases, $securitySchemes, $responseBases, $schemeBases];
    }

    /**
     * The scheme names an operation's `security` requirement lists. A requirement declared in config
     * rather than registered by an extension is absent from the registry and simply doesn't travel:
     * the assembler puts those back from config on every build, warm or cold.
     *
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private static function securityNames(array $operation): array
    {
        $names = [];

        foreach (is_array($operation['security'] ?? null) ? $operation['security'] : [] as $requirement) {
            foreach (is_array($requirement) ? $requirement : [] as $name => $_scopes) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Component names a node references via `$ref` (`#/components/{$kind}/NAME`), scanned recursively.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private function refs(array $node, string $kind): array
    {
        $prefix = '#/components/'.$kind.'/';

        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, $prefix)) {
                $refs[] = substr($value, strlen($prefix));

                continue;
            }
            if (is_array($value)) {
                foreach ($this->refs($value, $kind) as $ref) {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * Put a cached fragment's components back without waking the type engine, and hand back the
     * fragment on the slots they ACTUALLY landed in. A component the fragment recorded as `Foo` can
     * land as `Foo_2` when a route added since this fragment was cached registered a different class
     * under `Foo` first — and then the restored operation's `$ref` would silently point at the other
     * class's shape. So anything that moved is repointed, in the fragment and in the bodies it just
     * filed.
     *
     * Each schema goes back in under the name it ASKED for rather than the slot it was cached in, so
     * that a suffix is re-earned against this build's registry: deleting the route that owned the
     * plain name has to give it back to the survivor, and nothing invalidates the survivor's fragment.
     * (Which name a schema is finally PUBLISHED under is settled from the finished registry by
     * {@see ComponentNames}, which is why a warm build names things exactly as a cold one does.)
     */
    private function restoreComponents(OperationFragment $fragment, ComponentRegistry $components): OperationFragment
    {
        $schemas = [];
        foreach ($fragment->componentSchemas as $name => $schema) {
            $asked = $fragment->componentSchemaBases[$name] ?? (string) $name;
            $actual = $components->registerSchema($asked, $schema, $fragment->componentSchemaIds[$name] ?? null);
            if ($actual !== (string) $name) {
                $schemas[(string) $name] = $actual;
            }
        }

        $responses = [];
        foreach ($fragment->componentResponses as $name => $response) {
            $actual = $components->registerResponse($name, $response, $fragment->componentResponseBases[$name] ?? null);
            if ($actual !== $name) {
                $responses[$name] = $actual;
            }
        }

        // Security schemes go back in under the name they were cached with: unlike a schema name, that
        // name is vocabulary the registrar chose (`passport`, `sanctumStateful`), never a slot derived
        // from a class. A suffix is still possible — two routes referencing scopes the other doesn't
        // build two different `passport` definitions — so a slot that moved is repointed too.
        $securitySchemes = [];
        foreach ($fragment->componentSecuritySchemes as $name => $scheme) {
            $actual = $components->registerSecurityScheme((string) $name, $scheme, $fragment->componentSecuritySchemeBases[$name] ?? null);
            if ($actual !== (string) $name) {
                $securitySchemes[(string) $name] = $actual;
            }
        }

        if ($schemas === [] && $responses === [] && $securitySchemes === []) {
            return $fragment;
        }

        // The bodies went in carrying the names this fragment was cached with, so re-file them on the
        // ones they now point at. Only components this fragment's identities still hold are touched.
        foreach ($fragment->componentSchemas as $name => $schema) {
            $components->replaceSchema(
                $schemas[$name] ?? (string) $name,
                ComponentNames::rename($schema, $schemas),
                $fragment->componentSchemaIds[$name] ?? null,
            );
        }

        return $fragment->withRenamedComponents($schemas, $responses, $securitySchemes);
    }

    private function onFailure(
        RouteDescriptor $descriptor,
        DocumentConfig $document,
        string $documentId,
        string $path,
        string $method,
        string $reason,
        DiagnosticCollector $bag,
    ): ?OperationFragment {
        $signature = $descriptor->signature($method);

        $bag->add(new Diagnostic(
            severity: Severity::Error,
            code: 'route.build-failed',
            // The reason is usually a thrown message, which is where a machine path gets in; the
            // signature is ours, so it is composed around the scrubbed half, never through it.
            message: sprintf('Failed to document %s: %s', $signature, $this->messagePaths->relative($reason)),
            routeSignature: $signature,
            help: $document->onRouteError === 'omit' ? 'Route omitted from the document.' : 'A skeleton operation was emitted in its place.',
        ));

        if ($document->onRouteError === 'omit') {
            return null;
        }

        $operation = new OperationDraft;
        $operation->setDescription('Documentation could not be generated for this route.', Contribution::fallback());
        // A skeleton is still an operation a client generator will name a method after, so it owes an
        // operationId like any other. The strategies that read the ACTION cannot answer here — the
        // action is what could not be read — so this is the route's own name, or the mint that stands
        // in for one, and never the empty field that leaves the generator to invent a name.
        $operation->setOperationId(
            $descriptor->name === null || $descriptor->name === ''
                ? RouteOperationId::mint($method, $path)
                : $descriptor->name,
            Contribution::fallback(),
        );

        return $this->stamped(
            new OperationFragment($path, $method, $operation->freeze(), $signature),
            $this->identity->operationId($documentId, $method, $path, $descriptor->domain),
        );
    }

    /**
     * The fragment with its identity tree on it. A stored fragment carries none, so every path that
     * hands one on — cold, warm, and the skeleton a failed route leaves behind — comes through here,
     * and warm ids are cold ids by construction.
     */
    private function stamped(OperationFragment $fragment, string $operationId): OperationFragment
    {
        return $fragment->withOperation($this->identities->stamp($fragment->operation, $operationId));
    }

    /**
     * The route's analysis diagnostics, tagged with its signature. They ride on the fragment rather
     * than going straight to the document bag, so a warm cache hit replays them.
     *
     * This is also where the analyser's own words cross into ours, and a failed analysis reports what
     * the underlying tool threw — so the paths in it are relativised before the message goes anywhere,
     * which keeps them out of the cached fragment as well as out of the document.
     *
     * @return list<Diagnostic>
     */
    private function analysisDiagnostics(RouteContext $context, string $signature): array
    {
        $diagnostics = [];
        foreach ($context->analysis()->diagnostics as $diagnostic) {
            $diagnostics[] = new Diagnostic(
                severity: $diagnostic->severity,
                code: $diagnostic->code,
                message: $this->messagePaths->relative($diagnostic->message),
                source: $diagnostic->source,
                routeSignature: $diagnostic->routeSignature ?? $signature,
                help: $diagnostic->help,
            );
        }

        return $diagnostics;
    }
}
