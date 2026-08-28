<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gadget;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Showcase;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Strongbox;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Workbench routes exercising the Phase-4 integrations end-to-end through the pipeline (the golden
 * build reflects these; it never dispatches them, so the bodies are inert). The return/param shapes
 * are supplied by the stub {@see WorkbenchEngine}, standing in for
 * what the real PHPStan engine would recover.
 */
final class IntegrationsController
{
    /** Spatie Data request (body from the Data class) + response, under a folded 201. */
    public function storeArticle(ArticleData $data): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** An anonymous resource collection → array of the item schema. */
    public function listArticleResources(): AnonymousResourceCollection
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A single API resource whose whenLoaded fields become optional. */
    public function showArticleResource(string $id): ArticleResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A paginated resource collection → the length-aware {data, links, meta} envelope (Wave C item 1). */
    public function listPaginatedArticles(): AnonymousResourceCollection
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A jsonPaginate() resource collection → page[...] params + the paginator envelope (Wave C item 2). */
    public function listJsonPaginatedArticles(): AnonymousResourceCollection
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A resource wrapping a fresh Model::create() → a 201 Created response (Wave C item 4). */
    public function storeCreatedArticle(): ArticleResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A first-party JSON:API resource → JSON:API document + include/fields query params. */
    public function showJsonApiArticle(string $id): ArticleJsonApiResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** An Eloquent model → object schema from columns + casts + visible/hidden. */
    public function showWidget(string $id): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A 204 No Content response (noContent()). */
    public function destroyWidget(string $id): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** Distinct return paths carrying distinct statuses (200 + 202). */
    public function storeReport(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A polymorphic morph (Widget|Gadget) → discriminated oneOf keyed by the morph map. */
    public function showAttachment(string $id): Widget|Gadget
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** Throws a renderable exception whose render() defines a 402 — documented by the inferred tier. */
    public function checkout(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A model whose deny-list also reaches its appends and its eager-loaded relations. */
    public function showStrongbox(string $id): Strongbox
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A model whose allow-list is narrowed further by a deny-list naming one of the same keys. */
    public function showShowcase(string $id): Showcase
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /**
     * A union of a spatie Data envelope and a noContent() 204 — two statuses from one action. Placed
     * last so adding it never shifts a golden-routed method's source line. Routed only ad-hoc in
     * DataOrNoContentUnionTest (never in the default route set), so no committed golden includes it.
     */
    public function storeOrCancel(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }
}
