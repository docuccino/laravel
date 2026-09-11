<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * The collection is in the class docblock and nowhere else. A class-level `@property` tag is a
 * property to this build — it is how a magic-attribute model is documented — and it is not one to
 * spatie, whose factory builds from `ReflectionClass::getProperties()` alone. So the key never
 * reaches a payload, wrapped or bare, and there is nothing to report about it.
 *
 * @property NestedWrapItemData[] $things
 */
final class NestedWrapPhantomData extends Data
{
    public function __construct(
        public string $label,
    ) {}
}
