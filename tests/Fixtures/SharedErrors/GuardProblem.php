<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;

/**
 * The one RFC 9457 problem document every arm of {@see GuardProblemRenderer} answers with — the carrier and
 * the documented schema in one class, which is what an application lands on once it wants a single
 * problem-details component instead of an array literal per branch.
 */
final readonly class GuardProblem
{
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
    ) {}

    /** This document at 403, labelled the way RFC 9457 asks for. */
    public function toProblemResponse(): JsonResponse
    {
        return new JsonResponse($this, 403, ['Content-Type' => 'application/problem+json']);
    }
}
