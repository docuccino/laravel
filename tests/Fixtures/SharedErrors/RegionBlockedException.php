<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use RuntimeException;

/** The caller's region is not one this endpoint serves. */
final class RegionBlockedException extends RuntimeException {}
