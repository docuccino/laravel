<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi;

use Illuminate\Http\Request;
use TiMacDonald\JsonApi\JsonApiResource;
use TiMacDonald\JsonApi\Link;

/**
 * A `timacdonald/json-api` resource fixture. Only ever reflected; the per-member shapes
 * (`toAttributes`/`toRelationships`/`toLinks`) are supplied by the stub engine in tests. Exercises
 * the timacdonald schema mapper feeding the shared JSON:API document builder.
 *
 * @property object $resource
 */
final class TimacdonaldArticleResource extends JsonApiResource
{
    public string $type = 'articles';

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
            'author' => fn (): string => (string) $this->resource->author,
        ];
    }

    /**
     * @return list<Link>
     */
    public function toLinks(Request $request): array
    {
        return [
            Link::self("/articles/{$this->resource->id}"),
        ];
    }
}
