<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sends the route key through the model's own accessor for it, and the code only sometimes.
 *
 * @mixin Crate
 */
final class KeyedCrateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ref' => $this->getRouteKey(),
            'maybe' => $this->when($request->boolean('full'), $this->code),
        ];
    }
}
