<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * A page that is its own envelope, `{items, total}`, handed out unwrapped by a named constructor that
 * builds it with `new static` and hops once more after the unwrapping. Open to extension, so `static` is
 * not `self`.
 */
class StaticBuiltPageData extends Data
{
    /**
     * @param  list<string>  $items
     */
    final public function __construct(
        public array $items,
        public int $total,
    ) {}

    /**
     * @param  list<string>  $items
     */
    public static function page(array $items): static
    {
        return (new static($items, count($items)))->withoutWrapping()->except('none');
    }
}
