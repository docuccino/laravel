<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\WidgetData;
use Workbench\App\Http\Requests\StoreWidgetRequest;

/**
 * Exercises the validation integration: a FormRequest-bound store action whose request body is
 * recovered from `StoreWidgetRequest::rules()` (analysed statically, never executed).
 */
final class ValidationController
{
    #[Group('Widgets')]
    #[Response(status: 201, type: WidgetData::class, description: 'The created widget.')]
    public function store(StoreWidgetRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }
}
