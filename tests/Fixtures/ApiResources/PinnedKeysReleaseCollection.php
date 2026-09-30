<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection with Laravel's own `toArray` that keeps its keys by `#[PreserveKeys]`.
 */
#[PreserveKeys]
final class PinnedKeysReleaseCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = ReleaseResource::class;
}
