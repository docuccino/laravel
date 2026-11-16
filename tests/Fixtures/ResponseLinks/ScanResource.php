<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sends the scan's key, and the key of the parcel it belongs to under a name of its own.
 *
 * @mixin Scan
 */
final class ScanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'parcel_id' => $this->parcel_id, 'place' => $this->place];
    }
}
