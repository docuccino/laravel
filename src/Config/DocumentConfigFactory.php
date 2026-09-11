<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfiguredFlag;
use Docuccino\Core\Support\ConfiguredKeyword;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\LineEndings;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;

/**
 * Builds a framework-agnostic {@see DocumentConfig} from one `documents.*` entry of `docuccino.yaml`:
 * relativises every path-like key ({@see ConfigPaths}), reads `info.description.file` into its contents
 * so the pipeline never touches the filesystem, and resolves the two collaborators a document names by
 * class — the tag mapper ({@see ConfiguredTagMapper}) and the route filter
 * ({@see ConfiguredRouteFilter}).
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

        // A document declaring no servers gets the application's own URL wherever that proves
        // something ({@see DerivedServers}). The derived entry goes back into the bag as well as onto
        // the property: it shapes the emitted document, so the published `configHash` owes it, and the
        // fragment cache has to retire an operation whose host-bound `servers` hangs off it.
        $servers = Hydrate::listOfMaps($config['servers'] ?? null) ?? [];
        if ($servers === []) {
            $servers = DerivedServers::for($this->container->make(ConfigRepository::class));
            if ($servers !== []) {
                $config['servers'] = $servers;
            }
        }

        return new DocumentConfig(
            key: $key,
            info: $info,
            servers: $servers,
            routeInclude: Hydrate::stringList($routes['include'] ?? []),
            routeExclude: Hydrate::stringList($routes['exclude'] ?? []),
            routeFilter: (new ConfiguredRouteFilter($this->container))->resolve($key, $routes),
            includeVendor: ConfiguredFlag::read($routes, 'include_vendor', false)->on,
            authMiddleware: is_string($security['auth_middleware'] ?? null) ? $security['auth_middleware'] : null,
            errorResponses: self::errorResponses($config),
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
            // The one member that comes from the OTHER file: the viewer is framework-owned, because
            // boot and every viewer request read it ({@see ViewerConfig}). It shapes no emitted byte,
            // so carrying it here lets the runtime ask the document config and not the config files.
            viewer: ViewerConfig::for($key),
            versioning: ConfiguredKeyword::read(
                $config,
                'versioning',
                DocumentConfig::VERSIONING_DEFAULT,
                DocumentConfig::VERSIONING_POLICIES,
            )->keyword,
            tagMapper: (new ConfiguredTagMapper($this->container))->resolve($tags),
            raw: $config,
        );
    }

    /**
     * The error-response strategy: a closed set of two, where only an ABSENT key falls back to `none`.
     *
     * Absent and present-but-null are deliberately different readings. A document that never names the
     * key has expressed nothing, and `none` is the documented fallback it gets — the shipped file says
     * `default`, so a second document inherits none of the first's errors. A key that IS present has an
     * author behind it, and a key written with nothing after the colon is exactly that: an intent
     * expressed and unreadable. Reading it as `none` would take every 4xx and 5xx out of the document
     * without a word, so it degrades the way every other value outside the set does — as the shipped
     * `default`, with {@see ConfiguredKeywords} naming it.
     *
     * @param  array<string, mixed>  $config
     */
    private static function errorResponses(array $config): string
    {
        if (! array_key_exists('error_responses', $config)) {
            return DocumentConfig::ERROR_RESPONSES_ABSENT;
        }

        return ConfiguredKeyword::read(
            $config,
            'error_responses',
            DocumentConfig::ERROR_RESPONSES_DEFAULT,
            DocumentConfig::ERROR_RESPONSES,
        )->keyword;
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

        // Refused rather than coerced, both of them, and for the reason the configuration reader that
        // REPORTS them gives: `version: 1.10` parses to the float 1.1, and a coercing read publishes
        // "1.1" — a version number nobody wrote, in a document somebody's client is pinned to. The
        // fallback here is the one {@see \Docuccino\Laravel\Pipeline\DocumentBuilder::config()}
        // names in that report, so the diagnostic and the document say the same thing.
        $info['title'] = is_string($info['title'] ?? null) ? $info['title'] : DocumentConfig::DEFAULT_TITLE;
        $info['version'] = is_string($info['version'] ?? null) ? $info['version'] : DocumentConfig::DEFAULT_VERSION;

        return $info;
    }
}
