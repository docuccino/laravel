<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * A Data class that writes its OWN response and disables wrapping on the TRANSFORMATION rather than the
 * response — the second spelling a real production app uses, and the one the class-level unwrapping read
 * would otherwise have no case for. Only ever reflected; the wrap key is read statically off this file.
 */
final class OwnResponseProblemData extends Data
{
    public function __construct(
        public string $type,
        public int $status,
    ) {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(
            data: $this->transform(
                TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled)
            ),
            status: $this->status,
            headers: ['Content-Type' => 'application/problem+json'],
        );
    }
}
