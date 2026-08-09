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
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Overlay\OverlayDocument;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Pipeline\OperationFragment;
use Docuccino\Core\Pipeline\OperationPipeline;
use Docuccino\Core\Validation\Validator;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Registry\IntegrationToggles;
use Docuccino\Laravel\Routing\RouteContextBuilder;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The document pipeline (design §5): resolve extensions late, discover routes, build each
 * operation in phased isolation, assign identities, assemble, apply overlays/transformers,
 * validate against the bundled UIR schema, and return a {@see UirDocument} with deterministic
 * diagnostics. A broken route yields a skeleton (or is omitted) and an error diagnostic — never a
 * dead build.
 *
 * Responsibility split: this is the pipeline itself; {@see DocumentBuilder} is the config-facade
 * callers use to feed it (resolved config, overlays, config extensions).
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
        private readonly string $generatorVersion,
        ?FragmentCache $cache = null,
        private readonly IdentityGenerator $identity = new IdentityGenerator,
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
        $documentId = $this->identity->documentId($document->key);
        $components = new ComponentRegistry;
        $bag = new DiagnosticCollector;

        // One info diagnostic per integration that is installed but this document disabled (default-off
        // permission awaiting opt-in, or an explicit `enabled => false`) — the discoverability signal
        // (design §4). Nothing fires for an integration whose package is absent.
        $bag->addAll(IntegrationToggles::diagnostics($document));

        // Config-shape info diagnostics (design §9): an `enabled` switch on an always-on producer, or
        // an unknown tags.default_strategy — surfaced instead of silently ignored/coerced.
        $bag->addAll(ConfigDiagnostics::for($document));

        // Compile the narrative content tree (design §Narrative content layer): a document-level
        // input assembled fresh each build. It is deliberately KEPT OUT of the fragment cache key —
        // operation fragments never read content, so a prose edit must not invalidate them (at
        // production scale that would re-run out-of-process PHPStan across the whole route set for a typo fix).
        // Content edits are picked up regardless: content flows into the always-fresh assembly step
        // and into the document-level contentHash.
        [$content, $contentDiagnostics] = $this->contentCompiler->compile($document);
        $bag->addAll($contentDiagnostics);

        // Booted-app facts the fragments depend on but that no route file reflects (design §10, A4):
        // each ENABLED integration contributes its output-shaping global state (render-callback set,
        // morph map, QB/paginate parameter names, auth guards + session cookie, Passport scopes/grants/
        // app.url, spatie-data globals, the rate-limiter registration set) through the gated
        // EnvironmentDigestContributor chain. Folded into the document-level cache input because each is
        // global — a change can affect any fragment; a DISABLED integration contributes nothing.
        $configHash = $document->hash().'|env:'.$this->environmentDigest($resolved);
        $extensionClasses = $resolved->cacheSignature();

        $fragments = [];
        foreach ($this->descriptors($resolved, $document) as $descriptor) {
            // A route registered for several verbs documents one operation per method (arch F8).
            foreach ($descriptor->documentableMethods() as $method) {
                $fragment = $this->processRoute($descriptor, $method, $document, $documentId, $engine, $resolved, $components, $bag, $configHash, $extensionClasses);
                if ($fragment !== null) {
                    $fragments[] = $fragment;
                    $bag->addAll($fragment->diagnostics);
                }
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

        return new GenerationResult(UirDocument::fromArray($assembly->document), $bag->sorted());
    }

    /**
     * A canonical digest of the booted-app facts the fragment cache must key on beyond config,
     * routes, and extensions (design §10, A4). Each is a global fact whose change can alter any
     * route's fragment, so it lives at the document level, and each is contributed by the ENABLED
     * integration that owns it through the gated {@see EnvironmentDigestContributor} chain (a disabled
     * integration's globals never key the cache; the pipeline reads only the chain, never an
     * integration class). Segments are keyed by contributor class and sorted, so the digest is
     * independent of registration/sort order. Each contributor is itself defensive — an unresolvable
     * fact contributes the empty string — so the aggregate stays total and deterministic.
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
     * @return list<RouteDescriptor>
     */
    private function descriptors(ResolvedExtensions $resolved, DocumentConfig $document): array
    {
        $descriptors = [];
        foreach ($resolved->routeResolvers as $resolver) {
            foreach ($resolver->resolve($document) as $descriptor) {
                $descriptors[$descriptor->primaryMethod().' '.$descriptor->uri] ??= $descriptor;
            }
        }

        ksort($descriptors);

        return array_values($descriptors);
    }

    /**
     * @param  list<string>  $extensionClasses
     */
    private function processRoute(
        RouteDescriptor $descriptor,
        string $method,
        DocumentConfig $document,
        string $documentId,
        TypeEngine $engine,
        ResolvedExtensions $resolved,
        ComponentRegistry $components,
        DiagnosticCollector $bag,
        string $configHash,
        array $extensionClasses,
    ): ?OperationFragment {
        $path = $this->oasPath($descriptor->uri);
        // The human signature names the specific method being documented, so multi-method routes
        // produce distinct per-method diagnostics.
        $signature = strtoupper($method).' '.$descriptor->uri;

        // Fold the documented method into the cache key so each method of a multi-method route keys
        // to its own fragment (they differ: GET query vs POST body, distinct operation identities).
        $cacheKey = $this->cache->key($descriptor->cacheSignature().'|'.$method, $configHash, $extensionClasses);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            // Warm hit: restore the route's components without touching the type engine (design §10).
            $this->restoreComponents($cached, $components);

            return $cached;
        }

        // Snapshot the shared registry so a route that throws after registering components rolls
        // back cleanly, leaving no orphaned schemas from a route that never entered the document.
        $snapshot = $components->snapshot();

        try {
            $context = $this->contextBuilder->build(
                $descriptor,
                $document,
                $engine,
                $resolved->typeToSchema,
                $resolved->exceptionToResponse,
                $resolved->ruleTransformers,
                $components,
                $method,
                $resolved->responseAnalysisTargets,
                $resolved->responseStatusResolvers,
                $resolved->payloadMediaTypeResolvers,
                $resolved->routeBindingSchemaResolvers,
            );

            if ($context === null) {
                $components->restore($snapshot);

                return $this->onFailure($descriptor, $document, $documentId, $path, $method, 'action could not be reflected', $bag);
            }

            $operation = new OperationDraft;
            $this->pipeline->run($operation, $context, $resolved);
            $diagnostics = $this->analysisDiagnostics($context, $signature);
            $this->assignIds($operation, $documentId, $method, $path);

            $frozen = $operation->freeze();
            [$referencedSchemas, $referencedSchemaIds, $referencedResponses] = $this->componentClosure($frozen->toArray(), $components);

            $fragment = new OperationFragment($path, $method, $frozen, $signature, $diagnostics, $referencedSchemas, $referencedSchemaIds, $referencedResponses);
            // Merge trace-derived dependency files (design §10 seam): integrations that recover facts
            // by tracing widen the cache key, so a deep chain invalidates when any traced file changes.
            $this->cache->put($cacheKey, $fragment, $context->dependencyFiles());

            return $fragment;
        } catch (Throwable $exception) {
            $components->restore($snapshot);

            return $this->onFailure($descriptor, $document, $documentId, $path, $method, $exception->getMessage(), $bag);
        }
    }

    /**
     * The transitive closure of the schema AND response components this operation references (design
     * §5 hoist / arch F7): every component reachable from a `$ref` in the operation, plus every
     * component those components in turn reference (a response component's content `$ref`s a schema).
     * Carrying the full closure — not just the components this route happened to register first —
     * makes each cached fragment self-sufficient: a warm hit restores everything it points at, so
     * removing the route that first *owned* a shared component never leaves a surviving referencer
     * with a dangling `$ref`.
     *
     * @param  array<string, mixed>  $operation
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>, 2: array<string, array<string, mixed>>}
     */
    private function componentClosure(array $operation, ComponentRegistry $components): array
    {
        $schemaRegistry = $components->schemas();
        $schemaIdMap = $components->schemaIds();
        $responseRegistry = $components->responses();

        $schemas = [];
        $schemaIds = [];
        $responses = [];
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

            foreach ($this->refs($schemaRegistry[$name], 'schemas') as $nested) {
                if (! isset($seenSchema[$nested])) {
                    $schemaQueue[] = $nested;
                }
            }
        }

        return [$schemas, $schemaIds, $responses];
    }

    /**
     * The component names a node references via `$ref` (`#/components/{$kind}/NAME`), scanned
     * recursively.
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

    private function restoreComponents(OperationFragment $fragment, ComponentRegistry $components): void
    {
        foreach ($fragment->componentSchemas as $name => $schema) {
            $components->registerSchema($name, $schema, $fragment->componentSchemaIds[$name] ?? null);
        }
        foreach ($fragment->componentResponses as $name => $response) {
            $components->registerResponse($name, $response);
        }
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
        $signature = strtoupper($method).' '.$descriptor->uri;

        $bag->add(new Diagnostic(
            severity: Severity::Error,
            code: 'route.build-failed',
            message: sprintf('Failed to document %s: %s', $signature, $reason),
            routeSignature: $signature,
            help: $document->onRouteError === 'omit' ? 'Route omitted from the document.' : 'A skeleton operation was emitted in its place.',
        ));

        if ($document->onRouteError === 'omit') {
            return null;
        }

        $operation = new OperationDraft;
        $operation->setDescription('Documentation could not be generated for this route.', Contribution::fallback());
        $this->assignIds($operation, $documentId, $method, $path);

        return new OperationFragment($path, $method, $operation->freeze(), $signature);
    }

    private function assignIds(OperationDraft $operation, string $documentId, string $method, string $path): void
    {
        $operationId = $this->identity->operationId($documentId, $method, $path);
        $operation->assignId($operationId);
        $operation->assignChildIds(
            fn (string $in, string $name): string => $this->identity->parameterId($operationId, $in, $name),
            fn (string $status, string $mediaType): ?string => $mediaType === '' ? null : $this->identity->responseId($operationId, $status, $mediaType),
        );
    }

    /**
     * The route's analysis diagnostics, tagged with its signature — returned so they live on the
     * fragment (and are therefore cached and replayed on a warm hit) rather than added straight to
     * the document bag.
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
                message: $diagnostic->message,
                source: $diagnostic->source,
                routeSignature: $diagnostic->routeSignature ?? $signature,
                help: $diagnostic->help,
            );
        }

        return $diagnostics;
    }

    private function oasPath(string $uri): string
    {
        $path = preg_replace('/\{([^}]+)\?}/', '{$1}', $uri);

        return $path ?? $uri;
    }
}
