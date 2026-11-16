<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\JsonResponse;

/** A JSON response whose status a caller may pass, and which is a 503 when it passes none. */
class RetryLaterResponse extends JsonResponse
{
    /** @param  array<string, mixed>  $data */
    public function __construct(array $data, int $status = 503)
    {
        parent::__construct($data, $status);
    }
}
