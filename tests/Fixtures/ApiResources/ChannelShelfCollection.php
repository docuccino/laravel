<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

/**
 * A collection whose parent's `#[Collects]` is not its own, and whose name finds no resource.
 */
final class ChannelShelfCollection extends AttributedShelf {}
