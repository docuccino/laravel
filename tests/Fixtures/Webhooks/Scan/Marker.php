<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Scan;

trait Marker
{
    public function marked(): bool
    {
        return true;
    }
}
