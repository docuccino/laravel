<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\Response;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Workbench\App\Models\Form;

/**
 * One action per producer that writes a response, each dropping the status that producer would have
 * written. What every row proves is the same pair: the status is gone, and nothing the dropped body
 * would have hoisted is left in `components.schemas` — a producer that declines has to decline the
 * conversion too, because nothing prunes that bucket by reachability.
 *
 * Routed only ad-hoc (never in the default route set), so no committed golden includes them.
 */
// A class-level ignore for a status no action here answers with — which is the ordinary way one is
// written, and why a status that matches nothing is not worth a diagnostic.
#[IgnoreResponse(status: 418)]
final class IgnoredResponsesController
{
    /** Inference's own success response, over a body that would hoist a component. */
    #[IgnoreResponse(status: 200)]
    public function inferred(): ArticleResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** The same body under a status nothing drops — the component both actions reach must survive. */
    public function shared(): ArticleResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /**
     * A route that ignores nothing and hoists a component of its own, so a document holding one ignoring
     * route beside it still has a populated bucket — an empty one would agree with any answer at all.
     */
    public function companion(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A 404 the action signals by throwing, mapped through the exception→response chain. */
    #[IgnoreResponse(status: 404)]
    public function signalled(): ArticleResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** The 429 the throttle middleware on this route produces. */
    #[IgnoreResponse(status: 429)]
    public function throttled(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A resource wrapping a fresh create(), whose success status is re-homed 200 → 201. */
    #[IgnoreResponse(status: 201)]
    public function created(): ArticleResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A paginated resource collection, whose 200 is rewrapped in the length-aware envelope. */
    #[IgnoreResponse(status: 200)]
    public function paginated(): AnonymousResourceCollection
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** The same under json-api-paginate's envelope. */
    #[IgnoreResponse(status: 200)]
    public function jsonPaginated(): AnonymousResourceCollection
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** The 400 a strict-mode Query Builder answers an unknown filter with. */
    #[IgnoreResponse(status: 400)]
    public function queried(): AnonymousResourceCollection
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** The 404 synthesized from a model-bound path segment rather than from a throw. */
    #[IgnoreResponse(status: 404)]
    public function implicit(Form $form): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A status the author declared and then dropped in the same breath — the subtraction is the later word. */
    #[Response(status: 202, description: 'Whatever this said, the ignore below retracts.', type: 'array{id: int}')]
    #[IgnoreResponse(status: 202)]
    public function contradicted(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /**
     * The same contradiction at an ERROR status, where the declaration also names a component. The name
     * is the part that reaches past the `Responses` phase: it is re-read at `Finalize` to decide whether
     * anything read it, and the status it names is one a 4xx body can share.
     */
    #[Response(status: 404, description: 'Whatever this said, the ignore below retracts.', errorComponent: 'NotFound')]
    #[IgnoreResponse(status: 404)]
    public function declaredError(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A redirect inference documents under the `3XX` range, with a member of that range dropped. */
    #[IgnoreResponse(status: 302)]
    public function redirect(): RedirectResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }
}
