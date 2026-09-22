<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Workflows;

use Docuccino\Attributes\WorkflowStep;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Laravel\Routing\OasPath;

/**
 * Records what each operation says about the workflows it takes part in. It publishes nothing itself —
 * a workflow is a document-level fact, and {@see WorkflowAssembly} is what turns these observations
 * into one.
 *
 * Finalize, because the step carries the operation's NODE ID and its parameters are judged against the
 * operation as published: running earlier would record a claim about a shape later phases were still
 * writing.
 *
 * @internal
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final readonly class WorkflowStepExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        // The route as the DOCUMENT names it, never the operation's node id. A node id is minted per
        // document, and a stored fragment is shared between documents that build alike and re-stamped
        // on restore — so a note carrying one would hand the second document the first document's
        // identities, and every step of every workflow would resolve to nothing. This string is a
        // function of the route alone, so it survives the sharing, and the assembly resolves it against
        // the document it is actually building.
        $method = $context->documentedMethod ?? $context->route->primaryMethod();
        $signature = strtoupper($method).' '.OasPath::of($context->route->uri);

        foreach ($context->attributes->all(WorkflowStep::class) as $declaration) {
            $workflow = trim($declaration->workflow);

            if ($workflow === '') {
                continue;
            }

            DeclaredSteps::record($context, $workflow, [
                'order' => $declaration->order,
                'id' => trim($declaration->id),
                'route' => $signature,
                'description' => trim($declaration->description),
                'parameters' => $declaration->parameters,
                'body' => $declaration->body,
                'contentType' => trim($declaration->contentType),
                'outputs' => $declaration->outputs,
            ]);
        }
    }
}
