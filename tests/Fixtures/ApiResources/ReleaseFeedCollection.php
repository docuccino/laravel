<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/** A named collection with Laravel's own `toArray` and `with()`, collecting {@see ReleaseResource}. */
final class ReleaseFeedCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = ReleaseResource::class;
}
