<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

/**
 * One of the two parent types {@see Hoarding} is registered through, so which of them the gate resolves
 * to is decided by the order they were registered in and by nothing else.
 */
interface Illuminated {}
