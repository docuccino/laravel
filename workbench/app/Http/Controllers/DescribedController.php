<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\DescriptionFromFile;
use Illuminate\Http\JsonResponse;

/**
 * A controller whose #[DescriptionFromFile] points at an in-tree markdown file — the happy path for
 * the path-confinement guard (the file loads into the description and joins the cache dependencies).
 */
final class DescribedController
{
    #[DescriptionFromFile('docuccino-described.md')]
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
