<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\JsonResponse;

/** A JSON response that is always the same 410 body. */
class GoneJsonResponse extends JsonResponse
{
    public function __construct()
    {
        parent::__construct(['message' => 'This resource is gone.'], 410);
    }
}
