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
     * client can prepend to a path: a scheme it can speak, a host it can resolve, no query string or
     * fragment, which an OAS Server Object cannot carry, and no credentials, which no published URL
     * carries anywhere.
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

        // Two different questions, one of them asked elsewhere. A query string or a fragment makes the
        // value malformed AS a Server Object — the url is concatenated with a path that follows it —
        // so there is nothing here to publish. Credentials are not malformed, just not ours to carry,
        // and the rule for those has one owner ({@see MachineDependentValue::carriesCredentials()}) so
        // that this and the flow URLs cannot recognise different spellings of the same userinfo. Where
        // they differ is what they DO about it: a flow URL has no fallback and so publishes stripped,
        // while an absent `servers` already reads as the origin the document is served from.
        if (MachineDependentValue::carriesCredentials($url)) {
            return [];
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
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
