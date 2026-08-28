<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/**
 * The idiomatic workaround for spatie re-wrapping a nested collection: serialise it as the bare list.
 * Its presence is what makes a property opaque to the wrap diagnostic — never its identity.
 */
final class UnwrapCollectionTransformer implements Transformer
{
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): array
    {
        $items = [];

        foreach ($value as $item) {
            $items[] = $item instanceof Data ? $item->toArray() : $item;
        }

        return $items;
    }
}
