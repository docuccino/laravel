<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseHeaderMerge;

use Docuccino\Attributes\ResponseHeader;
use Illuminate\Http\JsonResponse;

/**
 * Actions whose routes are throttled, so the rate-limit integration documents the four `429` headers —
 * `Retry-After` among them, as a `required` integer — before any attribute is read. Each action states
 * a DIFFERENT subset of what a header entry can carry: the point of the fixture is what each
 * declaration is SILENT about, so nothing here says more than it means to.
 */
final class ThrottledReceiptController
{
    /** Prose and nothing else: no word about the type, none about whether the server always sends it. */
    #[ResponseHeader(name: 'Retry-After', status: 429, description: 'Wait this long before asking for the receipt again.')]
    public function prose(): JsonResponse
    {
        return response()->json([]);
    }

    /** A stated type is a statement, and this layer outranks the one that recovered the integer. */
    #[ResponseHeader(name: 'Retry-After', status: 429, type: 'string')]
    public function type(): JsonResponse
    {
        return response()->json([]);
    }

    /** A written `false` is also a statement: this deployment does not always send the header. */
    #[ResponseHeader(name: 'Retry-After', status: 429, required: false)]
    public function optional(): JsonResponse
    {
        return response()->json([]);
    }

    /** A name nothing inherited, at a status that inherited four others — it joins them rather than replacing them. */
    #[ResponseHeader(name: 'X-Receipt-Id', status: 429, description: 'The receipt this attempt was for.')]
    public function fresh(): JsonResponse
    {
        return response()->json([]);
    }
}
