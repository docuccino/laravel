<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\GeneratedDirectory;

/**
 * Builds the configured {@see TypeEngine} (design §Inference). `null` mode skips analysis entirely, and
 * so does an absent engine package ({@see EnginePackage}) — the build reports that once per document, as
 * an `engine.not-installed` diagnostic. Otherwise the engine's builder takes over and degrades to a
 * {@see NullTypeEngine} on any container/Larastan boot failure, so callers always get a total engine and
 * the build survives. The enum's caching/orchestrated modes are not implemented yet and are treated as
 * in-process.
 *
 * Process-wide side effects (the memory ceiling, the out-of-memory shutdown notice) are confined to a
 * {@see ConsoleBuild}: the viewer resolves a `TypeEngine` on any `.json` request — including
 * artifact/cache sources that never analyse anything — and a web request has no business changing the
 * limits the process serves every other request under.
 */
final readonly class TypeEngineFactory
{
    public function __construct(
        private string $basePath,
        private string $tmpDir,
        private EnginePackage $engine = new EnginePackage,
        private bool $console = false,
    ) {}

    /**
     * Whether this build owns the process well enough to change its limits — true only for a
     * {@see ConsoleBuild}. False on every web request, the viewer's `generate` source included.
     */
    public function mayTuneProcess(): bool
    {
        return $this->console;
    }

    /**
     * The configured engine, built on the first question asked of it ({@see LazyTypeEngine}) — a build
     * that answers every route from its fragment cache asks none, and must not pay a PHPStan boot to
     * do it. This is what the container binding hands out; {@see make()} is the eager build.
     *
     * @param  array<string, mixed>  $config  the `docuccino.engine` config array
     */
    public function deferred(array $config): TypeEngine
    {
        return new LazyTypeEngine(
            fn (): TypeEngine => $this->make($config),
            $this->engineIdentity($config),
        );
    }

    /**
     * Names what {@see make()} will resolve without building it: the builder that will build the
     * engine, or {@see NullTypeEngine} when nothing will. The fragment cache keys on which engine
     * resolved and computes that key before the first route, so it cannot wait for the engine to
     * exist. This partitions builds exactly as the resolved class does — nothing to analyse with on
     * one side, a real analyser on the other — and the analyser's own version reaches the key through
     * the app's `composer.lock`.
     *
     * @param  array<string, mixed>  $config  the `docuccino.engine` config array
     */
    public function engineIdentity(array $config): string
    {
        return $this->mode($config) === TypeEngineMode::Null || ! $this->engine->installed()
            ? NullTypeEngine::class
            : EnginePackage::BUILDER;
    }

    /**
     * @param  array<string, mixed>  $config  the `docuccino.engine` config array
     */
    public function make(array $config): TypeEngine
    {
        $mode = $this->mode($config);

        if ($mode === TypeEngineMode::Null) {
            return new NullTypeEngine;
        }

        $builder = $this->engine->builder();
        if ($builder === null) {
            return new NullTypeEngine;
        }

        // PHPStan is about to analyse inside this process, so the memory ceiling and the story an OOM
        // tells are ours to settle first — every console entry point (the build commands, cache warm)
        // comes through here, which is why it isn't done in the commands.
        if ($this->mayTuneProcess()) {
            $this->applyMemoryLimit($config);
        }

        $descendPaths = $this->projectPaths($config);

        GeneratedDirectory::ensure($this->tmpDir);

        // PRIME scope is wider than DESCEND scope on purpose: PHPStan strips the bodies of files it
        // doesn't analyse, so a class a trace hops into must be primed even though descent
        // (throws/inline-rules) stays confined to `project_paths`. The vendor dir is passed so traces
        // can hop into primed classes outside the descend scope while never following vendor code
        // itself (design §4).
        return $builder->build(
            projectRoot: $this->basePath,
            tmpDir: $this->tmpDir,
            vendorPath: $this->basePath.'/vendor',
            primePaths: $this->primePaths($descendPaths),
            descendPaths: $descendPaths,
        );
    }

    /**
     * The configured mode; anything unrecognised runs in-process.
     *
     * @param  array<string, mixed>  $config  the `docuccino.engine` config array
     */
    private function mode(array $config): TypeEngineMode
    {
        return TypeEngineMode::tryFrom(is_string($config['mode'] ?? null) ? $config['mode'] : '')
            ?? TypeEngineMode::InProcess;
    }

    /**
     * Raises the process ceiling to the configured limit when that's genuinely higher, and arms the
     * out-of-memory explanation either way — a process already generous enough still benefits from being
     * told what to change if it turns out not to be.
     *
     * @param  array<string, mixed>  $config
     */
    private function applyMemoryLimit(array $config): void
    {
        $configured = $config['memory_limit'] ?? null;

        $target = MemoryLimit::target(
            is_string($configured) ? $configured : null,
            ini_get('memory_limit'),
        );

        if ($target !== null) {
            ini_set('memory_limit', $target);
        }

        OutOfMemoryNotice::arm();
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
