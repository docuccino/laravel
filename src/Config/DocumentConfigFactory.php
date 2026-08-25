<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Closure;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\LineEndings;
use Docuccino\Laravel\Tags\PrefixTagMapper;
use Illuminate\Contracts\Container\Container;

/**
 * Builds a framework-agnostic {@see DocumentConfig} from one `config('docuccino.documents.*')` entry:
 * relativises every path-like key ({@see ConfigPaths}), reads `info.description.file` into its contents
 * so the pipeline never touches the filesystem, and resolves the tag mapper (a container-resolved
 * `tags.mapper`, else {@see PrefixTagMapper} over `tags.map`).
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
        // The choke point for path handling: relativise before anything reads the bag, so the emitted
        // `configHash` describes what paths mean rather than this machine's layout.
        $config = ConfigPaths::relativize($config, $this->basePath);

        $routes = Hydrate::map($config['routes'] ?? []);
        $security = Hydrate::map($config['security'] ?? []);
        $tags = Hydrate::map($config['tags'] ?? []);

        $closure = $routes['closure'] ?? null;

        // `error_responses` is either a preset string or a bag ['preset' => …, 'errors_shape' => …].
        $errorResponses = $config['error_responses'] ?? 'none';
        $preset = is_array($errorResponses) ? ($errorResponses['preset'] ?? 'none') : $errorResponses;
        $errorsShape = is_array($errorResponses) ? ($errorResponses['errors_shape'] ?? 'map') : 'map';

        $rawInfo = Hydrate::map($config['info'] ?? []);
        $info = $this->resolveInfo($rawInfo);

        // The raw bag keeps the description's PATH and nothing else, so a fingerprint over it alone
        // hashes a filename and not a word of what it says. Carry the contents BESIDE the path rather
        // than in place of it — the path is what the machine-dependent-path check reads.
        $description = $rawInfo['description'] ?? null;
        if (is_array($description) && is_string($description['file'] ?? null) && is_string($info['description'] ?? null)) {
            $description['contents'] = $info['description'];
            $config['info'] = [...$rawInfo, 'description' => $description];
        }

        return new DocumentConfig(
            key: $key,
            info: $info,
            servers: Hydrate::listOfMaps($config['servers'] ?? null) ?? [],
            routeInclude: Hydrate::stringList($routes['include'] ?? []),
            routeExclude: Hydrate::stringList($routes['exclude'] ?? []),
            routeFilter: $closure instanceof Closure ? $closure : null,
            includeVendor: ($routes['include_vendor'] ?? false) === true,
            authMiddleware: is_string($security['auto_detect_middleware'] ?? null) ? $security['auto_detect_middleware'] : null,
            errorResponses: is_string($preset) ? $preset : 'none',
            errorsShape: $errorsShape === 'pointer-list' ? 'pointer-list' : 'map',
            // A glob holding a NUL byte raises out of `glob()` and takes the build with it, so it never
            // reaches one — the same refusal every other path key gets, reported by ConfigDiagnostics.
            overlays: array_values(array_filter(
                Hydrate::stringList($config['overlays'] ?? []),
                static fn (string $pattern): bool => ConfinedPath::holdable($pattern) !== null,
            )),
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
     * A `tags.mapper` class-string is container-resolved so custom mappers get constructor DI; else a
     * non-empty `tags.map` builds a {@see PrefixTagMapper}. Null means tags pass through unchanged.
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
            // Confined to the app base path: a `../` escape reads nothing rather than leaking an
            // out-of-tree file. Nothing read means the document has NO description, not an empty one:
            // publishing `description: ""` claims this API's description is the empty string, where the
            // truth is that the file naming it could not be read — which DocumentBuilder now says.
            $resolved = ConfinedPath::resolve($this->basePath, $description['file']);
            $contents = $resolved === null ? false : @file_get_contents($resolved);

            if ($contents === false) {
                unset($info['description']);
            } else {
                $info['description'] = rtrim(LineEndings::normalize($contents), "\n");
            }
        }

        $info['title'] = is_string($info['title'] ?? null) ? $info['title'] : 'API Documentation';
        $info['version'] = Hydrate::stringOr($info['version'] ?? '1.0.0', '1.0.0');

        return $info;
    }
}
