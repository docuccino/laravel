<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\Description;
use Docuccino\Attributes\Summary;
use Illuminate\Http\JsonResponse;
use Workbench\App\Http\Requests\StoreWidgetRequest;

/**
 * The ways an action can address an API consumer while its docblock keeps talking to whoever
 * maintains it: the `@summary`/`@description` tags, and the `#[Summary]`/`#[Description]` attributes
 * that outrank them — including the `request:` form, which describes the body rather than the operation.
 */
final class ConsumerProseController
{
    /**
     * Internal — dispatched by the queue worker, never call this directly.
     *
     * The retry budget is three attempts.
     */
    #[Summary('Create an invoice')]
    #[Description(text: 'Creates a draft invoice for the authenticated tenant.')]
    public function attributed(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * Internal — dispatched by the queue worker, never call this directly.
     *
     * The retry budget is three attempts.
     *
     * @summary Void an invoice
     *
     * @description Marks an invoice void. Voiding is permanent and cannot be undone.
     */
    public function tagged(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * Internal — dispatched by the queue worker, never call this directly.
     *
     * The retry budget is three attempts.
     *
     * @summary Send an invoice
     */
    public function summaryTagOnly(): JsonResponse
    {
        return response()->json([]);
    }

    #[Description(text: 'Inline prose.', file: 'docuccino-described.md')]
    public function contradictory(): JsonResponse
    {
        return response()->json([]);
    }

    #[Description]
    public function empty(): JsonResponse
    {
        return response()->json([]);
    }

    #[Description(file: 'docuccino-absent.md')]
    public function absentFile(): JsonResponse
    {
        return response()->json([]);
    }

    #[Description(text: 'Creates a widget from the whole submitted body.')]
    #[Description(text: 'Send every field: a widget is replaced wholesale rather than merged.', request: true)]
    public function describedBody(StoreWidgetRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }

    #[Description(file: 'docuccino-body-prose.md', request: true)]
    public function describedBodyFromFile(StoreWidgetRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }

    #[BodyParameter(name: 'reason', type: 'string', required: true)]
    #[Description(text: 'One field, and the whole widget is voided.', request: true)]
    public function describedAttributeBody(): JsonResponse
    {
        return response()->json([]);
    }

    #[Description(text: 'Send only the fields you are changing.', request: true)]
    public function bodylessBodyProse(): JsonResponse
    {
        return response()->json([]);
    }

    #[Description(text: 'Inline prose.', file: 'docuccino-body-prose.md', request: true)]
    public function contradictoryBody(StoreWidgetRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }
}
