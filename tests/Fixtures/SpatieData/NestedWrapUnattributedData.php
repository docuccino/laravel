<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * A class holding a nested collection that disables wrapping behind a helper hop, so no receiver can
 * be named for the disabling. Spatie really does send everything here bare, which is why the nested
 * report has to stay quiet: it could not be told from the shape that keeps the envelope, and half the
 * time it would be reporting a divergence that is not there. Only ever reflected.
 */
final class NestedWrapUnattributedData extends Data
{
    /** @param list<NestedWrapItemData> $things */
    public function __construct(public array $things) {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse($this->transform($this->bare()));
    }

    private function bare(): TransformationContextFactory
    {
        return TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled);
    }
}
