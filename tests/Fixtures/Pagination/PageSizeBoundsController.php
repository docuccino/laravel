<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Pagination;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Workbench\App\Models\Gadget;

/**
 * `jsonPaginate()` page sizes whose default and ceiling come from different places: a cap below the
 * configured default, a call site that writes its default above its own cap, and one within its bounds.
 */
final class PageSizeBoundsController
{
    /** @return LengthAwarePaginator<int, Gadget> */
    public function cappedBelowConfig(): LengthAwarePaginator
    {
        return Gadget::query()->jsonPaginate(10);
    }

    /** @return LengthAwarePaginator<int, Gadget> */
    public function defaultAboveCap(): LengthAwarePaginator
    {
        return Gadget::query()->jsonPaginate(50, 80);
    }

    /** @return LengthAwarePaginator<int, Gadget> */
    public function withinBounds(): LengthAwarePaginator
    {
        return Gadget::query()->jsonPaginate(100, 25);
    }
}
