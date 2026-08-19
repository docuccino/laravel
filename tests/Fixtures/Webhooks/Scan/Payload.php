<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Scan;

/**
 * A class that spells `class` three more times without declaring anything: a constant fetch, an
 * anonymous class, and this docblock.
 */
final class Payload
{
    public function name(): string
    {
        $anonymous = new class
        {
            public string $label = 'anonymous';
        };

        return self::class.$anonymous->label;
    }
}
