<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;

/**
 * Builds the configured {@see TypeEngine} for a build (design §Inference). The `null` mode skips
 * PHPStan entirely; `in-process` boots the real engine — and {@see PhpStanEngineFactory::create()}
 * already degrades to a {@see NullTypeEngine} on any container/Larastan boot failure, so the
 * caller always gets a total engine and the build stays alive. Caching/orchestrated composition
 * is retained in the enum for Phase 3b; Phase 3a treats them as in-process.
 */
final readonly class TypeEngineFactory
{
    public function __construct(
        private string $basePath,
        private string $tmpDir,
        private PhpStanEngineFactory $factory = new PhpStanEngineFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `docuccino.engine` config array
     */
    public function make(array $config): TypeEngine
    {
        $mode = TypeEngineMode::tryFrom(is_string($config['mode'] ?? null) ? $config['mode'] : '')
            ?? TypeEngineMode::InProcess;

        if ($mode === TypeEngineMode::Null) {
            return new NullTypeEngine;
        }

        $descendPaths = $this->projectPaths($config);

        if (! is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0755, true);
        }

        // PRIME scope is broader than DESCEND scope: PHPStan strips the method bodies of any file it
        // does not analyse, so a Query class the QB trace follows must be PRIMED even though general
        // descent (throws/inline-rules) stays confined to `project_paths`. Priming every app PSR-4
        // source root (from the app's own composer.json) keeps a modular `Modules\…\Queries` body
        // intact for the `$query->query()` hop, without widening throw analysis across those modules.
        $primePaths = $this->primePaths($descendPaths);

        $runtime = new RuntimeConfig(
            projectRoot: $this->basePath,
            tmpDir: $this->tmpDir,
            phpVersion: PHP_VERSION_ID,
            projectPaths: $primePaths,
        );

        // The vendor dir lets the Query-Builder trace follow a `$query->query()` hop into a primed
        // Query class outside the DESCEND scope while never following vendor code (design §4).
        return $this->factory->create($runtime, EngineConfig::forProjectWithVendor($this->basePath.'/vendor', ...$descendPaths));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function projectPaths(array $config): array
    {
        $paths = $config['project_paths'] ?? ['app'];
        if (! is_array($paths)) {
            $paths = ['app'];
        }

        $out = [];
        foreach ($paths as $path) {
            if (is_string($path)) {
                $out[] = $this->basePath.'/'.ltrim($path, '/');
            }
        }

        return $out === [] ? [$this->basePath.'/app'] : $out;
    }

    /**
     * The set of directories whose `.php` bodies are preserved (primed): the DESCEND paths plus every
     * local PSR-4 source root declared in the app's `composer.json` (`autoload` + `autoload-dev`), so a
     * modular Query class the QB trace follows is not body-stripped. Vendor roots are never included
     * (composer's own `autoload.psr-4` maps only the app's dirs). Falls back to the descend paths when
     * composer.json is unreadable.
     *
     * @param  list<string>  $descendPaths
     * @return list<string>
     */
    private function primePaths(array $descendPaths): array
    {
        $paths = $descendPaths;

        $composer = $this->basePath.'/composer.json';
        $contents = is_file($composer) ? @file_get_contents($composer) : false;
        $decoded = $contents === false ? null : json_decode($contents, true);

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = is_array($decoded) && is_array($decoded[$section] ?? null) ? $decoded[$section] : [];
            $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
            foreach ($psr4 as $dirs) {
                foreach ((array) $dirs as $dir) {
                    if (is_string($dir) && $dir !== '') {
                        $paths[] = $this->basePath.'/'.rtrim(ltrim($dir, './'), '/');
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($paths, is_dir(...))));
    }
}
