<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource fixture exercising the toArray shape mapping: plain fields plus `whenLoaded`/`when`
 * conditional fields (which the engine types as `T|MissingValue`, making them optional). Only ever
 * reflected — the real toArray shape is supplied by the stub engine in tests.
 *
 * @property object $resource
 */
final class ArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'author' => $this->whenLoaded('author', fn (): AuthorResource => new AuthorResource($this->resource->author)),
            'excerpt' => $this->when($request->boolean('full'), fn (): ?string => $this->resource->excerpt),
        ];
    }
}
