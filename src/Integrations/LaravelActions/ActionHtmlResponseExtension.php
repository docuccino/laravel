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
use Docuccino\Laravel\Support\HtmlRepresentation;

/**
 * Records the `text/html` success representation of an action that defines `htmlResponse()`. The package's
 * controller decorator returns it for non-JSON clients, so the endpoint serves HTML alongside JSON — said
 * the one way the adapter says it ({@see HtmlRepresentation}).
 *
 * Runs LATE so the inferred JSON success response already exists; both representations share the same
 * `200`, since the decorator transforms one dispatched value. Purely additive — an action defining both
 * `jsonResponse()` and `htmlResponse()` keeps its recovered JSON schema and gains the HTML content type.
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
        LaravelAction::dependsOnDeclaration($context);

        if (! LaravelAction::definesHtmlResponse($context->actionRef->class)) {
            return;
        }

        $by = Contribution::integration('laravel-actions', $context->actionSource());

        $response = $operation->response('200');
        $response->setDescription('OK', $by);

        $content = $response->content(HtmlRepresentation::MEDIA_TYPE);
        foreach (HtmlRepresentation::SCHEMA as $keyword => $value) {
            $content->set($keyword, $value, $by);
        }
    }
}
