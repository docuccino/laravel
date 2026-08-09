<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Patch\Contribution;

/**
 * Records the `text/html` success representation of a laravel-actions action that defines
 * `htmlResponse()` (Phase 5d). The package's controller decorator returns `htmlResponse($response,
 * $request)` for non-JSON clients (its `ControllerDecorator::__invoke()`), so such an endpoint serves
 * HTML alongside its JSON form. That body is a rendered HTML string / view, not a JSON document, so
 * this adds a `text/html` content entry with a `string` schema rather than trying to type the HTML as
 * JSON.
 *
 * Runs LATE so the inferred JSON success response already exists; the `text/html` note is attached to
 * that same `200` (the decorator transforms one dispatched value, so both representations share a
 * status). Additive only — it never touches the JSON body, so an action defining both `jsonResponse()`
 * and `htmlResponse()` keeps its recovered JSON schema and gains the HTML content type.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class ActionHtmlResponseExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if (! LaravelAction::definesHtmlResponse($context->actionRef->class)) {
            return;
        }

        $by = Contribution::integration('laravel-actions', $context->actionSource());

        $response = $operation->response('200');
        $response->setDescription('OK', $by);
        $response->content('text/html')->set('type', 'string', $by);
    }
}
