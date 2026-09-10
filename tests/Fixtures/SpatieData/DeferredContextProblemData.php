<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * The same problem-document shape as {@see OwnResponseProblemData}, written with the transformation
 * context built into a local first — which is how it reads once a second option is set on it. The
 * receiver the context ends up on cannot be named from the call that builds it, so the wrap read
 * says so rather than guessing. Only ever reflected.
 */
final class DeferredContextProblemData extends Data
{
    public function __construct(
        public string $type,
        public int $status,
    ) {}

    public function toResponse($request): JsonResponse
    {
        $context = TransformationContextFactory::create()
            ->withWrapExecutionType(WrapExecutionType::Disabled);

        return new JsonResponse(
            data: $this->transform($context),
            status: $this->status,
            headers: ['Content-Type' => 'application/problem+json'],
        );
    }
}
