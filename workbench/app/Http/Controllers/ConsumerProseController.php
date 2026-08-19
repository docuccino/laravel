<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\Summary;
use Illuminate\Http\JsonResponse;

/**
 * The ways an action can address an API consumer while its docblock keeps talking to whoever
 * maintains it: the `@summary`/`@description` tags, and the `#[Summary]`/`#[Description]` attributes
 * that outrank them.
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
}
