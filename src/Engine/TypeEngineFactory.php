<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;

/**
 * Builds the configured {@see TypeEngine} (design §Inference). `null` mode skips PHPStan entirely;
 * everything else boots the real engine, and {@see PhpStanEngineFactory::create()} degrades to a
 * {@see NullTypeEngine} on any container/Larastan boot failure, so callers always get a total engine
 * and the build survives. The enum's caching/orchestrated modes are not implemented yet and are
 * treated as in-process.
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

        // PRIME scope is wider than DESCEND scope on purpose: PHPStan strips the bodies of files it
        // doesn't analyse, so a class a trace hops into must be primed even though descent
        // (throws/inline-rules) stays confined to `project_paths`.
        $primePaths = $this->primePaths($descendPaths);

        $runtime = new RuntimeConfig(
            projectRoot: $this->basePath,
            tmpDir: $this->tmpDir,
            phpVersion: PHP_VERSION_ID,
            projectPaths: $primePaths,
        );

        // The vendor dir is passed so traces can hop into primed classes outside the descend scope
        // while never following vendor code itself (design §4).
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
     * Directories whose `.php` bodies stay intact: the descend paths plus every local PSR-4 source root
     * from the app's `composer.json` (`autoload` + `autoload-dev`), so a class in a modular namespace
     * isn't body-stripped. Vendor roots never appear — composer's `autoload.psr-4` maps only the app's
     * own dirs. An unreadable composer.json falls back to the descend paths.
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
