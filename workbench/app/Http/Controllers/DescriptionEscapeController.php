<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\DescriptionFromFile;
use Illuminate\Http\JsonResponse;

/**
 * A controller whose #[DescriptionFromFile] points at a path escaping the application base — used
 * to prove the path-confinement guard (security L2) rejects the traversal with a diagnostic and
 * reads nothing.
 */
final class DescriptionEscapeController
{
    #[DescriptionFromFile('../../../../../../etc/passwd')]
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
