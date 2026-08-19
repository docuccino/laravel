<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\Example;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\WidgetData;

/**
 * The happy paths for `#[Example]`: several named examples on one response, one on another status, a
 * payload loaded from each file format, examples on a request body and on a query parameter.
 */
final class ExamplesController
{
    #[Response(status: 200, type: WidgetData::class, description: 'The widget.')]
    #[Response(status: 404, type: WidgetData::class, description: 'No such widget.')]
    #[Example(value: ['id' => 1, 'name' => 'Sprocket', 'status' => 'published'], name: 'stocked', summary: 'A widget in stock')]
    #[Example(value: ['id' => 2, 'name' => 'Grommet', 'status' => 'archived'], name: 'discontinued', summary: 'One that is no longer sold', description: 'Still readable, never orderable.')]
    #[Example(name: 'missing', value: ['id' => 0, 'name' => '', 'status' => 'archived'], status: 404)]
    public function show(): JsonResponse
    {
        return response()->json([]);
    }

    #[Response(status: 200, type: WidgetData::class, description: 'The widget.')]
    #[Example(name: 'from-json', file: 'docuccino-example.json', summary: 'Loaded from JSON')]
    #[Example(name: 'from-yaml', file: 'docuccino-example.yaml')]
    #[Example(name: 'from-yml', file: 'docuccino-example.yml')]
    #[Example(name: 'elsewhere', externalValue: 'https://example.test/widgets/1.json')]
    public function fromFile(): JsonResponse
    {
        return response()->json([]);
    }

    #[Response(status: 201, type: WidgetData::class, description: 'The created widget.')]
    #[BodyParameter(name: 'name', type: 'string', required: true)]
    #[BodyParameter(name: 'quantity', type: 'int')]
    #[Example(name: 'minimal', value: ['name' => 'Sprocket'], summary: 'Just the name', request: true)]
    #[Example(name: 'bulk', value: ['name' => 'Sprocket', 'quantity' => 500], request: true)]
    public function store(): JsonResponse
    {
        return response()->json([], 201);
    }

    #[Response(status: 200, type: WidgetData::class, description: 'The matching widgets.')]
    #[QueryParameter(name: 'q', type: 'string', description: 'Free-text search.')]
    #[Example(name: 'by-name', value: 'sprocket', parameter: 'q', summary: 'Search by name')]
    #[Example(name: 'by-sku', value: 'SKU-4711', parameter: 'q')]
    public function search(): JsonResponse
    {
        return response()->json([]);
    }

    #[Response(status: 200, type: WidgetData::class, description: 'The widget.')]
    #[Example(value: ['id' => 7, 'name' => 'Cog', 'status' => 'draft'])]
    public function unnamed(): JsonResponse
    {
        return response()->json([]);
    }

    // One parameter in each location OpenAPI has, so `parameter:` is proven to find them all rather
    // than only the query string it will usually be pointed at.
    #[Response(status: 200, type: WidgetData::class, description: 'The widget.')]
    #[QueryParameter(name: 'q', type: 'string')]
    #[HeaderParameter(name: 'X-Tenant', type: 'string')]
    #[CookieParameter(name: 'session', type: 'string')]
    #[Example(name: 'a-path-value', value: '42', parameter: 'widget')]
    #[Example(name: 'a-query-value', value: 'sprocket', parameter: 'q')]
    #[Example(name: 'a-header-value', value: 'acme', parameter: 'X-Tenant')]
    #[Example(name: 'a-cookie-value', value: 'abc123', parameter: 'session')]
    public function everyLocation(): JsonResponse
    {
        return response()->json([]);
    }
}
