<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\WidgetData;
use Workbench\App\Http\Requests\StoreWidgetRequest;

/**
 * A FormRequest-bound store action that also names one body property by attribute — the case where the
 * attribute layer PATCHES a recovered body instead of replacing it, and the recovered body's 422
 * survives being patched.
 */
final class AnnotatedValidationController
{
    #[Group('Widgets')]
    #[BodyParameter(name: 'note', type: 'string', description: 'A free-text note.')]
    #[Response(status: 201, type: WidgetData::class, description: 'The created widget.')]
    public function storeAnnotated(StoreWidgetRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }
}
