<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\JsonResponse;

/** A JSON response only its own factory builds, every time with its constructor's 429. */
class QuotaResponse extends JsonResponse
{
    private function __construct(int $status = 429)
    {
        parent::__construct(['error' => 'quota_exceeded'], $status);
    }

    public static function exceeded(): self
    {
        return new self;
    }
}
