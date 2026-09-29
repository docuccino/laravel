<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Workbench\App\Http\Requests\SubmitNoteRequest;
use Workbench\App\Http\Requests\TenantNoteRequest;

/**
 * Actions whose FormRequest copies other parts of the request into its input before validating it. The
 * stub engine scripts each trace over the real request bodies; nothing here is dispatched.
 */
final class NotesController
{
    /** Validates headers, a query value and a route parameter its request copies into the input. */
    public function submitNote(SubmitNoteRequest $request, string $note): JsonResponse
    {
        return response()->json(['note' => $request->validated()], 201);
    }

    /** The same request on a read verb, where its rules become query parameters. */
    public function showNote(SubmitNoteRequest $request, string $note): JsonResponse
    {
        return response()->json(['note' => $request->validated()]);
    }

    /** The same request, with the copied header declared under another case: the declaration stands. */
    #[HeaderParameter(name: 'idempotency-key', required: false, description: 'Makes a retried request safe to repeat.')]
    public function replayNote(SubmitNoteRequest $request, string $note): JsonResponse
    {
        return response()->json(['note' => $request->validated()], 201);
    }

    /** A header copied into the input only when it was sent. */
    public function tenantNote(TenantNoteRequest $request): JsonResponse
    {
        return response()->json(['note' => $request->validated()], 201);
    }
}
