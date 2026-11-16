<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Two returns that agree on the key and not on the code, and a closure whose own return is no return of
 * `toArray()`'s.
 *
 * @mixin Crate
 */
final class BranchingCrateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $describe = function (): array {
            return ['code' => $this->code];
        };

        if ($request->boolean('loud')) {
            return ['id' => $this->id, 'code' => strtoupper((string) $this->code), 'about' => $describe()];
        }

        return ['id' => $this->id, 'code' => $this->code];
    }
}
