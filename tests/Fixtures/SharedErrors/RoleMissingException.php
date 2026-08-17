<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use RuntimeException;

/** The caller is authenticated but holds none of the roles the endpoint needs. */
final class RoleMissingException extends RuntimeException {}
