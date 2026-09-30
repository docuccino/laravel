<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\LinkedTimacdonaldCollection;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\TimacdonaldArticleResource;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use TiMacDonald\JsonApi\JsonApiResourceCollection;

/**
 * A page of each JSON:API family's resources, one action per paginator kind. Only ever reflected; the
 * stub engine scripts the paginating chain each action traces to.
 */
final class PaginatedJsonApiController
{
    public function firstParty(): AnonymousResourceCollection
    {
        return MeteredJsonApiResource::collection([]);
    }

    public function timacdonald(): JsonApiResourceCollection
    {
        return TimacdonaldArticleResource::collection([]);
    }

    public function linkedTimacdonald(): LinkedTimacdonaldCollection
    {
        return new LinkedTimacdonaldCollection([], TimacdonaldArticleResource::class);
    }
}
