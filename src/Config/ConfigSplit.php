<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\NameList;
use Docuccino\Laravel\Commands\RefusesUnreadConfig;
use Docuccino\Laravel\Http\DocsController;

/**
 * The line between `docuccino.yaml` and `config/docuccino.php`, and everything the build has to say
 * when an application is on the wrong side of it.
 *
 * The framework's config file keeps exactly {@see FRAMEWORK_KEYS}: the master switch, each document's
 * viewer wiring and the cache store. Those are read while the app BOOTS and on the viewer's REQUEST
 * path, where nothing can afford to parse a project file. Everything else there is not merged, not
 * given precedence and not read — a build key left in `config/docuccino.php` is DETECTED and reported,
 * and that is all. Merging the two would be worse than ignoring one of them: the symptom of a silent
 * precedence rule is a document that quietly stops matching the file somebody edited.
 *
 * Every check here lives build-side, because `config/` is read at boot knowing nothing about the YAML.
 * Boot therefore degrades — it registers whatever viewer routes it is given — and the build is the one
 * place that can see both files at once and say when they disagree.
 *
 * @internal
 */
final class ConfigSplit
{
    /** No `docuccino.yaml`, and build settings sitting in `config/docuccino.php` — nothing is read. */
    public const string NOT_MIGRATED = 'config.not-migrated';

    /** `docuccino.yaml` is there, and `config/docuccino.php` still carries settings nothing reads. */
    public const string STALE_KEYS = 'config.stale-php-keys';

    /** A viewer is configured for a document the build does not define, so its routes 404 at best. */
    public const string VIEWER_ORPHAN = 'config.viewer-orphan';

    /** Two documents claim one viewer route, and the framework lets the last registration win. */
    public const string VIEWER_COLLISION = 'config.viewer-route-collision';

    /**
     * What `config/docuccino.php` still owns, as the paths a document-keyed entry is checked against.
     * `documents.*` is the wildcard: inside a document bag only `viewer` belongs there.
     *
     * @var list<string>
     */
    public const array FRAMEWORK_KEYS = ['enabled', 'cache.store', 'documents.*.viewer'];

    /**
     * Everything the two files disagree about, as one diagnostic per KIND of disagreement.
     *
     * One per kind and not one per key, because an application migrating from the framework config has
     * dozens of build settings in it: a warning each would be the whole build report, and a channel
     * that fires thirty times for one edit is a channel its reader learns to skip. {@see NameList}
     * caps what is named and counts the rest.
     *
     * @return list<Diagnostic>
     */
    public static function report(BuildConfig $build): array
    {
        $stale = self::staleKeys();

        return array_values(array_filter([
            self::migration($build, $stale),
            ...self::viewerReport($build),
        ]));
    }

