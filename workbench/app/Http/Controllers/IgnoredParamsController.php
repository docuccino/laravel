<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Workbench\App\Http\Requests\SearchFormsRequest;
use Workbench\App\Models\Form;

/**
 * One action per producer that documents a parameter, each with an `#[IgnoreParam]` naming one of them:
 * a FormRequest recovered a phase later, a paginator key written by the last extension in the parameter
 * phase, the route's own path segment, and class-level parameter attributes an action opts out of.
 * Routed only ad-hoc (never in the default route set), so no committed golden includes them.
 */
#[HeaderParameter(name: 'X-Trace', description: 'A trace id every action but one takes.')]
#[CookieParameter(name: 'flavour', description: 'A cookie every action but one takes.')]
// A class-level ignore for a key only some actions would ever have documented — which is the ordinary
// way one is written, and why a name that matches nothing is not worth a diagnostic.
#[IgnoreParam(name: 'per_page')]
final class IgnoredParamsController
{
    /** A read verb whose FormRequest rules land as query parameters, one of them dropped. */
    #[IgnoreParam(name: 'trace_id', in: 'query')]
    public function search(SearchFormsRequest $request): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A paginated resource collection whose `page` key the last parameter extension writes. */
    #[IgnoreParam(name: 'page')]
    public function paged(): AnonymousResourceCollection
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A route-model-bound path segment, dropped. */
    #[IgnoreParam(name: 'form', in: 'path')]
    public function show(Form $form): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** The class-level header and cookie, dropped by the action that does not take them. */
    #[IgnoreParam(name: 'X-Trace', in: 'header')]
    #[IgnoreParam(name: 'flavour', in: 'cookie')]
    public function bare(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A wrong-cased `in:`, which names the same location the lower-cased spelling does. */
    #[IgnoreParam(name: 'X-Trace', in: 'Header')]
    public function miscased(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** An `in:` that is not a parameter location at all. */
    #[IgnoreParam(name: 'X-Trace', in: 'body')]
    public function nowhere(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A same-action `#[QueryParameter]` the ignore contradicts — the subtraction is the later word. */
    #[QueryParameter(name: 'draft', description: 'Whatever this said, the ignore below retracts.')]
    #[IgnoreParam(name: 'draft', in: 'query')]
    public function contradicted(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }
}
