<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection with Laravel's own `toArray` that keeps the keys of what it collects.
 */
final class KeyedReleaseCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = ReleaseResource::class;

    /** @var bool */
    public $preserveKeys = true;
}
