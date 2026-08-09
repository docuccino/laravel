<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\ExcludeFromDocs;
use Illuminate\Http\JsonResponse;

/**
 * An excluded controller: `#[ExcludeFromDocs]` keeps every route to it out of the document.
 */
#[ExcludeFromDocs]
final class SecretController
{
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
