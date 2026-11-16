<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package;

use Illuminate\Http\JsonResponse;

/** A package's JSON response, wrapping whatever it is handed in an envelope. */
class EnvelopeResponse extends JsonResponse
{
    public function __construct(mixed $data = null, int $status = 200)
    {
        parent::__construct(['data' => $data], $status);
    }
}
