<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/** A collection whose `$collects` names a class that is not a resource. Only ever reflected. */
final class StrayCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = \stdClass::class;
}
