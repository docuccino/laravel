<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SerialisedKeys;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;

/** Answers with plain objects nested in a resource, returned whole, and one that states its own JSON form; and accepts one. */
final class WidgetController
{
    public function badges(): WidgetBadgeResource
    {
        return new WidgetBadgeResource(null);
    }

    public function stock(): JsonResponse
    {
        return response()->json(new WidgetStock(1));
    }

    #[BodyParameter(name: 'stock', type: WidgetStock::class, required: true)]
    public function store(): JsonResponse
    {
        return response()->json(new WidgetStock(1), 201);
    }

    public function caption(): JsonResponse
    {
        return response()->json(new WidgetCaption(1));
    }
}
