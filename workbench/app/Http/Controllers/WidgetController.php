<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\Unauthenticated;
use Illuminate\Http\JsonResponse;

/**
 * An attribute-decorated controller: the docs come entirely from attributes, not inference.
 */
final class WidgetController
{
    /**
     * Create a widget.
     */
    #[Group('Widgets')]
    #[Unauthenticated]
    #[QueryParameter(name: 'dry_run', type: 'bool', description: 'Validate without persisting.')]
    #[Response(status: 201, type: 'Workbench\\App\\Data\\WidgetData', description: 'The created widget.')]
    public function store(): JsonResponse
    {
        return response()->json([], 201);
    }
}
