<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** The same page, built by spatie's own `from()`. */
final class FromBuiltPageData extends Data
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
        return self::from(['items' => $items, 'total' => count($items)])->withoutWrapping();
    }
}
