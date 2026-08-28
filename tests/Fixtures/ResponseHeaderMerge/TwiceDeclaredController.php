<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseHeaderMerge;

use Docuccino\Attributes\ResponseHeader;
use Illuminate\Http\JsonResponse;

/**
 * One header name declared twice — once on the controller for every action, once on this action to say
 * something the controller's did not. Both are the attribute layer, so the ladder cannot separate them
 * and the tie is settled the way every other argument of the attribute settles it: the declaration
 * nearest the operation wins the members it states, and the other one's survive.
 */
#[ResponseHeader(name: 'X-Receipt-Id', type: 'integer', description: 'Identifies the receipt.', required: true)]
final class TwiceDeclaredController
{
    #[ResponseHeader(name: 'X-Receipt-Id', description: 'Identifies the reprinted receipt.')]
    public function show(): JsonResponse
    {
        return response()->json([]);
    }
}
