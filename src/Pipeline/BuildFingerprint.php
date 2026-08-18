<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\Json;
use Docuccino\Laravel\Engine\EngineNeon;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\LazyTypeEngine;

/**
 * The build-environment half of the fragment-cache key (design §10): which engine actually resolved,
 * whether the engine package is installed at all, how it is configured, and the app's locked
 * dependency set. None of that sits in a document's config bag or in any route's dependency files,
 * yet all of it decides what inference recovers — installing the engine, widening
 * `engine.project_paths` or upgrading the analyser changes emitted bytes without touching one
 * analysed file.
 *
 * `engine.memory_limit` is the one key deliberately left out: it is a process ceiling that cannot
 * change a documented byte, and `--memory-limit` would otherwise cost a full rebuild each way.
 * `engine.neon` goes in twice over — the path with the rest of the bag, and the file's CONTENT — since
 * an extension it registers can change any type the engine infers without the path ever moving.
 *
 * This names the engine that WILL answer, which a build discovers to be wrong only if that engine
 * fails to boot — later than any key can be computed. {@see DocumentGenerator::degraded()} owns what
 * happens then.
 *
 * @internal
 */
final readonly class BuildFingerprint
{
    /**
     * @param  array<string, mixed>  $engineConfig  the `docuccino.engine` bag
     * @param  string  $basePath  the application root, holding the `composer.lock` this digests
     */
    public function __construct(
        private array $engineConfig = [],
        private string $basePath = '',
        private EnginePackage $engine = new EnginePackage,
    ) {}

    /** The digest for a build about to run on `$engine`. */
    public function digest(TypeEngine $engine): string
    {
        $config = $this->engineConfig;
        unset($config['memory_limit']);

        return hash('sha256', implode("\0", [
            // A deferred engine names what it will build: asking a booted engine for its class would
            // cost exactly the analyser boot this key lets a warm build skip.
            $engine instanceof LazyTypeEngine ? $engine->identity() : $engine::class,
            $this->engine->installed() ? 'installed' : 'absent',
            Json::stable($config),
            EngineNeon::digest($this->engineConfig, $this->basePath),
            $this->lockDigest(),
        ]));
    }

    /**
     * The app's `composer.lock` content hash: an analyser or package upgrade can change every
     * inferred type, and nothing else in the key would notice. Unreadable (or absent) digests to the
     * empty string rather than failing the build.
     */
    private function lockDigest(): string
    {
        if ($this->basePath === '') {
            return '';
        }

        $hash = @hash_file('sha256', rtrim($this->basePath, '/').'/composer.lock');

        return $hash === false ? '' : $hash;
    }
}
