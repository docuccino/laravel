<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use RuntimeException;

/** The control: an exception that names nothing, so its error keeps the name its status gives it. */
final class UndeclaredException extends RuntimeException {}
