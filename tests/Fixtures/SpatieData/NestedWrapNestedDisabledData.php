<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * A class holding a nested collection that also hands a disabled transformation to a value it HOLDS.
 * The disabling rides that one transformation and never reaches the ordinary serialisation of the
 * collection, so the envelope is still on the wire and the report is still owed. Only ever reflected.
 */
final class NestedWrapNestedDisabledData extends Data
{
    /** @param list<NestedWrapItemData> $things */
    public function __construct(
        public array $things,
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
