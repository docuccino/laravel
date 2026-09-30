<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A base collection naming {@see ChannelResource} by `#[Collects]`, which a subclass does not inherit.
 */
#[Collects(ChannelResource::class)]
abstract class AttributedShelf extends ResourceCollection {}
