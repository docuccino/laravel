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
 * The document pipeline (design §5): resolve extensions late, discover routes, build each operation
 * in phased isolation, assign identities, assemble, apply overlays/transformers, validate against
 * the bundled UIR schema. A broken route yields a skeleton (or is omitted) plus an error diagnostic
 * — never a dead build. {@see DocumentBuilder} is the config-facade callers use to feed it.
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
        private readonly BuildFingerprint $fingerprint = new BuildFingerprint,
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

        // Discoverability: one info diagnostic per installed-but-disabled integration (design §4).
        $bag->addAll(IntegrationToggles::diagnostics($document));
        // Config-shape info diagnostics (design §9) — surfaced instead of silently coerced.
        $bag->addAll(ConfigDiagnostics::for($document));

        // The narrative content tree is a document-level input, rebuilt every run and kept OUT of the
        // fragment cache key: fragments never read content, so a prose typo mustn't re-run PHPStan
        // across the whole route set. It reaches output via assembly and the document contentHash.
        [$content, $contentDiagnostics] = $this->contentCompiler->compile($document);
        $bag->addAll($contentDiagnostics);

        // Document config, booted-app facts and the build environment the engine runs in: the three
        // document-level inputs every route's fragment-cache key carries.
        $configHash = $document->hash()
            .'|env:'.$this->environmentDigest($resolved)
            .'|build:'.$this->fingerprint->digest($engine);
        $extensionClasses = $resolved->cacheSignature();

        $fragments = [];
        foreach ($this->descriptors($resolved, $document) as $descriptor) {
            // A route registered for several verbs documents one operation per method.
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
        // Naming the specific method keeps multi-method routes' diagnostics distinct.
        $signature = strtoupper($method).' '.$descriptor->uri;

        // The method is part of the cache key: GET query vs POST body are different fragments with
        // different operation identities.
        $cacheKey = $this->cache->key($descriptor->cacheSignature().'|'.$method, $configHash, $extensionClasses);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            // Warm hit: restore components without waking the type engine (design §10).
            $this->restoreComponents($cached, $components);

            return $cached;
        }

        // Snapshot the shared registry: a route that throws mid-build rolls back, so it can't leave
        // orphaned schemas behind for a document it never entered.
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
            // Trace-derived dependency files widen the key, so a deep chain invalidates when any file
            // it walked changes (design §10 seam).
            $this->cache->put($cacheKey, $fragment, $context->dependencyFiles());

            return $fragment;
        } catch (Throwable $exception) {
            $components->restore($snapshot);

            return $this->onFailure($descriptor, $document, $documentId, $path, $method, $exception->getMessage(), $bag);
        }
    }

    /**
     * The transitive closure of schema and response components this operation `$ref`s, following refs
     * through the components themselves (design §5 hoist). The full closure — not just what this route
     * registered first — is what makes a cached fragment self-sufficient: deleting the route that
     * happened to own a shared component can't leave a survivor with a dangling `$ref`.
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
     * The route's analysis diagnostics, tagged with its signature. They ride on the fragment rather
     * than going straight to the document bag, so a warm cache hit replays them.
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
