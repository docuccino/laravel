<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/** A van that switches its envelope off through a helper, which leaves whether its root is wrapped open. */
final class UnsettledVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bare(): array
    {
        return $this->transform($this->context());
    }

    private function context(): TransformationContextFactory
    {
        return TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled);
    }
}
