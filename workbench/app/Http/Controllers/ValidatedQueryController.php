<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Workbench\App\Enums\WidgetStatus;
use Workbench\App\Http\Requests\ListWidgetsRequest;

/**
 * Read verbs whose query is validated, one action per way of validating it, beside one whose query
 * parameter is only declared. Routed only ad-hoc, so no committed golden includes them.
 */
final class ValidatedQueryController
{
    /** A FormRequest validates the query. */
    public function formRequest(ListWidgetsRequest $request): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** An inline validate() on the request validates the query. */
    public function inline(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(WidgetStatus::class)],
        ]);

        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A validator built from the query and asked to validate it. */
    public function validator(Request $request): JsonResponse
    {
        Validator::make($request->query(), [
            'status' => ['sometimes', Rule::enum(WidgetStatus::class)],
        ])->validate();

        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** The same query key, declared and never validated. */
    #[QueryParameter(name: 'status', type: 'string', description: 'Only widgets in this status.')]
    public function declared(Request $request): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }
}
