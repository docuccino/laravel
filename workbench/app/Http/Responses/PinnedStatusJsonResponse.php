<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\JsonResponse;

/** A JSON response that keeps its 423 whatever status it is later handed. */
class PinnedStatusJsonResponse extends JsonResponse
{
    public function __construct()
    {
        parent::__construct(['message' => 'Locked.'], 423);
    }

    public function setStatusCode(int $code, ?string $text = null): static
    {
        return parent::setStatusCode(423, $text);
    }
}
