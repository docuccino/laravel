<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\WorkflowStep;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\FormData;
use Workbench\App\Enums\FormChannel;

/**
 * The routes the workflow assembly is proved against. Registered by the tests rather than by the
 * workbench, so the declarations that are deliberately wrong — a pointer at a member nothing documents,
 * a parameter no operation declares — stay out of every golden document.
 */
final class WorkflowController
{
    /**
     * Start a checkout.
     *
     * Returns the hold placed on a basket, which the step after this one pays for.
     */
    #[Group('Checkout')]
    #[Response(status: 201, type: FormData::class, description: 'The hold.')]
    #[WorkflowStep('checkout', order: 1, id: 'reserve', outputs: ['holdId' => '$response.body#/id'])]
    public function reserve(): JsonResponse
    {
        return response()->json(new FormData(id: 1, title: 'Hold', publishedAt: null), 201);
    }

    /**
     * Pay for a held basket.
     *
     * Takes the hold the step before it placed.
     */
    #[Group('Checkout')]
    #[QueryParameter('hold', description: 'The hold to pay for.')]
    #[Response(status: 200, type: FormData::class, description: 'The receipt.')]
    #[WorkflowStep('checkout', order: 2, id: 'pay', parameters: ['hold' => '$steps.reserve.outputs.holdId'])]
    public function pay(): JsonResponse
    {
        return response()->json(new FormData(id: 2, title: 'Receipt', publishedAt: null));
    }

    /**
     * Read a member the response never documents.
     *
     * `FormData` publishes `id`, `title` and `publishedAt` and nothing else, so the pointer below names
     * a member the document does not describe.
     */
    #[Group('Checkout')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('typo', order: 1, id: 'mistyped', outputs: ['ref' => '$response.body#/referenceCode'])]
    public function mistyped(): JsonResponse
    {
        return response()->json(new FormData(id: 3, title: 'Form', publishedAt: null));
    }

    /**
     * Read a member of a response that describes no shape at all.
     *
     * Nothing here can contradict the pointer, so nothing is reported about it.
     */
    #[Group('Checkout')]
    #[Response(status: 200, description: 'Whatever the caller gets.')]
    #[WorkflowStep('vague', order: 1, id: 'unshaped', outputs: ['anything' => '$response.body#/who/knows'])]
    public function unshaped(): JsonResponse
    {
        return response()->json(['who' => ['knows' => true]]);
    }

    /**
     * Pass a parameter the operation does not declare.
     */
    #[Group('Checkout')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('stray', order: 1, id: 'strays', parameters: ['nothing' => 'x'])]
    public function strays(): JsonResponse
    {
        return response()->json(new FormData(id: 4, title: 'Form', publishedAt: null));
    }

    /**
     * Read an output the step it names does not produce.
     *
     * `reserve` is a step of this same workflow and declares `holdId`, so the reference is judged —
     * unlike one naming a step in another document, which this build cannot see and says nothing about.
     */
    #[Group('Checkout')]
    #[QueryParameter('hold', description: 'The hold to pay for.')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('checkout', order: 3, id: 'reads', parameters: ['hold' => '$steps.reserve.outputs.nope'])]
    public function reads(): JsonResponse
    {
        return response()->json(new FormData(id: 5, title: 'Form', publishedAt: null));
    }

    /**
     * Declare a body JSON cannot carry.
     *
     * A pure enum case is a legal attribute argument and ordinary PHP to write; `json_encode` refuses
     * it. The step cannot be recorded, and the build says so rather than publishing a workflow one
     * step short in silence.
     */
    #[Group('Checkout')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('unreadable', order: 1, id: 'sends', body: ['channel' => FormChannel::Email])]
    public function sends(): JsonResponse
    {
        return response()->json(new FormData(id: 11, title: 'Form', publishedAt: null));
    }

    /**
     * Belong to a workflow whose name Arazzo cannot carry.
     */
    #[Group('Checkout')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('check out', order: 1, id: 'spaced')]
    public function spaced(): JsonResponse
    {
        return response()->json(new FormData(id: 8, title: 'Form', publishedAt: null));
    }

    /**
     * Share a step id with another step of the same workflow.
     */
    #[Group('Checkout')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('twins', order: 1, id: 'same')]
    public function twinOne(): JsonResponse
    {
        return response()->json(new FormData(id: 9, title: 'Form', publishedAt: null));
    }

    /**
     * The other half of the shared step id.
     */
    #[Group('Checkout')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('twins', order: 2, id: 'same')]
    public function twinTwo(): JsonResponse
    {
        return response()->json(new FormData(id: 10, title: 'Form', publishedAt: null));
    }

    /**
     * Claim a position another step of the same workflow also claims.
     */
    #[Group('Checkout')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('contested', order: 1, id: 'first')]
    public function first(): JsonResponse
    {
        return response()->json(new FormData(id: 6, title: 'Form', publishedAt: null));
    }

    /**
     * The other half of the contested position.
     */
    #[Group('Checkout')]
    #[Response(status: 200, type: FormData::class, description: 'A form.')]
    #[WorkflowStep('contested', order: 1, id: 'second')]
    public function second(): JsonResponse
    {
        return response()->json(new FormData(id: 7, title: 'Form', publishedAt: null));
    }
}
