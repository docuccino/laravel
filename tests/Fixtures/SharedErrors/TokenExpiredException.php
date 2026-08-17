<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use RuntimeException;

/** The token a request arrived with has expired. */
final class TokenExpiredException extends RuntimeException {}
