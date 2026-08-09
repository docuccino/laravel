<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * A Laravel first-party JSON:API resource fixture. Only ever reflected; the per-member shapes
 * (`toAttributes`/`toRelationships`/`toLinks`) are supplied by the stub engine in tests.
 *
 * @property object $resource
 */
final class ArticleJsonApiResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'articles';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'title' => $this->resource->title,
            'body' => $this->resource->body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toRelationships(Request $request): array
    {
        return [
            'author' => fn (): AuthorResource => new AuthorResource($this->resource->author),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLinks(Request $request): array
    {
        return [
            'self' => "/articles/{$this->resource->id}",
        ];
    }
}
