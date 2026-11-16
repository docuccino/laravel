<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\JsonResponse;

/** A JSON response that sets its status only on one branch, so no single status is the class's. */
class PendingJsonResponse extends JsonResponse
{
    /** @param  array<string, mixed>  $data */
    public function __construct(array $data = [])
    {
        parent::__construct($data);

        if ($data === []) {
            $this->setStatusCode(202);
        }
    }
}
