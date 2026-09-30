<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection whose name finds {@see ReleaseResource} while `#[Collects]` names
 * {@see ChannelResource} — an attribute only a framework that reads it honours.
 */
#[Collects(ChannelResource::class)]
final class ReleaseResourceCollection extends ResourceCollection {}