    /**
     * The framework config's build settings, as dotted paths in name order.
     *
     * Sorted rather than left in declaration order: the order in the file is the order its author
     * reads them in, but this list is the union of several nesting levels, and interleaving them by
     * where they happen to sit reads as noise. Name order also makes the list the same list however
     * the file is arranged.
     *
     * @return list<string>
     */
    public static function staleKeys(): array
    {
        $keys = array_map(
            static fn (array $setting): string => implode('.', $setting['path']),
            self::strayed(),
        );

        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Every setting in `config/docuccino.php` that is not the framework's to keep, as the path it sits
     * at against the value written there.
     *
     * THE definition of the split, and the only place it is decided. The path is a list of segments and
     * never a dotted string, because a document key is an application's word and may hold a dot of its
     * own — joining here would make `documents.my.api.info` a path nothing could nest again.
     *
     * The depth each branch stops at is {@see FRAMEWORK_KEYS}: `enabled` is the framework's outright,
     * `cache` and a document bag are shared and so are read one level in, and everything else is a
     * build setting whole.
     *
     * @return list<array{path: list<string>, value: mixed}>
     */
    private static function strayed(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('docuccino', []);
        $strayed = [];

        foreach ($config as $key => $value) {
            $name = (string) $key;

            if ($name === 'enabled') {
                continue;
            }

            if ($name === 'cache') {
                foreach (Hydrate::map($value) as $inner => $member) {
                    if ($inner !== 'store') {
                        $strayed[] = ['path' => ['cache', $inner], 'value' => $member];
                    }
                }

                continue;
            }

            if ($name === 'documents') {
                foreach (Hydrate::map($value) as $document => $bag) {
                    foreach (Hydrate::map($bag) as $inner => $member) {
                        if ($inner !== 'viewer') {
                            $strayed[] = ['path' => ['documents', $document, $inner], 'value' => $member];
                        }
                    }
                }

                continue;
            }

            $strayed[] = ['path' => [$name], 'value' => $value];
        }

        return $strayed;
    }

    /**
     * The refusal an unmigrated application meets, or null for every other shape.
     *
     * Asked BEFORE a build by every command that reads the configuration to produce or check a
     * document ({@see RefusesUnreadConfig}), which is what makes the error an error: raised only from
     * inside the build it would print and still exit 0 under the default `--fail-on=none`, after a
     * full analysis. {@see ExportDiagnostics} sets that precedent for the other configuration state
     * that leaves nothing sensible to write, and this is the same call — the document would be built
     * from defaults rather than from what the author wrote, which is nearer "cannot proceed" than "a
     * finding you may accept". `--fail-on` picks a gate over what a build FOUND; it is not a say over
     * whether the configuration was read.
     */
    public static function notMigrated(BuildConfig $build): ?Diagnostic
    {
        $stale = self::staleKeys();

        return $stale === [] || $build->file()->error !== ConfigFile::ABSENT
            ? null
            : self::refusal($stale);
    }

    /**
     * What to say about build settings left in the framework config, whose severity is the situation
     * rather than the keys.
     *
     * No configuration file AND settings in `config/docuccino.php` is an application that has not been
     * migrated, and building it from defaults would answer confidently and wrongly: the routes, the
     * info, the security it configured are all silently gone, and the document that comes out looks
     * plausible. That is an ERROR, and the commands refuse before they build ({@see notMigrated()}).
     *
     * A configuration file that IS there beside leftovers is a migration somebody finished and did not
     * tidy. Nothing is lost — the YAML says what the document is — so it is a WARNING naming what to
     * delete.
     *
     * Neither, and nothing is said at all: an application that configures nothing is a supported shape,
     * and a diagnostic there would fire on every fresh install.
     *
     * @param  list<string>  $stale
     */
    private static function migration(BuildConfig $build, array $stale): ?Diagnostic
    {
        if ($stale === []) {
            return null;
        }

        if ($build->file()->error === ConfigFile::ABSENT) {
            return self::refusal($stale);
        }

        $names = NameList::of($stale) ?? '';

        return new Diagnostic(
            severity: Severity::Warning,
            code: self::STALE_KEYS,
            message: sprintf(
                'config/docuccino.php holds %d setting%s the build does not read — %s says what this document is, and these were ignored: %s.',
                count($stale),
                count($stale) === 1 ? '' : 's',
                ConfigFile::NAME,
                $names,
            ),
            help: sprintf(
                'Delete them from config/docuccino.php, which keeps only %s. Nothing there is merged over %s — so any of these you still want have to be written into it by hand first.',
                implode(', ', self::FRAMEWORK_KEYS),
                ConfigFile::NAME,
            ),
        );
    }

    /**
     * The one construction site for {@see NOT_MIGRATED}, so the refusal a command prints and the
     * report a build carries are the same sentence.
     *
     * @param  list<string>  $stale
     */
    private static function refusal(array $stale): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Error,
            code: self::NOT_MIGRATED,
            message: sprintf(
                'There is no %s, and config/docuccino.php holds %d setting%s the build no longer reads, so the document would have been built from defaults alone: %s.',
                ConfigFile::NAME,
                count($stale),
                count($stale) === 1 ? '' : 's',
                NameList::of($stale) ?? '',
            ),
            help: sprintf(
                'Write the settings you still want into %s under the same names, then delete them from config/docuccino.php — which keeps only %s.',
                ConfigFile::NAME,
                implode(', ', self::FRAMEWORK_KEYS),
            ),
        );
    }

    /**
     * What the viewer entries in the framework config say about documents the build knows, and about
     * each other.
     *
     * Only ONE direction of the id comparison is reported. A viewer naming a document that does not
     * exist is a request that will reach {@see DocsController} and fail, and
     * boot cannot know — it registers the routes and finds out per request. A document with NO viewer
     * is an ordinary shape and says nothing: an export-only document is exactly that, and reporting
     * both directions would turn one renamed id into two diagnostics pointing opposite ways.
     *
     * The orphan check waits for a configuration file that parsed. With no file the build has no
     * document set to judge a viewer against, and every viewer would read as an orphan on top of the
     * error that already says why.
     *
     * @return list<Diagnostic>
     */
    private static function viewerReport(BuildConfig $build): array
    {
        $viewers = self::viewerRoutes();
        $diagnostics = [];

        if ($build->present()) {
            // Asked of ConfiguredDocuments and not of the raw bag: the build's document set is the one
            // with the fallback applied, and judging a viewer against the raw bag would report the
            // `default` document's own viewer as an orphan on a file that declares no documents.
            $defined = ConfiguredDocuments::of($build);
            $orphans = array_values(array_filter(
                array_keys($viewers),
                static fn (string $key): bool => ! array_key_exists($key, $defined),
            ));

            if ($orphans !== []) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: self::VIEWER_ORPHAN,
                    message: sprintf(
                        'config/docuccino.php configures a viewer for %d document%s %s does not define, so those routes are registered and every request to them fails: %s.',
                        count($orphans),
                        count($orphans) === 1 ? '' : 's',
                        ConfigFile::NAME,
                        NameList::of($orphans) ?? '',
                    ),
                    help: sprintf('Add the document to %s, or delete its viewer entry.', ConfigFile::NAME),
                );
            }
        }

        foreach (self::collisions($viewers) as $route => $keys) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: self::VIEWER_COLLISION,
                message: sprintf(
                    '%d documents configure the viewer route %s, and the framework keeps only the last one registered: %s.',
                    count($keys),
                    $route,
                    NameList::of($keys) ?? '',
                ),
                help: 'Give each document its own viewer.route in config/docuccino.php.',
            );
        }

        return $diagnostics;
    }

    /**
     * Document key => the viewer route it claims, for every document that claims one. Read straight
     * off the framework config the way boot reads it, so what this judges is what will be registered.
     *
     * @return array<string, string>
     */
    private static function viewerRoutes(): array
    {
        $routes = [];

        foreach (ViewerConfig::all() as $key => $viewer) {
            $route = Hydrate::map($viewer)['route'] ?? null;

            if (is_string($route) && $route !== '') {
                $routes[$key] = '/'.ltrim($route, '/');
            }
        }

        return $routes;
    }

    /**
     * Routes claimed by more than one document, route => the documents claiming it. Keyed and sorted
     * by route so the report is the same however the documents are declared.
     *
     * @param  array<string, string>  $viewers
     * @return array<string, list<string>>
     */
    private static function collisions(array $viewers): array
    {
        $byRoute = [];
        foreach ($viewers as $key => $route) {
            $byRoute[$route][] = $key;
        }

        $collisions = array_filter($byRoute, static fn (array $keys): bool => count($keys) > 1);
        ksort($collisions, SORT_STRING);

        return $collisions;
    }
}
