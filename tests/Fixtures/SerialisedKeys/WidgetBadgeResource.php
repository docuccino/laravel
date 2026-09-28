<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SerialisedKeys;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Nests plain value objects in its body, which `json_encode` writes property by property. */
final class WidgetBadgeResource extends JsonResource
{
    /** @return array{badges: list<WidgetBadge>} */
    public function toArray(Request $request): array
    {
        return ['badges' => [new WidgetBadge('b1', 'new', false, null)]];
    }
}
