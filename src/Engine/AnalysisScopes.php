<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Laravel\Support\Psr4Namespaces;

/**
 * The two directory sets a build hands the analyser, derived from the application's own autoload map.
 *
 * PRIME is every source root the application maps, `autoload-dev` included: PHPStan strips the bodies of
 * files it does not analyse, so a class a trace hops into has to be primed or it reflects as a shell,
 * and a helper a test root declares is no different in that respect.
 *
 * DESCEND is the narrower set interprocedural work is confined to — throw classification and inline
 * `Validator::make(...)` rules. Its default is the roots the application SHIPS, and the difference from
 * prime is deliberate twice over: an `autoload-dev` root is not the API surface, so walking into it
 * costs analysis time and can document nothing, while `engine.project_paths` still narrows descent
 * wherever an application writes it.
 *
 * The default is derived rather than fixed because `app/` is one application shape's answer. A modular
 * application maps its own `Modules\…`/`Domain\…` roots, and a throw written a hop below a controller
 * there reaches the document only if descent may open the callee's file — so a fixed `['app']`
 * published no error response at all for an error the application really raises. A stock Laravel
 * skeleton maps `App\ → app/` AND the two `Database\…` roots, so the derived set there is three
 * directories rather than one; the two extra cost the fixture corpus no file walk, no memory and no
 * changed answer, because nothing an action calls is written in a factory or a seeder. So the common
 * case is unchanged in what it publishes, which is the claim — not in what the set literally is.
 *
 * Both go through {@see Psr4Namespaces}, which the scaffold command reads for the namespace a generated
 * class carries: one reader of `composer.json`, so nothing here can disagree with it about what the
 * application maps.
 *
 * @internal
 */
final readonly class AnalysisScopes
{
    public function __construct(private string $basePath) {}

    /**
     * Directories interprocedural descent is confined to: `engine.project_paths` where it is written,
     * and otherwise {@see declared()}.
     *
     * A configured list is taken as written and NOT filtered against the filesystem — a directory that
     * is not there yet narrows nothing, and silently dropping it would turn a typo into a scope the
     * reader never asked for.
     *
     * @param  array<string, mixed>  $config  the `engine` bag
     * @return list<string>
     */
    public function descend(array $config): array
    {
        $paths = $config['project_paths'] ?? null;
        if (! is_array($paths)) {
            return $this->declared();
        }

        $out = [];
        foreach ($paths as $path) {
            if (is_string($path)) {
                $out[] = $this->basePath.'/'.ltrim($path, '/');
            }
        }

        return $out === [] ? $this->declared() : $out;
    }

    /**
     * Every source root the app's `composer.json` declares under `autoload` — the descend default, and
     * so also the yardstick the engine is handed alongside the configured scope: a hop declined outside
     * THIS set is the engine's own containment, and one declined inside it is a narrowing the reader
     * wrote and can undo, which is the only one worth a notice ({@see TypeEngineFactory::make()}).
     *
     * A `composer.json` that will not read maps nothing, and the fallback is the historical `app/`
     * rather than an empty scope: descending nowhere would drop every interprocedural fact at once,
     * which is a far worse answer than descending where a Laravel application keeps its code.
     *
     * @return list<string>
     */
    public function declared(): array
    {
        $paths = $this->absolute(Psr4Namespaces::shipped($this->basePath));

        return $paths === [] ? [$this->basePath.'/app'] : $paths;
    }

    /**
     * Directories whose `.php` bodies stay intact: the descend paths plus every local PSR-4 source root
     * the app maps. Vendor roots never appear — composer's `autoload.psr-4` maps only the app's own
     * dirs. An unreadable composer.json leaves just the descend paths.
     *
     * @param  list<string>  $descendPaths
     * @return list<string>
     */
    public function prime(array $descendPaths): array
    {
        return array_values(array_unique(array_filter(
            [...$descendPaths, ...$this->absolute(Psr4Namespaces::roots($this->basePath))],
            is_dir(...),
        )));
    }

    /**
     * A PSR-4 map's roots as absolute directories that exist, deduped. `./app/` and `app` are one
     * directory to composer, so they are one here too ({@see Psr4Namespaces::relativeRoot()} folds
     * them, segment by segment, because a prefix has to be a prefix).
     *
     * Three roots answer to nothing here, and each is dropped rather than guessed at:
     *
     * - The BASE ITSELF (`.`, `./`, `''`) — a legal map, and the one root neither scope may take.
     *   `vendor/` sits under the base, so a scope rooted there puts every dependency's file inside
     *   both: descent stops treating vendor as a terminal and starts promoting a dependency's
     *   `@throws` into a published response, and priming hands PHPStan the whole tree to keep intact.
     * - One that CLIMBS OUT of the base (`../shared/src`) — a file outside the base has no
     *   root-relative name, so a diagnostic naming it would print a path off the build machine and
     *   the document would stop being reproducible.
     * - One that IS NOT THERE — handing PHPStan a path it cannot walk buys nothing.
     *
     * Dropping is local: the other roots still answer, and a map where none survives leaves
     * {@see descend()} on its `app/` fallback and {@see prime()} on the descend paths alone.
     *
     * @param  array<string, list<string>>  $map
     * @return list<string>
     */
    private function absolute(array $map): array
    {
        $paths = [];

        foreach ($map as $dirs) {
            foreach ($dirs as $dir) {
                $root = Psr4Namespaces::relativeRoot($dir);

                if ($root !== null && $root !== '') {
                    $paths[] = $this->basePath.'/'.$root;
                }
            }
        }

        return array_values(array_unique(array_filter($paths, is_dir(...))));
    }
}
