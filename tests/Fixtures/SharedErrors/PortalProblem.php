<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;

/**
 * The one RFC 9457 problem document both arms of {@see PortalProblemRenderer} answer 401 with. Same
 * carrier, same words, and a `type` one arm writes out and the other computes.
 */
final readonly class PortalProblem
{
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
    ) {}

    /** This document at 401, labelled the way RFC 9457 asks for. */
    public function toProblemResponse(): JsonResponse
    {
        return new JsonResponse($this, 401, ['Content-Type' => 'application/problem+json']);
    }
}
