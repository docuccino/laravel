<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Data;

/**
 * The 409 problem document the export endpoints answer with. Two of its members carry a value domain the
 * schema states for itself — a backed enum and a serialised date-time — which is what a body member looks
 * like once an application describes its errors as carefully as its successes. Two more carry the shapes
 * an illustration has to spell rather than describe: an RFC 9457 extension bag, which is an OBJECT and
 * never a list, and a flag.
 */
final class ExportProblem extends Data
{
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public ExportFailure $reason,
        public CarbonImmutable $failedAt,
        public bool $retryable,
        /** @var array<string, mixed> */
        public array $context,
    ) {}

    /** This document at 409, labelled the way RFC 9457 asks for. */
    public function toProblemResponse(): JsonResponse
    {
        return new JsonResponse($this, 409, ['Content-Type' => 'application/problem+json']);
    }
}
