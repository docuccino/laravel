<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Closure;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Tags\PrefixTagMapper;
use Illuminate\Contracts\Container\Container;

/**
 * Builds a framework-agnostic {@see DocumentConfig} from one `config('docuccino.documents.*')`
 * entry: relativising every path-like key against the app base path ({@see ConfigPaths}), resolving an
 * `info.description.file` reference to the file's contents so the pipeline never touches the
 * filesystem, and resolving the document's tag mapper (a custom `tags.mapper` class-string from the
 * container, else the built-in {@see PrefixTagMapper} over `tags.map`).
 */
final readonly class DocumentConfigFactory
{
    public function __construct(
        private string $basePath,
        private Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(string $key, array $config, string $onRouteError): DocumentConfig
    {
        // The single choke point for path handling: every path-like key is rewritten to its
        // base-relative form BEFORE anything reads the bag, so the emitted `configHash` (a digest of
        // `raw`) and every typed accessor over it describe the path's meaning, not this machine's layout.
        $config = ConfigPaths::relativize($config, $this->basePath);

        $routes = Hydrate::map($config['routes'] ?? []);
        $security = Hydrate::map($config['security'] ?? []);
        $tags = Hydrate::map($config['tags'] ?? []);

        $closure = $routes['closure'] ?? null;

        // `error_responses` is either a string preset ('default'|'problem-details'|'none') or a bag
        // ['preset' => …, 'errors_shape' => 'map'|'pointer-list'] carrying the 422 errors representation.
        $errorResponses = $config['error_responses'] ?? 'none';
        $preset = is_array($errorResponses) ? ($errorResponses['preset'] ?? 'none') : $errorResponses;
        $errorsShape = is_array($errorResponses) ? ($errorResponses['errors_shape'] ?? 'map') : 'map';

        return new DocumentConfig(
            key: $key,
            info: $this->resolveInfo(Hydrate::map($config['info'] ?? [])),
            servers: Hydrate::listOfMaps($config['servers'] ?? null) ?? [],
            routeInclude: Hydrate::stringList($routes['include'] ?? []),
            routeExclude: Hydrate::stringList($routes['exclude'] ?? []),
            routeFilter: $closure instanceof Closure ? $closure : null,
            includeVendor: ($routes['include_vendor'] ?? false) === true,
            authMiddleware: is_string($security['auto_detect_middleware'] ?? null) ? $security['auto_detect_middleware'] : null,
            errorResponses: is_string($preset) ? $preset : 'none',
            errorsShape: $errorsShape === 'pointer-list' ? 'pointer-list' : 'map',
            overlays: Hydrate::stringList($config['overlays'] ?? []),
            onRouteError: $onRouteError,
            security: $security,
            tags: $tags,
            representation: Hydrate::map($config['representation'] ?? []),
            viewer: Hydrate::map($config['viewer'] ?? []),
            versioning: is_string($config['versioning'] ?? null) ? $config['versioning'] : 'none',
            tagMapper: $this->resolveTagMapper($tags),
            raw: $config,
        );
    }

    /**
     * Resolve the document's tag mapper: a `tags.mapper` class-string is container-resolved (so a
     * custom mapper gets constructor DI); otherwise a non-empty `tags.map` builds the built-in
     * {@see PrefixTagMapper}. No mapper (null) means tags pass through unchanged.
     *
     * @param  array<string, mixed>  $tags
     */
    private function resolveTagMapper(array $tags): ?TagMapper
    {
        $mapper = $tags['mapper'] ?? null;
        if (is_string($mapper) && $mapper !== '') {
            $resolved = $this->container->make($mapper);

            return $resolved instanceof TagMapper ? $resolved : null;
        }

        $map = Hydrate::stringMap($tags['map'] ?? null);

        return $map === [] ? null : new PrefixTagMapper($map);
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private function resolveInfo(array $info): array
    {
        $description = $info['description'] ?? null;

        if (is_array($description) && isset($description['file']) && is_string($description['file'])) {
            // Confine the description file to the app base path (security L2): a `../` escape reads
            // nothing rather than leaking an out-of-tree file.
            $resolved = ConfinedPath::resolve($this->basePath, $description['file']);
            $contents = $resolved === null ? false : @file_get_contents($resolved);
            $info['description'] = $contents === false ? '' : rtrim($contents, "\n");
        }

        $info['title'] = is_string($info['title'] ?? null) ? $info['title'] : 'API Documentation';
        $info['version'] = Hydrate::stringOr($info['version'] ?? '1.0.0', '1.0.0');

        return $info;
    }
}
