<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;

/**
 * The 422 problem document the batch endpoints answer with. Its `origin` is typed as an INTERSECTION, so
 * the document describes it as a conjunction of two shapes — the one member here no render arm can write
 * out, and the shape a filled illustration has to be an instance of both halves of.
 */
final readonly class BatchProblem
{
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public BatchOrigin&BatchVerified $origin,
    ) {}

    /** This document at 422, labelled the way RFC 9457 asks for. */
    public function toProblemResponse(): JsonResponse
    {
        return new JsonResponse($this, 422, ['Content-Type' => 'application/problem+json']);
    }
}
