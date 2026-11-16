<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** The same page, built by `new` of the class's own name. */
final class NamedBuiltPageData extends Data
{
    /**
     * @param  list<string>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {}

    /**
     * @param  list<string>  $items
     */
    public static function page(array $items): self
    {
        return (new NamedBuiltPageData($items, count($items)))->withoutWrapping();
    }
}
