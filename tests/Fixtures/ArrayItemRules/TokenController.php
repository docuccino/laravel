<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ArrayItemRules;

use Illuminate\Http\JsonResponse;

/** Takes an {@see IssueTokenRequest}. */
final class TokenController
{
    public function store(IssueTokenRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }
}
