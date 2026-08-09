<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\InDocs;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * A controller pinned to the "admin" document with `#[InDocs]`: even though its route matches the
 * default document's `api/*` include, the attribute keeps it out of every document but "admin".
 */
#[InDocs('admin')]
final class AdminController
{
    /**
     * Admin panel snapshot.
     */
    #[Group('Admin')]
    #[Response(status: 200, type: 'Workbench\\App\\Data\\WidgetData', description: 'The admin panel snapshot.')]
    public function panel(): JsonResponse
    {
        return response()->json([]);
    }
}
