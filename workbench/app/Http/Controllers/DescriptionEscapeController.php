<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Description;
use Illuminate\Http\JsonResponse;

/**
 * A controller whose #[Description(file: …)] points at a path escaping the application base — used
 * to prove the path-confinement guard (security L2) rejects the traversal with a diagnostic and
 * reads nothing.
 */
final class DescriptionEscapeController
{
    #[Description(file: '../../../../../../etc/passwd')]
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
