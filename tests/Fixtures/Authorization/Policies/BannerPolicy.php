<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Banner;

/** Conventional policy for {@see Banner}, all of it borrowed. */
final class BannerPolicy
{
    use AdmitsEveryone;
}
