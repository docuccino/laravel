<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The workbench's hand-rolled migrations runtime: read the pinned API version off `X-Api-Version`, then
 * walk the JSON body back through the named resource's changes that shipped AFTER the pin, newest first.
 *
 * Docuccino executes nothing of the application, so it neither reads nor runs this. It stands in for the
 * runtime an application owns; only the declarative half is ever compiled into a document.
 *
 * Both orderings below are `strcmp`, and that is safe HERE only because these versions are fixed-width
 * dates. Byte order reads `1.10.0` as older than `1.9.0`, so an API on semver copying this shape would
 * apply its changes in the wrong order and silently serve the wrong shape — compare the three numbers.
 */
final class DowngradeToPinnedApiVersion
{
    public const string HEADER = 'X-Api-Version';

    /**
     * The changes each resource's history declares. Keyed rather than pooled: a migration applied to a
     * payload it knows nothing about is one of the named production failure modes, and a runtime that
     * ran every registered change over every response would have that failure built in.
     *
     * @var array<string, list<class-string<ApiChange>>>
     */
    private const array SETS = [
        'forms' => [TitleReplacesName::class],
        'entries' => [SubmittedAtAlwaysSent::class],
    ];

    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $set  which resource's history to walk, as the route's middleware names it
     */
    public function handle(Request $request, Closure $next, string $set = 'forms'): Response
    {
        $response = $next($request);
        $pinned = $request->header(self::HEADER);

        if (! is_string($pinned) || $pinned === '' || ! $response instanceof JsonResponse) {
            return $response;
        }

        // Migrations applied to error responses and to 204 No Content are a named production failure
        // mode: an error envelope is not the resource's shape, and a 204 has no body to rewrite.
        if ($response->getStatusCode() >= 400 || $response->getStatusCode() === 204) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data) || $data === []) {
            return $response;
        }

        foreach (self::changes($set) as $change) {
            // Strictly newer than the pin: a caller pinned to the version a change shipped in is
            // asking for that change, so it must not fire.
            if (strcmp($change->since(), $pinned) > 0) {
                $data = $change->downgrade($data);
            }
        }

        return $response->setData($data);
    }

    /**
     * Newest first, which is the order downgrades apply in: each one hands the shape of the version
     * below it to the next. The ordering is the part a general runtime would have to keep.
     *
     * @return list<ApiChange>
     */
    private static function changes(string $set): array
    {
        $changes = array_map(static fn (string $change): ApiChange => new $change, self::SETS[$set] ?? []);
        usort($changes, static fn (ApiChange $a, ApiChange $b): int => strcmp($b->since(), $a->since()));

        return $changes;
    }
}
