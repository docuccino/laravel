<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Laravel\Support\MachineDependentValue;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The `servers` array a document gets when it declares none: the application's own configured URL,
 * published only when that URL names a host a consumer could actually reach.
 *
 * Why this one degrades by OMITTING rather than by publishing-and-warning, which is what every other
 * environment-derived value does (design §9): those are contract-bearing — OAS requires a `tokenUrl`,
 * and a cookie scheme with the wrong `name` breaks the request — so there is no honest way to leave
 * them out. `servers` is the opposite: OAS reads an absent array as `[{"url": "/"}]`, the document
 * resolved against wherever it is served, which is a working answer for a local preview and for a spec
 * served beside its own API. So an unusable `app.url` costs the reader nothing by being dropped, while
 * publishing it would send every generated client at a host that answers inside the network the build
 * ran in and nowhere else. Nothing is reported either: the population is every build of every
 * application that has not set `APP_URL` to a public host — most local builds, and every build from
 * inside a private network or a CI runner — and nothing needs doing in almost all of them.
 *
 * @internal
 */
final class DerivedServers
{
    /**
     * The derived array, or `[]` when the configured URL proves nothing. It has to be a whole URL a
     * client can prepend to a path: a scheme it can speak, a host it can resolve, and no credentials
     * or query string, none of which belong in an OAS Server Object.
     *
     * @return list<array{url: string}>
     */
    public static function for(ConfigRepository $config): array
    {
        $url = $config->get('app.url');
        if (! is_string($url) || $url === '') {
            return [];
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return [];
        }

        $scheme = strtolower(is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return [];
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return [];
        }

        // Two questions, one reading each. The published-value rule owns which hosts name the build
        // machine, so a URL that reports can never be one this publishes; publication then asks the
        // stricter question it cannot answer — whether a reader outside this network could reach the
        // host at all ({@see ReachableHost}, which says why the two differ).
        if (MachineDependentValue::isLocalUrl($url) || ! ReachableHost::isPublishableUrl($url)) {
            return [];
        }

        $port = is_int($parts['port'] ?? null) ? ':'.$parts['port'] : '';
        // A server url is concatenated with each path, which already starts with `/`.
        $path = rtrim(is_string($parts['path'] ?? null) ? $parts['path'] : '', '/');

        return [['url' => $scheme.'://'.$host.$port.$path]];
    }
}
