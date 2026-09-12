<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Dates;

use Carbon\CarbonImmutable;

/**
 * An application's own Carbon subclass that leaves serialisation alone — the common reason a date
 * column is typed at something other than the vendor class.
 */
final class HeritableDate extends CarbonImmutable {}
