<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfiguredKeyword;
use Docuccino\Core\Support\Hydrate;

/**
 * One document's `viewer` bag, out of the framework's own `config/docuccino.php`.
 *
 * The whole bag stays there rather than in `docuccino.yaml` because every key in it is read on a path
 * that cannot afford to parse a project file: the route and its middleware at BOOT, and the gate, the
 * driver, the CDN switch, the served source and the driver's configuration on a viewer REQUEST. A
 * viewer page load that parsed a file somebody was halfway through editing would 500 on a document it
 * had already built correctly.
 *
 * One reader, because three places ask and they must agree: boot registers the routes from it, the
 * request renders the page from it, and {@see ConfigSplit} judges it against the documents the build
 * defines. It shapes no emitted byte — {@see DocumentConfig::hash()}
 * lifts `viewer` out — so carrying it on the document config costs nothing and keeps the runtime from
 * having to know which file it came from.
 *
 * @internal
 */
final class ViewerConfig
{
    /**
     * Where the served spec comes from — a closed set, ordered as the shipped framework config lists
     * it. Declared here rather than at the request that switches on it, because the request has
     * nowhere to put a diagnostic and the BUILD is what reports a value outside the set
     * ({@see ConfiguredKeywords}).
     *
     * @var non-empty-list<string>
     */
    public const array SOURCES = ['generate', 'artifact', 'cache'];

    public const string SOURCE_DEFAULT = 'generate';

    /**
     * One viewer bag's `source`, refused rather than coerced: a value outside the set is served the
     * default, which is what the request's own `match` was already going to do, and the build says so.
     *
     * @param  array<string, mixed>  $viewer
     */
    public static function source(array $viewer): string
    {
        return ConfiguredKeyword::read($viewer, 'source', self::SOURCE_DEFAULT, self::SOURCES)->keyword;
    }

    /**
     * The bag for `$key`, empty when the document configures no viewer — which is an ordinary shape,
     * because an export-only document has no page to serve.
     *
     * @return array<string, mixed>
     */
    public static function for(string $key): array
    {
        return Hydrate::map(self::all()[$key] ?? null);
    }

    /**
     * Every document's viewer bag, keyed by document, exactly as the framework config holds it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        /** @var array<string, mixed> $documents */
        $documents = (array) config('docuccino.documents', []);
        $viewers = [];

        foreach ($documents as $key => $bag) {
            $viewers[(string) $key] = Hydrate::map(Hydrate::map($bag)['viewer'] ?? null);
        }

        return $viewers;
    }
}
