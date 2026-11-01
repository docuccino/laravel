<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Rules;

use Stringable;

/**
 * A rule object implementing no rule contract, which Laravel casts to the rule string it returns — here
 * one that makes the field nullable. Only ever reflected.
 */
final class LegacyStringRule implements Stringable
{
    public function __toString(): string
    {
        return 'nullable';
    }
}
