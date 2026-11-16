<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** Where one page sits in a paginated list. */
final class PageMetaData extends Data
{
    public function __construct(
        /** The page this is, counting from 1. */
        public int $current_page,
        /** How many items there are across every page. */
        public int $total,
    ) {}
}
