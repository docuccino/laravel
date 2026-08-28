<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseHeaderMerge;

use Docuccino\Attributes\ResponseHeader;
use Illuminate\Http\JsonResponse;

/**
 * The other end of the merge: a header no producer documented and no declaration typed. Nothing is
 * inherited here, so what the document publishes is whatever the extension floors an untyped entry to.
 */
final class UntypedHeaderController
{
    #[ResponseHeader(name: 'X-Receipt-Id', description: 'Identifies this receipt.')]
    public function show(): JsonResponse
    {
        return response()->json([]);
    }
}
