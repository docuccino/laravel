<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use DateTimeInterface;

/**
 * {@see Dial} plus a `serializeDate()` override and nothing else, so a column the two serialise
 * differently is a column the hook governs.
 *
 * The pattern is deliberately unlike any of {@see Dial}'s own cast parameters: a parameterised cast
 * that happened to render the same bytes as the override would read as "the hook did not reach it"
 * whether that was true or not.
 */
final class Gnomon extends Dial
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('D, d M Y H:i:s');
    }
}
