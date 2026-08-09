<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Workbench\App\Models\Form;

/**
 * A plain controller: `response()->json(...)` returns whose shapes the engine infers, plus a
 * route-model-bound show route.
 */
final class FormController
{
    /**
     * List forms.
     *
     * Returns the collection of forms visible to the caller.
     */
    #[Group('Forms')]
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    /**
     * Show a form.
     */
    #[Group('Forms')]
    public function show(Form $form): JsonResponse
    {
        return response()->json($form);
    }
}
