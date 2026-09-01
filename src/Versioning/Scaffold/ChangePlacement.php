<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning\Scaffold;

use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Versioning\ChangeDirectories;

/**
 * Which configured `api_version.changes` directory one change belongs in: the one whose module owns the
 * class the change's verb names.
 *
 * This is not an inference about somebody's layout. A configured entry containing a wildcard DECLARES
 * where the boundary is — an entry of `modules/*` followed by `Api/Versions` says a module is the unit
 * — so {@see ChangeDirectories} can name the module root behind every match, and all this does is ask
 * which of those roots holds the class's own file. An application that configures one literal directory
 * has declared no boundary and gets that directory, which is what it always got.
 *
 * The rule, in full:
 *
 * 1. `--in` overrides everything. A named directory is an instruction, not evidence.
 * 2. Otherwise the module root containing the class's file wins, LONGEST root first — the same
 *    tie-break Composer's own PSR-4 resolution uses, and the one this adapter already reads the
 *    application's own PSR-4 map with.
 * 3. Two roots of equal length both holding the file name no single module, so the change falls back
 *    rather than picking one: a tie broken by glob enumeration order would be a destination that moves
 *    when a sibling module is added.
 * 4. No root holds it — a class outside every declared module, or a class whose file cannot be read —
 *    and the first configured directory takes it.
 *
 * A change names exactly one class, so a diff spanning two modules writes each change beside its own
 * module. There is nothing to refuse: the ambiguity the refusal would guard against is a SINGLE class
 * two modules both claim, which is case 3.
 *
 * @internal
 */
final readonly class ChangePlacement
{
    /**
     * @param  list<string>  $directories  absolute, configured order, non-empty
     * @param  array<string, string>  $modules  directory => the module root its entry's wildcard declared
     * @param  ?string  $forced  the directory `--in` named, already checked against `$directories`
     */
    public function __construct(
        private string $basePath,
        private array $directories,
        private array $modules,
        private ?string $forced = null,
    ) {}

    /** Where the change naming `$fqcn` is written, and why there. */
    public function for(string $fqcn): ChangeDestination
    {
        if ($this->forced !== null) {
            return new ChangeDestination($this->forced, 'you named it with --in');
        }

        $file = DeclarationFiles::of($fqcn)[0] ?? null;
        $claims = $file === null ? [] : $this->claims($file);

        if (count($claims) === 1) {
            $directory = array_key_first($claims);

            return new ChangeDestination($directory, sprintf(
                'beside %s, which owns %s',
                $this->readable($this->modules[$directory] ?? $directory),
                $fqcn,
            ));
        }

        return new ChangeDestination($this->directories[0] ?? '', $this->fallback($fqcn, $claims));
    }

    /**
     * The directories whose module root holds `$file`, narrowed to the longest root — so a module
     * mapped inside another module beats the outer one, and a tie comes back as the two it could not
     * choose between rather than as one of them.
     *
     * @return array<string, true>
     */
    private function claims(string $file): array
    {
        $claims = [];
        $longest = 0;

        foreach ($this->modules as $directory => $root) {
            if (! self::contains($root, $file) || strlen($root) < $longest) {
                continue;
            }

            if (strlen($root) > $longest) {
                $longest = strlen($root);
                $claims = [];
            }

            $claims[$directory] = true;
        }

        return $claims;
    }

    /**
     * Why the first configured directory took it — the tie, the absence of a module, or there having
     * been only ever one place to write.
     *
     * @param  array<string, true>  $claims
     */
    private function fallback(string $fqcn, array $claims): string
    {
        if (count($claims) > 1) {
            $named = array_map($this->readable(...), array_keys($claims));
            sort($named, SORT_STRING);

            return sprintf(
                'the first configured change directory; %s claim %s equally, so nothing here can choose',
                implode(' and ', $named),
                $fqcn,
            );
        }

        if (count($this->directories) === 1) {
            return 'the only configured change directory';
        }

        return sprintf('the first configured change directory; no configured module holds %s', $fqcn);
    }

    /**
     * Whether `$root` holds `$file`. Lexical first, and through `realpath()` only when that says no:
     * a class's file comes off reflection already resolved, while a configured root is the author's own
     * text, so a base path reached through a symlink — `/tmp` on macOS — would otherwise read as
     * holding nothing at all.
     */
    private static function contains(string $root, string $file): bool
    {
        if (str_starts_with(ConfinedPath::normalize($file), ConfinedPath::normalize($root).'/')) {
            return true;
        }

        $realRoot = realpath($root);
        $realFile = realpath($file);

        return $realRoot !== false && $realFile !== false && str_starts_with($realFile, $realRoot.'/');
    }

    /** A path as the author wrote it, which is what a reason has to name. */
    private function readable(string $path): string
    {
        return Paths::relative($path, $this->basePath) ?? $path;
    }
}
