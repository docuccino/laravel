<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InheritedShapes;

use Illuminate\Http\Request;

/**
 * A resource that says nothing about its own envelope — the key it is published under is written in its
 * parent's file.
 */
final class EnvelopedResource extends BaseEnvelopeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->id];
    }
}
