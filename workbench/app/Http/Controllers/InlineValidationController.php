<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workbench\App\Rules\NoticeFilter;

/**
 * Validates inline, and declares one of the two fields whose rules nothing can read statically — so
 * the document publishes that field from the declaration and drops the other — beside a field with one
 * rule object it cannot read.
 */
final class InlineValidationController
{
    #[BodyParameter(name: 'payload', type: 'object', description: 'Whatever the callback accepts.')]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string',
            'payload' => [static fn (): bool => true],
            'secret' => [static fn (): bool => true],
            'summary' => ['string', new NoticeFilter],
        ]);

        return response()->json([], 201);
    }
}
