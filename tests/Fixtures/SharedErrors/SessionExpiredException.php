<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use RuntimeException;

/** The session the request arrived on has expired. */
final class SessionExpiredException extends RuntimeException {}
