<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Dates;

use Carbon\CarbonImmutable;

/**
 * {@see HeritableDate} with the JSON form restated — the same vendor ancestry, a different wire value.
 * Inheriting from a class whose bytes are known says nothing once the method is written again.
 */
final class RestatedDate extends CarbonImmutable
{
    public function jsonSerialize(): string
    {
        return $this->format('d/m/Y');
    }
}
