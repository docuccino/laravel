<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseHeaderAttribute;

use Docuccino\Attributes\ResponseHeader;
use Illuminate\Http\JsonResponse;

/**
 * An action declaring one header the server always sends and one it only sometimes does. Both are
 * documented; only the first is `required`, which is the whole difference a consumer's generated client
 * and a contract check can read.
 */
final class ReceiptController
{
    #[ResponseHeader(name: 'X-Receipt-Id', type: 'string', description: 'Identifies this receipt.', required: true)]
    #[ResponseHeader(name: 'X-Reprint-Of', type: 'string', description: 'Present only on a reprint.')]
    public function show(): JsonResponse
    {
        return response()->json([]);
    }
}
