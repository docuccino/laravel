<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sends the parcel's key and tracking code as they are stored, and its label rewritten — so the label is
 * not a value any route could look the parcel up by.
 *
 * @mixin Parcel
 */
final class ParcelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tracking' => $this->resource->tracking,
            'label' => strtoupper($this->label),
        ];
    }
}
