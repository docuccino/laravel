<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Support\Paths;

/**
 * The one reading of `api_version.changes`: configured glob patterns in, absolute directories out,
 * with what could not be resolved said once.
 *
 * One reading because there are three readers — the collector that discovers the change classes,
 * `docuccino:watch`, which watches the same trees, and the scaffold command, which writes into one of
 * them. Two of them resolving the same patterns separately is how a build reads a module directory that
 * a watch session never notices.
 *
 * A pattern may be a glob, so a modular application matches its modules in one entry rather than
 * re-listing them every time one is added. Glob results are sorted and the entries keep their
 * configured order, so the answer is a function of the configuration rather than of the filesystem's
 * enumeration — and every resolved directory goes back through {@see ConfinedPath} rather than being
 * trusted because its pattern was: a wildcard matching a symlink out of the application is the one way
 * a glob becomes an escape.
 *
 * The third answer is the MODULE ROOT behind each globbed match. An entry containing a wildcard
 * declares where the author's boundary is — an entry of `modules/*` followed by `Api/Versions` says a
 * module is the unit — so the path up to and including the wildcard's own segment (`modules/Billing`)
 * is a fact the configuration states rather than a layout anything inferred. It is read HERE because
 * this is where the expansion happens: deriving it again from a resolved directory would be a second
 * reading of the same entry, and the two would disagree the first time a pattern grew a segment.
 *
 * A directory it names may not exist. A literal entry is still returned when it is missing — watching
 * it is what registers the change class somebody writes next — so the reader that opens one checks,
 * and says nothing when it is absent because {@see resolve()} already has.
 *
 * @phpstan-type ChangeDirectoryReading array{0: list<string>, 1: list<Diagnostic>, 2: array<string, string>}
 *
 * @internal
 */
final class ChangeDirectories
{
    /** The characters that make a configured entry a pattern rather than a path. */
    private const string GLOB_CHARACTERS = '*?[';

    /**
     * The directories `$document` declares its changes live in, the diagnostics for the entries that
     * named none, and the module root each globbed match declares.
     *
     * @return ChangeDirectoryReading absolute paths, deduped, configured order; then the diagnostics;
     *                                then directory => module root, for the globbed entries only
     */
    public static function resolve(string $basePath, DocumentConfig $document): array
    {
        $directories = [];
        $diagnostics = [];
        $modules = [];

        foreach ($document->apiVersionChangeDirs() as $configured) {
            $resolved = ConfinedPath::configuredDir($basePath, $configured);

            if ($resolved === null) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'versioning.dir-escapes-base',
                    message: sprintf('The version-changes directory "%s" does not name a path inside the application and was ignored.', PlainText::of($configured)),
                );

                continue;
            }

            [$matched, $escaped, $roots] = self::expand($basePath, $configured, $resolved);

            if ($escaped) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'versioning.dir-escapes-base',
                    message: sprintf('The version-changes directory "%s" matched a path outside the application, which was ignored.', PlainText::of($configured)),
                );
            }

            if (array_filter($matched, is_dir(...)) === []) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'versioning.dir-missing',
                    message: sprintf('The configured version-changes directory "%s" does not exist.', PlainText::of($configured)),
                    help: 'Create it or drop the entry from documents.*.api_version.changes.',
                );
            }

            foreach ($matched as $directory) {
                $directories[$directory] = true;
            }

            // First entry to claim a directory keeps it, so two patterns matching one tree answer in
            // configured order rather than in whichever was expanded last.
            foreach ($roots as $directory => $root) {
                $modules[$directory] ??= $root;
            }
        }

        return [array_keys($directories), $diagnostics, $modules];
    }

    /**
     * What one entry resolves to, and whether anything it matched was refused.
     *
     * A literal entry is itself, present or not — the caller wants to watch a directory the author is
     * about to create. A pattern is every directory it matches, sorted, each re-confined: an absolute
     * pattern is a deliberate statement about this machine and stands as written, while a relative one
     * may not leave the application however its `*` was spelled.
     *
     * @return array{0: list<string>, 1: bool, 2: array<string, string>} the directories, whether
     *                                                                   anything was refused, and each
     *                                                                   match's module root
     */
    private static function expand(string $basePath, string $configured, string $resolved): array
    {
        if (strpbrk($configured, self::GLOB_CHARACTERS) === false) {
            return [[$resolved], false, []];
        }

        $matches = glob($resolved, GLOB_ONLYDIR) ?: [];
        sort($matches, SORT_STRING);

        $absolute = str_starts_with($configured, '/');

        $kept = [];
        $roots = [];
        $escaped = false;

        foreach ($matches as $match) {
            $confined = $absolute ? $match : self::confine($basePath, $match);

            if ($confined === null) {
                $escaped = true;

                continue;
            }

            $kept[] = $confined;

            $root = self::moduleRoot($basePath, $configured, $confined);
            if ($root !== null) {
                $roots[$confined] = $root;
            }
        }

        return [$kept, $escaped, $roots];
    }

    /**
     * The module root one globbed match declares: the match truncated to the entry's FIRST wildcard
     * segment, so an entry of `modules/*` followed by `Api/Versions`, matched at
     * `modules/Billing/Api/Versions`, is `modules/Billing`.
     *
     * The first wildcard rather than the last because that is where the author put the boundary — a
     * later `*` divides the module up, it does not name a second unit. Read against the entry as
     * WRITTEN, not against the resolved pattern: an application whose own base path happens to hold a
     * `*` would otherwise have the boundary land somewhere it never declared.
     */
    private static function moduleRoot(string $basePath, string $configured, string $match): ?string
    {
        $absolute = str_starts_with($configured, '/');
        $subject = $absolute ? $match : Paths::relative($match, $basePath);

        if ($subject === null) {
            return null;
        }

        $segments = explode('/', trim($subject, '/'));

        foreach (explode('/', trim($configured, '/')) as $index => $segment) {
            if (strpbrk($segment, self::GLOB_CHARACTERS) === false) {
                continue;
            }

            if (! isset($segments[$index])) {
                return null;
            }

            $root = implode('/', array_slice($segments, 0, $index + 1));

            return $absolute ? '/'.$root : rtrim($basePath, '/').'/'.$root;
        }

        return null;
    }

    /** A globbed match put back through confinement, by the relative name it holds under the base. */
    private static function confine(string $basePath, string $match): ?string
    {
        $relative = Paths::relative($match, $basePath);

        return $relative === null ? null : ConfinedPath::configuredDir($basePath, $relative);
    }
}
