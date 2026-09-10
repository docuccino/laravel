<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * A problem document that builds its disabled transformation context in a helper, so the receiver the
 * context reaches is a method hop away from where it is written. Spatie really does send this one
 * unwrapped; the static read cannot follow the hop, so the document keeps the configured envelope and
 * the wrap diagnostic says which class could not be settled — the degraded answer, pinned as it is
 * rather than the fixture being rewritten until it resolves. Only ever reflected.
 */
final class HelperContextProblemData extends Data
{
    public function __construct(
        public string $type,
        public int $status,
    ) {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(
            data: $this->transform($this->bare()),
            status: $this->status,
            headers: ['Content-Type' => 'application/problem+json'],
        );
    }

    private function bare(): TransformationContextFactory
    {
        return TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled);
    }
}
