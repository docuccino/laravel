<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * A self-referential JSON:API resource fixture: a comment relates to its own replies (again
 * comments). Only ever reflected; the per-member shapes are supplied by the stub engine, whose
 * `replies` relationship types back to this same class — exercising the component-hoist cycle-break.
 *
 * @property object $resource
 */
final class CommentJsonApiResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'comments';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'body' => $this->resource->body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toRelationships(Request $request): array
    {
        return [
            'replies' => fn (): self => new self($this->resource->parent),
        ];
    }
}
