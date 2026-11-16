<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\JsonResponse;

/** A problem document whose status each kind of problem passes up. */
class ProblemResponse extends JsonResponse
{
    /** @param  array<string, mixed>  $problem */
    public function __construct(array $problem, int $status)
    {
        parent::__construct($problem, $status, ['Content-Type' => 'application/problem+json']);
    }
}
