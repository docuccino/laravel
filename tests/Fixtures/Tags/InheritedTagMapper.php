<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Tags;

/**
 * The class `tags.mapper` would name: it declares no `map()` at all, so its answer is written in a
 * parent and in a trait, and its own file is only where the question was asked.
 */
final class InheritedTagMapper extends BaseTagMapper
{
    protected function prefix(): string
    {
        return 'Inherited';
    }
}
