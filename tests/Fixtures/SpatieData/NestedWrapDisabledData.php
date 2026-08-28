<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;
use Override;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * A class that disables wrapping for its whole transformation, the way a problem-details carrier does.
 * `Disabled` propagates into nested values, so nothing here is wrapped and nothing is diagnosed.
 */
final class NestedWrapDisabledData extends Data
{
    /** @param list<NestedWrapItemData> $things */
    public function __construct(public array $things) {}

    #[Override]
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse($this->transform(
            TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled),
        ));
    }
}
