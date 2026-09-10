<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * A Data class whose own root stays wrapped while it transforms a NESTED value with wrapping disabled —
 * the transformation-context spelling of what {@see NestedUnwrappedData} does with `withoutWrapping()`.
 * A `WrapExecutionType` travels with the transformation it is handed, so disabling it for the author
 * says nothing about this class's own response. Only ever reflected.
 */
final class NestedTransformDisabledData extends Data
{
    public function __construct(
        public int $id,
        public AuthorData $author,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bareAuthor(): array
    {
        return $this->author->transform(
            TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled),
        );
    }
}
