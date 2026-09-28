<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\StatusChoice;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The resource an idempotent create-or-return answers with, under 201 or 200. */
final class StatusChoiceResource extends JsonResource
{
    /**
     * @return array{id: int}
     */
    public function toArray(Request $request): array
    {
        return ['id' => 1];
    }
}
