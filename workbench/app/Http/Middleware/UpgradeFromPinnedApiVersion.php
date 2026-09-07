<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The inbound half of the workbench's hand-rolled migrations runtime: read the pinned API version off
 * `X-Api-Version` and walk the JSON body FORWARD, so a request written the way an older version
 * accepted it reaches the application in the shape today's code validates.
 *
 * A request rename needs both halves or the version is a document that lies. The outbound half
 * ({@see DowngradeToPinnedApiVersion}) can be wrong and only a reader notices; this one being wrong
 * means a client pinned to an older version is refused outright, and it is the half a contract test can
 * actually falsify.
 *
 * Docuccino compiles the declarative half only, so it neither reads nor runs this.
 */
final class UpgradeFromPinnedApiVersion
{
    /** The version the inbound rename shipped in. Anyone pinned strictly before it sends the old spelling. */
    private const string SINCE = '2026-09-01';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $pinned = $request->header(DowngradeToPinnedApiVersion::HEADER);

        // Fixed-width dates, so `strcmp` is safe here and would not be on semver — the note on
        // {@see DowngradeToPinnedApiVersion} states why.
        if (is_string($pinned) && $pinned !== '' && strcmp(self::SINCE, $pinned) > 0) {
            $body = $request->json()->all();

            if (is_array($body) && array_key_exists('name', $body) && ! array_key_exists('title', $body)) {
                $body['title'] = $body['name'];
                unset($body['name']);

                $request->json()->replace($body);
                $request->replace($body);
            }
        }

        return $next($request);
    }
}
