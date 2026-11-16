<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Docuccino\Attributes\QueryParameter;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\WrappedData;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Query parameters a package reads off every request to a JSON:API resource, a Data object that opts into
 * request partials, and a signed link, described by their author and not.
 */
final class DescribedResourceController
{
    #[QueryParameter('include', description: 'Related records to embed.')]
    #[QueryParameter('fields', description: 'The attributes to return, per type.')]
    public function article(string $id): ArticleJsonApiResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function plainArticle(string $id): ArticleJsonApiResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    #[QueryParameter('include', description: 'Optional properties to add.')]
    #[QueryParameter('only', description: 'The only properties to return.')]
    public function wrapped(): WrappedData
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function plainWrapped(): WrappedData
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    #[QueryParameter('signature', description: 'Proof the link came from us.')]
    public function confirm(string $token): JsonResponse
    {
        return new JsonResponse(['confirmed' => true]);
    }

    public function plainConfirm(string $token): JsonResponse
    {
        return new JsonResponse(['confirmed' => true]);
    }
}
